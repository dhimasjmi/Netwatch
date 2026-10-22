<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\AlertMessage;
use PHPUnit\Framework\TestCase;

final class AlertMessageTest extends TestCase
{
    public function test_single_offline_includes_ping_diagnosis(): void
    {
        $text = AlertMessage::build([['kind' => 'offline', 'name' => 'PC-01', 'at' => '13 Okt 10:00', 'ping_ok' => false]]);

        $this->assertStringContainsString('PC-01 OFFLINE', $text);
        $this->assertStringContainsString('tidak merespons', $text);
    }

    public function test_ping_ok_points_to_agent_service(): void
    {
        $text = AlertMessage::pingText(['ping_ok' => true, 'ping_ms' => 1.234]);

        $this->assertStringContainsString('merespons (1.2 ms)', $text);
        $this->assertStringContainsString('agent', $text);
        $this->assertStringContainsString('belum diperiksa', AlertMessage::pingText([]));
    }

    public function test_many_offline_are_grouped_into_one_message(): void
    {
        $items = [];
        for ($i = 1; $i <= 20; $i++) {
            $items[] = ['kind' => 'offline', 'name' => "PC-{$i}", 'at' => 'x', 'ping_ok' => false];
        }

        $text = AlertMessage::build($items, 5);

        $this->assertStringContainsString('20 perangkat offline bersamaan', $text);
        $this->assertStringContainsString('dan 5 lainnya', $text);
        $this->assertStringNotContainsString('PC-20 OFFLINE', $text);
    }

    public function test_online_shows_downtime(): void
    {
        $text = AlertMessage::build([['kind' => 'online', 'name' => 'PC-01', 'at' => '10:05', 'downtime' => '5 menit']]);

        $this->assertStringContainsString('kembali ONLINE', $text);
        $this->assertStringContainsString('offline 5 menit', $text);
    }
}
