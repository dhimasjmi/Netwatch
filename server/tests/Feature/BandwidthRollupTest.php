<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Device;
use App\Models\DeviceInterface;
use App\Models\InterfaceSample;
use App\Services\BandwidthRollup;
use App\Support\BandwidthSamples;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class BandwidthRollupTest extends TestCase
{
    use RefreshDatabase;

    private DeviceInterface $iface;

    protected function setUp(): void
    {
        parent::setUp();
        config(['netwatch.retention.bandwidth_raw_days' => 3, 'netwatch.retention.bandwidth_days' => 365]);
        $this->travelTo(CarbonImmutable::parse('2026-10-23 10:30:00', 'UTC'));

        $device = Device::unguarded(fn () => Device::query()->create([
            'public_id' => (string) \Illuminate\Support\Str::uuid(), 'hostname' => 'PC-RU', 'os_family' => 'windows', 'os_version' => 'W11',
            'agent_version' => '1.0.0', 'enrolled_at' => now(), 'token_hash' => hash('sha256', 'tes-rollup'),
        ]));
        $this->iface = DeviceInterface::query()->create(['device_id' => $device->id, 'name' => 'Ethernet', 'last_rx_bytes' => 0, 'last_tx_bytes' => 0, 'last_sample_at' => now()]);
    }

    public function test_old_samples_are_summed_per_hour_then_deleted_and_recent_ones_stay_raw(): void
    {
        $old = now()->subDays(5)->setTime(8, 0);
        $this->sample($old->copy()->addMinutes(10), 100, 10);
        $this->sample($old->copy()->addMinutes(40), 300, 30);       // jam yang sama dengan di atas
        $this->sample(now()->subDay(), 500, 50);                    // masih dalam masa simpan mentah
        $this->sample(now()->subMinutes(10), 700, 70);              // jam berjalan: belum boleh dirangkum

        $result = app(BandwidthRollup::class)->run();

        $row = DB::table('interface_samples_hourly')->where('hour', $old->utc())->sole();
        $this->assertSame(400, (int) $row->rx_bytes);
        $this->assertSame(40, (int) $row->tx_bytes);
        $this->assertSame(2, (int) $row->samples);
        $this->assertSame(2, $result['deleted']);
        $this->assertSame(2, InterfaceSample::query()->count());     // sampel -1 hari dan -10 menit tetap ada
        $this->assertNull(DB::table('interface_samples_hourly')->where('hour', '>=', now()->startOfHour())->first());
    }

    public function test_rollup_is_idempotent(): void
    {
        $this->sample(now()->subDays(5), 100, 10);

        app(BandwidthRollup::class)->run();
        $second = app(BandwidthRollup::class)->run();

        $this->assertSame(0, $second['deleted']);
        $this->assertSame(1, DB::table('interface_samples_hourly')->count());
        $this->assertSame(100, (int) DB::table('interface_samples_hourly')->sole()->rx_bytes);
    }

    public function test_long_window_combines_hourly_and_raw_without_double_counting(): void
    {
        $this->sample(now()->subDays(5), 100, 10);   // akan dirangkum lalu dihapus
        $this->sample(now()->subDays(1), 500, 50);   // dirangkum per jam, mentah masih ada
        $this->sample(now()->subMinutes(30), 900, 90); // hanya mentah
        app(BandwidthRollup::class)->run();

        $sum = BandwidthSamples::between(now()->subDays(6), now())->sum('s.rx_bytes');
        $this->assertSame(1500, (int) $sum);          // 100 + 500 + 900, tidak ada yang dihitung dua kali

        $short = BandwidthSamples::between(now()->subHours(2), now())->sum('s.rx_bytes');
        $this->assertSame(900, (int) $short);
    }

    public function test_old_hourly_rows_are_pruned_by_long_retention(): void
    {
        DB::table('interface_samples_hourly')->insert([
            ['device_interface_id' => $this->iface->id, 'hour' => now()->subDays(400), 'rx_bytes' => 1, 'tx_bytes' => 1, 'interval_seconds' => 3600, 'samples' => 120],
            ['device_interface_id' => $this->iface->id, 'hour' => now()->subDays(10), 'rx_bytes' => 1, 'tx_bytes' => 1, 'interval_seconds' => 3600, 'samples' => 120],
        ]);

        $this->assertSame(1, app(BandwidthRollup::class)->pruneHourly(CarbonImmutable::now()));
        $this->assertSame(1, DB::table('interface_samples_hourly')->count());
    }

    private function sample(\DateTimeInterface $at, int $rx, int $tx): void
    {
        InterfaceSample::query()->create(['device_interface_id' => $this->iface->id, 'sampled_at' => $at, 'rx_bytes' => $rx, 'tx_bytes' => $tx, 'interval_seconds' => 30]);
    }
}
