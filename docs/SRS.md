# Software Requirements Specification (SRS) — NetWatch v1.6

Format mengikuti IEEE 830 yang diringkas. Seluruh kebutuhan di bawah **sudah diimplementasikan** dan ditautkan ke kode/dokumen; bukan daftar keinginan. Kebutuhan yang belum ada tertulis di §9.

## 1. Pendahuluan
**Tujuan.** Menentukan kebutuhan fungsional dan nonfungsional NetWatch untuk pengembangan, pengujian, dan troubleshooting.
**Lingkup.** Aplikasi web untuk memantau PC dan perangkat jaringan kantor, melakukan perawatan jarak jauh (cek task manager, kirim pesan, restart/shutdown, blokir situs), serta mengelola tiket bantuan. Terdiri dari **Server** (Laravel + PostgreSQL) dan **Agent** (PHP) di PC klien.
**Definisi.** *Agent*: program di PC klien. *Heartbeat*: laporan berkala agent. *Enrollment*: pendaftaran PC. *NOC*: pemantauan perangkat jaringan tanpa agent. *Policy*: kumpulan aturan blokir.
**Rujukan.** [`ARSITEKTUR.md`](ARSITEKTUR.md), [`ERD.md`](ERD.md), [`KAMUS-DATA.md`](KAMUS-DATA.md), [`API-AGENT.md`](API-AGENT.md), [`ACTIVITY-DIAGRAM-PERANGKAT.md`](ACTIVITY-DIAGRAM-PERANGKAT.md), [`ACTIVITY-DIAGRAM-SERVER.md`](ACTIVITY-DIAGRAM-SERVER.md).

## 2. Gambaran umum
**Pengguna.**

| Peran | Hak |
|---|---|
| Viewer | Melihat dashboard, perangkat, event, laporan |
| Operator | Viewer + perintah ke PC + menangani tiket |
| Admin | Operator + kelola perangkat, grup, token, policy, alert, jaringan, fitur, pengguna, keamanan, database, audit |
| Pengguna akhir (tanpa login) | Membuat tiket dan memberi penilaian |

**Lingkungan.** Server: Linux (Debian/Ubuntu), Nginx, PHP 8.3, Laravel 12/13, PostgreSQL ≥ 14. Agent: Windows 10/11 (penuh) dan Linux (tanpa blokir). Pengembangan/uji: Windows + Laragon.
**Asumsi.** Jaringan kantor menjangkau server lewat HTTPS; scheduler (cron) berjalan; jam server benar.

## 3. Kebutuhan fungsional

| ID | Kebutuhan | Implementasi | Alur |
|---|---|---|---|
| **FR-1 Pendaftaran** | | | |
| FR-1.1 | Admin membuat token pendaftaran (masa berlaku, batas pakai, grup) | `EnrollmentService`, `EnrollmentTokenController` | A1 |
| FR-1.2 | Agent mendaftar dengan token; mendapat `api_token` sekali; server menyimpan hash | `EnrollController`, `TokenService` | A1 |
| FR-1.3 | Hostname aktif yang sama didaftarkan ulang, token lama batal | `EnrollmentService` | A1 |
| FR-1.4 | Admin dapat mencabut perangkat; agent berhenti | `DeviceService::revoke` | A11 |
| **FR-2 Pemantauan** | | | |
| FR-2.1 | Menerima heartbeat (CPU, RAM, disk, uptime, pengguna) | `HeartbeatController`, `HeartbeatService` | A3 |
| FR-2.2 | Menentukan online/offline dan menyimpan riwayat uptime | `StatusTracker`, `check-status` | A4 |
| FR-2.3 | Membedakan PC mati vs agent mati lewat ping server | `PingService` | A4 |
| FR-2.4 | Grafik metrik, laporan uptime, ekspor CSV | `DeviceMetricsController`, `UptimeReportService` | S9 |
| FR-2.5 | Mode maintenance menahan alert dan notifikasi | `MaintenanceService` | S2, S4 |
| FR-2.6 | Bandwidth per interface, tampilan perangkat dan armada | `BandwidthRecorder`, `FleetBandwidthService` | A8 |
| FR-2.7 | Inventaris port, layanan, perangkat keras, perangkat lunak; event perubahan | `InventoryRecorder`, `AssetRecorder` | A7 |
| FR-2.8 | Riwayat browsing (opsional) dan Event Log klien (opsional) | `BrowsingRecorder`, `WinlogRecorder` | A9 |
| **FR-3 Maintenance jarak jauh** | | | |
| FR-3.1 | Melihat daftar proses (task manager) per PC | `ProcessSnapshotService` | A10 |
| FR-3.2 | Mengakhiri proses (dengan perlindungan proses sistem dan cek PID) | `CommandService`, `CommandExecutor` | A5 |
| FR-3.3 | Mengirim pesan ke layar PC; restart/shutdown berjeda | `CommandService` | A5 |
| FR-3.4 | Perintah kedaluwarsa bila tak diambil dalam TTL | `ExpireCommandsCommand` | A5 |
| FR-3.5 | Policy blokir domain/IP per semua/grup/perangkat dengan verifikasi, deteksi drift, pelepasan di luar kantor, dan penolakan aturan yang memblokir server | `PolicyBuilder`, `PolicySync`, `PolicyApplier`, `BlockRuleGuard` | A6 |
| **FR-4 Alert dan notifikasi** | | | |
| FR-4.1 | Aturan ambang CPU/RAM/Disk per cakupan dan durasi | `AlertEvaluator`, `AlertDecision` | S2 |
| FR-4.2 | Notifikasi Telegram/email untuk offline, online, alert, target, tiket, gagal backup, dengan peringkasan dan penyaringan pasangan | `AlertDispatcher` | S4 |
| FR-4.3 | Event Viewer dengan kategori, tingkat, dan petunjuk | `EventCatalog`, `EventController` | – |
| **FR-5 NOC** | | | |
| FR-5.1 | Memantau target jaringan (ICMP/TCP) tiap menit; status Online/Lambat/Offline; riwayat cek | `TargetMonitorService`, `SystemTargetProber` | S3 |
| FR-5.2 | Alert suara di browser per komputer | `network-alert.js` | S5 |
| **FR-6 Tiket** | | | |
| FR-6.1 | Pengguna akhir membuat tiket tanpa login (anti-bot, throttle) | `PublicTicketController` | S6 |
| FR-6.2 | Petugas menangani tiket, komentar, status, prioritas | `TicketService` | S6 |
| FR-6.3 | Penilaian layanan lewat kode sekali pakai 14 hari (email/popup) | `FeedbackService`, `TicketNotifier` | S6 |
| **FR-7 Administrasi** | | | |
| FR-7.1 | Login, RBAC, kunci akun, perlindungan admin terakhir | `AuthController`, `UserChangeGuard` | S1 |
| FR-7.2 | Saklar fitur oleh admin yang menimpa `.env` | `FeatureFlags` | S1 |
| FR-7.3 | Audit log aksi pengguna | `AuditLogger` | – |
| FR-7.4 | Pemeriksaan keamanan konfigurasi dan armada | `SecurityChecker` | S10 |
| FR-7.5 | Backup terjadwal berverifikasi, rotasi, salinan kedua; statistik database | `BackupService`, `DatabaseHealthService` | S7 |
| FR-7.6 | Retensi otomatis dan rollup data | `RetentionService` | S8 |
| FR-7.7 | Database dapat berbagi dengan aplikasi lain lewat schema (`DB_SCHEMA`) | `config/database.php` | [`MERGE-DATABASE.md`](MERGE-DATABASE.md) |

## 4. Kebutuhan nonfungsional

| ID | Kategori | Kebutuhan | Bukti/mekanisme |
|---|---|---|---|
| NFR-1 | Keamanan | Token agent disimpan sebagai hash SHA-256; token asli ditampilkan sekali | `TokenService`, `devices.token_hash` |
| NFR-2 | Keamanan | HTTPS wajib; agent menolak HTTP; cookie `secure`, `httponly`, `samesite`, sesi terenkripsi | `ApiClient`, `SecurityChecker` |
| NFR-3 | Keamanan | Throttle login, enroll, API agent, tiket publik | `AppServiceProvider` |
| NFR-4 | Keamanan | Tanpa shell injection: perintah OS sebagai larik argumen; daftar putih perintah | `ProcessRunner`, `CommandExecutor` |
| NFR-5 | Keamanan | Output ke browser lewat `textContent` (anti XSS); validasi lewat FormRequest | `public/js/*`, `Requests/*` |
| NFR-6 | Keandalan | Agent tidak mati karena galat tak terduga; backoff berjitter | `Agent::run` |
| NFR-7 | Keandalan | Kiriman agent idempoten (kursor tak maju bila gagal; dedup di server) | `BrowsingRecorder`, `WinlogRecorder` |
| NFR-8 | Keandalan | Interval status unik per perangkat (indeks parsial) | `device_status_history` |
| NFR-9 | Kinerja | Heartbeat 240/menit per token; operasi massal per 10.000 baris; ping paralel 25; cache monitoring 4 dtk | konfigurasi |
| NFR-10 | Skalabilitas | Dirancang untuk ratusan PC; retensi mencegah pertumbuhan tak terbatas | [`KONFIGURASI.md`](KONFIGURASI.md) §7 |
| NFR-11 | Pemeliharaan | `strict_types`, lapisan Controller/Service/Support, logika murni teruji | `server/tests`, `agent/tests/run.php` |
| NFR-12 | Privasi | Browsing dan Event Log nonaktif bawaan, query pencarian tidak disimpan, tampilan hanya admin | [`BROWSING.md`](BROWSING.md) |
| NFR-13 | Ketersediaan data | Backup harian terverifikasi; latihan pemulihan berkala | [`BACKUP-DATABASE.md`](BACKUP-DATABASE.md) |
| NFR-14 | Lokalisasi | Antarmuka Bahasa Indonesia; waktu UTC di DB, tampil Asia/Jakarta | `NETWATCH_TIMEZONE` |

## 5. Antarmuka eksternal
- **Antarmuka pengguna:** web responsif, auto-refresh, mode monitor/TV.
- **API agent:** [`API-AGENT.md`](API-AGENT.md).
- **Basis data:** PostgreSQL ([`ERD.md`](ERD.md)).
- **Layanan luar:** Telegram Bot API, SMTP, `pg_dump`/`pg_restore`, `ping` OS.

## 6. Batasan
PostgreSQL saja; blokir hanya Windows; pesan layar butuh `msg.exe` (bukan Windows Home); hanya IP internal untuk NOC kecuali diizinkan; agent butuh PHP (dibundel installer Windows).

## 7. Matriks keterlacakan (ringkas)

| Kebutuhan | Dokumen | Tes otomatis |
|---|---|---|
| FR-1 | A1, API-AGENT §3.1 | `AgentApiTest`, `EnrollmentTokenWebTest`, agent `tests/run.php` |
| FR-2 | A3/A4/A8 | `AgentApiTest`, `StatusTrackerTest`, `UptimeFlowTest`, `BandwidthFlowTest`, `AssetInventoryTest` |
| FR-3 | A5/A6 | `BlockPolicyTest`, `PolicyGroupTest`, `BlockTargetTest`, `DevicePolicyTest` |
| FR-4 | S2/S4 | `AlertRulesTest`, `AlertAndMaintenanceTest`, `AlertDecisionTest`, `AlertMessageTest`, `EventViewerTest` |
| FR-5 | S3 | `NetworkMonitorTest`, `TargetStatusDeciderTest`, `PingProbeTest` |
| FR-6 | S6 | `TicketFlowTest`, `FeedbackCodeTest` |
| FR-7 | S1/S7/S8/S10 | `DashboardAccessTest`, `UserManagementTest`, `FeatureFlagsTest`, `SecurityCheckTest`, `DatabaseHealthTest`, `PgDumpCommandTest`, `BackupRetentionTest`, `BrowsingRollupTest`, `BandwidthRollupTest` |

