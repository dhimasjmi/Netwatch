<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\CheckStatus;
use App\Enums\UserRole;
use App\Models\Device;
use App\Models\User;
use App\Services\EventRecorder;
use App\Services\SecurityChecker;
use App\Support\SecurityCheck;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class SecurityCheckTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_admin_can_open_the_security_page(): void
    {
        $this->actingAs($this->user(UserRole::Operator))->get('/security')->assertForbidden();
        $this->actingAs($this->user(UserRole::Admin))->get('/security')->assertOk()->assertSee('Pemeriksaan keamanan');
    }

    public function test_debug_mode_is_flagged_as_risky(): void
    {
        config(['app.debug' => true]);

        $this->assertSame(CheckStatus::Fail, $this->check('Mode debug')->status);

        config(['app.debug' => false]);

        $this->assertSame(CheckStatus::Ok, $this->check('Mode debug')->status);
    }

    public function test_single_admin_is_a_warning(): void
    {
        $this->user(UserRole::Admin);

        $this->assertSame(CheckStatus::Warning, $this->check('Administrator')->status);
    }

    public function test_outdated_agent_is_reported_with_hostname(): void
    {
        config(['netwatch.agent_latest_version' => '1.2.0']);
        $this->device('PC-LAMA', '1.0.0');
        $this->device('PC-BARU', '1.2.0');

        $check = $this->check('Versi agent');

        $this->assertSame(CheckStatus::Warning, $check->status);
        $this->assertStringContainsString('PC-LAMA', $check->detail);
        $this->assertStringNotContainsString('PC-BARU', $check->detail);
    }

    public function test_stale_device_is_reported(): void
    {
        $device = $this->device('PC-HILANG', '1.2.0');
        $device->forceFill(['last_seen_at' => now()->subDays(60)])->save();

        $this->assertSame(CheckStatus::Warning, $this->check('Perangkat usang')->status);
    }

    public function test_many_failed_logins_escalate(): void
    {
        $recorder = app(EventRecorder::class);
        for ($i = 0; $i < 12; $i++) {
            $recorder->record('auth.login_failed', 'gagal');
        }

        $this->assertSame(CheckStatus::Warning, $this->check('Login gagal (24 jam)')->status);

        for ($i = 0; $i < 40; $i++) {
            $recorder->record('auth.login_failed', 'gagal');
        }

        $this->assertSame(CheckStatus::Fail, $this->check('Login gagal (24 jam)')->status);
    }

    public function test_worst_status_wins(): void
    {
        $ok = new SecurityCheck('a', CheckStatus::Ok, '-');
        $warn = new SecurityCheck('b', CheckStatus::Warning, '-');
        $fail = new SecurityCheck('c', CheckStatus::Fail, '-');

        $this->assertSame(CheckStatus::Ok, SecurityChecker::worst([$ok]));
        $this->assertSame(CheckStatus::Warning, SecurityChecker::worst([$ok, $warn]));
        $this->assertSame(CheckStatus::Fail, SecurityChecker::worst([$warn, $fail, $ok]));
    }

    public function test_command_runs_and_fails_when_something_is_risky(): void
    {
        config(['app.debug' => true]);
        $this->user(UserRole::Admin);

        $this->artisan('netwatch:security-check')->assertFailed();
    }

    public function test_security_headers_are_sent(): void
    {
        $this->get('/login')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Cross-Origin-Opener-Policy', 'same-origin');
    }

    // ---------- helper ----------

    private function check(string $title): SecurityCheck
    {
        foreach (app(SecurityChecker::class)->run() as $check) {
            if ($check->title === $title) {
                return $check;
            }
        }

        $this->fail("Pemeriksaan tidak ditemukan: {$title}");
    }

    private function device(string $hostname, string $version): Device
    {
        return Device::query()->create([
            'hostname' => $hostname, 'os_family' => 'windows', 'agent_version' => $version,
            'token_hash' => hash('sha256', $hostname.uniqid('', true)), 'enrolled_at' => now(), 'last_seen_at' => now(),
        ]);
    }

    private function user(UserRole $role): User
    {
        return User::query()->firstOrCreate(
            ['email' => "{$role->value}@example.test"],
            ['name' => $role->label(), 'password' => 'Password-12345', 'role' => $role, 'is_active' => true],
        );
    }
}
