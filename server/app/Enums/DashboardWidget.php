<?php

declare(strict_types=1);

namespace App\Enums;

/** Widget yang bisa dipilih pengguna untuk dashboard. Urutan case = urutan tampil. */
enum DashboardWidget: string
{
    case DevicesSummary = 'devices_summary';
    case DevicesOffline = 'devices_offline';
    case DevicesCommands = 'devices_commands';
    case BandwidthSummary = 'bandwidth_summary';
    case BandwidthTop = 'bandwidth_top';
    case BrowsingSummary = 'browsing_summary';
    case BrowsingTop = 'browsing_top';
    case NetworkSummary = 'network_summary';
    case NetworkProblems = 'network_problems';

    public function label(): string
    {
        return match ($this) {
            self::DevicesSummary => 'Ringkasan perangkat (total, online, offline)',
            self::DevicesOffline => 'Daftar perangkat offline',
            self::DevicesCommands => 'Perintah terbaru',
            self::BandwidthSummary => 'Ringkasan bandwidth + tren 1 jam',
            self::BandwidthTop => 'Pengguna bandwidth terbesar',
            self::BrowsingSummary => 'Ringkasan browsing',
            self::BrowsingTop => 'Situs teratas',
            self::NetworkSummary => 'Ringkasan jaringan (online, offline, lambat)',
            self::NetworkProblems => 'Perangkat jaringan bermasalah',
        };
    }

    /** Menu asal widget; dipakai untuk pengelompokan dan tautan "Buka". */
    public function section(): string
    {
        return match ($this) {
            self::DevicesSummary, self::DevicesOffline, self::DevicesCommands => 'Perangkat',
            self::BandwidthSummary, self::BandwidthTop => 'Bandwidth',
            self::BrowsingSummary, self::BrowsingTop => 'Browsing',
            self::NetworkSummary, self::NetworkProblems => 'Jaringan',
        };
    }

    public function route(): string
    {
        return match ($this->section()) {
            'Bandwidth' => 'monitor.bandwidth',
            'Browsing' => 'monitor.browsing',
            'Jaringan' => 'network.index',
            default => 'devices.index',
        };
    }

    public function feature(): ?Feature
    {
        return match ($this->section()) {
            'Bandwidth' => Feature::Bandwidth,
            'Browsing' => Feature::Browsing,
            'Jaringan' => Feature::Network,
            default => null,
        };
    }

    /** Data browsing sensitif: hanya administrator. */
    public function adminOnly(): bool
    {
        return $this->section() === 'Browsing';
    }

    /** Tampilan bawaan = dashboard lama, agar yang sudah terbiasa tidak berubah. */
    public function defaultOn(): bool
    {
        return $this->section() === 'Perangkat';
    }

    /** Widget ringkasan tampil sebagai deretan kartu angka di bagian atas. */
    public function isSummary(): bool
    {
        return in_array($this, [self::DevicesSummary, self::BandwidthSummary, self::BrowsingSummary, self::NetworkSummary], true);
    }
}
