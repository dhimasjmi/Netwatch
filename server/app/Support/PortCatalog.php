<?php

declare(strict_types=1);

namespace App\Support;

/** Nama layanan umum dan tingkat perhatian untuk port yang terbuka ke jaringan. */
final class PortCatalog
{
    public const NORMAL = 'normal';
    public const ATTENTION = 'attention';
    public const HIGH = 'high';

    /** @var array<int, array{0: string, 1: string, 2: string|null}> port => [nama, tingkat, saran] */
    private const PORTS = [
        21 => ['FTP', self::HIGH, 'FTP mengirim kata sandi tanpa enkripsi. Matikan bila tidak dipakai, atau ganti dengan SFTP.'],
        22 => ['SSH', self::NORMAL, null],
        23 => ['Telnet', self::HIGH, 'Telnet tanpa enkripsi dan jarang dibutuhkan di PC kantor. Matikan layanan Telnet.'],
        53 => ['DNS', self::NORMAL, null],
        80 => ['HTTP', self::NORMAL, null],
        135 => ['RPC', self::NORMAL, null],
        139 => ['NetBIOS', self::NORMAL, null],
        443 => ['HTTPS', self::NORMAL, null],
        445 => ['SMB (berbagi file)', self::NORMAL, null],
        1433 => ['SQL Server', self::ATTENTION, 'Database terbuka ke jaringan. Pastikan memang disengaja dan dibatasi firewall.'],
        3306 => ['MySQL', self::ATTENTION, 'Database terbuka ke jaringan. Pastikan memang disengaja dan dibatasi firewall.'],
        3389 => ['Remote Desktop (RDP)', self::ATTENTION, 'RDP sering menjadi target serangan. Bila tidak dipakai, matikan Remote Desktop atau batasi lewat firewall.'],
        5432 => ['PostgreSQL', self::ATTENTION, 'Database terbuka ke jaringan. Pastikan memang disengaja dan dibatasi firewall.'],
        5900 => ['VNC', self::HIGH, 'VNC kerap tanpa enkripsi/kata sandi kuat. Matikan bila tidak dipakai.'],
        5985 => ['WinRM (HTTP)', self::ATTENTION, 'Administrasi jarak jauh Windows. Pastikan memang dipakai tim IT.'],
        5986 => ['WinRM (HTTPS)', self::ATTENTION, 'Administrasi jarak jauh Windows. Pastikan memang dipakai tim IT.'],
        6379 => ['Redis', self::ATTENTION, 'Redis biasanya tanpa autentikasi. Jangan buka ke jaringan.'],
        27017 => ['MongoDB', self::ATTENTION, 'Database terbuka ke jaringan. Pastikan memang disengaja dan dibatasi firewall.'],
    ];

    public static function name(int $port): ?string
    {
        return self::PORTS[$port][0] ?? null;
    }

    public static function level(int $port, bool $exposed): string
    {
        return $exposed ? (self::PORTS[$port][1] ?? self::NORMAL) : self::NORMAL;
    }

    public static function hint(int $port): ?string
    {
        return self::PORTS[$port][2] ?? null;
    }
}
