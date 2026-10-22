<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\EventCategory;
use App\Enums\EventSeverity;

/** Menentukan kategori, tingkat, dan saran untuk event Windows Event Log. Murni agar mudah diuji. */
final class WinlogClassifier
{
    /** @var list<array{0: list<string>, 1: EventCategory}> potongan nama provider (huruf kecil) => kategori; yang pertama cocok dipakai */
    private const CATEGORIES = [
        [['dns-client', 'dnscache'], EventCategory::Dns],
        [['dhcp'], EventCategory::Addressing],
        [['tcpip', 'networkprofile', 'wlan-autoconfig', 'wired-autoconfig', 'ndis', 'netwtw', 'netbt', 'netlogon', 'e1d', 'rtl8', 'nlasvc'], EventCategory::Connectivity],
        [['disk', 'ntfs', 'volmgr', 'storahci', 'stornvme', 'whea-logger', 'kernel-power', 'bugcheck', 'service control manager', 'eventlog', 'wininit', 'kernel-general'], EventCategory::Availability],
        [['resource-exhaustion', 'application hang', 'application error', '.net runtime', 'windows error reporting'], EventCategory::Performance],
    ];

    /** @var array<string, string> "provider|id" (provider huruf kecil) => saran */
    private const HINTS = [
        'microsoft-windows-kernel-power|41' => 'PC mati/restart mendadak tanpa shutdown normal: periksa catu daya/listrik, suhu (overheat), dan driver. Bila berulang, curigai PSU atau RAM.',
        'eventlog|6008' => 'Shutdown sebelumnya tidak normal (listrik putus atau PC dimatikan paksa).',
        'disk|7' => 'Blok rusak (bad block) pada disk. Segera backup data dan periksa kesehatan disk (SMART); disk mungkin akan segera gagal.',
        'disk|11' => 'Kontroler disk gagal merespons: periksa kabel SATA/daya dan kesehatan disk.',
        'disk|51' => 'Galat saat membaca/menulis disk: periksa kesehatan disk (SMART) dan kabelnya.',
        'disk|153' => 'Operasi I/O disk diulang: tanda awal disk atau kabel bermasalah.',
        'ntfs|55' => 'Sistem file rusak. Jalankan "chkdsk /f" dan periksa kesehatan disk.',
        'ntfs|98' => 'Volume perlu diperbaiki: jalankan "chkdsk /f".',
        'ntfs|137' => 'Galat pada log transaksi NTFS; periksa kesehatan disk.',
        'microsoft-windows-whea-logger|17' => 'Galat hardware yang dapat dikoreksi (WHEA): pantau; bila sering muncul, periksa suhu, RAM, dan driver.',
        'microsoft-windows-whea-logger|18' => 'Galat hardware fatal (WHEA): periksa RAM, CPU, suhu, dan overclock.',
        'microsoft-windows-whea-logger|19' => 'Galat hardware yang dapat dikoreksi (WHEA): pantau; bila sering muncul, periksa RAM dan suhu.',
        'microsoft-windows-whea-logger|47' => 'Galat hardware pada PCI Express (WHEA): periksa kartu/driver dan slot PCIe.',
        'tcpip|4199' => 'Alamat IP bentrok dengan perangkat lain di jaringan. Cari perangkat berIP sama, atau gunakan DHCP/reservasi yang benar.',
        'tcpip|4227' => 'Koneksi TCP gagal karena kehabisan port sementara; ada aplikasi yang membuka terlalu banyak koneksi.',
        'microsoft-windows-dhcp-client|1002' => 'Alamat IP dari DHCP tidak bisa diperbarui (lease habis). Periksa koneksi ke server DHCP dan kabel/WiFi.',
        'microsoft-windows-dhcp-client|1001' => 'Gagal memperbarui alamat IP dari DHCP: periksa server DHCP dan jaringan.',
        'microsoft-windows-dhcp-client|1003' => 'Gagal memperbarui alamat IP dari DHCP: periksa server DHCP dan jaringan.',
        'microsoft-windows-dns-client|1014' => 'Resolusi nama DNS time-out: periksa alamat server DNS di PC, koneksi ke DNS, dan apakah DNS kantor aktif.',
        'microsoft-windows-networkprofile|10000' => 'Jaringan tersambung. Bila sering muncul/hilang, periksa kabel atau sinyal WiFi.',
        'microsoft-windows-networkprofile|10001' => 'Jaringan terputus. Periksa kabel/port switch atau sinyal WiFi.',
        'microsoft-windows-wlan-autoconfig|8002' => 'Koneksi WiFi gagal. Periksa sandi, sinyal, dan pengaturan AP.',
        'microsoft-windows-wlan-autoconfig|8003' => 'WiFi terputus. Periksa sinyal dan AP.',
        'service control manager|7000' => 'Sebuah layanan gagal dimulai: lihat nama layanan di pesan lalu periksa dependensi dan akun layanannya.',
        'service control manager|7001' => 'Layanan gagal dimulai karena dependensinya gagal.',
        'service control manager|7009' => 'Layanan time-out saat dimulai. Biasanya sistem lambat (disk/CPU) atau layanan bermasalah.',
        'service control manager|7011' => 'Layanan tidak merespons tepat waktu. Periksa beban sistem.',
        'service control manager|7031' => 'Layanan berhenti tak terduga (crash). Periksa log aplikasinya, dan perbarui/instal ulang bila berulang.',
        'service control manager|7034' => 'Layanan berhenti tak terduga (crash). Periksa log aplikasinya, dan perbarui/instal ulang bila berulang.',
        'application error|1000' => 'Aplikasi crash. Perbarui atau instal ulang aplikasi yang disebut; bila terjadi pada banyak aplikasi, curigai RAM atau disk.',
        'application hang|1002' => 'Aplikasi tidak merespons (hang). Periksa beban CPU/RAM dan versi aplikasinya.',
        'microsoft-windows-resource-exhaustion-detector|2004' => 'Memori virtual hampir habis. Tutup aplikasi berat atau tambah RAM.',
        'microsoft-windows-kernel-general|6008' => 'Shutdown sebelumnya tidak normal.',
        'bugcheck|1001' => 'PC mengalami layar biru (BSOD). Catat kode galatnya di pesan, perbarui driver, dan periksa RAM/disk.',
    ];

    public static function category(string $provider): EventCategory
    {
        $name = strtolower($provider);

        foreach (self::CATEGORIES as [$needles, $category]) {
            foreach ($needles as $needle) {
                if (str_contains($name, $needle)) {
                    return $category;
                }
            }
        }

        return EventCategory::System;
    }

    /** Level Windows: 1 = Critical, 2 = Error, 3 = Warning. */
    public static function severity(int $level): EventSeverity
    {
        return match ($level) {
            1 => EventSeverity::Critical,
            2 => EventSeverity::Warning,
            default => EventSeverity::Info,
        };
    }

    public static function hint(string $provider, int $eventId): ?string
    {
        return self::HINTS[strtolower($provider).'|'.$eventId] ?? null;
    }
}
