<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\DevicePolicy;
use PHPUnit\Framework\TestCase;

final class DevicePolicyTest extends TestCase
{
    public function test_hash_ignores_order_and_duplicates(): void
    {
        $a = DevicePolicy::fromValues(['b.com', 'a.com', 'a.com'], ['10.0.0.1']);
        $b = DevicePolicy::fromValues(['a.com', 'b.com'], ['10.0.0.1']);

        $this->assertSame($a->hash(), $b->hash());
        $this->assertSame(['a.com', 'b.com'], $a->domains);
    }

    public function test_hash_changes_when_content_changes(): void
    {
        $this->assertNotSame(
            DevicePolicy::fromValues(['a.com'], [])->hash(),
            DevicePolicy::fromValues(['a.com'], ['10.0.0.1'])->hash(),
        );
        $this->assertNotSame(
            DevicePolicy::fromValues(['a.com'], [])->hash(),
            DevicePolicy::fromValues([], ['a.com'])->hash(),
        );
    }

    public function test_empty_policy_and_payload(): void
    {
        $empty = DevicePolicy::fromValues([], []);

        $this->assertTrue($empty->isEmpty());
        $this->assertSame(64, strlen($empty->hash()));
        $this->assertSame(['hash' => $empty->hash(), 'domains' => [], 'ips' => []], $empty->toPayload());
    }
}
