<?php

declare(strict_types=1);

namespace NetWatch\Agent\Platform;

use NetWatch\Agent\Dto\NetworkInterfaceStat;
use NetWatch\Agent\Dto\ProcessInfo;
use NetWatch\Agent\Dto\SystemMetrics;

interface PlatformInterface
{
    /** Nilai yang diterima server: "windows" atau "linux". */
    public function family(): string;

    public function hostname(): string;

    public function osVersion(): string;

    public function macAddress(): ?string;

    public function collectMetrics(): SystemMetrics;

    /**
     * Counter byte per interface fisik. Tidak boleh melempar: kegagalan membaca cukup mengembalikan [].
     *
     * @return list<NetworkInterfaceStat>
     */
    public function collectNetworkInterfaces(): array;

    /** @return list<ProcessInfo> */
    public function listProcesses(): array;

    /**
     * Koneksi TCP keluar yang sedang terbentuk, dengan nama domain dari cache DNS bila ada.
     * Hanya diminta bila pengumpulan browsing diaktifkan server.
     *
     * @return list<array{ip: string, port: int|null, process: string|null, host: string|null}>
     *
     * @throws PlatformException bila gagal dibaca
     */
    public function listConnections(): array;

    /**
     * Berkas riwayat Chrome/Edge milik semua pengguna di komputer ini.
     *
     * @return list<array{browser: 'chrome'|'edge', path: string}>
     */
    public function browserHistoryFiles(): array;

    /**
     * Port yang sedang listening dan daftar layanan sistem.
     *
     * @return array{
     *     ports: list<array{protocol: string, address: string, port: int, process: string|null}>,
     *     services: list<array{name: string, display: string|null, state: string, start: string|null}>
     * }
     *
     * @throws PlatformException bila gagal dibaca
     */
    public function collectInventory(): array;

    /**
     * Inventaris perangkat keras (satu ringkasan) dan perangkat lunak terpasang.
     * "software" null berarti tidak terbaca (jangan dianggap kosong); hardware null berarti tidak terbaca.
     *
     * @return array{
     *     hardware: array<string, mixed>|null,
     *     software: list<array{name: string, version: string|null, publisher: string|null, installed: string|null}>|null
     * }
     *
     * @throws PlatformException bila gagal dibaca
     */
    public function collectAssets(): array;

    /**
     * Entri Windows Event Log (System dan Application; Critical/Error, serta Warning dari penyedia terkait jaringan/hardware)
     * sejak waktu Unix tertentu. Platform selain Windows mengembalikan [].
     *
     * @return list<array{log: string, provider: string, id: int, level: int, time: int, record: int, message: string}>
     *
     * @throws PlatformException bila gagal dibaca
     */
    public function readEventLog(int $afterUnix): array;

    /** @throws PlatformException bila proses tidak bisa diakhiri */
    public function terminateProcess(int $pid): void;

    /** @throws PlatformException bila pesan tidak bisa ditampilkan */
    public function showMessage(string $message): void;

    /**
     * Terapkan blokir situs dan IP (menggantikan kebijakan NetWatch sebelumnya; daftar kosong = hapus semua).
     *
     * @param  list<string>  $domains domain yang sudah divalidasi
     * @param  list<string>  $ips IP atau CIDR yang sudah divalidasi
     *
     * @throws PlatformException bila sebagian atau seluruh kebijakan gagal diterapkan
     */
    public function applyBlockPolicy(array $domains, array $ips): void;

    /**
     * Baca aturan NetWatch yang BENAR-BENAR terpasang di komputer (bukan yang tercatat di state agent), per mekanisme:
     * file hosts, kebijakan browser, dan firewall. Hanya aturan milik NetWatch; aturan pihak lain tidak dilaporkan.
     *
     * @return array{hosts: list<string>, browser: list<string>, ips: list<string>}
     *
     * @throws PlatformException bila kondisi tidak bisa dibaca
     */
    public function inspectBlockPolicy(): array;

    /**
     * @param  'restart'|'shutdown'  $action
     *
     * @throws PlatformException
     */
    public function powerAction(string $action, int $delaySeconds): void;
}
