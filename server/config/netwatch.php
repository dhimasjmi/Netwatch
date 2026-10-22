<?php

declare(strict_types=1);

return [

    /*
    | Interval heartbeat yang diminta server dari agent (detik).
    | Perangkat dianggap offline bila tidak ada heartbeat selama `offline_after` detik.
    */
    'heartbeat_interval' => (int) env('NETWATCH_HEARTBEAT_INTERVAL', 30),
    'offline_after' => (int) env('NETWATCH_OFFLINE_AFTER', 120),

    // Seberapa sering agent mengirim daftar proses secara otomatis (detik).
    'process_snapshot_interval' => (int) env('NETWATCH_PROCESS_SNAPSHOT_INTERVAL', 300),
    'max_processes_per_snapshot' => 500,

    // Perintah yang tidak diambil agent dalam waktu ini dianggap kedaluwarsa (detik).
    'command_ttl' => (int) env('NETWATCH_COMMAND_TTL', 600),

    // Masa berlaku default token pendaftaran (jam).
    'enrollment_token_ttl_hours' => (int) env('NETWATCH_ENROLLMENT_TTL_HOURS', 24),

    // Retensi data (hari).
    'retention' => [
        'heartbeats_days' => (int) env('NETWATCH_RETENTION_HEARTBEATS', 14),
        // Snapshot proses besar: simpan hanya N terakhir per perangkat, bukan berdasarkan hari.
        'snapshots_keep' => (int) env('NETWATCH_SNAPSHOTS_KEEP', 12),
        'bandwidth_days' => (int) env('NETWATCH_RETENTION_BANDWIDTH', 90),
        'commands_days' => (int) env('NETWATCH_RETENTION_COMMANDS', 90),
        // Data browsing mentah (URL dan jam persis) lalu dirangkum per hari; ringkasan disimpan lebih lama.
        'browsing_days' => (int) env('NETWATCH_RETENTION_BROWSING', 14),
        'browsing_daily_days' => (int) env('NETWATCH_RETENTION_BROWSING_DAILY', 365),
        'monitor_checks_days' => (int) env('NETWATCH_RETENTION_MONITOR', 7),
        'events_days' => (int) env('NETWATCH_RETENTION_EVENTS', 180),
        'audit_days' => (int) env('NETWATCH_RETENTION_AUDIT', 365),
        // Riwayat uptime/downtime kecil ukurannya dan bernilai untuk laporan SLA, jadi disimpan lebih lama.
        'status_history_days' => (int) env('NETWATCH_RETENTION_STATUS_HISTORY', 730),
    ],

    // Proses sistem yang tidak boleh diakhiri lewat dashboard (huruf kecil, tanpa ekstensi .exe).
    'protected_processes' => [
        'system', 'system idle process', 'registry', 'smss', 'csrss', 'wininit', 'winlogon',
        'services', 'lsass', 'fontdrvhost', 'dwm', 'init', 'systemd', 'kthreadd', 'sshd',
        'php', 'netwatch-agent',
    ],

    // Versi agent terbaru yang dikenal server; agent di bawah versi ini ditandai "perlu diperbarui" di halaman Keamanan.
    'agent_latest_version' => env('NETWATCH_AGENT_LATEST_VERSION', '1.6.0'),

    // Perangkat yang tidak terlihat selama ini (hari) dianggap usang dan sebaiknya dicabut.
    'stale_device_days' => (int) env('NETWATCH_STALE_DEVICE_DAYS', 30),

    // Alamat IP server NetWatch (pisahkan koma) yang tidak boleh diblokir. Host dari APP_URL otomatis ikut dilindungi.
    'server_ips' => array_values(array_filter(array_map('trim', explode(',', (string) env('NETWATCH_SERVER_IPS', ''))))),

    // Batas jumlah aturan per jenis agar kebijakan per perangkat tetap kecil dan cepat diterapkan.
    'max_block_rules_per_type' => 1000,

    // Notifikasi (dikirim oleh `netwatch:send-alerts` tiap menit). Kosongkan token/alamat untuk menonaktifkan saluran itu.
    'alerts' => [
        'enabled' => (bool) env('NETWATCH_ALERTS_ENABLED', false),
        'codes' => ['device.offline', 'device.online', 'alert.firing', 'alert.resolved', 'ticket.created', 'target.offline', 'target.online'],
        // Event yang lebih tua dari ini (detik) tidak lagi dikirim, mis. setelah notifikasi lama tidak aktif.
        'max_age_seconds' => (int) env('NETWATCH_ALERT_MAX_AGE', 3600),
        // Bila sebanyak ini perangkat offline dalam satu kiriman, diringkas menjadi satu pesan.
        'group_threshold' => 5,
        'telegram_token' => env('NETWATCH_TELEGRAM_TOKEN'),
        'telegram_chat_id' => env('NETWATCH_TELEGRAM_CHAT_ID'),
        // Alamat email penerima, pisahkan koma. Pengirim memakai pengaturan MAIL_* Laravel.
        'mail_to' => array_values(array_filter(array_map('trim', explode(',', (string) env('NETWATCH_ALERT_MAIL_TO', ''))))),
    ],

    // Opsi pengiriman ping dari server untuk perangkat offline.
    'ping' => [
        'enabled' => (bool) env('NETWATCH_PING_ENABLED', true),
        'timeout_seconds' => 5,
    ],

    // Network Operations Center: perangkat jaringan tanpa agent (switch, AP, printer, server) di-ping dari server.
    'monitor' => [
        'enabled' => (bool) env('NETWATCH_MONITOR_ENABLED', true),
        'timeout_seconds' => 3,
        // Respons lebih lambat dari ini (ms) ditandai "Lambat".
        'warn_latency_ms' => (int) env('NETWATCH_MONITOR_WARN_MS', 150),
        // Offline baru ditetapkan setelah sekian kegagalan beruntun (satu paket hilang bukan gangguan).
        'fail_threshold' => (int) env('NETWATCH_MONITOR_FAIL_THRESHOLD', 2),
        // Hanya IP internal (privat/link-local) yang boleh dipantau; true bila perlu memantau IP publik.
        'allow_public' => (bool) env('NETWATCH_MONITOR_ALLOW_PUBLIC', false),
        'max_targets' => 1000,
    ],

    // Riwayat browsing/koneksi klien. Pastikan sudah sesuai ketentuan perusahaan dan pengguna telah diberi tahu.
    'browsing' => [
        'enabled' => (bool) env('NETWATCH_BROWSING_ENABLED', false),
        // domain = hanya nama situs; path = juga alamat halaman (query/kata pencarian tetap TIDAK disimpan).
        'detail' => env('NETWATCH_BROWSING_DETAIL', 'domain'),
        // Batas baris mentah per perangkat per 24 jam; kelebihannya dibuang agar satu PC tidak membanjiri tabel.
        'max_per_device_day' => (int) env('NETWATCH_BROWSING_MAX_PER_DEVICE_DAY', 5000),
    ],

    // Windows Event Log (error/kritis dari log System dan Application) dikirim agent ke Event Viewer. Isi log bisa memuat nama pengguna/jalur,
    // sehingga hanya terlihat oleh administrator.
    'winlog' => [
        'enabled' => (bool) env('NETWATCH_WINLOG_ENABLED', false),
    ],

    'force_https' => (bool) env('NETWATCH_FORCE_HTTPS', true),

    // Zona waktu untuk tampilan dashboard (data tetap disimpan dalam UTC).
    'display_timezone' => env('NETWATCH_TIMEZONE', 'Asia/Jakarta'),
];
