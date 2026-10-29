<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\EventCategory;
use App\Enums\EventSeverity;
use InvalidArgumentException;

/**
 * Daftar jenis event: kategori, tingkat keparahan bawaan, dan petunjuk troubleshooting.
 * Menambah jenis event baru cukup dengan satu entri di sini.
 */
final class EventCatalog
{
    /** @return array<string, array{0: EventCategory, 1: EventSeverity, 2: string|null}> */
    private static function definitions(): array
    {
        return [
            'device.online' => [EventCategory::Availability, EventSeverity::Info, null],
            'device.offline' => [EventCategory::Availability, EventSeverity::Warning,
                'Periksa apakah PC menyala dan tidak dalam mode sleep, kabel/WiFi tersambung, dan layanan "NetWatch Agent" berjalan. '
                .'Server hanya melihat heartbeat berhenti, jadi tidak bisa membedakan keempat kemungkinan itu. '
                .'Bila banyak perangkat offline bersamaan, curigai switch/AP atau server NetWatch itu sendiri.'],
            'device.maintenance_started' => [EventCategory::Availability, EventSeverity::Info, null],
            'device.maintenance_ended' => [EventCategory::Availability, EventSeverity::Info, null],
            'device.enrolled' => [EventCategory::Enrollment, EventSeverity::Info, null],
            'device.revoked' => [EventCategory::Enrollment, EventSeverity::Warning,
                'Akses perangkat dicabut. Agent di PC tersebut tidak bisa lagi mengirim data sampai didaftarkan ulang dengan token baru.'],
            'device.enroll_rejected' => [EventCategory::Security, EventSeverity::Warning,
                'Token pendaftaran salah, kedaluwarsa, sudah habis dipakai, atau dicabut. Bila terjadi berulang dari IP yang tidak dikenal, '
                .'anggap sebagai percobaan tidak sah dan cabut token yang masih aktif.'],
            'command.issued' => [EventCategory::Command, EventSeverity::Info, null],
            'command.failed' => [EventCategory::Command, EventSeverity::Warning,
                'Lihat pesan galat. Penyebab umum: proses sudah berhenti sebelum perintah sampai, PID berubah sehingga ditolak demi keamanan, '
                .'atau agent tidak punya hak untuk tindakan itu.'],
            'command.expired' => [EventCategory::Command, EventSeverity::Warning,
                'Perintah tidak diambil agent tepat waktu, biasanya karena perangkat offline. Ulangi setelah perangkat kembali online.'],
            'auth.login_failed' => [EventCategory::Security, EventSeverity::Warning,
                'Beberapa kegagalan beruntun dari IP yang sama menandakan tebakan kata sandi. Pertimbangkan menonaktifkan akun tersebut '
                .'atau memblokir IP di firewall. Satu kegagalan biasanya hanya salah ketik.'],
            'target.offline' => [EventCategory::Connectivity, EventSeverity::Warning,
                'Perangkat jaringan tidak membalas ping/cek port dari server. Periksa daya dan kabel perangkat, lalu port switch di sisinya. '
                .'Bila banyak target mati bersamaan, curigai switch/router di jalur menuju server NetWatch atau server NetWatch itu sendiri. '
                .'Perangkat yang memblokir ICMP perlu dicek lewat port TCP, bukan ping.'],
            'target.online' => [EventCategory::Connectivity, EventSeverity::Info, null],
            'target.slow' => [EventCategory::Connectivity, EventSeverity::Warning,
                'Perangkat jaringan masih merespons tetapi lebih lambat dari batas (NETWATCH_MONITOR_WARN_MS). Penyebab umum: link jenuh atau loop, '
                .'kabel/port bermasalah (cek error di switch), perangkat kelebihan beban, atau jalur ke server NetWatch padat. '
                .'Bila banyak target lambat bersamaan, curigai uplink atau server NetWatch itu sendiri.'],
            'target.normal' => [EventCategory::Connectivity, EventSeverity::Info, null],
            'backup.failed' => [EventCategory::System, EventSeverity::Critical,
                'Backup database terjadwal gagal. Lihat pesan galat: biasanya ruang disk kurang, folder backup tidak bisa ditulis, '
                .'path pg_dump salah (NETWATCH_PG_DUMP), atau kata sandi database berubah. Selama belum diperbaiki, backup terakhir yang berhasil adalah titik pemulihan Anda.'],
            'policy.applied' => [EventCategory::Policy, EventSeverity::Info, null],
            'alert.firing' => [EventCategory::Performance, EventSeverity::Warning,
                'Pemakaian sumber daya melewati ambang cukup lama. Buka Task Manager lewat perintah daftar proses di halaman perangkat untuk melihat '
                .'proses yang boros. CPU tinggi terus-menerus biasanya proses macet/malware, RAM penuh butuh penutupan aplikasi atau tambah RAM, '
                .'disk hampir penuh butuh pembersihan berkas atau penambahan kapasitas.'],
            'alert.resolved' => [EventCategory::Performance, EventSeverity::Info, null],
            'policy.offline_release' => [EventCategory::Policy, EventSeverity::Info,
                'Perangkat tidak dapat menghubungi server cukup lama (di luar jaringan kantor), sehingga agent melepas blokir. '
                .'Blokir dipasang kembali otomatis begitu perangkat terhubung ke server lagi.'],
            'policy.drift' => [EventCategory::Policy, EventSeverity::Warning,
                'Aturan NetWatch di komputer tidak lagi sesuai kebijakan (dihapus/diubah manual, atau data agent hilang karena reinstal). '
                .'Server mengirim ulang kebijakan dan agent menerapkannya kembali; bila berulang, periksa siapa yang mengubah file hosts atau registry.'],
            'policy.failed' => [EventCategory::Policy, EventSeverity::Warning,
                'Agent gagal menerapkan kebijakan blokir. Periksa pesan galat: biasanya agent tidak berjalan sebagai SYSTEM, '
                .'file hosts dikunci antivirus, atau Windows Firewall dikelola penuh oleh GPO. Agent mencoba lagi tiap 5 menit.'],
            'device.port_opened' => [EventCategory::Security, EventSeverity::Info,
                'Port baru terbuka ke jaringan. Bila tidak dikenal, periksa proses yang membukanya di halaman "Port & layanan" perangkat, '
                .'dan pastikan itu aplikasi yang sah. Port yang sama tidak diumumkan lagi dalam 24 jam.'],
            'software.installed' => [EventCategory::System, EventSeverity::Info,
                'Program baru terdeteksi di perangkat. Bila tidak dikenal, periksa halaman Inventaris perangkat tersebut.'],
            'software.removed' => [EventCategory::System, EventSeverity::Info, null],
            'hardware.changed' => [EventCategory::System, EventSeverity::Warning,
                'CPU, RAM, disk, atau model perangkat berbeda dari laporan sebelumnya: komponen diganti/ditambah, atau perangkat tertukar. Bandingkan dengan nomor seri di Inventaris.'],
            'ticket.created' => [EventCategory::System, EventSeverity::Info, null],
            'ticket.resolved' => [EventCategory::System, EventSeverity::Info, null],
            'ticket.feedback' => [EventCategory::System, EventSeverity::Info, null],
            // Event dari Windows Event Log; kategori aktual ditentukan WinlogClassifier saat dicatat.
            'winlog.event' => [EventCategory::System, EventSeverity::Warning, null],
            'user.created' => [EventCategory::Security, EventSeverity::Info, null],
            'user.deactivated' => [EventCategory::Security, EventSeverity::Info, null],
            'user.password_reset' => [EventCategory::Security, EventSeverity::Info, null],
        ];
    }

    /**
     * @return array{category: EventCategory, severity: EventSeverity, hint: string|null}
     *
     * @throws InvalidArgumentException bila kode tidak terdaftar (kesalahan pemrograman, bukan input pengguna)
     */
    public static function definition(string $code): array
    {
        $definition = self::definitions()[$code] ?? throw new InvalidArgumentException("Jenis event tidak dikenal: {$code}");

        return ['category' => $definition[0], 'severity' => $definition[1], 'hint' => $definition[2]];
    }

    /** @return list<string> */
    public static function codes(): array
    {
        return array_keys(self::definitions());
    }
}
