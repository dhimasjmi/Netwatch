<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Sparkline;
use PHPUnit\Framework\TestCase;

final class SparklineTest extends TestCase
{
    public function test_returns_empty_string_when_not_enough_points(): void
    {
        $this->assertSame('', Sparkline::points([]));
        $this->assertSame('', Sparkline::points([50]));
    }

    public function test_maps_values_to_svg_coordinates(): void
    {
        $this->assertSame('0.0,48.0 120.0,0.0 240.0,24.0', Sparkline::points([0, 100, 50]));
    }

    public function test_clamps_out_of_range_values(): void
    {
        $this->assertSame('0.0,0.0 240.0,48.0', Sparkline::points([500, -20]));
    }
}
