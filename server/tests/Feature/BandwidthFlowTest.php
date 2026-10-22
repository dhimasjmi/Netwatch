<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Device;
use App\Models\DeviceInterface;
use App\Models\InterfaceSample;
use App\Models\User;
use App\Services\EnrollmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class BandwidthFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_heartbeats_become_rate_samples_and_counter_reset_is_skipped(): void
    {
        $this->travelTo(now()->startOfMinute());
        [$device, $token] = $this->enrollDevice();

        $this->heartbeat($token, 1_000, 500);              // pembacaan awal: hanya baseline
        $this->assertSame(0, InterfaceSample::query()->count());

        $this->travel(30)->seconds();
        $this->heartbeat($token, 31_000, 2_500);           // selisih 30000 / 2000 byte dalam 30 dtk
        $sample = InterfaceSample::query()->firstOrFail();
        $this->assertSame(30_000, $sample->rx_bytes);
        $this->assertSame(2_000, $sample->tx_bytes);
        $this->assertSame(30, $sample->interval_seconds);

        $this->travel(30)->seconds();
        $this->heartbeat($token, 10, 10);                  // counter turun (reboot) → tidak ada sampel baru
        $this->assertSame(1, InterfaceSample::query()->count());

        $this->travel(30)->seconds();
        $this->heartbeat($token, 3_010, 310);              // setelah rebase kembali normal
        $this->assertSame(2, InterfaceSample::query()->count());
        $this->assertSame(1, DeviceInterface::query()->where('device_id', $device->id)->count());
    }

    public function test_heartbeat_without_interfaces_still_works(): void
    {
        [, $token] = $this->enrollDevice();

        $this->withToken($token)->postJson('/api/agent/v1/heartbeat', ['metrics' => $this->metrics()])->assertOk();
    }

    public function test_bandwidth_page_data_and_csv_are_available_to_viewers(): void
    {
        $this->travelTo(now()->startOfMinute());
        [$device, $token] = $this->enrollDevice();
        $this->heartbeat($token, 0, 0);
        $this->travel(30)->seconds();
        $this->heartbeat($token, 3_750, 750);              // 1000 bps unduh, 200 bps unggah

        $viewer = User::query()->create([
            'name' => 'V', 'email' => 'v@example.test', 'password' => 'Password-12345', 'role' => UserRole::Viewer, 'is_active' => true,
        ]);

        $this->actingAs($viewer)->get("/devices/{$device->public_id}/bandwidth")->assertOk();

        $json = $this->actingAs($viewer)->getJson("/devices/{$device->public_id}/bandwidth/data?preset=24h")->assertOk()->json();
        $this->assertSame('Ethernet', $json['interfaces'][0]['name']);
        $this->assertEqualsWithDelta(1000, $json['interfaces'][0]['avg_rx_bps'], 1);
        $this->assertEqualsWithDelta(200, $json['interfaces'][0]['avg_tx_bps'], 1);

        $csv = $this->actingAs($viewer)->get("/devices/{$device->public_id}/bandwidth/export?preset=24h")->assertOk();
        $this->assertStringContainsString('Ethernet', $csv->streamedContent());
    }

    public function test_invalid_range_input_is_rejected(): void
    {
        [$device] = $this->enrollDevice();
        $admin = User::query()->create([
            'name' => 'A', 'email' => 'a@example.test', 'password' => 'Password-12345', 'role' => UserRole::Admin, 'is_active' => true,
        ]);

        $this->actingAs($admin)->getJson("/devices/{$device->public_id}/bandwidth/data?from=bukan-tanggal&to=x")->assertUnprocessable();
    }

    // ---------- helper ----------

    /** @return array<string, int|float> */
    private function metrics(): array
    {
        return [
            'cpu_percent' => 5, 'memory_used_mb' => 1000, 'memory_total_mb' => 8000,
            'disk_used_gb' => 50, 'disk_total_gb' => 200, 'uptime_seconds' => 1000,
        ];
    }

    private function heartbeat(string $token, int $rx, int $tx): void
    {
        $this->withToken($token)->postJson('/api/agent/v1/heartbeat', [
            'metrics' => $this->metrics(),
            'interfaces' => [['name' => 'Ethernet', 'rx_bytes' => $rx, 'tx_bytes' => $tx, 'speed_bps' => 1_000_000_000]],
        ])->assertOk();
    }

    /** @return array{0: Device, 1: string} */
    private function enrollDevice(): array
    {
        $admin = User::query()->firstOrCreate(
            ['email' => 'enroller@example.test'],
            ['name' => 'E', 'password' => 'Password-12345', 'role' => UserRole::Admin, 'is_active' => true],
        );
        $token = app(EnrollmentService::class)->createToken($admin, 'tes', null, 5, 24)['plain'];

        $response = $this->postJson('/api/agent/v1/enroll', [
            'enrollment_token' => $token,
            'hostname' => 'PC-BW-01',
            'os_family' => 'windows',
            'os_version' => 'Windows 11 Pro',
            'agent_version' => '1.1.0',
        ])->assertCreated();

        return [Device::query()->where('public_id', $response->json('device_id'))->firstOrFail(), $response->json('api_token')];
    }
}
