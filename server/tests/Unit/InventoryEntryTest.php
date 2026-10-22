<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\InventoryEntry;
use App\Support\PortCatalog;
use PHPUnit\Framework\TestCase;

final class InventoryEntryTest extends TestCase
{
    public function test_valid_port_is_normalized_and_exposure_detected(): void
    {
        $open = InventoryEntry::port(['protocol' => 'TCP', 'address' => '0.0.0.0', 'port' => '3389', 'process' => 'C:\\Windows\\svchost.exe']);
        $local = InventoryEntry::port(['protocol' => 'tcp', 'address' => '127.0.0.1', 'port' => 5432, 'process' => 'postgres']);
        $v6 = InventoryEntry::port(['protocol' => 'tcp', 'address' => '[::1]', 'port' => 8000]);

        $this->assertSame(['protocol' => 'tcp', 'address' => '0.0.0.0', 'port' => 3389, 'process' => 'svchost.exe', 'exposed' => true], $open);
        $this->assertFalse($local['exposed']);
        $this->assertFalse($v6['exposed']);
    }

    public function test_invalid_ports_are_rejected(): void
    {
        foreach ([
            ['protocol' => 'icmp', 'address' => '0.0.0.0', 'port' => 80],
            ['protocol' => 'tcp', 'address' => 'bukan-ip', 'port' => 80],
            ['protocol' => 'tcp', 'address' => '0.0.0.0', 'port' => 0],
            ['protocol' => 'tcp', 'address' => '0.0.0.0', 'port' => 70000],
            ['protocol' => 'udp', 'address' => '0.0.0.0', 'port' => 55000], // port UDP sementara
            [],
        ] as $raw) {
            $this->assertNull(InventoryEntry::port($raw), json_encode($raw));
        }
    }

    public function test_service_validation_and_control_characters(): void
    {
        $row = InventoryEntry::service(['name' => "Spooler\x00", 'display' => 'Print Spooler', 'state' => 'Running', 'start' => 'Auto']);

        $this->assertSame(['name' => 'Spooler', 'display_name' => 'Print Spooler', 'state' => 'Running', 'start_mode' => 'Auto'], $row);
        $this->assertNull(InventoryEntry::service(['name' => '', 'state' => 'Running']));
        $this->assertNull(InventoryEntry::service(['name' => 'X']));
    }

    public function test_port_catalog_levels_depend_on_exposure(): void
    {
        $this->assertSame(PortCatalog::ATTENTION, PortCatalog::level(3389, true));
        $this->assertSame(PortCatalog::NORMAL, PortCatalog::level(3389, false));
        $this->assertSame(PortCatalog::HIGH, PortCatalog::level(23, true));
        $this->assertSame(PortCatalog::NORMAL, PortCatalog::level(8080, true));
        $this->assertSame('HTTPS', PortCatalog::name(443));
    }
}
