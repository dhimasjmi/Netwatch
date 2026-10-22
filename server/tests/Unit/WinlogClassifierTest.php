<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\EventCategory;
use App\Enums\EventSeverity;
use App\Support\WinlogClassifier;
use PHPUnit\Framework\TestCase;

final class WinlogClassifierTest extends TestCase
{
    public function test_network_providers_map_to_network_categories(): void
    {
        $this->assertSame(EventCategory::Dns, WinlogClassifier::category('Microsoft-Windows-DNS-Client'));
        $this->assertSame(EventCategory::Addressing, WinlogClassifier::category('Microsoft-Windows-Dhcp-Client'));
        $this->assertSame(EventCategory::Connectivity, WinlogClassifier::category('Tcpip'));
        $this->assertSame(EventCategory::Connectivity, WinlogClassifier::category('Microsoft-Windows-NetworkProfile'));
    }

    public function test_hardware_and_os_providers(): void
    {
        $this->assertSame(EventCategory::Availability, WinlogClassifier::category('Microsoft-Windows-Kernel-Power'));
        $this->assertSame(EventCategory::Availability, WinlogClassifier::category('disk'));
        $this->assertSame(EventCategory::Availability, WinlogClassifier::category('Service Control Manager'));
        $this->assertSame(EventCategory::Performance, WinlogClassifier::category('Application Error'));
        $this->assertSame(EventCategory::System, WinlogClassifier::category('Sesuatu-Yang-Lain'));
    }

    public function test_severity_follows_windows_level(): void
    {
        $this->assertSame(EventSeverity::Critical, WinlogClassifier::severity(1));
        $this->assertSame(EventSeverity::Warning, WinlogClassifier::severity(2));
        $this->assertSame(EventSeverity::Info, WinlogClassifier::severity(3));
    }

    public function test_hints_are_found_case_insensitively(): void
    {
        $this->assertStringContainsString('mati/restart mendadak', (string) WinlogClassifier::hint('Microsoft-Windows-Kernel-Power', 41));
        $this->assertStringContainsString('Alamat IP bentrok', (string) WinlogClassifier::hint('Tcpip', 4199));
        $this->assertNull(WinlogClassifier::hint('Tcpip', 1));
    }
}
