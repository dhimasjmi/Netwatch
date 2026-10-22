<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\TargetStatus;
use App\Support\TargetStatusDecider;
use PHPUnit\Framework\TestCase;

final class TargetStatusDeciderTest extends TestCase
{
    public function test_success_is_online_and_resets_failures(): void
    {
        $r = TargetStatusDecider::next(TargetStatus::Offline, 5, true, 12.0, 150.0, 2);

        $this->assertSame(TargetStatus::Online, $r['status']);
        $this->assertSame(0, $r['failures']);
    }

    public function test_slow_response_is_warning(): void
    {
        $this->assertSame(TargetStatus::Warning, TargetStatusDecider::next(TargetStatus::Online, 0, true, 185.0, 150.0, 2)['status']);
        $this->assertSame(TargetStatus::Online, TargetStatusDecider::next(TargetStatus::Online, 0, true, 150.0, 150.0, 2)['status']); // tepat di batas masih normal
        $this->assertSame(TargetStatus::Online, TargetStatusDecider::next(TargetStatus::Online, 0, true, null, 150.0, 2)['status']);
    }

    public function test_single_failure_keeps_status_until_threshold(): void
    {
        $first = TargetStatusDecider::next(TargetStatus::Online, 0, false, null, 150.0, 2);
        $this->assertSame(TargetStatus::Online, $first['status']);
        $this->assertSame(1, $first['failures']);

        $second = TargetStatusDecider::next($first['status'], $first['failures'], false, null, 150.0, 2);
        $this->assertSame(TargetStatus::Offline, $second['status']);
        $this->assertSame(2, $second['failures']);
    }

    public function test_unknown_stays_unknown_below_threshold_and_threshold_is_at_least_one(): void
    {
        $this->assertSame(TargetStatus::Unknown, TargetStatusDecider::next(TargetStatus::Unknown, 0, false, null, 150.0, 3)['status']);
        $this->assertSame(TargetStatus::Offline, TargetStatusDecider::next(TargetStatus::Online, 0, false, null, 150.0, 0)['status']);
    }

    public function test_availability(): void
    {
        $this->assertNull(TargetStatusDecider::availability(0, 0));
        $this->assertSame(96.1, TargetStatusDecider::availability(128, 123));
        $this->assertSame(100.0, TargetStatusDecider::availability(10, 10));
    }
}
