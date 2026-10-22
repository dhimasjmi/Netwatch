<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\PingProbe;
use PHPUnit\Framework\TestCase;

final class PingProbeTest extends TestCase
{
    public function test_builds_argument_list_without_shell(): void
    {
        $this->assertSame(['ping', '-n', '1', '-w', '1000', '192.168.1.10'], PingProbe::command('192.168.1.10', true));
        $this->assertSame(['ping', '-c', '1', '-W', '1', '192.168.1.10'], PingProbe::command('192.168.1.10', false));
    }

    public function test_rejects_non_ip_and_unusable_addresses(): void
    {
        foreach ([null, '', 'host.local', '1.1.1.1; rm -rf /', '-n 5', '127.0.0.1', '0.0.0.0', '224.0.0.1', '255.255.255.255', '::1', 'ff02::1'] as $value) {
            $this->assertNull(PingProbe::command($value, false), (string) $value);
        }
        $this->assertNotNull(PingProbe::command('2001:db8::5', false));
    }

    public function test_success_needs_reply_with_ttl(): void
    {
        $this->assertTrue(PingProbe::succeeded(0, 'Reply from 10.0.0.5: bytes=32 time=2ms TTL=128'));
        $this->assertFalse(PingProbe::succeeded(0, 'Reply from 10.0.0.1: Destination host unreachable.'));
        $this->assertFalse(PingProbe::succeeded(1, 'Request timed out.'));
    }

    public function test_reads_milliseconds(): void
    {
        $this->assertSame(2.0, PingProbe::milliseconds('time=2ms TTL=128'));
        $this->assertSame(0.5, PingProbe::milliseconds('time<1ms TTL=128'));
        $this->assertSame(0.43, PingProbe::milliseconds('64 bytes from x: icmp_seq=1 ttl=64 time=0.43 ms'));
        $this->assertNull(PingProbe::milliseconds('Request timed out.'));
    }
}
