<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\LatencyBars;
use PHPUnit\Framework\TestCase;

final class LatencyBarsTest extends TestCase
{
    public function test_bars_are_colored_and_sized_by_result(): void
    {
        $bars = LatencyBars::build([
            ['ok' => true, 'latency' => 15.0],
            ['ok' => true, 'latency' => 200.0],
            ['ok' => false, 'latency' => null],
        ], 150.0);

        $this->assertSame(['bar-ok', 'bar-slow', 'bar-fail'], array_column($bars, 'class'));
        $this->assertSame(LatencyBars::HEIGHT, $bars[2]['height']); // gagal = batang penuh
        $this->assertLessThan($bars[1]['height'], $bars[0]['height']);
        $this->assertSame(0, $bars[0]['x']);
        $this->assertSame(LatencyBars::BAR_WIDTH + LatencyBars::GAP, $bars[1]['x']);
        $this->assertSame($bars[0]['y'] + $bars[0]['height'], LatencyBars::HEIGHT); // alas sejajar
    }

    public function test_tiny_and_huge_latency_stay_within_bounds(): void
    {
        $bars = LatencyBars::build([['ok' => true, 'latency' => 0.0], ['ok' => true, 'latency' => 99999.0]], 150.0);

        $this->assertSame(3, $bars[0]['height']); // tetap terlihat
        $this->assertSame(LatencyBars::HEIGHT, $bars[1]['height']);
    }

    public function test_empty_and_width(): void
    {
        $this->assertSame([], LatencyBars::build([], 150.0));
        $this->assertSame(0, LatencyBars::width(0));
        $this->assertSame(4, LatencyBars::width(1));
        $this->assertSame(29, LatencyBars::width(6));
    }
}
