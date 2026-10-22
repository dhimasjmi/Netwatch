<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\StatusInterval;
use App\Support\UptimeCalculator;
use PHPUnit\Framework\TestCase;

final class UptimeCalculatorTest extends TestCase
{
    public function test_fully_up_range_is_100_percent(): void
    {
        $summary = UptimeCalculator::summarize([new StatusInterval(false, 0, 100)], 0, 100);

        $this->assertSame(100, $summary->coveredSeconds);
        $this->assertSame(0, $summary->downSeconds);
        $this->assertSame(0, $summary->outageCount);
        $this->assertSame(100.0, $summary->availabilityPercent());
        $this->assertNull($summary->mttrSeconds());
    }

    public function test_single_outage_is_counted(): void
    {
        $summary = UptimeCalculator::summarize([
            new StatusInterval(false, 0, 40),
            new StatusInterval(true, 40, 60),
            new StatusInterval(false, 60, 100),
        ], 0, 100);

        $this->assertSame(20, $summary->downSeconds);
        $this->assertSame(1, $summary->outageCount);
        $this->assertSame(20, $summary->longestOutageSeconds);
        $this->assertSame(80.0, $summary->availabilityPercent());
        $this->assertSame(20, $summary->mttrSeconds());
    }

    public function test_multiple_outages_report_longest_and_mean(): void
    {
        $summary = UptimeCalculator::summarize([
            new StatusInterval(true, 10, 20),
            new StatusInterval(false, 20, 50),
            new StatusInterval(true, 50, 80),
        ], 0, 100);

        $this->assertSame(2, $summary->outageCount);
        $this->assertSame(40, $summary->downSeconds);
        $this->assertSame(30, $summary->longestOutageSeconds);
        $this->assertSame(20, $summary->mttrSeconds());
    }

    public function test_intervals_are_clipped_to_the_range(): void
    {
        $summary = UptimeCalculator::summarize([new StatusInterval(true, 0, 200)], 50, 150);

        $this->assertSame(100, $summary->coveredSeconds);
        $this->assertSame(100, $summary->downSeconds);
    }

    public function test_open_interval_runs_until_end_of_range(): void
    {
        $summary = UptimeCalculator::summarize([
            new StatusInterval(false, 0, 40),
            new StatusInterval(true, 40, null),
        ], 0, 100);

        $this->assertSame(100, $summary->coveredSeconds);
        $this->assertSame(60, $summary->downSeconds);
        $this->assertSame(40.0, $summary->availabilityPercent());
    }

    public function test_intervals_outside_the_range_are_ignored(): void
    {
        $summary = UptimeCalculator::summarize([
            new StatusInterval(true, 0, 10),
            new StatusInterval(true, 200, 300),
        ], 50, 150);

        $this->assertSame(0, $summary->coveredSeconds);
        $this->assertSame(0, $summary->outageCount);
    }

    public function test_no_data_gives_null_availability_not_100_percent(): void
    {
        $summary = UptimeCalculator::summarize([], 0, 100);

        $this->assertNull($summary->availabilityPercent());
    }

    public function test_empty_or_inverted_range_yields_nothing(): void
    {
        $this->assertSame(0, UptimeCalculator::summarize([new StatusInterval(true, 0, 100)], 100, 100)->coveredSeconds);
        $this->assertSame(0, UptimeCalculator::summarize([new StatusInterval(true, 0, 100)], 100, 50)->coveredSeconds);
        $this->assertSame([], UptimeCalculator::segments([new StatusInterval(true, 0, 100)], 100, 50));
    }

    public function test_availability_is_rounded_to_three_decimals(): void
    {
        $summary = UptimeCalculator::summarize([
            new StatusInterval(false, 0, 2),
            new StatusInterval(true, 2, 3),
        ], 0, 3);

        $this->assertSame(66.667, $summary->availabilityPercent());
    }

    public function test_segments_are_sorted_and_clipped(): void
    {
        $segments = UptimeCalculator::segments([
            new StatusInterval(true, 60, 90),
            new StatusInterval(false, 0, 60),
        ], 10, 80);

        $this->assertSame([
            ['down' => false, 'start' => 10, 'end' => 60],
            ['down' => true, 'start' => 60, 'end' => 80],
        ], $segments);
    }
}
