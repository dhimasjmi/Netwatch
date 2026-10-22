<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\AgentVersion;
use PHPUnit\Framework\TestCase;

final class AgentVersionTest extends TestCase
{
    public function test_version_comparison(): void
    {
        $this->assertTrue(AgentVersion::isOutdated('1.0.0', '1.1.0'));
        $this->assertTrue(AgentVersion::isOutdated('1.0.9', '1.1.0'));
        $this->assertFalse(AgentVersion::isOutdated('1.1.0', '1.1.0'));
        $this->assertFalse(AgentVersion::isOutdated('1.10.0', '1.9.0'));
        $this->assertFalse(AgentVersion::isOutdated('2.0.0', '1.1.0'));
    }

    public function test_missing_or_malformed_version_is_outdated(): void
    {
        $this->assertTrue(AgentVersion::isOutdated(null, '1.1.0'));
        $this->assertTrue(AgentVersion::isOutdated('', '1.1.0'));
        $this->assertTrue(AgentVersion::isOutdated('abc', '1.1.0'));
    }
}
