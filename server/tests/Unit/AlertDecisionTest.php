<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\AlertDecision;
use PHPUnit\Framework\TestCase;

final class AlertDecisionTest extends TestCase
{
    public function test_fires_only_when_window_covered_and_every_sample_at_or_above_threshold(): void
    {
        $this->assertSame('fire', AlertDecision::decide(false, true, 90.0, 95.0, 90));
        $this->assertSame('none', AlertDecision::decide(false, true, 89.9, 95.0, 90));
        $this->assertSame('none', AlertDecision::decide(false, false, 99.0, 99.0, 90)); // perangkat baru online
        $this->assertSame('none', AlertDecision::decide(false, true, null, null, 90));
    }

    public function test_resolves_only_when_all_recent_samples_are_below_threshold(): void
    {
        $this->assertSame('resolve', AlertDecision::decide(true, true, 10.0, 50.0, 90));
        $this->assertSame('none', AlertDecision::decide(true, true, 10.0, 90.0, 90));
        $this->assertSame('none', AlertDecision::decide(true, true, null, null, 90)); // tidak ada data: tetap menyala
    }

    public function test_firing_alert_is_not_fired_again(): void
    {
        $this->assertSame('none', AlertDecision::decide(true, true, 99.0, 99.0, 90));
    }
}
