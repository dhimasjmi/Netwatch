<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\DateRange;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

final class DateRangeTest extends TestCase
{
    private const TZ = 'Asia/Jakarta';

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-08 12:00:00', 'UTC'));
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_preset_covers_the_requested_hours_ending_now(): void
    {
        $range = DateRange::fromInput(null, null, '24h', self::TZ);

        $this->assertSame(86400, $range->seconds());
        $this->assertSame(CarbonImmutable::now()->getTimestamp(), $range->to->getTimestamp());
    }

    public function test_unknown_or_missing_input_falls_back_to_seven_days(): void
    {
        $this->assertSame(7 * 86400, DateRange::fromInput(null, null, null, self::TZ)->seconds());
        $this->assertSame(7 * 86400, DateRange::fromInput(null, null, 'bogus', self::TZ)->seconds());
        $this->assertSame(7 * 86400, DateRange::fromInput('bukan-tanggal', '2026-10-01', null, self::TZ)->seconds());
    }

    public function test_dates_are_interpreted_in_display_timezone_and_end_is_inclusive(): void
    {
        $range = DateRange::fromInput('2026-10-01', '2026-10-02', null, self::TZ);

        $this->assertSame(CarbonImmutable::parse('2026-10-01 00:00:00', self::TZ)->getTimestamp(), $range->from->getTimestamp());
        $this->assertSame(CarbonImmutable::parse('2026-10-02 23:59:59', self::TZ)->getTimestamp(), $range->to->getTimestamp());
    }

    public function test_end_in_the_future_is_clamped_to_now(): void
    {
        $range = DateRange::fromInput('2026-10-01', '2099-01-01', null, self::TZ);

        $this->assertSame(CarbonImmutable::now()->getTimestamp(), $range->to->getTimestamp());
    }

    public function test_start_after_end_falls_back_to_default(): void
    {
        $this->assertSame(7 * 86400, DateRange::fromInput('2026-10-05', '2026-10-01', null, self::TZ)->seconds());
    }

    public function test_range_is_capped_to_max_days(): void
    {
        $range = DateRange::fromInput('2015-01-01', '2026-10-01', null, self::TZ, maxDays: 366);

        $this->assertSame(366 * 86400, $range->seconds());
    }
}
