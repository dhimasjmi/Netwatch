<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\HostMatcher;
use PHPUnit\Framework\TestCase;

final class HostMatcherTest extends TestCase
{
    public function test_matches_exact_host_and_subdomains_only(): void
    {
        $domains = ['windowsupdate.com' => true];

        $this->assertTrue(HostMatcher::matches('windowsupdate.com', $domains));
        $this->assertTrue(HostMatcher::matches('Download.WindowsUpdate.com.', $domains));
        $this->assertFalse(HostMatcher::matches('notwindowsupdate.com', $domains)); // bukan subdomain
        $this->assertFalse(HostMatcher::matches('windowsupdate.com.evil.test', $domains));
        $this->assertFalse(HostMatcher::matches(null, $domains));
        $this->assertFalse(HostMatcher::matches('a.test', []));
    }

    public function test_normalize_domain(): void
    {
        $this->assertSame('contoh.com', HostMatcher::normalizeDomain(' *.Contoh.com. '));
        $this->assertSame('contoh.com', HostMatcher::normalizeDomain('https://contoh.com/halaman?x=1'));
        $this->assertNull(HostMatcher::normalizeDomain('bukan domain'));
        $this->assertNull(HostMatcher::normalizeDomain('localhost'));
        $this->assertNull(HostMatcher::normalizeDomain('%'));
        $this->assertNull(HostMatcher::normalizeDomain(''));
    }
}
