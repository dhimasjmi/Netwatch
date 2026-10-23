<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\TargetStatus;
use App\Enums\UserRole;
use App\Models\Event;
use App\Models\MonitorTarget;
use App\Models\MonitorTargetCheck;
use App\Models\User;
use App\Services\Probe\ProbeResult;
use App\Services\Probe\TargetProber;
use App\Services\RetentionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class NetworkMonitorTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<string, ProbeResult> hasil palsu per alamat */
    private array $responses = [];

    protected function setUp(): void
    {
        parent::setUp();
        config(['netwatch.monitor.enabled' => true, 'netwatch.monitor.allow_public' => false, 'netwatch.monitor.fail_threshold' => 2, 'netwatch.monitor.warn_latency_ms' => 150]);

        $this->app->instance(TargetProber::class, new class($this) implements TargetProber
        {
            public function __construct(private readonly NetworkMonitorTest $test)
            {
            }

            public function probe(array $targets): array
            {
                $out = [];
                foreach ($targets as $t) {
                    $out[$t->id] = $this->test->responseFor($t->address);
                }

                return $out;
            }
        });
    }

    public function responseFor(string $address): ProbeResult
    {
        return $this->responses[$address] ?? new ProbeResult(false);
    }

    // ---------- pengecekan ----------

    public function test_command_updates_status_latency_and_history(): void
    {
        $this->target('Core Switch', '192.168.1.1');
        $this->target('Printer', '192.168.1.50');
        $this->responses = ['192.168.1.1' => new ProbeResult(true, 2.0), '192.168.1.50' => new ProbeResult(true, 185.0)];

        $this->artisan('netwatch:ping-targets')->assertSuccessful();

        $this->assertSame(TargetStatus::Online, MonitorTarget::query()->where('name', 'Core Switch')->sole()->status);
        $printer = MonitorTarget::query()->where('name', 'Printer')->sole();
        $this->assertSame(TargetStatus::Warning, $printer->status);
        $this->assertSame(185.0, $printer->latency_ms);
        $this->assertSame(2, MonitorTargetCheck::query()->count());
        $this->assertSame(0, Event::query()->count()); // naik pertama kali bukan kejadian
    }

    public function test_offline_needs_consecutive_failures_and_raises_one_event_then_recovery(): void
    {
        $target = $this->target('File Server', '192.168.1.10');
        $this->responses = ['192.168.1.10' => new ProbeResult(true, 3.0)];
        $this->artisan('netwatch:ping-targets');

        $this->responses = [];
        $this->artisan('netwatch:ping-targets');
        $this->assertSame(TargetStatus::Online, $target->refresh()->status); // satu kegagalan: belum offline
        $this->assertSame(0, Event::query()->where('code', 'target.offline')->count());

        $this->artisan('netwatch:ping-targets');
        $this->artisan('netwatch:ping-targets');
        $this->assertSame(TargetStatus::Offline, $target->refresh()->status);
        $this->assertSame(1, Event::query()->where('code', 'target.offline')->count()); // tidak berulang tiap menit

        $this->travel(5)->minutes();
        $this->responses = ['192.168.1.10' => new ProbeResult(true, 4.0)];
        $this->artisan('netwatch:ping-targets');

        $this->assertSame(TargetStatus::Online, $target->refresh()->status);
        $recovered = Event::query()->where('code', 'target.online')->sole();
        $this->assertGreaterThanOrEqual(300, $recovered->details['downtime_seconds']);
        $this->assertNull($recovered->device_id);
    }

    public function test_disabled_targets_and_disabled_monitor_are_skipped(): void
    {
        $this->target('Mati', '192.168.1.99', ['is_enabled' => false]);
        $this->responses = ['192.168.1.99' => new ProbeResult(true, 1.0)];

        $this->artisan('netwatch:ping-targets')->assertSuccessful();
        $this->assertSame(0, MonitorTargetCheck::query()->count());

        $this->target('Aktif', '192.168.1.98');
        config(['netwatch.monitor.enabled' => false]);
        $this->artisan('netwatch:ping-targets')->assertSuccessful();
        $this->assertSame(0, MonitorTargetCheck::query()->count());
    }

    public function test_old_check_history_is_pruned(): void
    {
        $target = $this->target('AP', '192.168.1.21');
        config(['netwatch.retention.monitor_checks_days' => 7]);
        MonitorTargetCheck::query()->create(['monitor_target_id' => $target->id, 'checked_at' => now()->subDays(8), 'ok' => true, 'latency_ms' => 1]);
        MonitorTargetCheck::query()->create(['monitor_target_id' => $target->id, 'checked_at' => now()->subDay(), 'ok' => true, 'latency_ms' => 1]);

        $this->assertSame(1, app(RetentionService::class)->prune()['monitor_checks']);
        $this->assertSame(1, MonitorTargetCheck::query()->count());
    }

    // ---------- halaman dan hak akses ----------

    public function test_everyone_can_view_dashboard_but_only_admin_manages(): void
    {
        $this->target('Core Switch', '192.168.1.1', ['status' => 'online', 'latency_ms' => 2.0, 'last_checked_at' => now()]);

        $this->actingAs($this->user(UserRole::Viewer))->get(route('network.index'))
            ->assertOk()->assertSee('Network Operations Center')->assertSee('Core Switch')->assertSee('192.168.1.1')->assertDontSee('■ Stop');

        $this->post(route('network.store'), $this->form())->assertForbidden();
        $this->actingAs($this->user(UserRole::Operator))->post(route('network.store'), $this->form())->assertForbidden();

        $this->actingAs($this->user(UserRole::Admin))->get(route('network.index'))->assertOk()->assertSee('■ Stop')->assertSee('Tambah target');
        $this->assertSame(0, MonitorTarget::query()->where('name', 'Baru')->count());
    }

    public function test_guest_is_redirected_to_login(): void
    {
        $this->get(route('network.index'))->assertRedirect(route('login'));
    }

    public function test_summary_cards_and_filters(): void
    {
        $this->target('A', '192.168.1.1', ['status' => 'online']);
        $this->target('B', '192.168.1.2', ['status' => 'offline']);
        $this->target('C', '192.168.1.3', ['status' => 'warning']);
        $admin = $this->user(UserRole::Admin);

        $this->actingAs($admin)->get(route('network.index'))->assertOk()->assertSee('66.7% tersedia');
        $this->get(route('network.index', ['status' => 'offline']))->assertSee('192.168.1.2')->assertDontSee('192.168.1.1');
        $this->get(route('network.index', ['q' => '192.168.1.3']))->assertSee('192.168.1.3')->assertDontSee('192.168.1.2');
        $this->get(route('network.index', ['q' => '%']))->assertDontSee('192.168.1.1'); // "%" dicari harfiah
    }

    public function test_targets_render_as_cards_with_history_bars(): void
    {
        $target = $this->target('Core Switch', '192.168.1.1', ['status' => 'online', 'latency_ms' => 2.0, 'last_checked_at' => now()]);
        $this->target('Tanpa Riwayat', '192.168.1.2');
        foreach ([[true, 3.0], [true, 400.0], [false, null]] as $i => [$ok, $ms]) {
            MonitorTargetCheck::query()->create(['monitor_target_id' => $target->id, 'checked_at' => now()->subMinutes(3 - $i), 'ok' => $ok, 'latency_ms' => $ms]);
        }

        $this->actingAs($this->user(UserRole::Viewer))->get(route('network.index'))->assertOk()
            ->assertSee('noc-card', false)->assertSee('192.168.1.1')
            ->assertSee('bar-ok', false)->assertSee('bar-slow', false)->assertSee('bar-fail', false)
            ->assertSee('Belum ada riwayat');
    }

    public function test_names_are_escaped(): void
    {
        $this->target('<script>alert(1)</script>', '192.168.1.5');

        $this->actingAs($this->user(UserRole::Admin))->get(route('network.index'))
            ->assertDontSee('<script>alert(1)</script>', false)->assertSee('&lt;script&gt;', false);
    }

    // ---------- CRUD ----------

    public function test_admin_creates_updates_toggles_and_deletes_with_audit(): void
    {
        $this->actingAs($this->user(UserRole::Admin))->post(route('network.store'), $this->form())->assertRedirect(route('network.index'));
        $target = MonitorTarget::query()->sole();
        $this->assertSame('192.168.1.1', $target->address);
        $this->assertSame('icmp', $target->method);
        $this->assertNull($target->port);
        $this->assertDatabaseHas('audit_logs', ['action' => 'monitor_target.created']);

        $this->patch(route('network.update', $target), $this->form(['name' => 'Core Switch Lt.2', 'method' => 'tcp', 'port' => '443']))->assertSessionHasNoErrors();
        $target->refresh();
        $this->assertSame('tcp', $target->method);
        $this->assertSame(443, $target->port);
        $this->assertSame(TargetStatus::Unknown, $target->status);

        $this->post(route('network.toggle', $target));
        $this->assertFalse($target->refresh()->is_enabled);

        $this->delete(route('network.destroy', $target))->assertRedirect(route('network.index'));
        $this->assertSame(0, MonitorTarget::query()->count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'monitor_target.deleted']);
    }

    public function test_validation_rejects_hostnames_loopback_public_ips_and_bad_ports(): void
    {
        $this->actingAs($this->user(UserRole::Admin));

        foreach (['switch.local', '127.0.0.1', '0.0.0.0', '224.0.0.1', '8.8.8.8', '-c 1 1.1.1.1', '192.168.1.1; ls'] as $bad) {
            $this->post(route('network.store'), $this->form(['address' => $bad]))->assertSessionHasErrors('address');
        }
        $this->post(route('network.store'), $this->form(['method' => 'tcp', 'port' => '']))->assertSessionHasErrors('port');
        $this->post(route('network.store'), $this->form(['method' => 'tcp', 'port' => '70000']))->assertSessionHasErrors('port');
        $this->post(route('network.store'), $this->form(['kind' => 'bukan']))->assertSessionHasErrors('kind');
        $this->assertSame(0, MonitorTarget::query()->count());

        config(['netwatch.monitor.allow_public' => true]);
        $this->post(route('network.store'), $this->form(['address' => '8.8.8.8']))->assertSessionHasNoErrors();
    }

    public function test_duplicate_name_or_same_address_and_method_is_rejected(): void
    {
        $this->actingAs($this->user(UserRole::Admin));
        $this->post(route('network.store'), $this->form())->assertSessionHasNoErrors();

        $this->post(route('network.store'), $this->form(['address' => '192.168.1.2']))->assertSessionHasErrors('name');
        $this->post(route('network.store'), $this->form(['name' => 'Lain']))->assertSessionHasErrors('address');
        $this->post(route('network.store'), $this->form(['name' => 'Lain', 'method' => 'tcp', 'port' => '22']))->assertSessionHasNoErrors(); // cara cek berbeda boleh
        $this->assertSame(2, MonitorTarget::query()->count());
    }

    public function test_ping_now_checks_immediately_and_reports_result(): void
    {
        $target = $this->target('Printer', '192.168.1.50');
        $this->responses = ['192.168.1.50' => new ProbeResult(true, 7.5)];

        $this->actingAs($this->user(UserRole::Admin))->post(route('network.ping', $target))
            ->assertRedirect()->assertSessionHas('status', 'Printer merespons dalam 7.5 ms.');

        $this->assertSame(TargetStatus::Online, $target->refresh()->status);
        $this->assertSame(1, MonitorTargetCheck::query()->count());
        $this->assertDatabaseHas('audit_logs', ['action' => 'monitor_target.pinged']);

        $this->actingAs($this->user(UserRole::Viewer))->post(route('network.ping', $target))->assertForbidden();
    }

    // ---------- helper ----------

    /** @param  array<string, mixed>  $override */
    private function form(array $override = []): array
    {
        return $override + ['name' => 'Baru', 'address' => '192.168.1.1', 'kind' => 'switch', 'method' => 'icmp', 'port' => '', 'notes' => ''];
    }

    /** @param  array<string, mixed>  $override */
    private function target(string $name, string $address, array $override = []): MonitorTarget
    {
        return MonitorTarget::unguarded(fn () => MonitorTarget::query()->create($override + ['name' => $name, 'address' => $address, 'kind' => 'other', 'method' => 'icmp']))->refresh();
    }

    private function user(UserRole $role): User
    {
        return User::query()->firstOrCreate(
            ['email' => "{$role->value}@example.test"],
            ['name' => $role->label(), 'password' => 'Password-12345', 'role' => $role, 'is_active' => true],
        );
    }
}
