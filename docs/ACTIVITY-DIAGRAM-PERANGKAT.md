# Activity Diagram — sisi perangkat (agent ↔ server)

Dokumen ini menggambarkan **alur kerja nyata** yang dijalankan agent di PC dan server NetWatch saat berhubungan. Setiap diagram dibuat dari kode (rujukan berkas ada di bawah tiap diagram), bukan dari rancangan awal. Dipakai untuk **troubleshooting**: temukan langkah tempat alur berhenti, lalu lihat tabel "titik gagal" di bawahnya.

Dokumen pasangan: [`ACTIVITY-DIAGRAM-SERVER.md`](ACTIVITY-DIAGRAM-SERVER.md) (alert, notifikasi, NOC, tiket, backup, retensi), [`API-AGENT.md`](API-AGENT.md) (kontrak API), [`AGENT-INTERNAL.md`](AGENT-INTERNAL.md), [`ERD.md`](ERD.md), [`KODE-EVENT.md`](KODE-EVENT.md).

> **Cara membaca.** Kotak biru = langkah di agent (PC klien). Kotak hijau = langkah di server (Laravel). Kotak abu = database. Belah ketupat = keputusan. Garis putus-putus = jalur galat. Semua waktu di database UTC; tampilan dashboard memakai `NETWATCH_TIMEZONE` (Asia/Jakarta).

Daftar alur:

| # | Alur | Pemicu |
|---|---|---|
| A1 | [Pendaftaran perangkat (enrollment)](#a1-pendaftaran-perangkat-enrollment) | Installer / `netwatch-agent enroll` |
| A2 | [Siklus hidup agent (loop utama)](#a2-siklus-hidup-agent-loop-utama) | Agent berjalan (tugas SYSTEM / systemd) |
| A3 | [Heartbeat diterima server](#a3-heartbeat-diterima-server) | `POST /api/agent/v1/heartbeat` |
| A4 | [Status online/offline dan riwayat uptime](#a4-status-onlineoffline-dan-riwayat-uptime) | Heartbeat + scheduler tiap menit |
| A5 | [Perintah dari dashboard ke PC](#a5-perintah-dari-dashboard-ke-pc) | Operator menekan tombol di halaman perangkat |
| A6 | [Kebijakan blokir (policy)](#a6-kebijakan-blokir-policy) | Policy diubah / heartbeat membawa hash |
| A7 | [Inventaris: port, layanan, perangkat keras, perangkat lunak](#a7-inventaris-port-layanan-perangkat-keras-perangkat-lunak) | Timer agent 5–10 menit |
| A8 | [Bandwidth: counter jadi sampel](#a8-bandwidth-counter-jadi-sampel) | Setiap heartbeat |
| A9 | [Browsing dan Windows Event Log](#a9-browsing-dan-windows-event-log) | Timer agent 2 / 5 menit |
| A10 | [Snapshot proses (Task Manager jarak jauh)](#a10-snapshot-proses-task-manager-jarak-jauh) | Timer 5 menit atau perintah `refresh_processes` |
| A11 | [Pencabutan, pemasangan ulang, dan pencopotan agent](#a11-pencabutan-pemasangan-ulang-dan-pencopotan-agent) | Admin mencabut / installer / uninstall |

---

## A1. Pendaftaran perangkat (enrollment)

**Aktor:** teknisi/installer, agent, server. **Hasil:** PC punya `device_id` dan `api_token` (disimpan agent di `agent.json`, server hanya menyimpan **hash SHA-256**-nya).

```mermaid
flowchart TD
  subgraph AG["Agent (PC klien)"]
    A1["Installer / netwatch-agent enroll<br/>--server, --token-file"] --> A2["Baca token pendaftaran<br/>(berkas dihapus setelah dibaca)"]
    A2 --> A3["Kumpulkan hostname, OS, versi agent,<br/>IP lokal, MAC"]
    A3 --> A4["POST /api/agent/v1/enroll"]
    A8["Simpan agent.json (0600):<br/>device_id + api_token"] --> A9["Daftarkan tugas SYSTEM / systemd<br/>lalu agent run"]
  end
  subgraph SV["Server"]
    S1{"Throttle agent-enroll<br/>lolos?"}
    S2{"EnrollRequest valid?<br/>token 64 alfanumerik,<br/>hostname, os_family"}
    S3{"Token ada, belum kedaluwarsa,<br/>belum habis, belum dicabut?"}
    S4["Hostname sudah terdaftar aktif?<br/>Ya: pakai ulang perangkat,<br/>token lama tidak berlaku"]
    S5["Buat perangkat + api_token baru<br/>simpan token_hash, catat riwayat 'enrolled'"]
    S6["Event device.enrolled"]
    S7["Event device.enroll_rejected<br/>(Security, Warning)"]
  end
  DB[("devices, enrollment_tokens,<br/>device_status_history, events")]
  A4 --> S1
  S1 -- "tidak" --> E429["HTTP 429"]
  S1 -- "ya" --> S2
  S2 -- "tidak" --> E422["HTTP 422 + daftar kolom salah"]
  S2 -- "ya" --> S3
  S3 -- "tidak" --> S7 --> E403["HTTP 403 (EnrollmentException)"]
  S3 -- "ya" --> S4 --> S5 --> DB
  S5 --> S6 --> R201["HTTP 201<br/>device_id, api_token,<br/>heartbeat_interval, process_snapshot_interval"]
  R201 --> A8
  E429 -.-> F["Agent mencatat 'Pendaftaran gagal'<br/>di agent.log, keluar kode 1"]
  E422 -.-> F
  E403 -.-> F
  classDef ag fill:#dbeafe,stroke:#1d4ed8,color:#0b1f4d;
  classDef sv fill:#dcfce7,stroke:#15803d,color:#052e16;
  classDef db fill:#e5e7eb,stroke:#4b5563,color:#111827;
  class A1,A2,A3,A4,A8,A9,F ag;
  class S1,S2,S3,S4,S5,S6,S7,R201,E429,E422,E403 sv;
  class DB db;
```

**Langkah:**
1. Agent membaca token dari `--token-file` (lalu **menghapus berkas**), `--token`, atau variabel `NETWATCH_ENROLL_TOKEN` (`agent/bin/netwatch-agent`, `readEnrollToken`).
2. `Enroller` mengumpulkan data perangkat; IP lokal diambil lewat soket UDP ke server (tanpa mengirim paket) (`agent/src/Enrollment/Enroller.php`).
3. Server membatasi laju (`agent-enroll`, 10/menit per IP), memvalidasi (`EnrollRequest`), lalu `EnrollmentService` memeriksa token. Hostname yang sudah aktif **didaftarkan ulang** (perangkat sama, token lama tidak berlaku lagi).
4. Respons 201 hanya berisi `device_id` (public id), `api_token` (ditampilkan **sekali**), dan interval. Agent menyimpan lewat tulis-atomik (`agent.json.tmp` → rename).

**Titik gagal**

| Titik | Gejala | Cara cek | Tindakan |
|---|---|---|---|
| Server tidak terjangkau | `Tidak dapat terhubung ke server: …` di `agent.log` | `curl -kI https://SERVER/login` dari PC itu; cek DNS/firewall | Perbaiki jaringan/URL; tes dengan `--ca-file` bila CA internal |
| Sertifikat tidak dipercaya | `SSL certificate problem` | Pasang CA lewat installer `/CAFILE=…` | Berikan `ca.pem` ke agent (`ca_file` di `agent.json`) |
| URL `http://` | `Server harus memakai HTTPS` | Lihat `server_url` | Pakai `https://`; `--allow-insecure-http` hanya uji coba |
| HTTP 403 | `Pendaftaran gagal … Token pendaftaran tidak valid atau sudah kedaluwarsa.` | Dashboard → Event → `device.enroll_rejected` (hostname + IP; alasan sengaja **tidak** dibedakan agar tidak membantu penyerang) | Di menu Token Pendaftaran periksa: kedaluwarsa? `used_count` = `max_uses`? dicabut? Salah salin (64 karakter)? Bila ragu, buat token baru |
| HTTP 422 | Pesan validasi | Isi `message`/`errors` pada respons | Hostname berisi karakter terlarang (spasi, `_` di awal) → ganti nama PC |
| HTTP 429 | Terlalu sering mencoba | Tunggu 1 menit | Jangan menjalankan enroll dalam loop |
| `agent.json` tidak tertulis | Enroll sukses tetapi agent tak jalan | Izin folder `C:\ProgramData\NetWatch` | Jalankan sebagai SYSTEM/Administrator |

---

## A2. Siklus hidup agent (loop utama)

**Rujukan:** `agent/src/Agent.php`. Satu putaran = satu heartbeat; tugas lain berjalan bila timernya jatuh tempo.

```mermaid
flowchart TD
  START(["Agent run"]) --> L1["Baca agent.json<br/>(belum enrolled: berhenti)"]
  L1 --> LOOP["Mulai putaran"]
  LOOP --> HB["Kumpulkan metrik + interface<br/>POST heartbeat (lihat A3)"]
  HB --> RESP["Terapkan interval dari server,<br/>saklar browsing_enabled / winlog_enabled"]
  RESP --> POL["Ada policy di respons?<br/>apply : nothingToApply (A6)"]
  POL --> CMD["Jalankan perintah di respons<br/>lalu POST commands/{id}/result (A5)"]
  CMD --> T1{"Snapshot proses<br/>jatuh tempo? (5 mnt)"}
  T1 -- "ya" --> SNAP["Kirim snapshot proses (A10)"]
  T1 -- "tidak" --> T2
  SNAP --> T2{"Browsing aktif &<br/>jatuh tempo? (2 mnt)"}
  T2 -- "ya" --> BR["Kirim browsing (A9)"]
  T2 -- "tidak" --> T3
  BR --> T3{"Inventaris port<br/>10 mnt / aset 5 mnt?"}
  T3 -- "ya" --> INV["Kirim inventaris (A7)"]
  T3 -- "tidak" --> T4
  INV --> T4{"Winlog aktif &<br/>jatuh tempo? (5 mnt)"}
  T4 -- "ya" --> WL["Kirim winlog (A9)"]
  T4 -- "tidak" --> OK
  WL --> OK["Reset hitungan gagal<br/>dan offlineSince"]
  OK --> SLP["Tidur sebesar heartbeat_interval<br/>(5–3600 detik, bawaan 30)"]
  SLP --> LOOP
  HB -. "ApiException 401/403" .-> STOP(["Log 'Token ditolak server'<br/>agent berhenti"])
  HB -. "gagal koneksi (status 0)" .-> OFF["offlineSince dicatat;<br/>bila ≥ offline_release_seconds<br/>blokir dilepas (A6)"]
  HB -. "galat lain" .-> BO["failures++<br/>tidur min(60, interval × 2^n) + jitter"]
  OFF --> BO
  BO --> LOOP
  classDef ag fill:#dbeafe,stroke:#1d4ed8,color:#0b1f4d;
  class START,L1,LOOP,HB,RESP,POL,CMD,T1,SNAP,T2,BR,T3,INV,T4,WL,OK,SLP,STOP,OFF,BO ag;
```

**Catatan penting untuk troubleshooting**
- Tugas sekunder (browsing, inventaris, aset, winlog) dibungkus `guarded()`: kegagalannya **hanya dicatat sebagai peringatan** di `agent.log` dan tidak menghentikan heartbeat. Jadi PC tampak online walau datanya tidak masuk.
- **Hanya** `ApiException` dengan status 401/403 yang menghentikan agent. Selain itu agent terus mencoba dengan backoff.
- Interval dari server dibatasi 5–3600 detik; nilai tidak numerik diabaikan.
- Saat dihentikan (SIGTERM/SIGINT) agent selesai di sela tidur 250 ms, lalu log `Agent berhenti.`

| Titik | Gejala | Cara cek | Tindakan |
|---|---|---|---|
| Agent tidak jalan | PC offline di dashboard, tak ada log baru | Windows: `schtasks /Query /TN NetWatchAgent`; Linux: `systemctl status netwatch-agent` | Mulai tugas/layanan; baca `agent.log` |
| 401 berulang | Log `Token ditolak server … Agent berhenti.` | Dashboard: perangkat berstatus dicabut? | Daftarkan ulang dengan token baru (A11) |
| Server mati/jaringan putus | `Gagal berkomunikasi dengan server` + jeda makin panjang | `ping`/`curl` ke server | Pulihkan server; agent pulih sendiri (maks. jeda ±72 detik) |
| Data tertentu tak masuk | Peringatan `Gagal mengirim data … (dicoba lagi)` | `agent.log` | Lihat alur A7/A9 yang bersangkutan |
| Waktu PC salah | Grafik/alert aneh, winlog ditolak | Bandingkan `server_time` di respons heartbeat dengan jam PC | Sinkronkan NTP |

---

## A3. Heartbeat diterima server

**Rujukan:** `HeartbeatController`, `HeartbeatService::record`, `PolicySync::sync`, `CommandService::claimPending`, `StatusTracker`.

```mermaid
flowchart TD
  subgraph AG["Agent"]
    H0["POST /heartbeat<br/>Bearer api_token<br/>metrics, interfaces, policy"]
    H9["Terima respons"]
  end
  subgraph SV["Server"]
    M1{"Middleware agent:<br/>token 64 karakter,<br/>hash cocok perangkat aktif?"}
    M2{"throttle agent-api<br/>lolos?"}
    M3{"HeartbeatRequest valid?"}
    R1["Simpan device_heartbeats<br/>(cpu, ram, disk, uptime, user)"]
    R2["Perbarui devices: latest_metrics,<br/>last_seen_at, ip, agent_version"]
    R3{"Fitur Bandwidth aktif?"}
    R4["BandwidthRecorder (A8)<br/>galat di sini tidak menggagalkan heartbeat"]
    R5{"last_seen_at lama lebih tua<br/>dari offline_after?"}
    R6["StatusTracker.recordGapAndMarkOnline<br/>riwayat 'heartbeat_gap', event device.online"]
    R7["StatusTracker.markOnline"]
    P1["PolicySync.sync (Windows): hash agent vs hash server<br/>(A6)"]
    C1["claimPending: ambil ≤ 10 perintah<br/>FOR UPDATE SKIP LOCKED,<br/>pending → dispatched"]
    RESP["Susun respons: server_time, interval,<br/>browsing_enabled, winlog_enabled,<br/>policy (bila beda), commands"]
  end
  DB[("device_heartbeats, devices,<br/>device_status_history, events,<br/>device_commands")]
  H0 --> M1
  M1 -- "tidak" --> U401["HTTP 401 Unauthenticated"]
  M1 -- "ya" --> M2
  M2 -- "tidak" --> U429["HTTP 429"]
  M2 -- "ya" --> M3
  M3 -- "tidak" --> U422["HTTP 422"]
  M3 -- "ya" --> R1 --> R2 --> R3
  R3 -- "ya" --> R4 --> R5
  R3 -- "tidak" --> R5
  R5 -- "ya" --> R6 --> P1
  R5 -- "tidak" --> R7 --> P1
  P1 --> C1 --> RESP --> H9
  R1 --> DB
  R2 --> DB
  R6 --> DB
  R7 --> DB
  C1 --> DB
  classDef ag fill:#dbeafe,stroke:#1d4ed8,color:#0b1f4d;
  classDef sv fill:#dcfce7,stroke:#15803d,color:#052e16;
  classDef db fill:#e5e7eb,stroke:#4b5563,color:#111827;
  class H0,H9 ag;
  class M1,M2,M3,R1,R2,R3,R4,R5,R6,R7,P1,C1,RESP,U401,U429,U422 sv;
  class DB db;
```

| Titik | Gejala | Cara cek | Tindakan |
|---|---|---|---|
| 401 | Agent berhenti, perangkat "dicabut" | `devices.revoked_at`; `SELECT … WHERE token_hash = sha256(token)` | Daftarkan ulang |
| 422 | Log agent: `HTTP 422` | `storage/logs/laravel.log` / respons `errors` | Biasanya `cpu_percent` di luar 0–100 atau metrik negatif → bug collector; kirim `agent.log` |
| 429 | Agent mengirim > 240 permintaan/menit | Batas `agent-api` = 240/menit **per token** (hash), bukan per IP; ada di `AppServiceProvider` | Biasanya loop bug di agent; periksa `agent.log` |
| 500 | Agent mencoba terus dengan backoff | `storage/logs/laravel.log` | Periksa DB (koneksi, `DB_SCHEMA`), izin `storage/` |
| Perangkat online tetapi grafik kosong | `device_heartbeats` tidak bertambah | `SELECT max(recorded_at) FROM device_heartbeats WHERE device_id=…` | Cek retensi/prune, jam server |
| Perintah tak sampai | Status tetap `pending` | Lihat A5 | Pastikan heartbeat berjalan (perintah hanya dikirim lewat respons heartbeat) |

---

## A4. Status online/offline dan riwayat uptime

**Rujukan:** `StatusTracker`, `netwatch:check-status` (tiap menit), `netwatch:ping-offline` (tiap menit), `PingService`, `UptimeCalculator`.

Prinsip: **offline dihitung dari heartbeat terakhir**, bukan dari saat scheduler menandainya. Riwayat disimpan sebagai interval (`device_status_history`) dengan indeks unik parsial agar satu perangkat hanya punya **satu interval terbuka**.

```mermaid
flowchart TD
  subgraph SCH["Scheduler (tiap menit)"]
    C0["netwatch:check-status"] --> C1["Cari perangkat aktif dengan<br/>last_seen_at ≤ now − offline_after (120 dtk)<br/>dan interval 'online' masih terbuka"]
    C1 --> C3["markOffline: waktu mulai gangguan = last_seen_at,<br/>alasan 'heartbeat_timeout'"]
    C3 --> C4["Transaksi + kunci baris: tutup interval online,<br/>buka interval offline<br/>(UniqueConstraintViolation diabaikan)"]
    C4 --> C5["Event device.offline (Warning)<br/>dicatat di StatusTracker"]
    P0["netwatch:ping-offline"] --> P1["Perangkat tak terdengar ≥ offline_after<br/>(maks 500, kelompok 25): ping ICMP"]
    P1 --> P2["Simpan ping_ok, ping_ms, ping_checked_at"]
  end
  subgraph HBN["Heartbeat masuk"]
    H1{"Jeda sejak last_seen_at<br/>> offline_after?"}
    H2["recordGapAndMarkOnline:<br/>tutup offline, buka online ('heartbeat_gap'),<br/>event device.online + downtime_seconds"]
    H3["markOnline: pastikan interval online terbuka<br/>(alasan 'heartbeat')"]
    H1 -- "ya" --> H2
    H1 -- "tidak" --> H3
  end
  C5 --> N["send-alerts membaca event (alur S4)"]
  H2 --> N
  P2 --> V["Halaman perangkat (saat offline):<br/>ping merespons = PC menyala, agent berhenti;<br/>tidak merespons = PC mati/sleep/terputus"]
  classDef sv fill:#dcfce7,stroke:#15803d,color:#052e16;
  class C0,C1,C3,C4,C5,P0,P1,P2,H1,H2,H3,N,V sv;
```

**Arti status di dashboard**
- *Online*: ada interval online terbuka dan `last_seen_at` dalam batas `offline_after`.
- *Offline*: interval offline terbuka. `ping_ok` membantu membedakan: `true` = PC hidup tetapi agent/ jaringan agent bermasalah; `false` = PC mati/terputus.
- *Maintenance*: `maintenance_until` di masa depan. Pencatatan status/uptime **tetap berjalan**; yang dilewati hanyalah evaluasi alert threshold (`AlertEvaluator`) dan pengiriman notifikasi (`AlertDispatcher`) untuk perangkat ini.
- Event `device.offline`/`device.online` baru dibuat pada **perubahan nyata**; pembukaan riwayat pertama (saat enroll) tidak menghasilkan event.
- Waktu kejadian event offline = `last_seen_at` (bukan waktu scheduler berjalan), sehingga lama downtime akurat walau scheduler sempat tertunda.

| Titik | Gejala | Cara cek | Tindakan |
|---|---|---|---|
| Scheduler tidak jalan | Perangkat mati tetap "online" | Halaman **Keamanan** → "Penjadwal"; `crontab -l`; `artisan schedule:list` | Pasang cron (`DEPLOY-LINUX.md` §7); `artisan schedule:run` manual |
| Banyak perangkat offline bersamaan | Event massal (diringkas notifikasi jika ≥ 5) | Cek switch/AP, server NetWatch, sertifikat kedaluwarsa | Pulihkan jalur; jangan cabut perangkat |
| Flapping online/offline | Banyak event `device.online/offline` | `SELECT * FROM device_status_history WHERE device_id=… ORDER BY started_at DESC` | Naikkan `NETWATCH_OFFLINE_AFTER`, cek kabel/WiFi/sleep |
| Offline padahal PC menyala | `ping_ok = true` | `agent.log`; layanan agent | Restart tugas/layanan agent; cek firewall ke server |
| Uptime % janggal | Laporan beda dengan harapan | `UptimeCalculator` memotong interval ke jendela laporan; data sebelum enroll dihitung "tidak ada data" | Bandingkan dengan interval mentah di tabel |
| Interval ganda terbuka | Seharusnya mustahil (indeks unik) | `SELECT device_id,count(*) FROM device_status_history WHERE ended_at IS NULL GROUP BY 1 HAVING count(*)>1` | Laporkan sebagai bug; sertakan hasil query |

---

## A5. Perintah dari dashboard ke PC

**Rujukan:** `DeviceCommandController`, `StoreDeviceCommandRequest`, `CommandService`, `CommandResultController`, `netwatch:expire-commands`, `agent/src/Command/CommandExecutor.php`.

Jenis perintah (`CommandType`): `refresh_processes`, `terminate_process`, `send_message`, `restart_computer`, `shutdown_computer`. Hak: Admin & Operator (`run-commands`); Viewer hanya melihat.

```mermaid
flowchart TD
  subgraph OP["Operator (browser)"]
    O1["Klik tombol di halaman perangkat<br/>POST /devices/{id}/commands"]
    O9["Halaman memoll status<br/>tiap 5 detik"]
  end
  subgraph SV["Server"]
    V1{"Gate run-commands &<br/>FormRequest valid?"}
    V2{"Perangkat dicabut, atau proses<br/>terlindungi (config protected_processes)?"}
    V3["Buat device_commands status 'pending'<br/>event command.issued + audit log"]
    V4["Heartbeat berikutnya: claimPending<br/>pending → dispatched"]
    V5["CommandResultController:<br/>404 bila bukan milik perangkat,<br/>409 bila bukan 'dispatched'"]
    V6["status → succeeded / failed<br/>failed: event command.failed"]
    V7["expire-commands: pending/dispatched<br/>lebih tua dari command_ttl (600 dtk)<br/>→ expired + event command.expired"]
  end
  subgraph AG["Agent"]
    G1["Terima perintah di respons heartbeat"]
    G2{"Jenis?"}
    G3["refresh_processes:<br/>kirim snapshot proses"]
    G4{"terminate_process:<br/>pid ≥ 2, nama diberikan,<br/>bukan proses terlindungi,<br/>PID masih proses yang sama?"}
    G5["send_message: tampilkan pesan (≤ 500 karakter)"]
    G6["restart/shutdown: delay 0–3600 dtk<br/>(bawaan 60)"]
    G7["POST commands/{id}/result<br/>status + result/error"]
  end
  O1 --> V1
  V1 -- "tidak" --> X1["403 / 422"]
  V1 -- "ya" --> V2
  V2 -- "ya" --> X2["422 pesan penolakan"]
  V2 -- "tidak" --> V3 --> V4 --> G1 --> G2
  G2 --> G3 --> G7
  G2 --> G4
  G4 -- "ya: akhiri proses" --> G7
  G4 -- "tidak: failed + alasan" --> G7
  G2 --> G5 --> G7
  G2 --> G6 --> G7
  G7 --> V5 --> V6 --> O9
  V3 --> O9
  V3 -.-> V7
  V4 -.-> V7
  classDef ag fill:#dbeafe,stroke:#1d4ed8,color:#0b1f4d;
  classDef sv fill:#dcfce7,stroke:#15803d,color:#052e16;
  classDef op fill:#fef3c7,stroke:#b45309,color:#451a03;
  class O1,O9 op;
  class V1,V2,V3,V4,V5,V6,V7,X1,X2 sv;
  class G1,G2,G3,G4,G5,G6,G7 ag;
```

**Status perintah:** `pending → dispatched → succeeded | failed`, atau `expired` bila tak diambil/diselesaikan dalam `NETWATCH_COMMAND_TTL` (600 detik).

| Titik | Gejala | Cara cek | Tindakan |
|---|---|---|---|
| Tetap `pending` | Tidak ada reaksi di PC | Apakah perangkat online? (A4). Perintah hanya diambil saat heartbeat. | Tunggu satu interval (30 dtk); bila offline, perintah menjadi `expired` setelah 10 menit |
| `expired` | Event `command.expired` | `SELECT * FROM device_commands WHERE device_id=… ORDER BY id DESC` | Ulangi saat perangkat online |
| `failed`: "dilindungi" | Pesan `Proses '…' dilindungi` | Daftar di `config/netwatch.php` + `ProtectedProcesses` agent | Memang disengaja; proses sistem tidak boleh diakhiri |
| `failed`: "PID … sekarang milik proses lain" | PID dipakai ulang OS | Refresh daftar proses lalu coba lagi | Normal; mencegah salah-bunuh |
| `failed`: tanpa hak | Gagal `taskkill`/`kill` | `agent.log` (galat platform) | Agent harus berjalan sebagai SYSTEM/root |
| 404 / 409 pada `result` | Log agent `HTTP 404/409` | Perintah milik perangkat lain, atau sudah diselesaikan/kedaluwarsa | Abaikan; agent tidak mengulang |
| Restart/shutdown tak terjadi | Status `succeeded` tetapi PC hidup | Delay (bawaan 60 dtk) dan pembatalan manual pengguna | Cek `shutdown /a`; gunakan delay 0 untuk uji |

---

## A6. Kebijakan blokir (policy)

**Rujukan:** `PolicyBuilder::forDevice`, `PolicySync`, `BlockRuleGuard`, `agent/src/Policy/PolicyApplier.php`, `BlockPolicy`, `HostsFile`. Hanya perangkat **Windows**: agent Linux belum mendukung penerapan blokir (`LinuxPlatform::applyBlockPolicy` melempar "belum didukung" bila ada aturan). Detail fitur ada di [`BLOKIR.md`](BLOKIR.md).

```mermaid
flowchart TD
  subgraph ADM["Admin"]
    A1["Tambah aturan domain/IP di Policy<br/>(cakupan: semua / grup / perangkat)"]
    A2{"BlockRuleGuard: aturan meliputi<br/>server NetWatch (APP_URL / NETWATCH_SERVER_IPS)?"}
  end
  subgraph SV["Server (tiap heartbeat)"]
    S1["PolicyBuilder.forDevice:<br/>gabung aturan aktif dari policy aktif<br/>sesuai cakupan, hitung hash kanonik"]
    S2{"Hash agent (di heartbeat)<br/>= hash server?"}
    S3["Respons tanpa policy<br/>(nothingToApply di agent)"]
    S4["Respons membawa policy penuh"]
    S5["Catat event: policy.applied / policy.failed /<br/>policy.drift / policy.offline_release"]
  end
  subgraph AG["Agent (PolicyApplier)"]
    G1["BlockPolicy.fromArray<br/>validasi, tolak bila memuat host server"]
    G2["verify(): baca hosts, kebijakan browser, firewall<br/>→ hash aktual"]
    G3{"Hash aktual = diminta?"}
    G4{"Pernah gagal dengan hash ini<br/>< 5 menit lalu?"}
    G5["applyBlockPolicy: tulis hosts, kebijakan<br/>Chrome/Edge, aturan firewall IP"]
    G6{"Verifikasi setelah terapan<br/>cocok?"}
    G7["Simpan policy-state.json<br/>hash sukses"]
    G8["Simpan error di policy-state.json<br/>(dilaporkan di heartbeat berikut)"]
    G9["Server tak terjangkau ≥ 600 dtk:<br/>blokir dilepas (offline_release)"]
  end
  A1 --> A2
  A2 -- "ya" --> REJ["Aturan ditolak (pesan validasi)"]
  A2 -- "tidak" --> S1
  S1 --> S2
  S2 -- "sama" --> S3
  S2 -- "beda" --> S4 --> G1
  G1 -- "ditolak" --> G8
  G1 --> G2 --> G3
  G3 -- "ya" --> G7
  G3 -- "tidak" --> G4
  G4 -- "ya" --> SKIP["Lewati (tunggu 5 menit)"]
  G4 -- "tidak" --> G5 --> G6
  G6 -- "ya" --> G7
  G6 -- "tidak" --> G8
  G7 -.-> S5
  G8 -.-> S5
  G9 -.-> S5
  classDef ag fill:#dbeafe,stroke:#1d4ed8,color:#0b1f4d;
  classDef sv fill:#dcfce7,stroke:#15803d,color:#052e16;
  classDef op fill:#fef3c7,stroke:#b45309,color:#451a03;
  class A1,A2 op;
  class S1,S2,S3,S4,S5,REJ sv;
  class G1,G2,G3,G4,G5,G6,G7,G8,G9,SKIP ag;
```

**Pengaman yang sengaja ada:** (1) aturan yang meliputi server NetWatch ditolak server **dan** agent; (2) blokir dilepas otomatis bila PC lama tak bisa menghubungi server (di luar jaringan kantor); (3) setiap penerapan **diverifikasi** dengan membaca kembali sistem; (4) `netwatch-agent clear-policy` dan `recover-policy.ps1` untuk darurat.

| Titik | Gejala | Cara cek | Tindakan |
|---|---|---|---|
| `policy.failed` | Event Warning + pesan galat | Halaman perangkat → status policy; `agent.log` | Agent harus SYSTEM; antivirus mengunci `hosts`; GPO mengatur firewall penuh |
| `policy.drift` | Hash aktual ≠ yang diminta | Seseorang mengubah `hosts` / registry | Server mengirim ulang otomatis; bila berulang telusuri pelakunya |
| Situs tak terblokir | Policy tampil "diterapkan" | Browser memakai DoH/VPN/proxy; cache DNS | `ipconfig /flushdns`; tutup browser; cek kebijakan Chrome/Edge di `chrome://policy` |
| Semua situs terblokir / server tak bisa dibuka | Aturan terlalu luas | `BlockRuleGuard` seharusnya menolak | Jalankan `netwatch-agent clear-policy` atau `install\windows\recover-policy.ps1 -Apply` |
| Policy tidak turun ke PC | Hash tak pernah berubah | Perangkat Linux (tidak didukung), policy nonaktif, cakupan salah | Periksa `policies.is_enabled`, grup perangkat, OS |

---

## A7. Inventaris: port, layanan, perangkat keras, perangkat lunak

**Rujukan:** `InventoryController/Recorder` (port & layanan, tiap 10 menit), `AssetsController`/`AssetRecorder` (aset, tiap 5 menit). Dapat dimatikan lewat saklar fitur **Inventaris**.

```mermaid
flowchart TD
  subgraph AG["Agent"]
    I1["InventoryReporter: daftar port listen<br/>+ layanan"]
    I2["AssetReporter: perangkat keras<br/>+ program terpasang"]
  end
  subgraph SV["Server"]
    F{"Fitur Inventaris aktif?"}
    SK["Respons {skipped: true}<br/>data dibuang"]
    V{"FormRequest valid?<br/>maks 3000 port, 1500 layanan,<br/>3000 program"}
    T["Transaksi: upsert port / layanan<br/>hapus yang tak muncul lagi"]
    B{"Sudah punya baseline?<br/>(inventory_reported_at / assets_reported_at)"}
    N1["Event device.port_opened<br/>(TCP, terbuka ke jaringan, port < 49152,<br/>maks 10 per laporan, cooldown 24 jam)"]
    N2["Event software.installed / software.removed<br/>(maks 10 per jenis), hardware.changed<br/>(CPU, RAM, model, disk)"]
    N3["Laporan pertama: hanya simpan,<br/>tanpa event (menjadi baseline)"]
  end
  I1 --> F
  I2 --> F
  F -- "tidak" --> SK
  F -- "ya" --> V
  V -- "tidak" --> E422["HTTP 422"]
  V -- "ya" --> T --> B
  B -- "ya" --> N1
  B -- "ya" --> N2
  B -- "tidak" --> N3
  classDef ag fill:#dbeafe,stroke:#1d4ed8,color:#0b1f4d;
  classDef sv fill:#dcfce7,stroke:#15803d,color:#052e16;
  class I1,I2 ag;
  class F,SK,V,T,B,N1,N2,N3,E422 sv;
```

Catatan: `hardware = null` atau `software = null` artinya agent **gagal membaca** bagian itu; data lama dipertahankan (bukan dihapus). Program yang berganti versi dianggap pembaruan, bukan instal/uninstal.

| Titik | Gejala | Cara cek | Tindakan |
|---|---|---|---|
| Halaman Inventaris kosong | Belum ada laporan | `devices.inventory_reported_at`, `assets_reported_at` | Tunggu 10 menit setelah agent start; cek saklar **Fitur** |
| Banjir event `device.port_opened` | Aplikasi sering restart | Batas 10/laporan dan cooldown 24 jam sudah ada | Abaikan atau tutup port yang tak perlu |
| Event `software.removed` palsu | Agent gagal membaca daftar sebagian | `agent.log` | Agent mengirim `null` bila gagal total, tetapi daftar parsial akan dianggap uninstal; perbaiki sumber galat |
| Agent lama | Tidak ada data aset | Inventaris perangkat keras/lunak butuh agent 1.6.0+ | Pasang agent terbaru |

---

## A8. Bandwidth: counter jadi sampel

**Rujukan:** `BandwidthRecorder`, `BandwidthMath::delta`, `BandwidthRollup`. Agent mengirim **counter kumulatif** (`rx_bytes`, `tx_bytes`) per interface di setiap heartbeat; server menghitung selisihnya.

```mermaid
flowchart TD
  A["Heartbeat membawa interfaces[]<br/>name, rx_bytes, tx_bytes, speed_bps"] --> B{"Fitur Bandwidth aktif?"}
  B -- "tidak" --> Z["Abaikan"]
  B -- "ya" --> C["Untuk tiap interface:<br/>cari / buat device_interfaces"]
  C --> D{"Ada pembacaan sebelumnya?"}
  D -- "tidak (pertama)" --> R["Hanya simpan counter (rebase)"]
  D -- "ya" --> E{"BandwidthMath.delta:<br/>0 < selang ≤ 300 dtk<br/>dan counter tidak turun?"}
  E -- "tidak" --> R
  E -- "ya" --> S["Insert interface_samples:<br/>rx, tx, interval_seconds"]
  S --> R
  R --> H["Setiap jam (menit 05): netwatch:rollup-bandwidth<br/>sampel mentah → interface_samples_hourly"]
  H --> P["Sampel mentah dihapus setelah 3 hari,<br/>ringkasan per jam disimpan 365 hari"]
  classDef sv fill:#dcfce7,stroke:#15803d,color:#052e16;
  class A,B,Z,C,D,E,S,R,H,P sv;
```

| Titik | Gejala | Cara cek | Tindakan |
|---|---|---|---|
| Grafik bolong | Selang heartbeat > 300 dtk atau counter reset (reboot) | Disengaja: sampel tak valid dibuang, bukan diratakan | Normal; periksa kestabilan jaringan bila sering |
| Grafik kosong sama sekali | Fitur mati / agent lama | Menu **Fitur**; `device_interfaces` untuk perangkat | Aktifkan; update agent |
| Nilai terlalu besar | Dua adapter virtual terhitung | Lihat daftar interface di halaman bandwidth | Abaikan adapter virtual (Hyper-V/VPN) |
| Rollup tertinggal | Grafik 7 hari tak lengkap | `SELECT max(hour) FROM interface_samples_hourly` | Pastikan scheduler jalan; `artisan netwatch:rollup-bandwidth` manual (maks 14 hari per eksekusi) |

---

## A9. Browsing dan Windows Event Log

**Rujukan:** `agent/src/Browsing/*`, `BrowsingRecorder`, `BrowsingRollup`, `agent/src/Winlog/WinlogReporter.php`, `WinlogRecorder`, `WinlogClassifier`. Keduanya **dimatikan secara bawaan**; server memberi tahu agent lewat `browsing_enabled` / `winlog_enabled` di respons heartbeat. Pertimbangan privasi: [`BROWSING.md`](BROWSING.md).

```mermaid
flowchart TD
  subgraph AG["Agent"]
    B0{"browsing_enabled?"} -- "ya" --> B1["Baca riwayat Chrome/Edge sejak kursor<br/>(berkas baru: 10 menit terakhir) + koneksi aktif"]
    B1 --> B2["Sanitizer: buang query, chrome://, dll."]
    B2 --> B3["POST browsing (≤ 500 baris)"]
    W0{"winlog_enabled?"} -- "ya" --> W1["Baca log System & Application<br/>level 1–3 sejak kursor (awal: 1 jam)"]
    W1 --> W2["collapse: gabung kejadian identik,<br/>hitung count; maks 200 per kiriman"]
    W2 --> W3["POST winlog"]
  end
  subgraph SV["Server"]
    S1{"Saklar fitur aktif?"}
    S2["Respons kosong tanpa galat<br/>(data dibuang)"]
    S3["BrowsingRecorder: normalisasi,<br/>abaikan host di daftar abaikan,<br/>dedup, batas 5000 / perangkat / 24 jam,<br/>insertOrIgnore"]
    S4["WinlogRecorder: hanya log System/Application,<br/>umur ≤ 7 hari, buang duplikat (log|record),<br/>klasifikasi severity & kategori → event winlog.event"]
    S5["Harian: netwatch:prune → BrowsingRollup<br/>mentah > 14 hari → browsing_daily (365 hari)"]
  end
  B3 --> S1
  W3 --> S1
  S1 -- "tidak" --> S2
  S1 -- "ya" --> S3
  S1 -- "ya" --> S4
  S3 --> S5
  B3 -. "gagal kirim: kursor tak maju,<br/>kiriman diulang" .-> B1
  W3 -. "gagal kirim: kursor tak maju" .-> W1
  classDef ag fill:#dbeafe,stroke:#1d4ed8,color:#0b1f4d;
  classDef sv fill:#dcfce7,stroke:#15803d,color:#052e16;
  class B0,B1,B2,B3,W0,W1,W2,W3 ag;
  class S1,S2,S3,S4,S5 sv;
```

| Titik | Gejala | Cara cek | Tindakan |
|---|---|---|---|
| Browsing kosong | Saklar mati di server atau `.env` | Menu **Fitur**; `NETWATCH_BROWSING_ENABLED` | Aktifkan (cek kebijakan privasi perusahaan) |
| Hanya koneksi IP, tanpa URL | `pdo_sqlite` tidak ada | `agent.log`: "Ekstensi pdo_sqlite tidak ada" | Pasang ulang agent dari installer terbaru |
| Riwayat Chrome terkunci | `warnOnce` log per jam | `agent.log` | Normal bila browser sedang menulis; dicoba lagi |
| `over_limit` > 0 | Satu PC mengirim terlalu banyak | Respons `over_limit`; `NETWATCH_BROWSING_MAX_PER_DEVICE_DAY` | Tambah domain abaikan (CDN/iklan) atau naikkan batas |
| Winlog kosong | Saklar mati / sumber tidak ada | `NETWATCH_WINLOG_ENABLED`, menu Fitur; di Linux butuh `journalctl` (systemd) | Aktifkan. Sumber: Windows Event Log (agent 1.4.0+) dan journald Linux (1.5.0+, prioritas `err`) |
| Pesan winlog tampak terpotong | Dipotong 300 karakter dan karakter kontrol dibuang | Disengaja | Lihat Event Viewer Windows untuk aslinya |
| Tabel browsing membengkak | > 3 juta baris | Halaman **Keamanan** → "Volume data browsing" | Pastikan `netwatch:prune` jalan; turunkan `NETWATCH_RETENTION_BROWSING` |

---

## A10. Snapshot proses (Task Manager jarak jauh)

**Rujukan:** `ProcessSnapshotController`, `ProcessSnapshotService`, `agent/src/Command/ProcessReporter.php`. Inilah dasar fitur "cek task manager" di halaman perangkat.

```mermaid
flowchart TD
  T["Timer 5 menit<br/>atau perintah refresh_processes"] --> A["Agent: daftar proses<br/>pid, nama, CPU detik, RAM MB, user"]
  A --> B["POST /processes (maks 5000 proses)"]
  B --> C{"Middleware agent + FormRequest lolos?"}
  C -- "tidak" --> X["401 / 422 / 429"]
  C -- "ya" --> D["Simpan maks 500 proses ke process_snapshots<br/>(jsonb), process_count"]
  D --> E["Hapus snapshot lama:<br/>sisakan 12 terbaru per perangkat"]
  E --> F["Halaman perangkat menampilkan<br/>snapshot terbaru (poll 5 dtk)"]
  classDef ag fill:#dbeafe,stroke:#1d4ed8,color:#0b1f4d;
  classDef sv fill:#dcfce7,stroke:#15803d,color:#052e16;
  class T,A,B ag;
  class C,X,D,E,F sv;
```

| Titik | Gejala | Cara cek | Tindakan |
|---|---|---|---|
| Daftar proses lama | Waktu snapshot tertinggal | Kolom `captured_at` | Klik "Segarkan" (perintah `refresh_processes`) |
| Daftar terpotong | Proses > 500 | `max_processes_per_snapshot` | Disengaja (urut sesuai agent) |
| CPU proses tampil kumulatif | Agent mengirim `cpu_seconds` total | Bukan persentase | Bandingkan dua snapshot |

---

## A11. Pencabutan, pemasangan ulang, dan pencopotan agent

**Rujukan:** `DeviceController::destroy`, `Device::active()`, `NetWatchAgent.iss`, `install.ps1`, `uninstall.ps1`, `recover-policy.ps1`, `netwatch-agent clear-policy`.

```mermaid
flowchart TD
  subgraph ADM["Admin (dashboard)"]
    R1["Hapus / cabut perangkat"] --> R2["revoked_at diisi, interval status ditutup,<br/>event device.revoked + audit log"]
  end
  subgraph AG["Agent"]
    R3["Heartbeat berikutnya → 401"] --> R4["Agent berhenti<br/>(PC tidak lagi melapor)"]
    N1["Buat token pendaftaran baru<br/>installer /REENROLL=1"] --> N2["enroll (A1): hostname sama<br/>→ perangkat dipakai ulang"]
    U1["Uninstall (Control Panel / uninstall.ps1)"] --> U2["Hentikan tugas NetWatchAgent"]
    U2 --> U3["clear-policy: kosongkan hosts, kebijakan<br/>browser, firewall → verifikasi bersih"]
    U3 --> U4{"Bersih?"}
    U4 -- "ya" --> U5["Hapus C:\ProgramData\NetWatch (token)"]
    U4 -- "tidak" --> U6["Pertahankan data + salin skrip recover-policy.ps1<br/>ke ProgramData\NetWatch-Recovery, tampilkan petunjuk"]
  end
  R2 --> R3
  R4 --> N1
  classDef ag fill:#dbeafe,stroke:#1d4ed8,color:#0b1f4d;
  classDef op fill:#fef3c7,stroke:#b45309,color:#451a03;
  classDef sv fill:#dcfce7,stroke:#15803d,color:#052e16;
  class R1 op;
  class R2 sv;
  class R3,R4,N1,N2,U1,U2,U3,U4,U5,U6 ag;
```

| Titik | Gejala | Cara cek | Tindakan |
|---|---|---|---|
| Setelah uninstal situs tetap terblokir | `clear-policy` gagal | Pesan "Blokir NetWatch belum bisa dibersihkan" | Jalankan `recover-policy.ps1` (periksa tanpa `-Apply`, lalu dengan `-Apply`) sebagai Administrator |
| Perangkat ganda di dashboard | Hostname berubah sebelum enroll ulang | `devices` per hostname/MAC | Cabut entri lama |
| Reinstal tanpa token baru | Agent memakai `agent.json` lama | Token lama masih valid? (belum dicabut) | Cukup restart agent; token baru hanya bila dicabut |

---

## Lampiran — ringkasan jalur HTTP agent

| Jalur | Dipicu oleh | Autentikasi | Respons sukses |
|---|---|---|---|
| `POST enroll` | A1 | token pendaftaran | 201 |
| `POST heartbeat` | A2/A3 | Bearer | 200 |
| `POST processes` | A10 | Bearer | 201 |
| `POST inventory` | A7 | Bearer | 201 / `{skipped}` |
| `POST assets` | A7 | Bearer | 201 / `{skipped}` |
| `POST browsing` | A9 | Bearer | 201 / `{stored:0}` bila fitur mati |
| `POST winlog` | A9 | Bearer | 201 / `{stored:0}` bila fitur mati |
| `POST commands/{id}/result` | A5 | Bearer | 200 |

Detail muatan, kode status, dan contoh `curl` ada di [`API-AGENT.md`](API-AGENT.md).
