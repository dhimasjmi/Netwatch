<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\BrowsingEntry;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

final class BrowsingEntryTest extends TestCase
{
    private CarbonImmutable $now;

    protected function setUp(): void
    {
        $this->now = CarbonImmutable::parse('2026-10-13 06:00:00', 'UTC');
    }

    public function test_url_keeps_only_host_by_default_and_drops_query(): void
    {
        $row = BrowsingEntry::normalize(
            ['kind' => 'url', 'at' => '2026-10-13T05:59:00Z', 'url' => 'https://User:Pw@WWW.Example.com/cari?q=rahasia#x', 'browser' => 'chrome'],
            'domain', $this->now,
        );

        $this->assertSame('www.example.com', $row['host']);
        $this->assertNull($row['url']);
        $this->assertSame('chrome', $row['browser']);
    }

    public function test_path_detail_keeps_path_but_never_query_or_credentials(): void
    {
        $row = BrowsingEntry::normalize(
            ['kind' => 'url', 'at' => '2026-10-13T05:59:00Z', 'url' => 'https://u:p@example.com/a/b?token=abc#frag'],
            'path', $this->now,
        );

        $this->assertSame('example.com/a/b', $row['url']);
        $this->assertStringNotContainsString('token', json_encode($row));
        $this->assertStringNotContainsString('u:p', json_encode($row));
    }

    public function test_rejects_bad_scheme_host_time_and_kind(): void
    {
        $ok = ['kind' => 'url', 'at' => '2026-10-13T05:59:00Z', 'url' => 'https://example.com/'];

        $this->assertNotNull(BrowsingEntry::normalize($ok, 'domain', $this->now));
        $this->assertNull(BrowsingEntry::normalize([...$ok, 'url' => 'file:///C:/rahasia.txt'], 'domain', $this->now));
        $this->assertNull(BrowsingEntry::normalize([...$ok, 'url' => 'javascript:alert(1)'], 'domain', $this->now));
        $this->assertNull(BrowsingEntry::normalize([...$ok, 'url' => 'https://bad host/'], 'domain', $this->now));
        $this->assertNull(BrowsingEntry::normalize([...$ok, 'kind' => 'other'], 'domain', $this->now));
        $this->assertNull(BrowsingEntry::normalize([...$ok, 'at' => '2026-10-13T07:00:00Z'], 'domain', $this->now)); // masa depan
        $this->assertNull(BrowsingEntry::normalize([...$ok, 'at' => '2026-09-01T00:00:00Z'], 'domain', $this->now)); // terlalu lama
        $this->assertNull(BrowsingEntry::normalize([...$ok, 'at' => 'bukan waktu'], 'domain', $this->now));
    }

    public function test_connection_requires_valid_ip_and_sanitizes_process(): void
    {
        $base = ['kind' => 'connection', 'at' => '2026-10-13T05:59:00Z', 'ip' => '93.184.216.34', 'port' => 443, 'host' => 'Example.com.', 'process' => 'C:\\Program Files\\Google\\chrome.exe'];

        $row = BrowsingEntry::normalize($base, 'domain', $this->now);
        $this->assertSame('example.com', $row['host']);
        $this->assertSame('chrome.exe', $row['process']);
        $this->assertSame(443, $row['port']);

        $this->assertNull(BrowsingEntry::normalize([...$base, 'ip' => 'bukan-ip'], 'domain', $this->now));
        $this->assertNull(BrowsingEntry::normalize([...$base, 'ip' => null], 'domain', $this->now));
        $this->assertNull(BrowsingEntry::normalize([...$base, 'port' => 70000], 'domain', $this->now)['port']);
        $this->assertNull(BrowsingEntry::normalize([...$base, 'host' => '<script>'], 'domain', $this->now)['host']);
    }

    public function test_same_minute_same_target_has_same_dedupe_hash(): void
    {
        $a = BrowsingEntry::normalize(['kind' => 'url', 'at' => '2026-10-13T05:59:05Z', 'url' => 'https://example.com/'], 'domain', $this->now);
        $b = BrowsingEntry::normalize(['kind' => 'url', 'at' => '2026-10-13T05:59:50Z', 'url' => 'https://example.com/x'], 'domain', $this->now);
        $c = BrowsingEntry::normalize(['kind' => 'url', 'at' => '2026-10-13T05:58:50Z', 'url' => 'https://example.com/'], 'domain', $this->now);

        $this->assertSame($a['dedupe_hash'], $b['dedupe_hash']);
        $this->assertNotSame($a['dedupe_hash'], $c['dedupe_hash']);
    }
}
