# Kamus data NetWatch

Kamus data lengkap keadaan akhir basis data (31 tabel, hasil menjalankan seluruh migrasi). Diagram relasi: `docs/ERD.md`. Nama indeks `*_index`/`*_unique` adalah nama bawaan Laravel (`tabel_kolom_jenis`); nama lain berasal dari SQL mentah di migrasi.

Semua kolom waktu bertipe `timestamptz` (UTC) kecuali dicatat lain. `?` pada kolom "Null" berarti boleh NULL.

## Daftar isi

- **Identitas, akses, dan pengaturan**: [`users`](#users), [`sessions`](#sessions), [`device_groups`](#device_groups), [`enrollment_tokens`](#enrollment_tokens), [`audit_logs`](#audit_logs), [`feature_flags`](#feature_flags)
- **Perangkat dan telemetri**: [`devices`](#devices), [`device_heartbeats`](#device_heartbeats), [`process_snapshots`](#process_snapshots), [`device_status_history`](#device_status_history), [`device_commands`](#device_commands)
- **Inventaris perangkat**: [`device_hardware`](#device_hardware), [`device_software`](#device_software), [`device_ports`](#device_ports), [`device_services`](#device_services)
- **Bandwidth dan riwayat browsing**: [`device_interfaces`](#device_interfaces), [`interface_samples`](#interface_samples), [`interface_samples_hourly`](#interface_samples_hourly), [`browsing_visits`](#browsing_visits), [`browsing_daily`](#browsing_daily), [`browsing_ignored_hosts`](#browsing_ignored_hosts)
- **Policy, alert, event, dan jaringan (NOC)**: [`policies`](#policies), [`block_rules`](#block_rules), [`alert_rules`](#alert_rules), [`alert_states`](#alert_states), [`events`](#events), [`monitor_targets`](#monitor_targets), [`monitor_target_checks`](#monitor_target_checks)
- **Tiket dan penilaian layanan**: [`tickets`](#tickets), [`ticket_comments`](#ticket_comments), [`ticket_feedback`](#ticket_feedback)
- [Cara membaca data saat troubleshooting (query SQL)](#cara-membaca-data-saat-troubleshooting)

Ringkasan pertumbuhan dan retensi: tabel yang dibersihkan otomatis diberi baris **Retensi**; tabel tanpa baris itu tumbuh pelan (data master) dan tidak dihapus otomatis. Kebijakan lengkap: `docs/BASIS-DATA.md`.

## Identitas, akses, dan pengaturan

### `users`

Akun yang bisa login ke dashboard. `role` menentukan hak (admin/operator/viewer); `is_active=false` memblokir login tanpa menghapus akun. `dashboard_widgets` (jsonb) menyimpan pilihan widget dashboard per pengguna.

| Kolom | Tipe | Null | Default | Keterangan |
|---|---|---|---|---|
| `id` | bigint |  |  | **PK** (auto-increment) |
| `name` | varchar(255) |  |  |  |
| `email` | varchar(255) |  |  |  |
| `email_verified_at` | timestamptz | ? |  | Bawaan Laravel; tidak dipakai alur verifikasi email. |
| `password` | varchar(255) |  |  | Hash bcrypt (BCRYPT_ROUNDS). |
| `remember_token` | varchar(100) | ? |  | Token "ingat saya" bawaan Laravel. |
| `created_at` | timestamptz | ? |  |  |
| `updated_at` | timestamptz | ? |  |  |
| `role` | varchar(20) |  | `viewer` | admin / operator / viewer (CHECK). |
| `is_active` | boolean |  | true | false = tidak bisa login. |
| `dashboard_widgets` | jsonb | ? |  |  |

**Indeks dan constraint**

- UNIQUE `users_email_unique` (email)
- CHECK `users_role_check`: `role IN ('admin', 'operator', 'viewer')`

**Dipakai oleh** (hasil pencarian nama model/SQL di `server/app`, tidak membedakan baca/tulis): `Console/Commands/CreateUserCommand`, `Http/Controllers/Web/FeatureController`, `Http/Controllers/Web/TicketController`, `Http/Controllers/Web/UserController`, `Providers/AppServiceProvider`, `Services/AlertRuleService`, `Services/AuditLogger`, `Services/BlockRuleService`, `Services/BrowsingIgnoreList` dan 20 lainnya.

### `sessions`

Sesi login Laravel (SESSION_DRIVER=database). `payload` berisi data sesi (terenkripsi bila SESSION_ENCRYPT=true). Tidak punya foreign key ke `users`. Isinya tidak ikut backup.

| Kolom | Tipe | Null | Default | Keterangan |
|---|---|---|---|---|
| `id` | varchar(255) |  |  | **PK** · ID sesi (string), bukan angka. |
| `user_id` | bigint | ? |  |  |
| `ip_address` | varchar(45) | ? |  |  |
| `user_agent` | text | ? |  |  |
| `payload` | text |  |  |  |
| `last_activity` | integer |  |  | Unix timestamp (detik) aktivitas terakhir. |

**Indeks dan constraint**

- INDEX `sessions_user_id_index` (user_id)
- INDEX `sessions_last_activity_index` (last_activity)

**Dipakai oleh** (hasil pencarian nama model/SQL di `server/app`, tidak membedakan baca/tulis): `Services/UserManager (SQL langsung)`.

**Retensi**: Dibersihkan oleh mekanisme sesi Laravel.

**Backup**: strukturnya ikut, **isinya dilewati** (bawaan `NETWATCH_BACKUP_EXCLUDE_DATA`).

### `device_groups`

Pengelompokan perangkat (mis. per ruangan/divisi). Dipakai sebagai cakupan policy, alert, dan token pendaftaran.

| Kolom | Tipe | Null | Default | Keterangan |
|---|---|---|---|---|
| `id` | bigint |  |  | **PK** (auto-increment) |
| `name` | varchar(100) |  |  |  |
| `description` | text | ? |  |  |
| `created_at` | timestamptz | ? |  |  |
| `updated_at` | timestamptz | ? |  |  |

**Indeks dan constraint**

- UNIQUE `device_groups_name_unique` (name)

**Dipakai oleh** (hasil pencarian nama model/SQL di `server/app`, tidak membedakan baca/tulis): `Http/Controllers/Web/AlertRuleController`, `Http/Controllers/Web/DeviceController`, `Http/Controllers/Web/DeviceGroupController`, `Http/Controllers/Web/EnrollmentTokenController`, `Http/Controllers/Web/FleetBandwidthController`, `Http/Controllers/Web/PolicyController`, `Http/Controllers/Web/UptimeReportController` dan 4 lainnya.

### `enrollment_tokens`

Token sekali/terbatas pakai untuk mendaftarkan agent baru. Hanya hash SHA-256 yang disimpan (token asli hanya tampil sekali saat dibuat).

| Kolom | Tipe | Null | Default | Keterangan |
|---|---|---|---|---|
| `id` | bigint |  |  | **PK** (auto-increment) |
| `label` | varchar(100) |  |  |  |
| `token_hash` | char(64) |  |  | SHA-256 dari token (hex 64). |
| `device_group_id` | bigint | ? |  | FK -> `device_groups.id` ON DELETE SET NULL · Perangkat yang mendaftar otomatis masuk grup ini. |
| `max_uses` | smallint |  | 1 | Berapa agent boleh mendaftar dengan token ini (>=1). |
| `used_count` | smallint |  | 0 | Sudah dipakai berapa kali (0..max_uses). |
| `expires_at` | timestamptz |  |  | Lewat waktu ini token ditolak. |
| `revoked_at` | timestamptz | ? |  | Terisi = dicabut. |
| `created_by` | bigint | ? |  | FK -> `users.id` ON DELETE SET NULL |
| `created_at` | timestamptz | ? |  |  |
| `updated_at` | timestamptz | ? |  |  |

**Indeks dan constraint**

- UNIQUE `enrollment_tokens_token_hash_unique` (token_hash)
- CHECK `enrollment_tokens_usage_check`: `used_count >= 0 AND max_uses >= 1`

**Dipakai oleh** (hasil pencarian nama model/SQL di `server/app`, tidak membedakan baca/tulis): `Http/Controllers/Web/EnrollmentTokenController`, `Services/EnrollmentService`, `Services/SecurityChecker`.

### `audit_logs`

Catatan tindakan admin/operator di dashboard dan tindakan sistem penting (siapa, apa, dari IP mana, detail dalam jsonb).

| Kolom | Tipe | Null | Default | Keterangan |
|---|---|---|---|---|
| `id` | bigint |  |  | **PK** (auto-increment) |
| `user_id` | bigint | ? |  | FK -> `users.id` ON DELETE SET NULL |
| `device_id` | bigint | ? |  | FK -> `devices.id` ON DELETE SET NULL |
| `action` | varchar(60) |  |  | Kode tindakan (mis. login, perintah, perubahan policy), terindeks. |
| `ip_address` | inet | ? |  |  |
| `metadata` | jsonb | ? |  | Detail tindakan (jsonb). |
| `created_at` | timestamptz |  |  |  |

**Indeks dan constraint**

- INDEX `audit_logs_action_index` (action)
- INDEX `audit_logs_created_at_index` (created_at DESC)

**Dipakai oleh** (hasil pencarian nama model/SQL di `server/app`, tidak membedakan baca/tulis): `Http/Controllers/Web/AuditLogController`, `Services/AuditLogger`, `Services/RetentionService`.

**Retensi**: Dihapus setelah `NETWATCH_RETENTION_AUDIT` (365 hari).

### `feature_flags`

Saklar fitur yang diatur admin (kunci cocok dengan enum `App\Enums\Feature`). Baris tidak ada = mengikuti nilai bawaan dari `.env`; panel admin menimpa `.env`.

| Kolom | Tipe | Null | Default | Keterangan |
|---|---|---|---|---|
| `key` | varchar(40) |  |  | **PK** · Kunci fitur (PK string): bandwidth, browsing, inventory, winlog, network, tickets, threshold_alerts. |
| `enabled` | boolean |  |  | Nilai yang dipilih admin (menimpa `.env`); tombol reset menghapus barisnya. |
| `updated_by` | bigint | ? |  | FK -> `users.id` ON DELETE SET NULL |
| `updated_at` | timestamptz | ? |  |  |

**Dipakai oleh** (hasil pencarian nama model/SQL di `server/app`, tidak membedakan baca/tulis): `Services/FeatureFlags`.

## Perangkat dan telemetri

### `devices`

Satu baris per komputer ber-agent. Menyimpan identitas, hash token agent, metrik terakhir (`latest_metrics`), waktu terakhir terlihat, status policy, mode maintenance, hasil ping server, dan penanda kapan inventaris/aset terakhir dilaporkan.

| Kolom | Tipe | Null | Default | Keterangan |
|---|---|---|---|---|
| `id` | bigint |  |  | **PK** (auto-increment) |
| `public_id` | uuid |  |  | UUID yang dipakai di URL/API (bukan id angka). |
| `device_group_id` | bigint | ? |  | FK -> `device_groups.id` ON DELETE SET NULL |
| `enrollment_token_id` | bigint | ? |  | FK -> `enrollment_tokens.id` ON DELETE SET NULL |
| `hostname` | varchar(255) |  |  |  |
| `display_name` | varchar(255) | ? |  |  |
| `os_family` | varchar(20) |  |  | windows / linux (CHECK). |
| `os_version` | varchar(100) | ? |  |  |
| `agent_version` | varchar(20) |  |  |  |
| `ip_address` | inet | ? |  | tipe native `inet` di PostgreSQL |
| `mac_address` | macaddr | ? |  | tipe native `macaddr` di PostgreSQL |
| `token_hash` | char(64) |  |  | SHA-256 token agent; dipakai server mengenali agent. |
| `latest_metrics` | jsonb | ? |  | jsonb metrik heartbeat terakhir + `recorded_at`. |
| `last_seen_at` | timestamptz | ? |  | Heartbeat terakhir; dasar status online/offline. |
| `enrolled_at` | timestamptz |  |  |  |
| `revoked_at` | timestamptz | ? |  | Terisi = akses agent dicabut (token tidak diterima). |
| `created_at` | timestamptz | ? |  |  |
| `updated_at` | timestamptz | ? |  |  |
| `policy_hash` | varchar(64) | ? |  | Hash policy yang terakhir DILAPORKAN agent sudah diterapkan (dibandingkan dengan hash yang diinginkan server). |
| `policy_error` | varchar(500) | ? |  | Pesan kegagalan penerapan policy dari agent. |
| `policy_reported_at` | timestamptz | ? |  | Kapan agent terakhir melapor status policy. |
| `maintenance_until` | timestamptz | ? |  | Sebelum waktu ini perangkat dalam mode maintenance: alert ambang tidak dinilai dan notifikasi event perangkat dilewati. |
| `maintenance_note` | varchar(255) | ? |  | Alasan maintenance. |
| `ping_ok` | boolean | ? |  | Hasil ping ICMP dari SERVER ke perangkat yang sudah berhenti mengirim heartbeat (membedakan PC mati vs hanya agent mati); butuh `ip_address`. |
| `ping_ms` | double precision | ? |  | Latensi ping server. |
| `ping_checked_at` | timestamptz | ? |  | Kapan ping terakhir dilakukan. |
| `inventory_reported_at` | timestamptz | ? |  | Laporan port/service pertama sudah diterima (NULL = belum; dipakai sebagai baseline). |
| `assets_reported_at` | timestamptz | ? |  | Laporan hardware/software pertama sudah diterima (baseline). |

**Indeks dan constraint**

- UNIQUE `devices_public_id_unique` (public_id)
- INDEX `devices_hostname_index` (hostname)
- UNIQUE `devices_token_hash_unique` (token_hash)
- INDEX `devices_last_seen_at_index` (last_seen_at)
- CHECK `devices_os_family_check`: `os_family IN ('windows', 'linux')`

**Dipakai oleh** (hasil pencarian nama model/SQL di `server/app`, tidak membedakan baca/tulis): `Console/Commands/CheckStatusCommand`, `Http/Controllers/Web/AlertRuleController`, `Http/Controllers/Web/AssetController`, `Http/Controllers/Web/BrowsingController`, `Http/Controllers/Web/BrowsingMonitorController`, `Http/Controllers/Web/DashboardController`, `Http/Controllers/Web/DeviceAssetsController`, `Http/Controllers/Web/DeviceBandwidthController`, `Http/Controllers/Web/DeviceBrowsingController` dan 53 lainnya.

### `device_heartbeats`

Satu baris per heartbeat agent (±tiap 30 detik): CPU, RAM, disk, uptime, user yang login. Tabel terbesar dan paling cepat tumbuh; dibersihkan otomatis.

| Kolom | Tipe | Null | Default | Keterangan |
|---|---|---|---|---|
| `id` | bigint |  |  | **PK** (auto-increment) |
| `device_id` | bigint |  |  | FK -> `devices.id` ON DELETE CASCADE |
| `cpu_percent` | numeric(5,2) |  |  | Persen 0-100 (2 desimal). |
| `memory_used_mb` | integer |  |  |  |
| `memory_total_mb` | integer |  |  |  |
| `disk_used_gb` | integer |  |  |  |
| `disk_total_gb` | integer |  |  |  |
| `uptime_seconds` | bigint |  |  | Lama PC menyala (detik). |
| `logged_in_user` | varchar(255) | ? |  | User Windows/Linux yang sedang login. |
| `recorded_at` | timestamptz |  |  | Waktu server menerima heartbeat (UTC). |

**Indeks dan constraint**

- INDEX `device_heartbeats_recorded_at_index` (recorded_at)
- INDEX `device_heartbeats_device_recorded_index` (device_id, recorded_at DESC)

**Dipakai oleh** (hasil pencarian nama model/SQL di `server/app`, tidak membedakan baca/tulis): `Http/Controllers/Web/DeviceMetricsController`, `Services/AlertEvaluator`, `Services/HeartbeatService`, `Services/RetentionService` dan 1 lainnya.

**Retensi**: Dihapus setelah `NETWATCH_RETENTION_HEARTBEATS` (14 hari) oleh `netwatch:prune`.

**Backup**: strukturnya ikut, **isinya dilewati** (bawaan `NETWATCH_BACKUP_EXCLUDE_DATA`).

### `process_snapshots`

Daftar proses (jsonb) yang dikirim agent; hanya N terakhir per perangkat yang disimpan (`snapshots_keep`).

| Kolom | Tipe | Null | Default | Keterangan |
|---|---|---|---|---|
| `id` | bigint |  |  | **PK** (auto-increment) |
| `device_id` | bigint |  |  | FK -> `devices.id` ON DELETE CASCADE |
| `captured_at` | timestamptz |  |  |  |
| `process_count` | integer |  |  | Jumlah proses dalam snapshot. |
| `processes` | jsonb |  |  | jsonb array proses (nama, PID, CPU/RAM). |

**Indeks dan constraint**

- INDEX `process_snapshots_device_captured_index` (device_id, captured_at DESC)

**Dipakai oleh** (hasil pencarian nama model/SQL di `server/app`, tidak membedakan baca/tulis): `Services/ProcessSnapshotService` dan 1 lainnya.

**Retensi**: Hanya `NETWATCH_SNAPSHOTS_KEEP` (12) terbaru per perangkat (ProcessSnapshotService).

**Backup**: strukturnya ikut, **isinya dilewati** (bawaan `NETWATCH_BACKUP_EXCLUDE_DATA`).

### `device_status_history`

Riwayat interval online/offline per perangkat untuk laporan uptime/SLA. Paling banyak satu interval terbuka (`ended_at IS NULL`) per perangkat.

| Kolom | Tipe | Null | Default | Keterangan |
|---|---|---|---|---|
| `id` | bigint |  |  | **PK** (auto-increment) |
| `device_id` | bigint |  |  | FK -> `devices.id` ON DELETE CASCADE |
| `status` | varchar(10) |  |  | online / offline (CHECK). |
| `started_at` | timestamptz |  |  |  |
| `ended_at` | timestamptz | ? |  | NULL = interval yang sedang berjalan (maksimal satu per perangkat). |
| `reason` | varchar(30) |  |  | Penyebab perubahan: mis. heartbeat, heartbeat_timeout, heartbeat_gap, backfill. |

**Indeks dan constraint**

- INDEX `device_status_history_device_id_started_at_index` (device_id, started_at)
- UNIQUE INDEX `device_status_history_open_unique` (device_id) **WHERE** ended_at IS NULL
- CHECK `device_status_history_status_check`: `status IN ('online', 'offline')`
- CHECK `device_status_history_range_check`: `ended_at IS NULL OR ended_at >= started_at`

**Dipakai oleh** (hasil pencarian nama model/SQL di `server/app`, tidak membedakan baca/tulis): `Services/RetentionService`, `Services/StatusTracker`, `Services/UptimeReportService` dan 1 lainnya.

**Retensi**: Interval yang sudah selesai dihapus setelah `NETWATCH_RETENTION_STATUS_HISTORY` (730 hari); interval terbuka tidak pernah dihapus.

### `device_commands`

Antrean perintah dari dashboard ke agent (kill proses, pesan, restart, dll.) beserta hasilnya. Agent memakai `public_id` (uuid), bukan `id`.

| Kolom | Tipe | Null | Default | Keterangan |
|---|---|---|---|---|
| `id` | bigint |  |  | **PK** (auto-increment) |
| `public_id` | uuid |  |  | UUID yang dipakai agent saat mengambil/melapor. |
| `device_id` | bigint |  |  | FK -> `devices.id` ON DELETE CASCADE |
| `requested_by` | bigint | ? |  | FK -> `users.id` ON DELETE SET NULL |
| `type` | varchar(40) |  |  | Jenis perintah (whitelist di server dan agent). |
| `payload` | jsonb | ? |  | Argumen perintah (jsonb). |
| `status` | varchar(20) |  | `pending` | pending -> dispatched -> succeeded / failed / expired (CHECK). |
| `result` | jsonb | ? |  | Hasil dari agent (jsonb). |
| `error_message` | text | ? |  |  |
| `expires_at` | timestamptz |  |  | Lewat waktu ini perintah yang belum selesai menjadi expired. |
| `dispatched_at` | timestamptz | ? |  | Kapan diambil agent. |
| `completed_at` | timestamptz | ? |  | Kapan hasil diterima. |
| `created_at` | timestamptz | ? |  |  |
| `updated_at` | timestamptz | ? |  |  |

**Indeks dan constraint**

- UNIQUE `device_commands_public_id_unique` (public_id)
- INDEX `device_commands_device_created_index` (device_id, created_at DESC)
- INDEX `device_commands_pending_index` (device_id, created_at) **WHERE** status = 'pending'
- CHECK `device_commands_status_check`: `status IN ('pending', 'dispatched', 'succeeded', 'failed', 'expired')`

**Dipakai oleh** (hasil pencarian nama model/SQL di `server/app`, tidak membedakan baca/tulis): `Http/Controllers/Api/Agent/CommandResultController`, `Http/Controllers/Api/Agent/HeartbeatController`, `Http/Controllers/Web/DashboardController`, `Http/Controllers/Web/DeviceController`, `Services/CommandService`, `Services/RetentionService` dan 1 lainnya.

**Retensi**: Perintah selesai (succeeded/failed/expired) dihapus setelah `NETWATCH_RETENTION_COMMANDS` (90 hari).

## Inventaris perangkat

### `device_hardware`

Inventaris perangkat keras terbaru, satu baris per perangkat (diganti tiap laporan).

| Kolom | Tipe | Null | Default | Keterangan |
|---|---|---|---|---|
| `id` | bigint |  |  | **PK** (auto-increment) |
| `device_id` | bigint |  |  | FK -> `devices.id` ON DELETE CASCADE |
| `manufacturer` | varchar(100) | ? |  |  |
| `model` | varchar(100) | ? |  |  |
| `serial` | varchar(100) | ? |  |  |
| `cpu` | varchar(150) | ? |  |  |
| `cpu_cores` | smallint | ? |  |  |
| `cpu_threads` | smallint | ? |  |  |
| `memory_mb` | bigint | ? |  | Total RAM (MB). |
| `os` | varchar(100) | ? |  |  |
| `os_version` | varchar(50) | ? |  |  |
| `os_build` | varchar(50) | ? |  |  |
| `disks` | jsonb | ? |  | jsonb daftar disk. |
| `updated_at` | timestamptz |  |  |  |

**Indeks dan constraint**

- UNIQUE `device_hardware_device_id_unique` (device_id)
- INDEX `device_hardware_serial_index` (serial)

**Dipakai oleh** (hasil pencarian nama model/SQL di `server/app`, tidak membedakan baca/tulis): `Http/Controllers/Web/DeviceAssetsController`, `Services/AssetRecorder` dan 1 lainnya.

### `device_software`

Daftar aplikasi terpasang per perangkat; baris yang tidak dilaporkan lagi dianggap sudah di-uninstall (`last_seen_at` berhenti bergerak).

| Kolom | Tipe | Null | Default | Keterangan |
|---|---|---|---|---|
| `id` | bigint |  |  | **PK** (auto-increment) |
| `device_id` | bigint |  |  | FK -> `devices.id` ON DELETE CASCADE |
| `name` | varchar(300) |  |  |  |
| `version` | varchar(100) |  | `''` | kosong = tidak diketahui; bukan null agar kunci unik bekerja |
| `publisher` | varchar(200) | ? |  |  |
| `installed_on` | date | ? |  | Tanggal pemasangan dari OS (bila ada). |
| `first_seen_at` | timestamptz |  |  | Pertama kali terlihat terpasang. |
| `last_seen_at` | timestamptz |  |  | Terakhir kali masih dilaporkan agent. |

**Indeks dan constraint**

- UNIQUE `device_software_device_id_name_version_unique` (device_id, name, version)
- INDEX `device_software_name_index` (name)

**Dipakai oleh** (hasil pencarian nama model/SQL di `server/app`, tidak membedakan baca/tulis): `Http/Controllers/Web/AssetController`, `Http/Controllers/Web/DeviceAssetsController`, `Services/AssetRecorder`.

### `device_ports`

Port yang terbuka/mendengarkan di perangkat; `exposed` = terbuka ke jaringan (bukan hanya loopback).

| Kolom | Tipe | Null | Default | Keterangan |
|---|---|---|---|---|
| `id` | bigint |  |  | **PK** (auto-increment) |
| `device_id` | bigint |  |  | FK -> `devices.id` ON DELETE CASCADE |
| `protocol` | varchar(3) |  |  | tcp / udp (CHECK). |
| `address` | varchar(45) |  |  |  |
| `port` | integer |  |  |  |
| `process` | varchar(100) | ? |  |  |
| `exposed` | boolean |  |  | true = mendengarkan di alamat selain loopback. |
| `first_seen_at` | timestamptz |  |  | Tidak diperbarui saat laporan berikutnya (penanda port baru). |
| `last_seen_at` | timestamptz |  |  | Diperbarui tiap laporan. |

**Indeks dan constraint**

- UNIQUE `device_ports_device_id_protocol_address_port_unique` (device_id, protocol, address, port)
- INDEX `device_ports_port_protocol_index` (port, protocol)
- CHECK `device_ports_protocol_check`: `protocol IN ('tcp', 'udp')`

**Dipakai oleh** (hasil pencarian nama model/SQL di `server/app`, tidak membedakan baca/tulis): `Http/Controllers/Web/DeviceInventoryController`, `Services/InventoryRecorder`.

### `device_services`

Service Windows/Linux di perangkat beserta statusnya.

| Kolom | Tipe | Null | Default | Keterangan |
|---|---|---|---|---|
| `id` | bigint |  |  | **PK** (auto-increment) |
| `device_id` | bigint |  |  | FK -> `devices.id` ON DELETE CASCADE |
| `name` | varchar(256) |  |  |  |
| `display_name` | varchar(256) | ? |  |  |
| `state` | varchar(20) |  |  | Status service (teks dari OS, mis. running/stopped). |
| `start_mode` | varchar(20) | ? |  | Mode start (mis. auto/manual/disabled). |
| `updated_at` | timestamptz |  |  |  |

**Indeks dan constraint**

- UNIQUE `device_services_device_id_name_unique` (device_id, name)

**Dipakai oleh** (hasil pencarian nama model/SQL di `server/app`, tidak membedakan baca/tulis): `Http/Controllers/Web/DeviceController`, `Http/Controllers/Web/DeviceInventoryController`, `Services/DeviceService`, `Services/InventoryRecorder`.

## Bandwidth dan riwayat browsing

### `device_interfaces`

Interface jaringan per perangkat. Menyimpan penghitung byte terakhir (`last_rx_bytes/last_tx_bytes`) agar selisih per heartbeat bisa dihitung.

| Kolom | Tipe | Null | Default | Keterangan |
|---|---|---|---|---|
| `id` | bigint |  |  | **PK** (auto-increment) |
| `device_id` | bigint |  |  | FK -> `devices.id` ON DELETE CASCADE |
| `name` | varchar(100) |  |  |  |
| `speed_bps` | bigint | ? |  | Kecepatan link (bit/detik) bila diketahui. |
| `last_rx_bytes` | bigint |  |  | Penghitung byte masuk terakhir (untuk menghitung selisih). |
| `last_tx_bytes` | bigint |  |  | Penghitung byte keluar terakhir. |
| `last_sample_at` | timestamptz |  |  | Waktu sampel terakhir. |
| `created_at` | timestamptz | ? |  |  |
| `updated_at` | timestamptz | ? |  |  |

**Indeks dan constraint**

- UNIQUE `device_interfaces_device_id_name_unique` (device_id, name)

**Dipakai oleh** (hasil pencarian nama model/SQL di `server/app`, tidak membedakan baca/tulis): `Services/BandwidthRecorder`, `Services/BandwidthReportService`.

### `interface_samples`

Sampel bandwidth mentah (selisih byte per interval heartbeat). Dirangkum per jam lalu dihapus setelah `bandwidth_raw_days`.

| Kolom | Tipe | Null | Default | Keterangan |
|---|---|---|---|---|
| `id` | bigint |  |  | **PK** (auto-increment) |
| `device_interface_id` | bigint |  |  | FK -> `device_interfaces.id` ON DELETE CASCADE |
| `sampled_at` | timestamptz |  |  | untuk pembersihan retensi |
| `rx_bytes` | bigint |  |  | Byte masuk pada interval ini (selisih, bukan total). |
| `tx_bytes` | bigint |  |  | Byte keluar pada interval ini. |
| `interval_seconds` | integer |  |  | Panjang interval yang diwakili. |

**Indeks dan constraint**

- INDEX `interface_samples_device_interface_id_sampled_at_index` (device_interface_id, sampled_at)
- INDEX `interface_samples_sampled_at_index` (sampled_at)

**Dipakai oleh** (hasil pencarian nama model/SQL di `server/app`, tidak membedakan baca/tulis): `Services/BandwidthRecorder`, `Services/BandwidthRollup (SQL langsung)`, `Support/BandwidthSamples (SQL langsung)` dan 1 lainnya.

**Retensi**: Dirangkum ke `interface_samples_hourly` lalu dihapus setelah `NETWATCH_RETENTION_BANDWIDTH_RAW` (3 hari).

**Backup**: strukturnya ikut, **isinya dilewati** (bawaan `NETWATCH_BACKUP_EXCLUDE_DATA`).

### `interface_samples_hourly`

Rangkuman bandwidth per interface per jam; dasar grafik jangka panjang.

| Kolom | Tipe | Null | Default | Keterangan |
|---|---|---|---|---|
| `id` | bigint |  |  | **PK** (auto-increment) |
| `device_interface_id` | bigint |  |  | FK -> `device_interfaces.id` ON DELETE CASCADE |
| `hour` | timestamptz |  |  | Awal jam (UTC). |
| `rx_bytes` | bigint |  |  |  |
| `tx_bytes` | bigint |  |  |  |
| `interval_seconds` | bigint |  |  | Total detik yang tercakup. |
| `samples` | integer |  |  | Jumlah sampel mentah yang dirangkum. |

**Indeks dan constraint**

- UNIQUE `interface_samples_hourly_device_interface_id_hour_unique` (device_interface_id, hour)
- INDEX `interface_samples_hourly_hour_index` (hour)

**Dipakai oleh** (hasil pencarian nama model/SQL di `server/app`, tidak membedakan baca/tulis): `Services/BandwidthRollup (SQL langsung)`, `Support/BandwidthSamples (SQL langsung)`.

**Retensi**: Dihapus setelah `NETWATCH_RETENTION_BANDWIDTH` (365 hari).

### `browsing_visits`

Kunjungan browsing mentah (URL atau koneksi IP) dari agent. `dedupe_hash` mencegah baris ganda bila agent mengirim ulang.

| Kolom | Tipe | Null | Default | Keterangan |
|---|---|---|---|---|
| `id` | bigint |  |  | **PK** (auto-increment) |
| `device_id` | bigint |  |  | FK -> `devices.id` ON DELETE CASCADE |
| `visited_at` | timestamptz |  |  |  |
| `kind` | varchar(12) |  |  | url / connection (CHECK). |
| `host` | varchar(253) | ? |  | Nama situs. |
| `ip` | varchar(45) | ? |  | IP tujuan. |
| `port` | integer | ? |  |  |
| `url` | varchar(1000) | ? |  | Alamat halaman (hanya bila NETWATCH_BROWSING_DETAIL=path; query tidak pernah disimpan). |
| `process` | varchar(100) | ? |  | Proses sumber. |
| `browser` | varchar(12) | ? |  | Jenis browser. |
| `dedupe_hash` | char(40) |  |  | Hash unik per perangkat untuk mencegah duplikat. |

**Indeks dan constraint**

- UNIQUE `browsing_visits_device_id_dedupe_hash_unique` (device_id, dedupe_hash)
- INDEX `browsing_visits_device_id_visited_at_index` (device_id, visited_at)
- INDEX `browsing_visits_visited_at_index` (visited_at)
- INDEX `browsing_visits_host_index` (host)
- CHECK `browsing_visits_kind_check`: `kind IN ('url', 'connection')`

**Dipakai oleh** (hasil pencarian nama model/SQL di `server/app`, tidak membedakan baca/tulis): `Services/BrowsingDashboardService`, `Services/BrowsingRecorder`, `Services/BrowsingReportService`, `Services/BrowsingRollup`, `Services/BrowsingRollup (SQL langsung)`.

**Retensi**: Dirangkum ke `browsing_daily` lalu dihapus setelah `NETWATCH_RETENTION_BROWSING` (14 hari).

### `browsing_daily`

Rangkuman kunjungan per perangkat/hari/situs dari `browsing_visits` (disimpan lebih lama).

| Kolom | Tipe | Null | Default | Keterangan |
|---|---|---|---|---|
| `id` | bigint |  |  | **PK** (auto-increment) |
| `device_id` | bigint |  |  | FK -> `devices.id` ON DELETE CASCADE |
| `day` | date |  |  |  |
| `kind` | varchar(12) |  |  |  |
| `host` | varchar(253) |  | `''` | Kosong ('') bila tidak ada. |
| `ip` | varchar(45) |  | `''` | Kosong ('') bila tidak ada. |
| `visits` | bigint |  |  | Jumlah kunjungan pada hari itu. |
| `first_at` | timestamptz |  |  |  |
| `last_at` | timestamptz |  |  |  |

**Indeks dan constraint**

- UNIQUE `browsing_daily_device_id_day_kind_host_ip_unique` (device_id, day, kind, host, ip)
- INDEX `browsing_daily_day_index` (day)
- INDEX `browsing_daily_host_index` (host)
- CHECK `browsing_daily_kind_check`: `kind IN ('url', 'connection')`

**Dipakai oleh** (hasil pencarian nama model/SQL di `server/app`, tidak membedakan baca/tulis): `Services/BrowsingDashboardService`, `Services/BrowsingReportService`, `Services/RetentionService`.

**Retensi**: Dihapus setelah `NETWATCH_RETENTION_BROWSING_DAILY` (365 hari).

### `browsing_ignored_hosts`

Domain yang tidak dicatat/ditampilkan di riwayat browsing (mis. layanan sistem). Terisi bawaan oleh migrasi.

| Kolom | Tipe | Null | Default | Keterangan |
|---|---|---|---|---|
| `id` | bigint |  |  | **PK** (auto-increment) |
| `domain` | varchar(253) |  |  | Unik; domain (dan subdomainnya) yang diabaikan. |
| `note` | varchar(100) | ? |  |  |
| `created_by` | bigint | ? |  | FK -> `users.id` ON DELETE SET NULL |
| `created_at` | timestamptz |  |  |  |

**Indeks dan constraint**

- UNIQUE `browsing_ignored_hosts_domain_unique` (domain)

**Dipakai oleh** (hasil pencarian nama model/SQL di `server/app`, tidak membedakan baca/tulis): `Http/Controllers/Web/BrowsingController`, `Http/Controllers/Web/BrowsingIgnoredHostController`, `Services/BrowsingIgnoreList`.

## Policy, alert, event, dan jaringan (NOC)

### `policies`

Kelompok aturan blokir. Cakupan: grup (`device_group_id`), satu perangkat (`device_id`), atau keduanya NULL = semua perangkat.

| Kolom | Tipe | Null | Default | Keterangan |
|---|---|---|---|---|
| `id` | bigint |  |  | **PK** (auto-increment) |
| `name` | varchar(100) |  |  |  |
| `description` | varchar(255) | ? |  |  |
| `is_enabled` | boolean |  | true | false = policy tidak diterapkan. |
| `device_group_id` | bigint | ? |  | FK -> `device_groups.id` ON DELETE CASCADE |
| `device_id` | bigint | ? |  | FK -> `devices.id` ON DELETE CASCADE |
| `created_by` | bigint | ? |  | FK -> `users.id` ON DELETE SET NULL |
| `created_at` | timestamptz | ? |  |  |
| `updated_at` | timestamptz | ? |  |  |

**Indeks dan constraint**

- UNIQUE `policies_name_unique` (name)
- INDEX `policies_is_enabled_device_group_id_device_id_index` (is_enabled, device_group_id, device_id)
- CHECK `policies_scope_check`: `device_group_id IS NULL OR device_id IS NULL`

**Dipakai oleh** (hasil pencarian nama model/SQL di `server/app`, tidak membedakan baca/tulis): `Enums/EventCategory`, `Http/Controllers/Web/PolicyController`, `Http/Middleware/SecurityHeaders`, `Http/Requests/Web/PolicyRequest`, `Services/BlockRuleService`, `Services/PolicyService`, `Support/EventCatalog` dan 1 lainnya.

### `block_rules`

Aturan blokir domain atau IP milik sebuah policy.

| Kolom | Tipe | Null | Default | Keterangan |
|---|---|---|---|---|
| `id` | bigint |  |  | **PK** (auto-increment) |
| `type` | varchar(10) |  |  | domain / ip (CHECK). |
| `value` | varchar(255) |  |  | Nilai yang diblokir. |
| `note` | varchar(255) | ? |  |  |
| `is_enabled` | boolean |  | true |  |
| `created_by` | bigint | ? |  | FK -> `users.id` ON DELETE SET NULL |
| `created_at` | timestamptz | ? |  |  |
| `updated_at` | timestamptz | ? |  |  |
| `policy_id` | bigint |  |  | FK -> `policies.id` ON DELETE CASCADE · NOT NULL; unik bersama (type, value). |

**Indeks dan constraint**

- UNIQUE INDEX `block_rules_unique` (policy_id, type, value)
- CHECK `block_rules_type_check`: `type IN ('domain', 'ip')`

**Dipakai oleh** (hasil pencarian nama model/SQL di `server/app`, tidak membedakan baca/tulis): `Http/Controllers/Web/PolicyController`, `Services/BlockRuleService`, `Services/PolicyBuilder` dan 1 lainnya.

### `alert_rules`

Aturan ambang CPU/RAM/disk: metrik, ambang (%), lama bertahan (menit), tingkat keparahan, cakupan grup/perangkat.

| Kolom | Tipe | Null | Default | Keterangan |
|---|---|---|---|---|
| `id` | bigint |  |  | **PK** (auto-increment) |
| `name` | varchar(100) |  |  |  |
| `metric` | varchar(10) |  |  | cpu / memory / disk (CHECK). |
| `threshold` | smallint |  |  | Persen 1-100. |
| `duration_minutes` | smallint |  |  | Harus melewati ambang selama ini (1-1440 menit). |
| `severity` | varchar(10) |  |  | warning / critical. |
| `device_group_id` | bigint | ? |  | FK -> `device_groups.id` ON DELETE CASCADE |
| `device_id` | bigint | ? |  | FK -> `devices.id` ON DELETE CASCADE |
| `is_enabled` | boolean |  | true |  |
| `created_by` | bigint | ? |  | FK -> `users.id` ON DELETE SET NULL |
| `created_at` | timestamp | ? |  |  |
| `updated_at` | timestamp | ? |  |  |

**Indeks dan constraint**

- UNIQUE `alert_rules_name_unique` (name)
- INDEX `alert_rules_is_enabled_device_group_id_device_id_index` (is_enabled, device_group_id, device_id)
- CHECK `alert_rules_metric_check`: `metric IN ('cpu', 'memory', 'disk')`
- CHECK `alert_rules_severity_check`: `severity IN ('warning', 'critical')`
- CHECK `alert_rules_threshold_check`: `threshold BETWEEN 1 AND 100`
- CHECK `alert_rules_duration_check`: `duration_minutes BETWEEN 1 AND 1440`
- CHECK `alert_rules_scope_check`: `device_group_id IS NULL OR device_id IS NULL`

**Dipakai oleh** (hasil pencarian nama model/SQL di `server/app`, tidak membedakan baca/tulis): `Http/Controllers/Web/AlertRuleController`, `Http/Requests/Web/AlertRuleRequest`, `Services/AlertEvaluator`, `Services/AlertRuleService` dan 1 lainnya.

### `alert_states`

Status berjalan tiap pasangan (aturan, perangkat): sejak kapan melewati ambang (`firing_since`) dan nilai terakhir.

| Kolom | Tipe | Null | Default | Keterangan |
|---|---|---|---|---|
| `id` | bigint |  |  | **PK** (auto-increment) |
| `alert_rule_id` | bigint |  |  | FK -> `alert_rules.id` ON DELETE CASCADE |
| `device_id` | bigint |  |  | FK -> `devices.id` ON DELETE CASCADE |
| `firing_since` | timestamptz | ? |  | NULL = tidak sedang melewati ambang. |
| `last_value` | numeric(5,1) | ? |  | Nilai metrik terakhir (1 desimal). |
| `updated_at` | timestamptz | ? |  |  |

**Indeks dan constraint**

- UNIQUE `alert_states_alert_rule_id_device_id_unique` (alert_rule_id, device_id)
- INDEX `alert_states_firing_since_index` (firing_since)

**Dipakai oleh** (hasil pencarian nama model/SQL di `server/app`, tidak membedakan baca/tulis): `Http/Controllers/Web/AlertRuleController`, `Services/AlertEvaluator` dan 1 lainnya.

### `events`

Event Viewer: kejadian (offline/online, perintah, enrollment, policy, jaringan, backup, dll.) dengan kategori, tingkat keparahan, dan kode. Juga antrean notifikasi Telegram/email.

| Kolom | Tipe | Null | Default | Keterangan |
|---|---|---|---|---|
| `id` | bigint |  |  | **PK** (auto-increment) |
| `device_id` | bigint | ? |  | FK -> `devices.id` ON DELETE SET NULL |
| `user_id` | bigint | ? |  | FK -> `users.id` ON DELETE SET NULL |
| `category` | varchar(30) |  |  | availability / connectivity / addressing / dns / performance / command / enrollment / security / policy / system (CHECK). |
| `severity` | varchar(10) |  |  | info / warning / critical (CHECK). |
| `code` | varchar(60) |  |  | Kode event (mis. device.offline, target.slow); lihat docs/KODE-EVENT.md. |
| `message` | varchar(500) |  |  |  |
| `details` | jsonb | ? |  | jsonb konteks tambahan. |
| `occurred_at` | timestamptz |  |  |  |
| `notified_at` | timestamptz | ? |  | NULL = belum diproses pengirim notifikasi; terisi = sudah diproses (dikirim ATAU sengaja dilewati). |
| `notification_sent` | boolean |  | false | true = notifikasi benar-benar dikirim; dasar keputusan "pemulihan hanya dikirim bila masalahnya sudah diberitahukan". |

**Indeks dan constraint**

- INDEX `events_occurred_at_index` (occurred_at)
- INDEX `events_device_id_occurred_at_index` (device_id, occurred_at)
- INDEX `events_category_occurred_at_index` (category, occurred_at)
- INDEX `events_severity_occurred_at_index` (severity, occurred_at)
- INDEX `events_pending_notification_idx` (id) **WHERE** notified_at IS NULL
- CHECK `events_severity_check`: `severity IN ('info', 'warning', 'critical')`
- CHECK `events_category_check`: `category IN ('availability', 'connectivity', 'addressing', 'dns', 'performance', 'command', 'enrollment', 'security', 'policy', 'system')`

**Dipakai oleh** (hasil pencarian nama model/SQL di `server/app`, tidak membedakan baca/tulis): `Enums/Feature`, `Http/Controllers/Web/EventController`, `Services/AlertDispatcher`, `Services/DatabaseHealthService`, `Services/EventRecorder`, `Services/InventoryRecorder`, `Services/RetentionService`, `Services/SecurityChecker`, `Services/WinlogRecorder` dan 2 lainnya.

**Retensi**: Dihapus setelah `NETWATCH_RETENTION_EVENTS` (180 hari).

### `monitor_targets`

Target pemantauan jaringan (NOC): IP/host + metode ping (icmp) atau cek port (tcp) beserta status terakhir.

| Kolom | Tipe | Null | Default | Keterangan |
|---|---|---|---|---|
| `id` | bigint |  |  | **PK** (auto-increment) |
| `name` | varchar(100) |  |  |  |
| `address` | varchar(45) |  |  |  |
| `kind` | varchar(20) |  | `other` | Jenis perangkat (enum TargetKind, bawaan other). |
| `method` | varchar(5) |  | `icmp` | icmp / tcp (CHECK). |
| `port` | smallint | ? |  | Wajib untuk tcp (1-65535), NULL untuk icmp (CHECK). |
| `is_enabled` | boolean |  | true |  |
| `status` | varchar(10) |  | `unknown` | unknown / online / warning / offline (CHECK). |
| `latency_ms` | double precision | ? |  | Latensi pengecekan terakhir. |
| `consecutive_failures` | smallint |  | 0 | Gagal berturut-turut; offline setelah mencapai ambang. |
| `last_checked_at` | timestamptz | ? |  |  |
| `status_since` | timestamptz | ? |  | Sejak kapan status sekarang. |
| `notes` | varchar(255) | ? |  |  |
| `created_by` | bigint | ? |  | FK -> `users.id` ON DELETE SET NULL |
| `created_at` | timestamp | ? |  |  |
| `updated_at` | timestamp | ? |  |  |

**Indeks dan constraint**

- UNIQUE `monitor_targets_name_unique` (name)
- INDEX `monitor_targets_is_enabled_status_index` (is_enabled, status)
- CHECK `monitor_targets_status_check`: `status IN ('unknown', 'online', 'warning', 'offline')`
- CHECK `monitor_targets_method_check`: `method IN ('icmp', 'tcp')`
- CHECK `monitor_targets_port_check`: `(method = 'tcp' AND port BETWEEN 1 AND 65535) OR (method = 'icmp' AND port IS NULL)`

**Dipakai oleh** (hasil pencarian nama model/SQL di `server/app`, tidak membedakan baca/tulis): `Http/Controllers/Web/DashboardController`, `Http/Controllers/Web/NetworkMonitorController`, `Http/Requests/Web/MonitorTargetRequest`, `Services/AlertDispatcher`, `Services/Probe/SystemTargetProber`, `Services/Probe/TargetProber`, `Services/TargetMonitorService`.

### `monitor_target_checks`

Hasil tiap pengecekan target (per menit): berhasil/tidaknya dan latensi. Dasar grafik dan perhitungan status.

| Kolom | Tipe | Null | Default | Keterangan |
|---|---|---|---|---|
| `id` | bigint |  |  | **PK** (auto-increment) |
| `monitor_target_id` | bigint |  |  | FK -> `monitor_targets.id` ON DELETE CASCADE |
| `checked_at` | timestamptz |  |  |  |
| `ok` | boolean |  |  | true = pengecekan berhasil. |
| `latency_ms` | double precision | ? |  | NULL bila gagal. |

**Indeks dan constraint**

- INDEX `monitor_target_checks_monitor_target_id_checked_at_index` (monitor_target_id, checked_at)
- INDEX `monitor_target_checks_checked_at_index` (checked_at)

**Dipakai oleh** (hasil pencarian nama model/SQL di `server/app`, tidak membedakan baca/tulis): `Http/Controllers/Web/NetworkMonitorController (SQL langsung)`, `Services/RetentionService`, `Services/TargetMonitorService` dan 1 lainnya.

**Retensi**: Dihapus setelah `NETWATCH_RETENTION_MONITOR` (7 hari).

**Backup**: strukturnya ikut, **isinya dilewati** (bawaan `NETWATCH_BACKUP_EXCLUDE_DATA`).

## Tiket dan penilaian layanan

### `tickets`

Tiket permintaan bantuan (dari form pengguna atau dibuat admin).

| Kolom | Tipe | Null | Default | Keterangan |
|---|---|---|---|---|
| `id` | bigint |  |  | **PK** (auto-increment) |
| `device_id` | bigint | ? |  | FK -> `devices.id` ON DELETE SET NULL |
| `requester_name` | varchar(100) |  |  |  |
| `requester_email` | varchar(150) | ? |  |  |
| `subject` | varchar(150) |  |  |  |
| `description` | text |  |  |  |
| `category` | varchar(20) |  | `other` | network / hardware / software / account / other (CHECK). |
| `priority` | varchar(10) |  | `normal` | low / normal / high (CHECK). |
| `status` | varchar(20) |  | `open` | open / in_progress / resolved (CHECK). |
| `source` | varchar(10) |  | `web` | web (form pengguna) / admin (CHECK). |
| `assigned_to` | bigint | ? |  | FK -> `users.id` ON DELETE SET NULL · Staf penanggung jawab. |
| `created_by` | bigint | ? |  | FK -> `users.id` ON DELETE SET NULL |
| `ip_address` | inet | ? |  | IP pengirim form. |
| `created_at` | timestamptz | ? |  |  |
| `updated_at` | timestamptz | ? |  |  |
| `resolved_at` | timestamptz | ? |  |  |
| `resolved_by` | bigint | ? |  | FK -> `users.id` ON DELETE SET NULL · Staf yang menutup tiket. |

**Indeks dan constraint**

- INDEX `tickets_status_created_at_index` (status, created_at)
- INDEX `tickets_assigned_to_index` (assigned_to)
- INDEX `tickets_device_id_index` (device_id)
- CHECK `tickets_status_check`: `status IN ('open', 'in_progress', 'resolved')`
- CHECK `tickets_priority_check`: `priority IN ('low', 'normal', 'high')`
- CHECK `tickets_category_check`: `category IN ('network', 'hardware', 'software', 'account', 'other')`
- CHECK `tickets_source_check`: `source IN ('web', 'admin')`

**Dipakai oleh** (hasil pencarian nama model/SQL di `server/app`, tidak membedakan baca/tulis): `Http/Controllers/Web/TicketController`, `Services/FeedbackService`, `Services/TicketNotifier`, `Services/TicketReportService (SQL langsung)`, `Services/TicketService` dan 1 lainnya.

### `ticket_comments`

Komentar pada tiket; `is_internal=true` hanya terlihat staf.

| Kolom | Tipe | Null | Default | Keterangan |
|---|---|---|---|---|
| `id` | bigint |  |  | **PK** (auto-increment) |
| `ticket_id` | bigint |  |  | FK -> `tickets.id` ON DELETE CASCADE |
| `user_id` | bigint | ? |  | FK -> `users.id` ON DELETE SET NULL |
| `body` | text |  |  |  |
| `is_internal` | boolean |  | false | true = catatan internal. |
| `created_at` | timestamptz |  |  |  |

**Indeks dan constraint**

- INDEX `ticket_comments_ticket_id_created_at_index` (ticket_id, created_at)

**Dipakai oleh** (hasil pencarian nama model/SQL di `server/app`, tidak membedakan baca/tulis): `Services/TicketService` dan 1 lainnya.

### `ticket_feedback`

Penilaian layanan sekali pakai per tiket: kode (hash) dikirim ke pengguna, rating 1-5 diisi sekali.

| Kolom | Tipe | Null | Default | Keterangan |
|---|---|---|---|---|
| `id` | bigint |  |  | **PK** (auto-increment) |
| `ticket_id` | bigint |  |  | FK -> `tickets.id` ON DELETE CASCADE |
| `code_hash` | char(64) |  |  | SHA-256 kode penilaian sekali pakai (unik). |
| `expires_at` | timestamptz |  |  | Kode kedaluwarsa setelah ini. |
| `sent_via` | varchar(10) | ? |  | Saluran pengiriman kode. |
| `rating` | smallint | ? |  | 1-5, NULL = belum diisi (CHECK). |
| `comment` | text | ? |  |  |
| `submitted_at` | timestamptz | ? |  | NULL = belum diisi. |
| `created_at` | timestamptz |  |  |  |

**Indeks dan constraint**

- UNIQUE `ticket_feedback_ticket_id_unique` (ticket_id)
- UNIQUE `ticket_feedback_code_hash_unique` (code_hash)
- INDEX `ticket_feedback_submitted_at_index` (submitted_at)
- CHECK `ticket_feedback_rating_check`: `rating IS NULL OR rating BETWEEN 1 AND 5`

**Dipakai oleh** (hasil pencarian nama model/SQL di `server/app`, tidak membedakan baca/tulis): `Services/FeedbackService`, `Services/TicketReportService (SQL langsung)` dan 1 lainnya.

## Cara membaca data saat troubleshooting

Semua query di bawah **hanya membaca** (SELECT). Jalankan sebagai role `netwatch` (search_path sudah mengarah ke schema NetWatch). Untuk database bersama, pastikan `SHOW search_path;` = `netwatch`. Waktu tersimpan UTC; WIB = UTC+7.

```sql
-- 1. Perangkat yang paling lama tidak mengirim heartbeat (kandidat offline). NULL = belum pernah terlihat.
SELECT id, hostname, last_seen_at, now() - last_seen_at AS senyap
FROM devices WHERE revoked_at IS NULL ORDER BY last_seen_at NULLS FIRST LIMIT 20;

-- 2. Riwayat online/offline satu perangkat (ganti 1 dengan id perangkat). ended_at kosong = interval berjalan.
SELECT status, started_at, ended_at, reason
FROM device_status_history WHERE device_id = 1 ORDER BY started_at DESC LIMIT 20;

-- 3. Apakah heartbeat satu perangkat masuk teratur? Kolom jeda normalnya ±30 detik.
SELECT recorded_at, cpu_percent, memory_used_mb,
       recorded_at - lag(recorded_at) OVER (ORDER BY recorded_at) AS jeda
FROM device_heartbeats WHERE device_id = 1 ORDER BY recorded_at DESC LIMIT 20;

-- 4. Event 24 jam terakhir dikelompokkan per kode (mana yang paling sering muncul).
SELECT code, severity, count(*) AS jumlah, max(occurred_at) AS terakhir
FROM events WHERE occurred_at > now() - interval '24 hours'
GROUP BY code, severity ORDER BY terakhir DESC;

-- 5. Antrean notifikasi: event yang belum diproses pengirim (harusnya hampir kosong bila scheduler jalan).
SELECT id, code, severity, occurred_at, notified_at, notification_sent
FROM events WHERE notified_at IS NULL ORDER BY id LIMIT 50;

-- 6. Perintah yang tersangkut (belum diambil agent atau belum dilaporkan hasilnya).
SELECT c.public_id, d.hostname, c.type, c.status, c.created_at, c.expires_at, c.error_message
FROM device_commands c JOIN devices d ON d.id = c.device_id
WHERE c.status IN ('pending', 'dispatched') ORDER BY c.created_at LIMIT 50;

-- 7. Target jaringan (NOC) yang tidak online beserta sejak kapan.
SELECT name, address, method, port, status, consecutive_failures, latency_ms, last_checked_at, status_since
FROM monitor_targets WHERE is_enabled AND status <> 'online' ORDER BY status_since;

-- 8. 30 hasil pengecekan terakhir satu target (ganti 1 dengan id target). ok=false -> gagal.
SELECT checked_at, ok, latency_ms
FROM monitor_target_checks WHERE monitor_target_id = 1 ORDER BY checked_at DESC LIMIT 30;

-- 9. Perangkat yang policy-nya belum diterapkan agent (error atau belum pernah lapor).
SELECT hostname, policy_hash, policy_error, policy_reported_at
FROM devices WHERE revoked_at IS NULL AND (policy_error IS NOT NULL OR policy_reported_at IS NULL) ORDER BY hostname;

-- 10. Token pendaftaran yang masih bisa dipakai.
SELECT label, used_count, max_uses, expires_at
FROM enrollment_tokens WHERE revoked_at IS NULL AND expires_at > now() AND used_count < max_uses;

-- 11. Ukuran tabel NetWatch di schema aktif (15 terbesar).
SELECT c.relname AS tabel, pg_size_pretty(pg_total_relation_size(c.oid)) AS ukuran, c.reltuples::bigint AS perkiraan_baris
FROM pg_class c JOIN pg_namespace ns ON ns.oid = c.relnamespace
WHERE ns.nspname = current_schema() AND c.relkind = 'r'
ORDER BY pg_total_relation_size(c.oid) DESC LIMIT 15;

-- 12. Data tertua per tabel berretensi: bila jauh melewati masa simpan, netwatch:prune tidak berjalan.
SELECT 'device_heartbeats' AS tabel, min(recorded_at) AS tertua FROM device_heartbeats
UNION ALL SELECT 'events', min(occurred_at) FROM events
UNION ALL SELECT 'interface_samples', min(sampled_at) FROM interface_samples
UNION ALL SELECT 'browsing_visits', min(visited_at) FROM browsing_visits
UNION ALL SELECT 'monitor_target_checks', min(checked_at) FROM monitor_target_checks;

-- 13. 30 tindakan terakhir di dashboard (audit log).
SELECT a.created_at, u.email, a.action, a.ip_address, a.metadata
FROM audit_logs a LEFT JOIN users u ON u.id = a.user_id ORDER BY a.created_at DESC LIMIT 30;

-- 14. Alert ambang yang sedang menyala.
SELECT r.name, r.metric, r.threshold, d.hostname, s.firing_since, s.last_value
FROM alert_states s JOIN alert_rules r ON r.id = s.alert_rule_id JOIN devices d ON d.id = s.device_id
WHERE s.firing_since IS NOT NULL ORDER BY s.firing_since;

-- 15. Migrasi terakhir yang tercatat (cek apakah migrate sudah dijalankan setelah pembaruan).
SELECT migration, batch FROM migrations ORDER BY id DESC LIMIT 5;
```

