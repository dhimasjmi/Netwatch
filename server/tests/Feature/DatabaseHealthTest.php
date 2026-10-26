<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use App\Services\BackupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

final class DatabaseHealthTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'nwbk-'.bin2hex(random_bytes(4));
        mkdir($this->dir);
        config(['netwatch.backup.path' => $this->dir, 'netwatch.backup.copy_to' => null, 'netwatch.backup.enabled' => true]);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir.DIRECTORY_SEPARATOR.'*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir);
        parent::tearDown();
    }

    public function test_only_admin_can_open_page_and_it_warns_when_no_backup_exists(): void
    {
        foreach ([UserRole::Operator, UserRole::Viewer] as $role) {
            $this->actingAs($this->user($role))->get('/database')->assertForbidden();
        }

        $this->actingAs($this->user(UserRole::Admin))->get('/database')->assertOk()
            ->assertSee('Database &amp; Backup', false)->assertSee('Belum ada backup')->assertSee('salinan kedua', false);
    }

    public function test_recent_backup_clears_the_missing_backup_warning(): void
    {
        file_put_contents($this->dir.'/netwatch-'.gmdate('Ymd-His').'.dump', 'x');

        $this->actingAs($this->user(UserRole::Admin))->get('/database')->assertOk()->assertDontSee('Belum ada backup');
        $this->assertCount(1, app(BackupService::class)->list());
    }

    public function test_stale_backup_is_flagged(): void
    {
        file_put_contents($this->dir.'/netwatch-'.gmdate('Ymd-His', time() - 5 * 86400).'.dump', 'x');

        $this->actingAs($this->user(UserRole::Admin))->get('/database')->assertOk()->assertSee('sudah lebih dari');
    }

    public function test_verify_rejects_a_file_whose_checksum_does_not_match(): void
    {
        $file = $this->dir.'/netwatch-20261023-013000.dump';
        file_put_contents($file, 'isi asli');
        file_put_contents($file.'.sha256', str_repeat('0', 64).'  netwatch-20261023-013000.dump'.PHP_EOL);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Checksum tidak cocok');
        app(BackupService::class)->verify($file);
    }

    public function test_list_ignores_foreign_files_and_orders_newest_first(): void
    {
        foreach (['netwatch-20261001-010000.dump', 'netwatch-20261022-010000.dump', 'catatan.txt', 'netwatch-20261022-010000.dump.sha256'] as $name) {
            file_put_contents($this->dir.'/'.$name, 'x');
        }

        $names = array_column(app(BackupService::class)->list(), 'name');
        $this->assertSame(['netwatch-20261022-010000.dump', 'netwatch-20261001-010000.dump'], $names);
    }

    private function user(UserRole $role): User
    {
        return User::query()->firstOrCreate(['email' => "{$role->value}@example.test"], ['name' => $role->value, 'password' => 'Password-12345', 'role' => $role, 'is_active' => true]);
    }
}
