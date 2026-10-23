<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Fitur yang bisa dihidupkan/dimatikan administrator dari panel. Mematikan fitur menyembunyikan menunya, menutup halamannya,
 * dan menghentikan pengumpulan/pemrosesan datanya; data yang sudah tersimpan tidak dihapus.
 */
enum Feature: string
{
    case Bandwidth = 'bandwidth';
    case Browsing = 'browsing';
    case Inventory = 'inventory';
    case Winlog = 'winlog';
    case Network = 'network';
    case Tickets = 'tickets';
    case ThresholdAlerts = 'threshold_alerts';

    public function label(): string
    {
        return match ($this) {
            self::Bandwidth => 'Bandwidth monitoring',
            self::Browsing => 'Riwayat browsing',
            self::Inventory => 'Inventaris, port & layanan',
            self::Winlog => 'Windows Event Log',
            self::Network => 'Jaringan (NOC)',
            self::Tickets => 'Tiket bantuan IT',
            self::ThresholdAlerts => 'Alert ambang CPU/RAM/disk',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Bandwidth => 'Grafik dan tabel pemakaian bandwidth per perangkat dan seluruh PC.',
            self::Browsing => 'Situs dan IP tujuan yang dikunjungi pengguna. Data sensitif; pastikan sesuai ketentuan perusahaan.',
            self::Inventory => 'Perangkat keras/lunak terpasang serta port dan layanan yang terbuka di PC.',
            self::Winlog => 'Error/kritis dari Windows Event Log PC masuk ke Event Viewer (hanya terlihat admin).',
            self::Network => 'Ping/cek port switch, AP, printer, dan server dari server NetWatch.',
            self::Tickets => 'Form tiket untuk pengguna, penanganan oleh teknisi, dan penilaian layanan.',
            self::ThresholdAlerts => 'Peringatan saat CPU/RAM/disk melewati ambang cukup lama.',
        };
    }

    public function effect(): string
    {
        return match ($this) {
            self::Bandwidth => 'Menu & halaman bandwidth ditutup, server berhenti menyimpan sampel bandwidth dari agent.',
            self::Browsing => 'Menu & halaman browsing ditutup, agent diminta berhenti mengumpulkan dan server membuang kiriman riwayat.',
            self::Inventory => 'Halaman inventaris, port & layanan ditutup, server membuang kiriman inventaris dari agent.',
            self::Winlog => 'Agent diminta berhenti mengirim Event Log dan server membuang kirimannya.',
            self::Network => 'Menu Jaringan ditutup dan ping terjadwal berhenti (status terakhir tidak diperbarui).',
            self::Tickets => 'Form tiket dan halaman penilaian ditutup untuk pengguna; menu Tiket disembunyikan.',
            self::ThresholdAlerts => 'Evaluasi alert berhenti dan halaman aturan alert ditutup.',
        };
    }

    /** Nilai bawaan saat belum diatur dari panel: sebagian fitur masih mengikuti .env agar instalasi lama tidak berubah perilaku. */
    public function defaultEnabled(): bool
    {
        return match ($this) {
            self::Browsing => (bool) config('netwatch.browsing.enabled'),
            self::Winlog => (bool) config('netwatch.winlog.enabled'),
            self::Network => (bool) config('netwatch.monitor.enabled'),
            default => true,
        };
    }
}
