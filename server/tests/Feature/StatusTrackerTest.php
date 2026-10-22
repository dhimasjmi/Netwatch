<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\ConnectionStatus;
use App\Models\Device;
use App\Models\DeviceStatusHistory;
use App\Services\StatusTracker;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class StatusTrackerTest extends TestCase
{
    use RefreshDatabase;

    private StatusTracker $tracker;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tracker = app(StatusTracker::class);
    }

    public function test_transition_closes_previous_interval_and_opens_a_new_one(): void
    {
        $device = $this->device();
        $start = CarbonImmutable::parse('2026-10-08 10:00:00');

        $this->tracker->markOnline($device, $start, 'enrolled');
        $this->tracker->markOffline($device, $start->addMinutes(5));

        $rows = $this->history($device);

        $this->assertCount(2, $rows);
        $this->assertSame(ConnectionStatus::Online, $rows[0]->status);
        $this->assertTrue($rows[0]->ended_at->equalTo($start->addMinutes(5)));
        $this->assertSame(ConnectionStatus::Offline, $rows[1]->status);
        $this->assertNull($rows[1]->ended_at);
    }

    public function test_repeating_the_same_status_does_not_create_rows(): void
    {
        $device = $this->device();
        $start = CarbonImmutable::parse('2026-10-08 10:00:00');

        $this->tracker->markOnline($device, $start);
        $this->tracker->markOnline($device, $start->addMinute());
        $this->tracker->markOnline($device, $start->addMinutes(2));

        $this->assertCount(1, $this->history($device));
    }

    public function test_end_time_is_never_earlier_than_start_time(): void
    {
        $device = $this->device();
        $start = CarbonImmutable::parse('2026-10-08 10:00:00');

        $this->tracker->markOnline($device, $start);
        $this->tracker->markOffline($device, $start->subMinutes(10)); // jam bergeser mundur

        $rows = $this->history($device);

        $this->assertTrue($rows[0]->ended_at->equalTo($rows[0]->started_at));
        $this->assertTrue($rows[1]->started_at->gte($rows[0]->started_at));
    }

    public function test_close_ends_the_open_interval_without_opening_another(): void
    {
        $device = $this->device();
        $start = CarbonImmutable::parse('2026-10-08 10:00:00');

        $this->tracker->markOnline($device, $start);
        $this->tracker->close($device, $start->addHour());

        $this->assertCount(1, $this->history($device));
        $this->assertSame(0, $this->openCount($device));
    }

    public function test_there_is_at_most_one_open_interval_after_many_transitions(): void
    {
        $device = $this->device();
        $time = CarbonImmutable::parse('2026-10-08 10:00:00');

        foreach (range(0, 9) as $i) {
            $i % 2 === 0
                ? $this->tracker->markOnline($device, $time->addMinutes($i))
                : $this->tracker->markOffline($device, $time->addMinutes($i));
        }

        $this->assertSame(1, $this->openCount($device));
        $this->assertCount(10, $this->history($device));
    }

    public function test_gap_is_recorded_backwards_when_heartbeat_returns_without_scheduler(): void
    {
        $device = $this->device();
        $start = CarbonImmutable::parse('2026-10-08 10:00:00');

        $this->tracker->markOnline($device, $start);
        $this->tracker->recordGapAndMarkOnline($device, $start->addMinute(), $start->addMinutes(31));

        $rows = $this->history($device);

        $this->assertCount(3, $rows);
        $this->assertSame([ConnectionStatus::Online, ConnectionStatus::Offline, ConnectionStatus::Online], $rows->map->status->all());
        $this->assertTrue($rows[1]->started_at->equalTo($start->addMinute()));
        $this->assertTrue($rows[1]->ended_at->equalTo($start->addMinutes(31)));
        $this->assertNull($rows[2]->ended_at);
    }

    public function test_gap_is_not_duplicated_when_scheduler_already_marked_offline(): void
    {
        $device = $this->device();
        $start = CarbonImmutable::parse('2026-10-08 10:00:00');

        $this->tracker->markOnline($device, $start);
        $this->tracker->markOffline($device, $start->addMinute());
        $this->tracker->recordGapAndMarkOnline($device, $start->addMinute(), $start->addMinutes(31));

        $rows = $this->history($device);

        $this->assertCount(3, $rows);
        $this->assertSame([ConnectionStatus::Online, ConnectionStatus::Offline, ConnectionStatus::Online], $rows->map->status->all());
        $this->assertTrue($rows[1]->ended_at->equalTo($start->addMinutes(31)));
    }

    /** @return \Illuminate\Support\Collection<int, DeviceStatusHistory> */
    private function history(Device $device)
    {
        return DeviceStatusHistory::query()->where('device_id', $device->id)->orderBy('started_at')->orderBy('id')->get()->values();
    }

    private function openCount(Device $device): int
    {
        return DeviceStatusHistory::query()->where('device_id', $device->id)->whereNull('ended_at')->count();
    }

    private function device(): Device
    {
        return Device::query()->create([
            'hostname' => 'PC-TEST',
            'os_family' => 'linux',
            'agent_version' => '1.0.0',
            'token_hash' => hash('sha256', 'token-'.uniqid()),
            'enrolled_at' => now(),
        ]);
    }
}
