<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\TrendChart;
use PHPUnit\Framework\TestCase;

final class TrendChartTest extends TestCase
{
    public function test_maps_values_to_svg_coordinates(): void
    {
        $segments = TrendChart::segments([0 => 0.0, 100 => 100.0], 0, 100, 200, 100, 1000);

        $this->assertSame(['0,100 200,0'], $segments);
    }

    public function test_gap_splits_the_line(): void
    {
        $segments = TrendChart::segments([0 => 10.0, 30 => 10.0, 500 => 20.0, 530 => 20.0], 0, 600, 600, 100, 120);

        $this->assertCount(2, $segments);
    }

    public function test_single_point_becomes_a_visible_dot_and_out_of_range_is_dropped(): void
    {
        $segments = TrendChart::segments([-50 => 5.0, 50 => 50.0, 999 => 5.0], 0, 100, 100, 100, 1000);

        $this->assertSame(['50,50 50,50'], $segments);
    }

    public function test_values_are_clamped_to_0_100(): void
    {
        $this->assertSame(['0,0 10,100'], TrendChart::segments([0 => 150.0, 10 => -5.0], 0, 10, 10, 100, 100));
    }

    public function test_summary(): void
    {
        $this->assertNull(TrendChart::summary([]));
        $this->assertSame(['min' => 10.0, 'avg' => 20.0, 'max' => 30.0, 'last' => 30.0], TrendChart::summary([1 => 10.0, 2 => 20.0, 3 => 30.0]));
    }
}
