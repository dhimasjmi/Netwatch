<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\BlockTarget;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BlockTargetTest extends TestCase
{
    /** @return array<string, array{string, string}> */
    public static function validDomains(): array
    {
        return [
            'huruf besar' => ['Facebook.com', 'facebook.com'],
            'url lengkap' => ['https://WWW.Facebook.com/path?x=1', 'www.facebook.com'],
            'wildcard' => ['*.tiktok.com', 'tiktok.com'],
            'dengan port' => ['http://a.b.co.id:8080/x', 'a.b.co.id'],
            'titik di akhir' => ['x.com.', 'x.com'],
        ];
    }

    #[DataProvider('validDomains')]
    public function test_domain_is_normalized(string $input, string $expected): void
    {
        $this->assertSame($expected, BlockTarget::normalizeDomain($input));
    }

    /** @return array<string, array{string}> */
    public static function invalidDomains(): array
    {
        return ['tanpa titik' => ['com'], 'localhost' => ['localhost'], 'spasi' => ['exa mple.com'], 'garis bawah' => ['a_b.com'],
            'diawali strip' => ['-a.com'], 'sisipan perintah' => ['a.com"; calc'], 'ip' => ['1.2.3.4'], 'kosong' => ['']];
    }

    #[DataProvider('invalidDomains')]
    public function test_invalid_domain_is_rejected(string $input): void
    {
        $this->expectException(InvalidArgumentException::class);

        BlockTarget::normalizeDomain($input);
    }

    /** @return array<string, array{string, string}> */
    public static function validIps(): array
    {
        return [
            'ipv4' => ['203.0.113.10', '203.0.113.10'],
            'cidr dikosongkan bit host' => ['203.0.113.77/24', '203.0.113.0/24'],
            'slash 32 dibuang' => ['192.168.1.5/32', '192.168.1.5'],
            'ipv6' => ['2001:db8::1', '2001:db8::1'],
            'ipv6 cidr' => ['2001:db8:abcd::5/32', '2001:db8::/32'],
        ];
    }

    #[DataProvider('validIps')]
    public function test_ip_is_normalized(string $input, string $expected): void
    {
        $this->assertSame($expected, BlockTarget::normalizeIp($input));
    }

    /** @return array<string, array{string}> */
    public static function invalidIps(): array
    {
        return ['terlalu luas' => ['10.0.0.0/7'], 'seluruh internet' => ['0.0.0.0/0'], 'loopback' => ['127.0.0.1'], 'loopback v6' => ['::1'],
            'v6 terlalu luas' => ['2001:db8::/8'], 'oktet salah' => ['999.1.1.1'], 'prefix lewat batas' => ['1.2.3.4/33'],
            'prefix bukan angka' => ['1.2.3.4/x'], 'ipv4-mapped' => ['::ffff:1.2.3.4'], 'sisipan' => ['1.2.3.4; calc']];
    }

    #[DataProvider('invalidIps')]
    public function test_invalid_ip_is_rejected(string $input): void
    {
        $this->expectException(InvalidArgumentException::class);

        BlockTarget::normalizeIp($input);
    }

    public function test_covers(): void
    {
        $this->assertTrue(BlockTarget::covers('192.168.1.0/24', '192.168.1.50'));
        $this->assertFalse(BlockTarget::covers('192.168.1.0/24', '192.168.2.1'));
        $this->assertTrue(BlockTarget::covers('10.0.0.5', '10.0.0.5'));
        $this->assertTrue(BlockTarget::covers('172.16.0.0/12', '172.31.255.255'));
        $this->assertFalse(BlockTarget::covers('172.16.0.0/12', '172.32.0.1'));
        $this->assertTrue(BlockTarget::covers('2001:db8::/32', '2001:db8:1::1'));
        $this->assertFalse(BlockTarget::covers('10.0.0.0/8', '2001:db8::1'));
    }

    public function test_domain_covers_host(): void
    {
        $this->assertTrue(BlockTarget::domainCoversHost('perusahaan.local', 'netwatch.perusahaan.local'));
        $this->assertTrue(BlockTarget::domainCoversHost('perusahaan.local', 'perusahaan.local'));
        $this->assertFalse(BlockTarget::domainCoversHost('x.com', 'notx.com'));
    }
}
