# ERD (Entity Relationship Diagram) NetWatch

Dokumen ini menggambarkan struktur basis data **keadaan akhir setelah seluruh 27 migrasi dijalankan** (31 tabel). Sumber: `server/database/migrations/*.php` dan relasi Eloquent di `server/app/Models/`. Kolom, tipe, dan indeks lengkap per tabel ada di `docs/KAMUS-DATA.md`; skema referensi lama `docs/schema.sql` hanya pembanding (bisa tertinggal dari migrasi).

Cara membaca notasi (crow's foot): `||` tepat satu, `|o` nol atau satu, `o{` nol atau banyak. Contoh `devices ||--o{ device_heartbeats` = satu perangkat punya banyak heartbeat; `device_groups |o--o{ devices` = grup boleh tidak ada (kolom FK boleh NULL).

Daftar isi: [1. Ikhtisar](#1-ikhtisar-seluruh-relasi) · [2. Diagram per domain](#2-diagram-per-domain) · [3. Daftar relasi](#3-daftar-relasi-foreign-key) · [4. Relasi tanpa foreign key](#4-relasi-logis-tanpa-foreign-key) · [5. Dampak penghapusan](#5-dampak-penghapusan-data) · [6. Catatan teknis](#6-catatan-teknis)

## 1. Ikhtisar seluruh relasi

Hanya relasi (tanpa kolom) agar seluruh tabel muat dalam satu gambar. Relasi ke `users` (kolom `created_by`, `requested_by`, `assigned_to`, `resolved_by`, `user_id`, `updated_by`) **tidak digambar** karena hampir semua tabel menunjuk ke sana dan membuat gambar tak terbaca; lihat tabel di bagian 3 dan diagram per domain. Tabel `sessions` tidak punya foreign key (bagian 4).

```mermaid
erDiagram
    device_groups |o--o{ alert_rules : "device_group_id"
    devices |o--o{ alert_rules : "device_id"
    alert_rules ||--o{ alert_states : "alert_rule_id"
    devices ||--o{ alert_states : "device_id"
    devices |o--o{ audit_logs : "device_id"
    policies ||--o{ block_rules : "policy_id"
    devices ||--o{ browsing_daily : "device_id"
    devices ||--o{ browsing_visits : "device_id"
    devices ||--o{ device_commands : "device_id"
    devices ||--|| device_hardware : "device_id"
    devices ||--o{ device_heartbeats : "device_id"
    devices ||--o{ device_interfaces : "device_id"
    devices ||--o{ device_ports : "device_id"
    devices ||--o{ device_services : "device_id"
    devices ||--o{ device_software : "device_id"
    devices ||--o{ device_status_history : "device_id"
    device_groups |o--o{ devices : "device_group_id"
    enrollment_tokens |o--o{ devices : "enrollment_token_id"
    device_groups |o--o{ enrollment_tokens : "device_group_id"
    devices |o--o{ events : "device_id"
    device_interfaces ||--o{ interface_samples : "device_interface_id"
    device_interfaces ||--o{ interface_samples_hourly : "device_interface_id"
    monitor_targets ||--o{ monitor_target_checks : "monitor_target_id"
    device_groups |o--o{ policies : "device_group_id"
    devices |o--o{ policies : "device_id"
    devices ||--o{ process_snapshots : "device_id"
    tickets ||--o{ ticket_comments : "ticket_id"
    tickets ||--|| ticket_feedback : "ticket_id"
    devices |o--o{ tickets : "device_id"
```

## 2. Diagram per domain

Menampilkan kolom kunci (PK, FK) dan kolom yang paling sering dipakai saat troubleshooting. Tabel dari domain lain yang menjadi induk ikut digambar tanpa kolom detail bila perlu.

### 2.1 Identitas, akses, dan pengaturan

```mermaid
erDiagram
    users {
        bigint id PK
        varchar email
        varchar role
        boolean is_active
    }
    sessions {
        varchar id PK
        bigint user_id
        integer last_activity
    }
    device_groups {
        bigint id PK
        varchar name
    }
    enrollment_tokens {
        bigint id PK
        varchar label
        bigint device_group_id FK
        smallint max_uses
        smallint used_count
        timestamptz expires_at
        timestamptz revoked_at
        bigint created_by FK
    }
    audit_logs {
        bigint id PK
        bigint user_id FK
        bigint device_id FK
        varchar action
        timestamptz created_at
    }
    feature_flags {
        varchar key PK
        boolean enabled
        bigint updated_by FK
    }
    devices {
        bigint id PK
        uuid public_id
        bigint device_group_id FK
        bigint enrollment_token_id FK
        varchar hostname
        varchar os_family
        varchar agent_version
        timestamptz last_seen_at
        timestamptz revoked_at
        timestamptz maintenance_until
    }
    users |o--o{ audit_logs : "user_id"
    devices |o--o{ audit_logs : "device_id"
    device_groups |o--o{ devices : "device_group_id"
    enrollment_tokens |o--o{ devices : "enrollment_token_id"
    device_groups |o--o{ enrollment_tokens : "device_group_id"
    users |o--o{ enrollment_tokens : "created_by"
    users |o--o{ feature_flags : "updated_by"
```

Tabel induk dari domain lain yang ikut digambar: `devices`.

### 2.2 Perangkat dan telemetri

```mermaid
erDiagram
    devices {
        bigint id PK
        uuid public_id
        bigint device_group_id FK
        bigint enrollment_token_id FK
        varchar hostname
        varchar os_family
        varchar agent_version
        timestamptz last_seen_at
        timestamptz revoked_at
        timestamptz maintenance_until
    }
    device_heartbeats {
        bigint id PK
        bigint device_id FK
        numeric cpu_percent
        integer memory_used_mb
        timestamptz recorded_at
    }
    process_snapshots {
        bigint id PK
        bigint device_id FK
        timestamptz captured_at
        integer process_count
    }
    device_status_history {
        bigint id PK
        bigint device_id FK
        varchar status
        timestamptz started_at
        timestamptz ended_at
        varchar reason
    }
    device_commands {
        bigint id PK
        uuid public_id
        bigint device_id FK
        bigint requested_by FK
        varchar type
        varchar status
        timestamptz expires_at
    }
    users {
        bigint id PK
        varchar email
        varchar role
        boolean is_active
    }
    device_groups {
        bigint id PK
        varchar name
    }
    enrollment_tokens {
        bigint id PK
        varchar label
        bigint device_group_id FK
        smallint max_uses
        smallint used_count
        timestamptz expires_at
        timestamptz revoked_at
        bigint created_by FK
    }
    devices ||--o{ device_commands : "device_id"
    users |o--o{ device_commands : "requested_by"
    devices ||--o{ device_heartbeats : "device_id"
    devices ||--o{ device_status_history : "device_id"
    device_groups |o--o{ devices : "device_group_id"
    enrollment_tokens |o--o{ devices : "enrollment_token_id"
    device_groups |o--o{ enrollment_tokens : "device_group_id"
    users |o--o{ enrollment_tokens : "created_by"
    devices ||--o{ process_snapshots : "device_id"
```

Tabel induk dari domain lain yang ikut digambar: `users`, `device_groups`, `enrollment_tokens`.

### 2.3 Inventaris perangkat

```mermaid
erDiagram
    device_hardware {
        bigint id PK
        bigint device_id FK
        varchar manufacturer
        varchar model
        varchar serial
    }
    device_software {
        bigint id PK
        bigint device_id FK
        varchar name
        varchar version
        timestamptz last_seen_at
    }
    device_ports {
        bigint id PK
        bigint device_id FK
        varchar protocol
        integer port
        boolean exposed
    }
    device_services {
        bigint id PK
        bigint device_id FK
        varchar name
        varchar state
    }
    devices {
        bigint id PK
        uuid public_id
        bigint device_group_id FK
        bigint enrollment_token_id FK
        varchar hostname
        varchar os_family
        varchar agent_version
        timestamptz last_seen_at
        timestamptz revoked_at
        timestamptz maintenance_until
    }
    devices ||--|| device_hardware : "device_id"
    devices ||--o{ device_ports : "device_id"
    devices ||--o{ device_services : "device_id"
    devices ||--o{ device_software : "device_id"
```

Tabel induk dari domain lain yang ikut digambar: `devices`.

### 2.4 Bandwidth dan riwayat browsing

```mermaid
erDiagram
    device_interfaces {
        bigint id PK
        bigint device_id FK
        varchar name
        timestamptz last_sample_at
    }
    interface_samples {
        bigint id PK
        bigint device_interface_id FK
        timestamptz sampled_at
        bigint rx_bytes
        bigint tx_bytes
    }
    interface_samples_hourly {
        bigint id PK
        bigint device_interface_id FK
        timestamptz hour
        bigint rx_bytes
        bigint tx_bytes
    }
    browsing_visits {
        bigint id PK
        bigint device_id FK
        timestamptz visited_at
        varchar kind
        varchar host
        char dedupe_hash
    }
    browsing_daily {
        bigint id PK
        bigint device_id FK
        date day
        varchar kind
        varchar host
        bigint visits
    }
    browsing_ignored_hosts {
        bigint id PK
        varchar domain
        bigint created_by FK
    }
    devices {
        bigint id PK
        uuid public_id
        bigint device_group_id FK
        bigint enrollment_token_id FK
        varchar hostname
        varchar os_family
        varchar agent_version
        timestamptz last_seen_at
        timestamptz revoked_at
        timestamptz maintenance_until
    }
    users {
        bigint id PK
        varchar email
        varchar role
        boolean is_active
    }
    devices ||--o{ browsing_daily : "device_id"
    users |o--o{ browsing_ignored_hosts : "created_by"
    devices ||--o{ browsing_visits : "device_id"
    devices ||--o{ device_interfaces : "device_id"
    device_interfaces ||--o{ interface_samples : "device_interface_id"
    device_interfaces ||--o{ interface_samples_hourly : "device_interface_id"
```

Tabel induk dari domain lain yang ikut digambar: `devices`, `users`.

### 2.5 Policy, alert, event, dan jaringan (NOC)

```mermaid
erDiagram
    policies {
        bigint id PK
        varchar name
        boolean is_enabled
        bigint device_group_id FK
        bigint device_id FK
        bigint created_by FK
    }
    block_rules {
        bigint id PK
        varchar type
        varchar value
        boolean is_enabled
        bigint created_by FK
        bigint policy_id FK
    }
    alert_rules {
        bigint id PK
        varchar name
        varchar metric
        smallint threshold
        smallint duration_minutes
        bigint device_group_id FK
        bigint device_id FK
        bigint created_by FK
    }
    alert_states {
        bigint id PK
        bigint alert_rule_id FK
        bigint device_id FK
        timestamptz firing_since
        numeric last_value
    }
    events {
        bigint id PK
        bigint device_id FK
        bigint user_id FK
        varchar category
        varchar severity
        varchar code
        timestamptz occurred_at
        timestamptz notified_at
        boolean notification_sent
    }
    monitor_targets {
        bigint id PK
        varchar name
        varchar address
        varchar method
        varchar status
        smallint consecutive_failures
        bigint created_by FK
    }
    monitor_target_checks {
        bigint id PK
        bigint monitor_target_id FK
        timestamptz checked_at
        boolean ok
        double_precision latency_ms
    }
    device_groups {
        bigint id PK
        varchar name
    }
    devices {
        bigint id PK
        uuid public_id
        bigint device_group_id FK
        bigint enrollment_token_id FK
        varchar hostname
        varchar os_family
        varchar agent_version
        timestamptz last_seen_at
        timestamptz revoked_at
        timestamptz maintenance_until
    }
    users {
        bigint id PK
        varchar email
        varchar role
        boolean is_active
    }
    device_groups |o--o{ alert_rules : "device_group_id"
    devices |o--o{ alert_rules : "device_id"
    users |o--o{ alert_rules : "created_by"
    alert_rules ||--o{ alert_states : "alert_rule_id"
    devices ||--o{ alert_states : "device_id"
    users |o--o{ block_rules : "created_by"
    policies ||--o{ block_rules : "policy_id"
    device_groups |o--o{ devices : "device_group_id"
    devices |o--o{ events : "device_id"
    users |o--o{ events : "user_id"
    monitor_targets ||--o{ monitor_target_checks : "monitor_target_id"
    users |o--o{ monitor_targets : "created_by"
    device_groups |o--o{ policies : "device_group_id"
    devices |o--o{ policies : "device_id"
    users |o--o{ policies : "created_by"
```

Tabel induk dari domain lain yang ikut digambar: `device_groups`, `devices`, `users`.

### 2.6 Tiket dan penilaian layanan

```mermaid
erDiagram
    tickets {
        bigint id PK
        bigint device_id FK
        varchar subject
        varchar category
        varchar priority
        varchar status
        bigint assigned_to FK
        bigint created_by FK
        bigint resolved_by FK
    }
    ticket_comments {
        bigint id PK
        bigint ticket_id FK
        bigint user_id FK
        boolean is_internal
        timestamptz created_at
    }
    ticket_feedback {
        bigint id PK
        bigint ticket_id FK
        smallint rating
        timestamptz submitted_at
    }
    users {
        bigint id PK
        varchar email
        varchar role
        boolean is_active
    }
    devices {
        bigint id PK
        uuid public_id
        bigint device_group_id FK
        bigint enrollment_token_id FK
        varchar hostname
        varchar os_family
        varchar agent_version
        timestamptz last_seen_at
        timestamptz revoked_at
        timestamptz maintenance_until
    }
    tickets ||--o{ ticket_comments : "ticket_id"
    users |o--o{ ticket_comments : "user_id"
    tickets ||--|| ticket_feedback : "ticket_id"
    devices |o--o{ tickets : "device_id"
    users |o--o{ tickets : "assigned_to"
    users |o--o{ tickets : "created_by"
    users |o--o{ tickets : "resolved_by"
```

Tabel induk dari domain lain yang ikut digambar: `users`, `devices`.

## 3. Daftar relasi (foreign key)

Semua relasi di bawah adalah foreign key sungguhan di PostgreSQL. "Wajib" berarti kolom NOT NULL.

| Tabel anak | Kolom | Tabel induk | Kardinalitas | Wajib? | ON DELETE |
|---|---|---|---|---|---|
| `alert_rules` | `created_by` | `users.id` | 1 : banyak | tidak (boleh NULL) | SET NULL |
| `alert_rules` | `device_group_id` | `device_groups.id` | 1 : banyak | tidak (boleh NULL) | CASCADE |
| `alert_rules` | `device_id` | `devices.id` | 1 : banyak | tidak (boleh NULL) | CASCADE |
| `alert_states` | `alert_rule_id` | `alert_rules.id` | 1 : banyak | ya | CASCADE |
| `alert_states` | `device_id` | `devices.id` | 1 : banyak | ya | CASCADE |
| `audit_logs` | `device_id` | `devices.id` | 1 : banyak | tidak (boleh NULL) | SET NULL |
| `audit_logs` | `user_id` | `users.id` | 1 : banyak | tidak (boleh NULL) | SET NULL |
| `block_rules` | `created_by` | `users.id` | 1 : banyak | tidak (boleh NULL) | SET NULL |
| `block_rules` | `policy_id` | `policies.id` | 1 : banyak | ya | CASCADE |
| `browsing_daily` | `device_id` | `devices.id` | 1 : banyak | ya | CASCADE |
| `browsing_ignored_hosts` | `created_by` | `users.id` | 1 : banyak | tidak (boleh NULL) | SET NULL |
| `browsing_visits` | `device_id` | `devices.id` | 1 : banyak | ya | CASCADE |
| `device_commands` | `device_id` | `devices.id` | 1 : banyak | ya | CASCADE |
| `device_commands` | `requested_by` | `users.id` | 1 : banyak | tidak (boleh NULL) | SET NULL |
| `device_hardware` | `device_id` | `devices.id` | 1 : 0..1 | ya | CASCADE |
| `device_heartbeats` | `device_id` | `devices.id` | 1 : banyak | ya | CASCADE |
| `device_interfaces` | `device_id` | `devices.id` | 1 : banyak | ya | CASCADE |
| `device_ports` | `device_id` | `devices.id` | 1 : banyak | ya | CASCADE |
| `device_services` | `device_id` | `devices.id` | 1 : banyak | ya | CASCADE |
| `device_software` | `device_id` | `devices.id` | 1 : banyak | ya | CASCADE |
| `device_status_history` | `device_id` | `devices.id` | 1 : banyak | ya | CASCADE |
| `devices` | `device_group_id` | `device_groups.id` | 1 : banyak | tidak (boleh NULL) | SET NULL |
| `devices` | `enrollment_token_id` | `enrollment_tokens.id` | 1 : banyak | tidak (boleh NULL) | SET NULL |
| `enrollment_tokens` | `created_by` | `users.id` | 1 : banyak | tidak (boleh NULL) | SET NULL |
| `enrollment_tokens` | `device_group_id` | `device_groups.id` | 1 : banyak | tidak (boleh NULL) | SET NULL |
| `events` | `device_id` | `devices.id` | 1 : banyak | tidak (boleh NULL) | SET NULL |
| `events` | `user_id` | `users.id` | 1 : banyak | tidak (boleh NULL) | SET NULL |
| `feature_flags` | `updated_by` | `users.id` | 1 : banyak | tidak (boleh NULL) | SET NULL |
| `interface_samples` | `device_interface_id` | `device_interfaces.id` | 1 : banyak | ya | CASCADE |
| `interface_samples_hourly` | `device_interface_id` | `device_interfaces.id` | 1 : banyak | ya | CASCADE |
| `monitor_target_checks` | `monitor_target_id` | `monitor_targets.id` | 1 : banyak | ya | CASCADE |
| `monitor_targets` | `created_by` | `users.id` | 1 : banyak | tidak (boleh NULL) | SET NULL |
| `policies` | `created_by` | `users.id` | 1 : banyak | tidak (boleh NULL) | SET NULL |
| `policies` | `device_group_id` | `device_groups.id` | 1 : banyak | tidak (boleh NULL) | CASCADE |
| `policies` | `device_id` | `devices.id` | 1 : banyak | tidak (boleh NULL) | CASCADE |
| `process_snapshots` | `device_id` | `devices.id` | 1 : banyak | ya | CASCADE |
| `ticket_comments` | `ticket_id` | `tickets.id` | 1 : banyak | ya | CASCADE |
| `ticket_comments` | `user_id` | `users.id` | 1 : banyak | tidak (boleh NULL) | SET NULL |
| `ticket_feedback` | `ticket_id` | `tickets.id` | 1 : 0..1 | ya | CASCADE |
| `tickets` | `assigned_to` | `users.id` | 1 : banyak | tidak (boleh NULL) | SET NULL |
| `tickets` | `created_by` | `users.id` | 1 : banyak | tidak (boleh NULL) | SET NULL |
| `tickets` | `device_id` | `devices.id` | 1 : banyak | tidak (boleh NULL) | SET NULL |
| `tickets` | `resolved_by` | `users.id` | 1 : banyak | tidak (boleh NULL) | SET NULL |

Total 43 foreign key.

## 4. Relasi logis tanpa foreign key

Hubungan berikut dipakai aplikasi tetapi **tidak dijaga oleh database** — data bisa tidak sinkron bila salah satu sisi berubah di luar aplikasi.

| Hubungan | Penjelasan |
|---|---|
| `sessions.user_id` -> `users.id` | Kolom ada dan berindeks tetapi tanpa foreign key; sesi milik pengguna yang dihapus tidak otomatis hilang (kedaluwarsa sendiri). |
| `events.code` -> katalog `App\Support\EventCatalog` | Kode event adalah teks; arti, kategori, dan petunjuk diambil dari katalog di kode (lihat `docs/KODE-EVENT.md`). |
| `feature_flags.key` -> enum `App\Enums\Feature` | Kunci fitur dibaca oleh `FeatureFlags`; baris tidak ada = nilai bawaan fitur. |
| `interface_samples` -> `interface_samples_hourly` | Rangkuman per jam dibuat oleh `netwatch:rollup-bandwidth`; keduanya berelasi ke `device_interfaces` tetapi tidak saling terhubung. |
| `browsing_visits` -> `browsing_daily` | Rangkuman harian oleh `BrowsingRollup` saat `netwatch:prune`; hubungan lewat (device_id, tanggal, host/ip, kind). |
| `devices` -> `device_status_history` (interval terbuka) | Maksimal satu baris `ended_at IS NULL` per perangkat dijaga indeks unik parsial, bukan FK terpisah. |
| `devices.policy_hash` <-> hash policy yang diinginkan | Dibandingkan di aplikasi (`PolicyStatusResolver`); tidak ada tabel yang menyimpan hash "diinginkan". |
| `tickets.requester_name/email` | Identitas pemohon berupa teks bebas dari form publik; tidak terhubung ke `users`. |
| Tabel Laravel `migrations` | Dibuat Laravel saat `migrate` (bukan dari berkas migrasi NetWatch): mencatat migrasi mana yang sudah dijalankan. Tidak digambar di atas. |

## 5. Dampak penghapusan data

Berguna saat menghapus perangkat/grup/pengguna di dashboard dan menelusuri "kenapa datanya hilang / kenapa kolomnya jadi kosong".

**Menghapus baris `devices`**
- ikut **terhapus**: `alert_rules`, `alert_states`, `browsing_daily`, `browsing_visits`, `device_commands`, `device_hardware`, `device_heartbeats`, `device_interfaces`, `device_ports`, `device_services`, `device_software`, `device_status_history`, `policies`, `process_snapshots`
- kolom jadi **NULL** (baris tetap ada): `audit_logs.device_id`, `events.device_id`, `tickets.device_id`

**Menghapus baris `device_groups`**
- ikut **terhapus**: `alert_rules`, `policies`
- kolom jadi **NULL** (baris tetap ada): `devices.device_group_id`, `enrollment_tokens.device_group_id`

**Menghapus baris `users`**
- kolom jadi **NULL** (baris tetap ada): `alert_rules.created_by`, `audit_logs.user_id`, `block_rules.created_by`, `browsing_ignored_hosts.created_by`, `device_commands.requested_by`, `enrollment_tokens.created_by`, `events.user_id`, `feature_flags.updated_by`, `monitor_targets.created_by`, `policies.created_by`, `ticket_comments.user_id`, `tickets.assigned_to`, `tickets.created_by`, `tickets.resolved_by`

**Menghapus baris `policies`**
- ikut **terhapus**: `block_rules`

**Menghapus baris `tickets`**
- ikut **terhapus**: `ticket_comments`, `ticket_feedback`

**Menghapus baris `alert_rules`**
- ikut **terhapus**: `alert_states`

**Menghapus baris `device_interfaces`**
- ikut **terhapus**: `interface_samples`, `interface_samples_hourly`

**Menghapus baris `monitor_targets`**
- ikut **terhapus**: `monitor_target_checks`

**Menghapus baris `enrollment_tokens`**
- kolom jadi **NULL** (baris tetap ada): `devices.enrollment_token_id`

## 6. Catatan teknis

- **Semua waktu UTC.** Koneksi database diatur `timezone=UTC`; tampilan dashboard dikonversi ke `NETWATCH_TIMEZONE`.
- Tabel yang kolom `created_at/updated_at`-nya bertipe `timestamp` **tanpa zona waktu** (dibuat dengan `timestamps()`, berbeda dari tabel lain yang `timestamptz`): `alert_rules`, `monitor_targets`. Tidak bermasalah selama sesi UTC, tetapi perlu diingat saat membandingkan waktu dengan tabel lain.
- Kolom `jsonb` (8): `audit_logs.metadata`, `device_commands.payload`, `device_commands.result`, `device_hardware.disks`, `devices.latest_metrics`, `events.details`, `process_snapshots.processes`, `users.dashboard_widgets`.
- Tipe PostgreSQL khusus: `inet` (`devices.ip_address`, `audit_logs.ip_address`, `tickets.ip_address`), `macaddr` (`devices.mac_address`), `uuid` (`devices.public_id`, `device_commands.public_id`). Migrasi memakai indeks parsial/ekspresi dan CHECK constraint lewat SQL mentah, jadi **hanya PostgreSQL yang didukung** (tes pun wajib pgsql).
- Tabel yang tidak punya model Eloquent: `sessions` (dikelola Laravel). Selain itu tiap tabel punya model di `server/app/Models`.
- Semua perubahan skema datang dari migrasi; jangan mengubah tabel manual di produksi. Setelah memperbarui aplikasi jalankan `php artisan migrate --force` (lihat `docs/DEPLOY-LINUX.md`).
