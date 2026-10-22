<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\BlockRuleType;
use App\Support\PolicyEntries;
use PHPUnit\Framework\TestCase;

final class PolicyEntriesTest extends TestCase
{
    public function test_splits_lines_commas_and_spaces_and_removes_duplicates(): void
    {
        $this->assertSame(
            ['facebook.com', 'instagram.com', '10.0.0.0/8', 'x.com'],
            PolicyEntries::parse("facebook.com\r\n instagram.com, 10.0.0.0/8;FACEBOOK.com  x.com\n\n"),
        );
        $this->assertSame([], PolicyEntries::parse("  \n "));
    }

    public function test_limits_entries_per_submit(): void
    {
        $text = implode("\n", array_map(fn (int $i): string => "d{$i}.com", range(1, 500)));

        $this->assertCount(PolicyEntries::MAX_PER_SUBMIT, PolicyEntries::parse($text));
    }

    public function test_detects_ip_cidr_and_domain(): void
    {
        $this->assertSame(BlockRuleType::Ip, PolicyEntries::typeOf('203.0.113.7'));
        $this->assertSame(BlockRuleType::Ip, PolicyEntries::typeOf('203.0.113.0/24'));
        $this->assertSame(BlockRuleType::Ip, PolicyEntries::typeOf('2001:db8::1'));
        $this->assertSame(BlockRuleType::Domain, PolicyEntries::typeOf('facebook.com'));
        $this->assertSame(BlockRuleType::Domain, PolicyEntries::typeOf('*.facebook.com'));
        $this->assertSame(BlockRuleType::Domain, PolicyEntries::typeOf('https://example.com/a'));
    }
}
