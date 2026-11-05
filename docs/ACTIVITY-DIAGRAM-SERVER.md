# Activity Diagram — sisi server (dashboard, alert, NOC, tiket, backup, retensi)

Pasangan dari [`ACTIVITY-DIAGRAM-PERANGKAT.md`](ACTIVITY-DIAGRAM-PERANGKAT.md). Dokumen ini mencakup alur yang **berjalan di server atau browser operator**, bukan di PC klien. Seluruh diagram dibuat dari kode (rujukan berkas di bawah tiap diagram).

> **Warna.** Hijau = server Laravel. Kuning/oranye = operator/pengguna di browser. Biru = agent. Abu = database / sistem luar (Telegram, SMTP, `pg_dump`). Garis putus-putus = jalur galat.

| # | Alur | Pemicu |
|---|---|---|
| S1 | [Login, hak akses, dan saklar fitur](#s1-login-hak-akses-dan-saklar-fitur) | Pengguna membuka dashboard |
| S2 | [Evaluasi alert ambang (CPU/RAM/Disk)](#s2-evaluasi-alert-ambang-cpuramdisk) | Scheduler tiap menit |
| S3 | [Pemantauan target jaringan (NOC)](#s3-pemantauan-target-jaringan-noc) | Scheduler tiap menit / tombol "Ping sekarang" |
| S4 | [Pengiriman notifikasi Telegram/email](#s4-pengiriman-notifikasi-telegramemail) | Scheduler tiap menit |
| S5 | [Alert suara di halaman Jaringan](#s5-alert-suara-di-halaman-jaringan-browser) | Auto-refresh halaman |
| S6 | [Tiket bantuan dan penilaian](#s6-tiket-bantuan-dan-penilaian) | Pengguna akhir / petugas |
| S7 | [Backup database](#s7-backup-database) | Scheduler 01:30 / manual |
| S8 | [Retensi data dan rollup](#s8-retensi-data-dan-rollup) | Scheduler 02:30 dan tiap jam |
| S9 | [Laporan uptime dan auto-refresh dashboard](#s9-laporan-uptime-dan-auto-refresh-dashboard) | Operator membuka halaman |
| S10 | [Pemeriksaan keamanan](#s10-pemeriksaan-keamanan) | Halaman Keamanan / `netwatch:security-check` |
| S11 | [Penjadwal (cron) — peta semua tugas berkala](#s11-penjadwal-cron--peta-semua-tugas-berkala) | `schedule:run` tiap menit |

---

## S1. Login, hak akses, dan saklar fitur

**Rujukan:** `routes/web.php`, `AuthController`, `EnsureActiveUser`, `RequireFeature`, `AppServiceProvider` (Gate & rate limiter), `UserChangeGuard`.

```mermaid
flowchart TD
  U1["Buka URL dashboard"] --> M1{"Sesi login?"}
  M1 -- "tidak" --> L1["Halaman /login"]
  L1 --> L2{"Throttle login<br/>5 per menit per email+IP?"}
  L2 -- "lewat batas" --> E429["HTTP 429"]
  L2 -- "ok" --> L3{"Auth::attempt:<br/>email + kata sandi + is_active = true"}
  L3 -- "gagal" --> L4["Audit auth.login_failed +<br/>Event auth.login_failed (Security, Warning)<br/>pesan 'Email atau kata sandi salah'"]
  L3 -- "ok" --> L5["Regenerasi sesi, audit auth.login"]
  L5 --> M2
  M1 -- "ya" --> M2{"Middleware active:<br/>akun masih aktif?"}
  M2 -- "tidak" --> L6["Logout paksa + pesan<br/>'Akun Anda dinonaktifkan'"]
  M2 -- "ya" --> R1{"Gate rute?"}
  R1 -- "dashboard, perangkat, event,<br/>laporan (semua peran)" --> F1
  R1 -- "run-commands / handle-tickets<br/>(Admin, Operator)" --> F1
  R1 -- "manage-fleet / manage-users<br/>(Admin)" --> F1
  R1 -- "tidak berhak" --> E403["HTTP 403"]
  F1{"Middleware feature:X<br/>fitur menyala?"}
  F1 -- "tidak" --> E404["HTTP 404 'Fitur dinonaktifkan'<br/>(JSON: pesan yang sama)"]
  F1 -- "ya" --> OK["Controller memproses permintaan"]
  classDef sv fill:#dcfce7,stroke:#15803d,color:#052e16;
  classDef op fill:#fef3c7,stroke:#b45309,color:#451a03;
  class U1 op;
  class L1,L2,L3,L4,L5,L6,M1,M2,R1,F1,E429,E403,E404,OK sv;
```

**Matriks peran** (`UserRole`, `AppServiceProvider`)

| Kemampuan | Viewer | Operator | Admin |
|---|:--:|:--:|:--:|
| Lihat dashboard, perangkat, event, laporan uptime, bandwidth | ✔ | ✔ | ✔ |
| Jalankan perintah ke PC (`run-commands`) | – | ✔ | ✔ |
| Tangani tiket (`handle-tickets`) | – | ✔ | ✔ |
| Kelola perangkat, grup, token, policy, jaringan, alert, fitur, keamanan, database, audit, browsing & inventaris (`manage-fleet`) | – | – | ✔ |
| Kelola pengguna (`manage-users`) | – | – | ✔ |

**Pengaman akun:** admin tidak bisa menonaktifkan/menurunkan dirinya sendiri, dan harus tersisa minimal satu admin aktif (`UserChangeGuard`).

| Titik | Gejala | Cara cek | Tindakan |
|---|---|---|---|
| Tidak bisa login | "Email atau kata sandi salah" padahal benar | `users.is_active`, `users.role`; event `auth.login_failed` | Aktifkan akun; reset sandi (`netwatch:create-user` bila belum ada admin) |
| 429 saat login | Terlalu banyak percobaan | 5/menit per kombinasi email + IP (`AppServiceProvider`) | Tunggu 1 menit |
| Terlempar ke login terus | Sesi tidak tersimpan | `SESSION_SECURE_COOKIE=true` tetapi akses lewat `http://`; `APP_KEY` berubah; tabel `sessions` hilang | Akses lewat HTTPS; jangan ganti `APP_KEY`; jalankan migrasi |
| 403 | Menu tidak muncul/ditolak | Peran pengguna | Naikkan peran sesuai kebutuhan |
| 404 "Fitur dinonaktifkan" | Halaman bandwidth/browsing/tiket dll. | Menu **Fitur** (admin); `feature_flags` menimpa `.env` | Aktifkan fitur |
| 419 (page expired) | Form lama dikirim | Token CSRF kedaluwarsa (`SESSION_LIFETIME=120`) | Muat ulang halaman |
| Login berhasil tapi 500 | Skema DB / `DB_SCHEMA` | `storage/logs/laravel.log` | Lihat `MERGE-DATABASE.md` |

---

## S2. Evaluasi alert ambang (CPU/RAM/Disk)

**Rujukan:** `netwatch:evaluate-alerts` → `AlertEvaluator`, `AlertDecision` (murni, teruji). Aturan dikelola di menu **Alert** (butuh fitur `threshold_alerts`).

```mermaid
flowchart TD
  A["Scheduler: netwatch:evaluate-alerts (tiap menit)"] --> B["Hapus alert_states milik aturan<br/>yang sudah dinonaktifkan / dihapus"]
  B --> C["Untuk setiap aturan aktif"]
  C --> D["Tentukan perangkat dalam cakupan:<br/>semua / satu grup / satu perangkat<br/>(hanya perangkat aktif, tidak dicabut)"]
  D --> E["Hapus state perangkat yang keluar cakupan"]
  E --> F["Satu query agregat atas device_heartbeats:<br/>jendela = max(durasi, 5 menit)<br/>nilai terendah dalam durasi, nilai tertinggi 3 menit terakhir,<br/>nilai terbaru, waktu sampel pertama"]
  F --> G{"Perangkat offline<br/>atau maintenance<br/>atau tanpa sampel?"}
  G -- "ya" --> SKIP["Lewati (tidak dinilai)"]
  G -- "tidak" --> H["AlertDecision.decide"]
  H --> I{"Sedang firing?"}
  I -- "tidak" --> J{"Jendela tercakup data<br/>(sampel pertama ≤ awal durasi + 3 mnt)<br/>dan nilai terendah ≥ ambang?"}
  J -- "ya" --> FIRE["FIRE: simpan alert_states.firing_since,<br/>event alert.firing (Warning)"]
  J -- "tidak" --> NONE["Tidak berubah"]
  I -- "ya" --> K{"Semua sampel 3 menit terakhir < ambang?"}
  K -- "ya" --> RES["RESOLVE: firing_since = null,<br/>event alert.resolved (Info)"]
  K -- "tidak" --> UPD["Perbarui last_value saja"]
  FIRE --> N["send-alerts mengirim event (S4)"]
  RES --> N
  classDef sv fill:#dcfce7,stroke:#15803d,color:#052e16;
  class A,B,C,D,E,F,G,H,I,J,K,SKIP,FIRE,NONE,RES,UPD,N sv;
```

**Aturan keputusan (sengaja konservatif):**
- *Menyala* hanya bila **seluruh** sampel selama `duration_minutes` ≥ ambang **dan** data benar-benar menutupi jendela itu (perangkat yang baru online tidak langsung memicu alert).
- *Pulih* bila semua sampel 3 menit terakhir < ambang (tidak bolak-balik karena satu sampel turun).
- Nilai persentase: CPU = `cpu_percent`; RAM = `used/total`; Disk = `used/total` (`AlertMetric::sqlPercent`).

| Titik | Gejala | Cara cek | Tindakan |
|---|---|---|---|
| Alert tidak pernah menyala | CPU tinggi tapi sunyi | Menu **Alert**: aturan aktif? cakupan benar? `duration_minutes` > lama lonjakan? Perangkat sedang maintenance/offline? Scheduler jalan? | Turunkan durasi; matikan maintenance; cek scheduler |
| Alert menyala lalu padam terus-menerus | Nilai di sekitar ambang | Pulih butuh 3 menit di bawah ambang | Naikkan ambang atau durasi |
| Alert menyala setelah perangkat kembali | Tidak: data jendela harus tercakup | Normal | – |
| Notifikasi tidak terkirim | Event ada, Telegram tidak | Lihat S4 | – |
| Heartbeat dipangkas (14 hari) | Alert dengan durasi > retensi tak mungkin | `NETWATCH_RETENTION_HEARTBEATS` | Durasi aturan harus ≪ 14 hari |

---

## S3. Pemantauan target jaringan (NOC)

**Rujukan:** `netwatch:ping-targets` → `TargetMonitorService`, `SystemTargetProber`, `PingProbe`, `TargetStatusDecider`; UI di `/network` (fitur `network`). Target = switch, AP, printer, server tanpa agent.

```mermaid
flowchart TD
  subgraph TRG["Pemicu"]
    T1["Scheduler tiap menit:<br/>semua target aktif (maks 1000)"]
    T2["Admin klik 'Ping sekarang'<br/>(throttle 30/menit)"]
  end
  T1 --> P0
  T2 --> P0
  P0["SystemTargetProber.probe"] --> P1{"Metode target?"}
  P1 -- "ICMP" --> P2["Proses ping bawaan OS tanpa shell,<br/>kelompok 25 paralel, timeout 3 dtk,<br/>latensi dari keluaran ping"]
  P1 -- "TCP" --> P3["Soket non-blokir ke host:port,<br/>kelompok 100, latensi = waktu sambung"]
  P2 --> D
  P3 --> D
  D["Untuk tiap target: TargetStatusDecider.next"] --> D1{"Respons OK?"}
  D1 -- "ya" --> D2{"Latensi > batas lambat<br/>(150 ms)?"}
  D2 -- "ya" --> W["status = Lambat (warning),<br/>kegagalan = 0"]
  D2 -- "tidak" --> O["status = Online,<br/>kegagalan = 0"]
  D1 -- "tidak" --> F["kegagalan + 1"]
  F --> F1{"kegagalan ≥ 2 beruntun?"}
  F1 -- "ya" --> OFF["status = Offline"]
  F1 -- "tidak" --> KEEP["status tetap (satu paket hilang<br/>bukan gangguan)"]
  W --> S["Simpan monitor_targets + baris monitor_target_checks"]
  O --> S
  OFF --> S
  KEEP --> S
  S --> EV{"Transisi?"}
  EV -- "→ Offline" --> E1["event target.offline (Warning)"]
  EV -- "Online → Lambat" --> E2["event target.slow (Warning)"]
  EV -- "Lambat → Online" --> E3["event target.normal (Info)"]
  EV -- "Offline → Online/Lambat" --> E4["event target.online (Info)<br/>+ downtime_seconds"]
  E1 --> N["send-alerts (S4)"]
  E2 --> N
  E3 --> N
  E4 --> N
  classDef sv fill:#dcfce7,stroke:#15803d,color:#052e16;
  classDef op fill:#fef3c7,stroke:#b45309,color:#451a03;
  class T1,T2 op;
  class P0,P1,P2,P3,D,D1,D2,W,O,F,F1,OFF,KEEP,S,EV,E1,E2,E3,E4,N sv;
```

**Aturan validasi target** (saat dibuat/diubah): hanya IP privat/link-local kecuali `NETWATCH_MONITOR_ALLOW_PUBLIC=true`; mengubah alamat/metode mereset status ke "belum dicek".

| Titik | Gejala | Cara cek | Tindakan |
|---|---|---|---|
| Semua target "Belum dicek" | Scheduler mati / NOC dimatikan | `NETWATCH_MONITOR_ENABLED`, menu **Fitur**, `artisan schedule:list` | Aktifkan scheduler |
| Semua target Offline serentak | Server NetWatch yang bermasalah | `ping 8.8.8.8` dari server; `ping` ada di PATH? (`which ping`) | Pasang `iputils-ping`; cek jaringan server |
| `ping` tak bisa dijalankan | Hasil selalu gagal | `which ping`; hak `CAP_NET_RAW`/setuid | Linux: `sudo apt install iputils-ping` |
| Target memblokir ICMP | Offline padahal hidup | Coba metode TCP + port | Ganti metode ke TCP |
| Terlalu sering "Lambat" | Ambang 150 ms terlalu ketat untuk WAN | `NETWATCH_MONITOR_WARN_MS` | Naikkan; notifikasi lambat hanya dikirim bila bertahan 180 dtk |
| Tabel `monitor_target_checks` besar | 1000 target × 1440/hari | Retensi 7 hari | Turunkan `NETWATCH_RETENTION_MONITOR` |
| IP publik ditolak | Pesan validasi | Kebijakan keamanan | `NETWATCH_MONITOR_ALLOW_PUBLIC=true` bila memang perlu |

---

## S4. Pengiriman notifikasi Telegram/email

**Rujukan:** `netwatch:send-alerts` → `AlertDispatcher`, `AlertMessage`, `Alerts/TelegramChannel`, `Alerts/MailChannel`. Dinyalakan dengan `NETWATCH_ALERTS_ENABLED=true` dan minimal satu saluran terisi. Uji dengan `php artisan netwatch:test-alert`.

```mermaid
flowchart TD
  A["Scheduler: netwatch:send-alerts (tiap menit)"] --> B{"alerts.enabled<br/>dan ada saluran terkonfigurasi?"}
  B -- "tidak" --> Z["Selesai (event dibiarkan menunggu)"]
  B -- "ya" --> C["Ambil ≤ 200 event: notified_at kosong,<br/>kode ∈ daftar (offline, online, alert.*, ticket.created,<br/>target.*, backup.failed);<br/>'target.slow' ditahan sampai ≥ 180 dtk"]
  C --> D["Untuk tiap event: itemFor"]
  D --> E{"Lolos penyaringan?"}
  E -- "tidak" --> SK["Tandai notified_at saja<br/>(notification_sent = false)"]
  E -- "ya" --> G["Kumpulkan sebagai item"]
  G --> H["AlertMessage.build<br/>(≥ 5 offline sekaligus diringkas)"]
  H --> I["Kirim ke semua saluran"]
  I --> J{"Minimal satu saluran sukses?"}
  J -- "ya" --> K["notified_at + notification_sent = true"]
  J -- "tidak" --> L["Event dibiarkan: dicoba lagi menit berikutnya<br/>sampai lewat max_age (3600 dtk)"]
  classDef sv fill:#dcfce7,stroke:#15803d,color:#052e16;
  classDef ext fill:#e5e7eb,stroke:#4b5563,color:#111827;
  class A,B,C,D,E,G,H,I,J,K,L,SK,Z sv;
```

**Aturan penyaringan `itemFor` (alasan pesan "hilang")**

| Jenis event | Dikirim bila… | Dilewati bila… |
|---|---|---|
| `device.offline` | Perangkat aktif, tidak maintenance | Sudah pulih sebelum sempat dikirim (heartbeat baru lebih dari `offline_after` sesudah kejadian); event lebih tua dari `max_age` |
| `device.online` | Kabar offline-nya **benar-benar terkirim** | Offline-nya tidak pernah terkirim (gangguan sesaat) |
| `alert.firing` | Perangkat aktif, tidak maintenance | Maintenance / dicabut |
| `alert.resolved` | `alert.firing` pasangannya sudah terkirim | Pasangan tak terkirim |
| `target.offline` | Selalu (≤ `max_age`) | Terlalu tua |
| `target.slow` | Target masih Lambat dan sudah ≥ 180 dtk terus-menerus | Pulih / offline / dinonaktifkan |
| `target.normal`, `target.online` | Kabar "lambat"/"offline"-nya terkirim | Tidak terkirim |
| `ticket.created`, `backup.failed` | Selalu | Terlalu tua |

| Titik | Gejala | Cara cek | Tindakan |
|---|---|---|---|
| Tidak ada notifikasi sama sekali | Tidak ada saluran aktif | `NETWATCH_ALERTS_ENABLED`, `NETWATCH_TELEGRAM_TOKEN` + `_CHAT_ID`, `NETWATCH_ALERT_MAIL_TO`; `config:cache` ulang setelah ubah `.env` | `php artisan netwatch:test-alert` |
| Telegram error | `errors[]` berisi `telegram: …` | Token salah; bot belum diundang ke grup; chat id grup diawali `-100` | Perbaiki token/chat id |
| Email gagal | `mail: …` | `MAIL_*` di `.env`; `storage/logs/laravel.log` | Uji SMTP; `MAIL_MAILER` bukan `log` |
| Notifikasi telat | Scheduler mati sementara | `max_age_seconds` 3600: event lebih tua dibuang | Pulihkan scheduler |
| "Online" tak pernah dikirim | Offline-nya dulu dilewati | Normal (lihat tabel) | – |
| Notifikasi dobel | Dua scheduler jalan (dua server/cron) | `withoutOverlapping` hanya di satu mesin | Pastikan satu cron |

---

## S5. Alert suara di halaman Jaringan (browser)

**Rujukan:** `public/js/network-alert.js`, `public/js/netwatch.js` (auto-refresh), tampilan `network/index`. Murni di browser: **tidak melibatkan Telegram** dan tidak berjalan bila halaman ditutup.

```mermaid
flowchart TD
  A["Halaman /network dimuat"] --> B["Baca pengaturan dari localStorage<br/>'nw.noc.sound' (default: nonaktif)"]
  B --> C{"Diaktifkan pengguna?"}
  C -- "tidak" --> Z["Tidak ada suara"]
  C -- "ya" --> D["Auto-refresh halaman (nw:refreshed)"]
  D --> E["Baca daftar target down dari #noc-down<br/>(dikirim server, tidak terpengaruh filter)"]
  E --> F{"Ada target baru down?<br/>(pemicu: offline / offline+lambat)"}
  F -- "ya" --> G["Bunyikan nada Web Audio<br/>(sirene/bip/bel) + ucapkan nama bila diaktifkan"]
  F -- "tidak" --> H{"Masih ada yang down<br/>dan sudah ≥ jeda ulang<br/>(30 / 60 / 300 dtk)?"}
  H -- "ya" --> G
  H -- "tidak" --> D
  G --> D
  classDef op fill:#fef3c7,stroke:#b45309,color:#451a03;
  class A,B,C,D,E,F,G,H,Z op;
```

| Titik | Gejala | Cara cek | Tindakan |
|---|---|---|---|
| Tidak ada suara | Browser memblokir audio otomatis | Perlu satu klik di halaman setelah dibuka | Klik tombol uji / halaman sekali |
| Suara berhenti saat tab di latar belakang | Browser menunda timer | Halaman Jaringan tetap me-refresh saat alert suara aktif | Jangan *discard* tab; gunakan mode TV (`?tv=1`) |
| Pengaturan hilang | Mode privat/ganti browser | `localStorage` per browser | Atur ulang di tiap komputer/TV |
| Suara ucapan tak ada | `speechSynthesis` tak didukung | Catatan tampil di panel | Gunakan nada saja |

---

## S6. Tiket bantuan dan penilaian

**Rujukan:** `PublicTicketController`, `PublicTicketRequest`, `TicketService`, `FeedbackService`, `TicketNotifier`, `FeedbackController`. Fitur `tickets` (dapat dimatikan). Dua pintu: pengguna akhir (tanpa login, ikon "Buat Tiket IT") dan petugas (login).

```mermaid
flowchart TD
  subgraph USR["Pengguna akhir (tanpa login)"]
    U1["Buka /tickets/new?pc=HOSTNAME<br/>(ikon Desktop dari installer agent)"] --> U2["Isi nama, subjek, kategori, deskripsi"]
    U2 --> U3{"Throttle 5/menit/IP<br/>dan kolom jebakan kosong?"}
    U7["Buka /f/KODE atau isi kode di /feedback"] --> U8{"Kode valid, belum dipakai,<br/>≤ 14 hari? (throttle 10/menit)"}
  end
  subgraph SV["Server"]
    T1["PC dicocokkan dengan hostname aktif<br/>(hanya bila tepat satu)"]
    T2["Buat tiket status 'open' + event ticket.created"]
    T3["send-alerts → Telegram/email petugas (S4)"]
    P1["Petugas: komentar, ubah status/prioritas/penanggung jawab<br/>(Admin/Operator, audit log)"]
    P2["Selesaikan tiket: transaksi status 'resolved'<br/>+ catatan publik + event ticket.resolved"]
    P3["FeedbackService.issue: kode baru (hash disimpan),<br/>berlaku 14 hari; null bila sudah dinilai"]
    P4["Kirim kode: email (bila MAIL_* & email pemohon)<br/>dan/atau popup ke PC (perintah send_message)"]
    P5["Simpan rating 1–5 + komentar sekali pakai<br/>event ticket.feedback"]
  end
  U3 -- "bot / lewat batas" --> BOT["Pura-pura sukses (bot) / HTTP 429"]
  U3 -- "ya" --> T1 --> T2 --> T3
  T2 --> P1 --> P2 --> P3 --> P4
  P4 -.-> U7
  U8 -- "ya" --> P5
  U8 -- "tidak" --> X["Pesan kode tidak valid / kedaluwarsa"]
  classDef sv fill:#dcfce7,stroke:#15803d,color:#052e16;
  classDef op fill:#fef3c7,stroke:#b45309,color:#451a03;
  class U1,U2,U3,U7,U8 op;
  class T1,T2,T3,P1,P2,P3,P4,P5,BOT,X sv;
```

| Titik | Gejala | Cara cek | Tindakan |
|---|---|---|---|
| Halaman tiket tidak terbuka dari PC klien | Timeout / sertifikat | Dari PC itu: `curl -kI https://SERVER/tickets/new`; DNS nama server; CA terpasang di browser | Pastikan nama server resolvable dan CA dipercaya (status: dikerjakan terpisah, lihat `TIKET.md`) |
| 404 | Fitur tiket dimatikan | Menu **Fitur** | Aktifkan |
| Tiket tidak terkait PC | `pc` tak cocok / ganda | Hostname dobel → tidak ditautkan (sengaja) | Perbaiki hostname |
| Email penilaian gagal | Pesan di UI: "Email tidak dikirim …" | `MAIL_MAILER`; email pemohon kosong | Atur `MAIL_*`; gunakan popup/kode manual |
| Popup tidak muncul | Perintah `send_message` tidak diambil | Perangkat offline → `expired` (A5) | Berikan kode secara lisan |
| Kode ditolak | Kedaluwarsa (14 hari) atau sudah dinilai | `ticket_feedback.expires_at/submitted_at` | Terbitkan kode baru (tombol di tiket) |

---

## S7. Backup database

**Rujukan:** `netwatch:backup` → `BackupService`, `PgDumpCommand`, `BackupRetention`. Panduan operasional: [`BACKUP-DATABASE.md`](BACKUP-DATABASE.md).

```mermaid
flowchart TD
  A["Scheduler 01:30 / php artisan netwatch:backup"] --> B{"NETWATCH_BACKUP_ENABLED?"}
  B -- "tidak" --> Z["Keluar (peringatan)"]
  B -- "ya" --> C["Pastikan folder ada & bisa ditulis"]
  C --> D{"Ruang kosong ≥ max(min_free_mb,<br/>1,5 × backup terakhir)?"}
  D -- "tidak" --> FAIL
  D -- "ya" --> E["pg_dump format custom ke file .partial<br/>--schema = DB_SCHEMA, data tabel mentah dikecualikan"]
  E --> F["pg_restore --list: pastikan tabel users ada"]
  F --> G["Hitung SHA-256, rename .partial → netwatch-AAAAMMDD-JJMMDD.dump,<br/>tulis .sha256, chmod 0600"]
  G --> H{"NETWATCH_BACKUP_COPY_TO diisi?"}
  H -- "ya" --> I["Salin ke folder kedua (.partial → rename),<br/>bandingkan checksum"]
  H -- "tidak" --> J
  I --> J["Rotasi: sisakan 7 harian, 4 mingguan, 6 bulanan<br/>(yang terbaru tak pernah dihapus)"]
  J --> OK["Selesai (laporan di halaman Database & Backup)"]
  E -. "gagal" .-> FAIL
  F -. "gagal" .-> FAIL
  I -. "gagal" .-> FAIL
  FAIL["RuntimeException → event backup.failed (Critical)<br/>→ notifikasi (S4); .partial dihapus"]
  classDef sv fill:#dcfce7,stroke:#15803d,color:#052e16;
  class A,B,C,D,E,F,G,H,I,J,OK,Z,FAIL sv;
```

Pesan galat dan tindakannya ada di [`BACKUP-DATABASE.md`](BACKUP-DATABASE.md) bagian "Jika backup gagal". Halaman **Database & Backup** menandai backup basi bila lebih tua dari `NETWATCH_BACKUP_STALE_HOURS` (36 jam).

---

## S8. Retensi data dan rollup

**Rujukan:** `netwatch:prune` (02:30) → `RetentionService`; `netwatch:rollup-bandwidth` (tiap jam menit 05) → `BandwidthRollup`; `BrowsingRollup`.

```mermaid
flowchart TD
  A["02:30 netwatch:prune"] --> H1["device_heartbeats > 14 hari → hapus"]
  H1 --> H2["BandwidthRollup.run:<br/>rangkum jam utuh → interface_samples_hourly,<br/>hapus sampel mentah > 3 hari<br/>(hanya yang sudah masuk ringkasan)"]
  H2 --> H3["Ringkasan per jam > 365 hari → hapus"]
  H3 --> H4["BrowsingRollup: mentah > 14 hari<br/>→ browsing_daily lalu hapus"]
  H4 --> H5["browsing_daily > 365 hari → hapus"]
  H5 --> H6["monitor_target_checks > 7 hari → hapus"]
  H6 --> H7["events > 180 hari → hapus"]
  H7 --> H8["device_commands selesai > 90 hari → hapus"]
  H8 --> H9["audit_logs > 365 hari → hapus"]
  H9 --> H10["device_status_history selesai > 730 hari → hapus<br/>(interval terbuka tidak pernah dihapus)"]
  B["Tiap jam menit 05:<br/>netwatch:rollup-bandwidth"] --> H2
  S["Saat snapshot proses disimpan"] --> S1["Sisakan 12 terbaru per perangkat"]
  classDef sv fill:#dcfce7,stroke:#15803d,color:#052e16;
  class A,B,S,S1,H1,H2,H3,H4,H5,H6,H7,H8,H9,H10 sv;
```

Semua penghapusan dilakukan **per 10.000 baris** agar tidak mengunci tabel lama. Nilai retensi ada di [`KONFIGURASI.md`](KONFIGURASI.md).

| Titik | Gejala | Cara cek | Tindakan |
|---|---|---|---|
| Database membengkak | Tabel besar tidak menyusut | `php artisan netwatch:db-stats`; halaman Database | Pastikan scheduler jalan; `artisan netwatch:prune` manual |
| Grafik bandwidth > 3 hari kosong | Rollup tertinggal | `SELECT max(hour) FROM interface_samples_hourly` | `artisan netwatch:rollup-bandwidth` (maks 14 hari per eksekusi, ulangi) |
| Data browsing mentah lama masih ada | Prune gagal di tengah | Log `laravel.log`; kunci DB | Jalankan ulang; transaksi per 50.000 baris aman diulang |

---

## S9. Laporan uptime dan auto-refresh dashboard

**Rujukan:** `public/js/netwatch.js`, `monitor-ui.js`, `UptimeReportService`, `UptimeCalculator`, `DeviceController::live/liveIndex`, atribut `data-refresh-seconds` / `data-autoreload` / `data-inplace` di `resources/views`.

Ada **tiga mekanisme** pembaruan layar; mengetahui yang mana dipakai sebuah halaman mempercepat diagnosis "angka tidak berubah".

```mermaid
flowchart TD
  subgraph BR["Browser operator"]
    B1["Halaman dimuat"] --> B2{"Atribut di &lt;main&gt;?"}
    B2 -- "devices-index / devices-show" --> P1["poll(): fetch JSON /devices/live atau<br/>/devices/{id}/live (halaman perangkat tiap 5 dtk),<br/>lewati bila tab tersembunyi, minimal 2 dtk"]
    B2 -- "data-autoreload<br/>(dashboard, event, jaringan)" --> P2["Timer ≥ 10 dtk (bawaan 30):<br/>tunda bila tab tersembunyi<br/>atau pengguna sedang mengisi formulir"]
    P2 --> P3{"Mode monitor (kiosk)<br/>atau data-inplace (Jaringan)?"}
    P3 -- "ya" --> P4["Ambil HTML halaman, ganti isi #content<br/>tanpa reload (kirim event nw:refreshed)"]
    P3 -- "tidak" --> P5["window.location.reload()"]
    B2 -- "halaman Monitoring<br/>(bandwidth / browsing)" --> P6["monitor-ui.js: fetch JSON<br/>/monitoring/*/data sesuai interval pilihan<br/>(disimpan di localStorage)"]
  end
  subgraph SV["Server"]
    S1["Endpoint live: status dari last_seen_at<br/>+ latest_metrics"]
    S2["Halaman server-rendered (Blade)"]
    S3["Data monitoring di-cache<br/>NETWATCH_FLEET_CACHE (4 dtk)"]
    S4["Laporan uptime: UptimeCalculator memotong interval<br/>status ke jendela laporan; hitung % online,<br/>jumlah gangguan, downtime terpanjang"]
  end
  P1 --> S1
  P4 --> S2
  P5 --> S2
  P6 --> S3
  S4 --> CSV["Ekspor CSV (reports/uptime/export)"]
  P1 -. "401/419 atau respons dialihkan" .-> RL["Reload penuh / halaman login"]
  classDef sv fill:#dcfce7,stroke:#15803d,color:#052e16;
  classDef op fill:#fef3c7,stroke:#b45309,color:#451a03;
  class B1,B2,P1,P2,P3,P4,P5,P6,RL op;
  class S1,S2,S3,S4,CSV sv;
```

| Titik | Gejala | Cara cek | Tindakan |
|---|---|---|---|
| Angka tidak ter-update | Halaman tampak diam | Teks "Diperbarui … otomatis tiap N detik" di pojok; F12 → Network: respons 401/419/500 | Login ulang; cek `laravel.log` |
| "Pembaruan otomatis ditunda" | Anda sedang mengetik di formulir | Disengaja agar ketikan tidak hilang | Kirim/kosongkan formulir |
| Tab di latar belakang tidak berubah | Browser menunda tab tersembunyi | Halaman Jaringan dengan alert suara tetap diperbarui (`data-refresh-hidden`) | Gunakan mode TV (`?tv=1`) untuk layar monitor |
| Layar penuh keluar sendiri | `reload()` menutup fullscreen | Dipakai refresh in-place di mode kiosk | Gunakan tombol Mode monitor di halaman |
| Dashboard lambat dengan banyak penonton | CPU/DB tinggi | `NETWATCH_FLEET_CACHE` (monitoring); jumlah tab terbuka | Naikkan cache ke 10 dtk; perpanjang interval refresh |
| Uptime 100% padahal ada gangguan | Gangguan saat maintenance? | Riwayat status tetap dicatat selama maintenance | Bandingkan dengan `device_status_history` |
| Uptime rendah di awal periode | Data sebelum enroll tidak dihitung | `UptimeCalculator` memotong ke jendela data | Normal |

---

## S10. Pemeriksaan keamanan

**Rujukan:** `SecurityChecker`, `netwatch:security-check` (kode keluar 1 bila ada yang berisiko). Halaman **Keamanan** (Admin).

```mermaid
flowchart TD
  A["Halaman Keamanan / artisan netwatch:security-check"] --> B["SecurityChecker.run: 12 pemeriksaan"]
  B --> C1["Mode debug (APP_DEBUG)"]
  B --> C2["APP_ENV, HTTPS, cookie & sesi"]
  B --> C3["Role database bukan superuser"]
  B --> C4["Jumlah admin aktif (1–5)"]
  B --> C5["Token pendaftaran masih aktif"]
  B --> C6["Versi agent usang"]
  B --> C7["Perangkat usang > 30 hari"]
  B --> C8["Kesehatan scheduler"]
  B --> C9["Volume tabel browsing"]
  B --> C10["Login gagal 24 jam (≥10 peringatan, ≥50 gagal)"]
  C1 --> W["Status terburuk: OK / Peringatan / Gagal"]
  C2 --> W
  C3 --> W
  C4 --> W
  C5 --> W
  C6 --> W
  C7 --> W
  C8 --> W
  C9 --> W
  C10 --> W
  classDef sv fill:#dcfce7,stroke:#15803d,color:#052e16;
  class A,B,C1,C2,C3,C4,C5,C6,C7,C8,C9,C10,W sv;
```

Setiap pemeriksaan memuat pesan dan **saran perbaikan**; tidak ada yang mengubah data. Pemeriksaan "Penjadwal" menandai perangkat yang masih berstatus online padahal `last_seen_at` lebih tua dari 3 × `offline_after` — itu tanda paling andal bahwa **cron tidak berjalan**.

---

## S11. Penjadwal (cron) — peta semua tugas berkala

**Rujukan:** `routes/console.php`. Satu entri cron `* * * * * php artisan schedule:run`.

```mermaid
flowchart LR
  CRON["cron: schedule:run<br/>tiap menit"] --> M["Tiap menit"]
  CRON --> H["Tiap jam (menit 05)"]
  CRON --> D["Harian"]
  M --> M1["check-status"]
  M --> M2["ping-offline"]
  M --> M3["ping-targets"]
  M --> M4["evaluate-alerts"]
  M --> M5["send-alerts"]
  M --> M6["expire-commands"]
  H --> H1["rollup-bandwidth"]
  D --> D1["01:30 backup"]
  D --> D2["02:30 prune"]
  classDef sv fill:#dcfce7,stroke:#15803d,color:#052e16;
  class CRON,M,H,D,M1,M2,M3,M4,M5,M6,H1,D1,D2 sv;
```

`withoutOverlapping` mencegah eksekusi tumpang tindih (kunci kadaluwarsa: 5 menit untuk ping/alert, 120 menit rollup, 180 menit backup). **Jika scheduler mati**: perangkat yang mati tetap tampil online, notifikasi berhenti, perintah tak kedaluwarsa, backup/retensi berhenti. Tanda utamanya ada di halaman Keamanan (S10).

Rincian tiap perintah ada di [`PERINTAH-DAN-JADWAL.md`](PERINTAH-DAN-JADWAL.md).
