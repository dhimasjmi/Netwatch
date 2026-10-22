<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\BandwidthMath;
use PHPUnit\Framework\TestCase;

final class BandwidthMathTest extends TestCase
{
    public function test_delta_between_two_readings(): void
    {
        $this->assertSame(['rx' => 500, 'tx' => 200, 'seconds' => 30], BandwidthMath::delta(1000, 100, 0, 1500, 300, 30));
    }

    public function test_counter_reset_is_ignored(): void
    {
        $this->assertNull(BandwidthMath::delta(9000, 9000, 0, 10, 10, 30));
    }

    public function test_long_gap_and_backwards_time_are_ignored(): void
    {
        $this->assertNull(BandwidthMath::delta(0, 0, 0, 10, 10, BandwidthMath::MAX_SAMPLE_GAP_SECONDS + 1));
        $this->assertNull(BandwidthMath::delta(0, 0, 100, 10, 10, 100));
        $this->assertNull(BandwidthMath::delta(0, 0, 100, 10, 10, 50));
    }

    public function test_bucket_grows_with_range_and_never_exceeds_the_largest(): void
    {
        $this->assertSame(60, BandwidthMath::bucketSeconds(3600));
        $this->assertSame(300, BandwidthMath::bucketSeconds(86400));
        $this->assertSame(3600, BandwidthMath::bucketSeconds(7 * 86400));
        $this->assertSame(86400, BandwidthMath::bucketSeconds(366 * 86400)); // batas maksimum rentang
    }

    public function test_bits_per_second(): void
    {
        $this->assertSame(8000.0, BandwidthMath::bitsPerSecond(1000, 1));
        $this->assertSame(0.0, BandwidthMath::bitsPerSecond(1000, 0));
    }
}
