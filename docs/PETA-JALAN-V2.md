# NetWatch v2: Peta Jalan (arah SolarWinds + 8 fitur tambahan)

Basis: proyek NetWatch v1 yang sudah berjalan (server Laravel + PostgreSQL, agent PHP, installer Windows,
terbukti terhubung dan online di PC uji coba). Keputusan: mulai dari Fase 0; riwayat browsing domain saja;
server produksi nanti Linux/VM (laptop Windows hanya pilot).

## Status implementasi (diperbarui 2026-10-09)
| Bagian | Status |
|---|---|
| Fase 0: riwayat online/downtime (fitur 8) | **Kode selesai + tes ditulis, menunggu uji di mesin Anda.** Tabel `device_status_history`, `StatusTracker`, perintah `netwatch:check-status` (tiap menit), laporan ketersediaan per perangkat dan armada, filter tanggal, ekspor CSV. 13 tes logika murni sudah lulus; tes database/HTTP perlu dijalankan dengan `vendor\bin\phpunit`. |
| Fase 0 (sebagian): ICMP ping, notifikasi Telegram/email, mode maintenance | Selesai 13 Okt (perlu uji pilot) |
| No. 2 Riwayat browsing (IP + domain, Chrome/Edge) | Selesai 13 Okt (perlu uji pilot, agent 1.3.0) |
| Fase 0 (sisa): RBAC + cakupan grup + 2FA, aturan alert berbasis ambang | Belum |
| Fase 1-4 | Belum |


## 1. Yang dipertahankan dari v1
- API agent `/api/agent/v1/*` (heartbeat, daftar proses, perintah, hasil) tetap berlaku. Agent v1 di lapangan tidak boleh putus.
- Pendaftaran token sekali pakai, hash token, rate limit, header keamanan, audit log, retensi data.
- Perintah agent berbasis whitelist (tanpa shell bebas), proses sistem dilindungi di server dan agent.
- Installer Windows `.exe`, penjadwalan sebagai tugas SYSTEM saat boot, konfigurasi `agent.json`.
- Peran admin/operator/viewer (diperluas di v2, bukan diganti).

## 2. Hasil studi SolarWinds dan relevansinya

SolarWinds (Orion Platform) adalah rangkaian modul di atas satu platform: objek bernama *node*, antarmuka (*interface*),
volume, aplikasi; semuanya dipoll berkala, disimpan sebagai deret waktu, lalu dipakai oleh dashboard, alert, dan laporan.

| Modul SolarWinds | Fitur inti (dari dokumentasi resmi) | Relevansi ke NetWatch |
|---|---|---|
| **NPM** (Network Performance Monitor) | Discovery lewat ICMP/SNMP/WMI per subnet; poller SNMP; server syslog dan trap; alert lanjutan (kondisi bersarang, dependensi induk/anak, jadwal jam); dashboard kustom drag-and-drop; peta jaringan otomatis; NetPath (analisis hop); baseline dan ramalan kapasitas; laporan terjadwal; kesehatan perangkat keras | **Tinggi.** Ini kerangka v2: node, poller, status, alert, dashboard, laporan |
| **NTA** (NetFlow Traffic Analyzer) | Bandwidth per pengguna/aplikasi/percakapan dari NetFlow/sFlow; top talker; alert bandwidth | **Menengah.** Bandwidth per interface (fitur Anda no. 1 dan 4) dipenuhi lewat counter SNMP/agent. Analisis per aplikasi/pengguna butuh kolektor flow dan masuk Fase 4 |
| **SAM** (Server & Application Monitor) | Template monitor layanan, proses, port, performance counter, event log; 250+ template; kredensial per node | **Tinggi.** Cocok dengan fitur Anda no. 7 dan 5 |
| **UDT** (User Device Tracker) | Pemetaan perangkat/pengguna ke port switch dan VLAN lewat SNMP; deteksi perangkat asing (whitelist); watch list; riwayat koneksi; mematikan port | **Menengah.** Murah ditambahkan setelah SNMP ada. Fase 4 |
| **Log Analyzer / trap & syslog** | Penerima trap UDP 162 dan syslog; pencocokan OID ke MIB; tingkat keparahan; filter; alert dari kondisi log | **Tinggi** untuk fitur no. 5 dan 6 |
| **NCM** (Network Configuration Manager) | Backup dan perubahan konfigurasi perangkat jaringan | Rendah, nanti (Fase 4) |

Catatan: bandwidth per interface di SolarWinds berasal dari NPM (counter SNMP), sedangkan rincian "siapa/aplikasi apa yang memakai
bandwidth" berasal dari NetFlow di NTA. Pembagian yang sama saya pakai di sini.

## 3. Pemetaan 8 fitur Anda

### 1 dan 4. Bandwidth per interface, grafik trend, rekam, filter tanggal
- **Sumber data:** (a) perangkat jaringan lewat SNMP (`ifHCInOctets/ifHCOutOctets` 64-bit, `ifSpeed/ifHighSpeed`, `ifOperStatus`, error dan discard); (b) PC lewat agent (Windows: statistik adapter; Linux: `/proc/net/dev`).
- **Hitungan:** laju = selisih counter x 8 / selisih waktu. Wajib menangani counter wrap dan reset saat perangkat reboot (cek `sysUpTime`). Counter 32-bit tidak dipakai karena habis dalam hitungan detik pada 1 Gbps.
- **Penyimpanan:** `interface_samples` per menit, dipartisi per hari; agregasi otomatis ke 5 menit dan 1 jam. Retensi usulan: mentah 14 hari, 5 menit 90 hari, 1 jam 2 tahun.
- **Tampilan:** grafik trend masuk/keluar, utilisasi %, puncak, rata-rata, persentil ke-95, filter rentang tanggal (hari ini, 7/30 hari, kustom), ekspor CSV, perbandingan antar interface.

### 2. Riwayat browsing, filter periode, top visiting
- **Cara:** agent membaca database riwayat browser (Chrome, Edge, Firefox) tiap profil pengguna secara inkremental (salin berkas ke temp dulu karena terkunci), lalu mengirim domain, judul, waktu kunjungan, jumlah.
- **Batasan teknis:** mode incognito tidak tercatat. Isi halaman tidak terlihat. Yang tercatat adalah URL/domain.
- **Tampilan:** top domain, top pengguna, tren kunjungan, filter tanggal dan perangkat/grup.
- **Privasi (wajib diputuskan dulu):** detail default usulan = **domain saja**; URL penuh sebagai opsi per grup. Hak akses khusus ("lihat riwayat browsing"), log siapa yang membuka, retensi pendek (30-90 hari), dan pemberitahuan kepada karyawan lewat kebijakan perusahaan. Di Indonesia ini termasuk data pribadi (UU PDP No. 27/2022); sebaiknya dikonsultasikan ke HRD/hukum. Ini bukan nasihat hukum.

### 3. User management
- v1 sudah punya admin/operator/viewer. v2 menambah: matriks izin granular (RBAC), **cakupan per grup perangkat** (operator hanya melihat grupnya), 2FA TOTP, kebijakan password dan penguncian akun, daftar sesi aktif (cabut sesi), riwayat login, nonaktif/aktif, reset password. LDAP/Active Directory sebagai opsi lanjutan.

### 5. Event Viewer kategori manajemen jaringan untuk troubleshooting
- **Sumber:** (a) agent mengumpulkan Event Log Windows (dan `journald` di Linux) hanya untuk kategori yang relevan; (b) perangkat jaringan lewat syslog (UDP 514) dan trap SNMP (UDP 162).
- **Kategori awal:** Konektivitas (link up/down, kehilangan jaringan), DHCP, DNS, Wi-Fi, Driver/NIC, Daya dan shutdown tak terduga, Otentikasi, Layanan jaringan.
- **Pengumpulan:** inkremental per bookmark (record id), difilter berdasarkan kanal/ID/level agar volume kecil, batas per paket, dedup.
- **Tampilan:** daftar event dengan filter kategori, tingkat keparahan, perangkat, rentang waktu; **linimasa troubleshooting** yang menumpuk event, perubahan status online/offline, dan lonjakan bandwidth pada satu perangkat.
- Daftar kanal dan ID event yang tepat harus diverifikasi di Windows sungguhan sebelum dikunci.

### 6. Protokol SNMP
- Dukungan v1/v2c/v3 (v3 untuk produksi: autentikasi dan enkripsi). Kredensial disimpan terenkripsi.
- Discovery: sapuan ping dan SNMP per subnet, penambahan manual, deteksi jenis perangkat lewat `sysObjectID`.
- MIB awal: IF-MIB, SNMPv2-MIB (`sysUpTime`), HOST-RESOURCES (CPU/RAM/disk), ENTITY dan suhu bila tersedia; poller OID kustom.
- Poller memakai ekstensi `snmp` PHP atau biner net-snmp. Verifikasi ketersediaan di server Windows Anda (lihat risiko).

### 7. Open port dan service pada klien
- **Dari dalam (agent):** daftar port LISTEN beserta proses pemilik, dan daftar layanan Windows/systemd (nama, status, jenis start). Snapshot berkala + **diff**: event "port baru terbuka", "layanan berhenti".
- **Dari luar (server):** pemindaian TCP terjadwal pada rentang IP yang disetujui. Selisih dalam vs luar menunjukkan efek firewall.
- **Baseline per grup:** daftar port/layanan yang diizinkan; penyimpangan jadi alert.
- **Maintenance:** start/stop/restart layanan sebagai perintah whitelist (khusus admin), selaras dengan tujuan awal proyek.

### 8. Uptime, riwayat online dan downtime
- v1 hanya menyimpan `last_seen_at`; belum ada riwayat. v2 menambah `node_status_history` (status, mulai, selesai, durasi, sebab, sumber).
- **Status:** Up, Down (ping gagal), Agent-offline (ping ok tapi heartbeat berhenti), Maintenance, Unknown. Pembeda ini penting: heartbeat saja tidak bisa membedakan PC mati, jaringan putus, atau agent berhenti.
- **Sumber:** heartbeat agent, ICMP dari server, jangkauan SNMP, deteksi reboot dari penurunan uptime.
- **Laporan:** persentase ketersediaan (SLA) harian/mingguan/bulanan, jumlah dan durasi gangguan, MTTR, daftar downtime terlama, jendela maintenance dikecualikan, ekspor CSV/PDF.

## 4. Fitur ala SolarWinds yang ikut direncanakan
- **Mesin alert** (inti SolarWinds): aturan "metrik operator ambang selama N menit", cakupan node/grup, tingkat keparahan, acknowledge, eskalasi, jam tenang, dependensi induk/anak (supaya satu switch mati tidak memunculkan 40 alert). Notifikasi: email, Telegram, webhook (WhatsApp lewat gateway bila diinginkan).
- **Dashboard NOC** yang dapat diatur, **laporan terjadwal**, **mode maintenance/unmanage**, **grup dan properti kustom**, **pustaka kredensial**, **peta topologi** (LLDP/CDP), baseline dinamis dan ramalan kapasitas, UDT, kolektor NetFlow, backup konfigurasi.

## 5. Perubahan arsitektur
```
Browser -> Laravel (web, API, scheduler, antrean)
                 |            ^ 
   PostgreSQL <--+            | hasil poll, event
                 |
   Poller (SNMP/ICMP/port scan)  -- worker proses terpisah (service)
   Penerima syslog/trap UDP      -- worker proses terpisah (service)
   Agent v1/v2 di PC              -- heartbeat + metrik + event + port/layanan
```
- **Konsep `node`**: `devices` v1 digeneralisasi menjadi node berjenis *agent*, *snmp*, atau *icmp-only*. Tabel dan API v1 tetap berjalan (kompatibel mundur).
- **Deret waktu di PostgreSQL** (partisi deklaratif + tabel agregasi), tanpa teknologi baru. TimescaleDB menjadi opsi bila beban membesar.
- **Poller dan penerima UDP** sebagai proses panjang terpisah dari web (dijalankan sebagai layanan). Bila skala tumbuh, kolektor ini bisa diganti program Go tanpa mengubah web dan database.
- **Agent:** tetap PHP untuk Fase 0-3 agar installer dan kode yang ada terus dipakai. Agent Go satu file (rencana tahap B) sangat dianjurkan sebelum jumlah koleksi di agent bertambah (NIC, event log, port, riwayat browser), karena semuanya kini akan memanggil PowerShell berkali-kali.

### Tabel baru (ringkas)
`nodes` (generalisasi devices) · `node_credentials` (terenkripsi) · `node_status_history` · `interfaces` · `interface_samples`
(+ `_5m`, `_1h`) · `events` (terpadu: agent, syslog, trap) · `event_categories` · `listening_ports` · `node_services` · `port_baselines` ·
`browsing_visits` (+ agregat harian) · `alert_rules` · `alert_events` · `notification_channels` · `maintenance_windows` ·
`permissions`, `role_permissions`, `user_group_scopes`, `user_totp` · `dashboards`, `dashboard_widgets` · `report_schedules`.

## 6. Fase dan urutan
Urutan ditentukan oleh ketergantungan: status/alert/hak akses dipakai semua fitur lain; deret waktu dipakai bandwidth;
riwayat browsing paling bergantung pada keputusan privasi sehingga ditaruh belakangan.

| Fase | Isi | Fitur Anda | Ukuran* |
|---|---|---|---|
| **0 Fondasi** | Node generik, riwayat uptime/downtime + ICMP, mesin alert dasar + email/Telegram, RBAC + cakupan grup + 2FA, mode maintenance | 8, 3 | M |
| **1 Bandwidth + SNMP** | Poller SNMP, discovery, interface, sampel + agregasi, grafik trend, filter tanggal, persentil 95, ekspor; counter NIC dari agent | 1, 4, 6 | L |
| **2 Port, layanan, event** | Port/layanan agent + diff + baseline + kontrol layanan, event collector + kategori, penerima syslog/trap, linimasa troubleshooting | 7, 5, 6 | L |
| **3 Riwayat browsing** | Kolektor browser, top domain, filter tanggal, kontrol akses + audit + retensi | 2 | M |
| **4 Tingkat SolarWinds** | Dashboard NOC kustom, peta topologi, laporan terjadwal, UDT, baseline/ramalan, NetFlow, backup konfigurasi, agent Go | - | XL |

*M = beberapa sesi kerja kecil, L = beberapa kali lipat, XL = proyek tersendiri. Estimasi jam tidak diberikan karena bergantung pada hasil uji di perangkat nyata.

Setiap fase diakhiri uji di PC/perangkat jaringan pilot dan rilis yang bisa langsung dipakai, bukan satu rilis besar di akhir.

## 7. Risiko dan hal yang perlu diverifikasi
| Risiko | Dampak | Penanganan |
|---|---|---|
| Server produksi di laptop Windows | Poller/UDP 162 bentrok dengan layanan SNMP Trap bawaan Windows; tidak ada `pcntl` untuk paralelisme; laptop tidur | Laptop dipakai untuk pilot. Produksi disarankan server Linux/VM. Poller dibuat bisa jalan di keduanya |
| Ekstensi SNMP PHP di Windows | Mungkin perlu pustaka tambahan | Uji di server Anda di awal Fase 1; cadangan: biner net-snmp |
| Beban agent (PowerShell tiap siklus) | CPU naik di PC lama saat koleksi bertambah | Interval dibedakan per jenis data, koleksi dibatch; agent Go (tahap B) |
| Riwayat browsing | Risiko privasi/hukum dan kepercayaan karyawan | Keputusan kebijakan dulu, domain saja secara default, akses dibatasi, audit, retensi pendek |
| Volume data deret waktu | Tabel membengkak, grafik lambat | Partisi, agregasi, retensi bertingkat, indeks, batas rentang kueri |
| Kredensial SNMP/SSH tersimpan | Kebocoran bila database bocor | Enkripsi aplikasi (kunci di luar DB), akses terbatas, tidak pernah ditampilkan ulang |
| Pemindaian port dari server | Dianggap serangan oleh keamanan jaringan | Hanya rentang yang disetujui, terjadwal, tercatat di audit |
| Kode server belum teruji menyeluruh | v1 baru lulus uji otomatis dan satu PC pilot | Setiap fase wajib disertai tes otomatis dan uji pilot |

## 8. Keputusan yang dibutuhkan sebelum Fase 0
1. Jumlah PC dan perangkat jaringan (switch/router/AP) yang akan dimonitor, serta merek dan dukungan SNMP (v2c atau v3).
2. Server produksi: tetap laptop Windows atau akan ada server Linux/VM.
3. Kebijakan riwayat browsing: tingkat detail (domain saja atau URL penuh) dan siapa yang boleh melihat.
4. Kanal notifikasi alert: email (butuh SMTP), Telegram, WhatsApp.
5. Fase mana yang dimulai lebih dulu (usulan: Fase 0).

## Sumber
- [SolarWinds NPM: fitur monitoring jaringan](https://www.solarwinds.com/network-performance-monitor/network-monitoring-software)
- [SolarWinds NPM datasheet](https://content.solarwinds.com/creative/v9.7/pdf/datasheets/SW_NPM_Datasheet.pdf)
- [SolarWinds User Device Tracker](https://solarwinds.com/user-device-tracker)
- [SolarWinds SNMP trap receiver dan Log Analyzer](https://solarwinds.com/topics/snmp-trap-receiver)
- [Dokumentasi SolarWinds SAM](https://documentation.solarwinds.com/en/success_center/sam/content/onboarding/monitor%20your%20environment/sam_ob_monitor_your_os.htm)


## Status pelaksanaan (diperbarui 12 Okt 2026)

| Item | Status |
|---|---|
| 3. Manajemen pengguna (dasar: tambah/ubah/nonaktifkan/reset sandi, proteksi admin terakhir) | Selesai. Sisa: riwayat login, penguncian akun, sesi aktif, cakupan grup, izin rinci, 2FA |
| 8. Uptime dan riwayat downtime | Selesai |
| 1 dan 4. Bandwidth per interface, grafik trend, filter tanggal, CSV | Selesai (agent 1.1.0) |
| 9. Auto refresh 30 detik | Selesai |
| 5. Event Viewer, tahap 1 (event dari server: status, perintah, pendaftaran, keamanan) | Selesai. Tahap 2: event Windows Event Log dari agent |
| Pemeriksaan keamanan (halaman Keamanan, `netwatch:security-check`, docs/KEAMANAN.md) | Selesai |
| Blokir situs/IP di PC (agent Windows: hosts, firewall, kebijakan Chrome/Edge), lihat docs/BLOKIR.md | Selesai (agent 1.2.0). Integrasi firewall jaringan: ditunda, vendor belum diketahui |
| 2. Riwayat browsing | Menunggu keputusan: URL penuh butuh ekstensi browser terkelola (lihat percakapan); alamat IP tujuan dari agent bisa langsung |
| Policies (grup policy: nama + banyak domain/IP, penugasan semua/grup perangkat/perangkat) | Selesai (server); aturan lama otomatis dimigrasi |
| Perbaikan aturan blokir tertinggal + skrip pemulihan, lihat docs/BLOKIR.md | Selesai (agent 1.5.0); pemulihan Windows belum diuji di PC sungguhan |
| 7. Port dan layanan terbuka, lihat docs/PORT-LAYANAN.md | Selesai (agent 1.4.0+); diff "port hilang"/"layanan berhenti" belum |
| 5 tahap 2: Windows Event Log + journald, lihat docs/EVENT-LOG.md | Selesai sebagian; syslog UDP 514 dan SNMP trap belum |
| 6. SNMP | Belum |
| Alert ambang CPU/RAM/disk + grafik tren per perangkat, lihat docs/INVENTARIS-ALERT.md | Selesai (server); belum diuji di server sungguhan |
| Inventaris perangkat keras & lunak (instal/uninstall terdeteksi), lihat docs/INVENTARIS-ALERT.md | Selesai (agent 1.6.0 + server); belum diuji di PC sungguhan |
| Tiket bantuan IT + penilaian layanan (shortcut Desktop, kode sekali pakai via email/popup, laporan), lihat docs/TIKET.md | Selesai (server + installer); belum diuji di PC sungguhan |
| Network Operations Center: ping/cek port perangkat jaringan tanpa agent, status Online/Lambat/Offline, event + notifikasi, lihat docs/NOC.md | Selesai (server); belum diuji di jaringan sungguhan |
| Pengurangan data browsing (rollup harian, retensi berjenjang, domain diabaikan), lihat docs/BROWSING.md | Selesai |

## Update 22 Okt: Menu Monitoring terpadu + saklar fitur
Selesai: tab Perangkat/Bandwidth/Browsing, halaman Bandwidth fleet & Browsing interaktif, panel Fitur admin. Lihat `docs/MONITORING.md`. Belum: SNMP, syslog/trap, 2FA, update agent bertanda tangan, CI, paket produksi Linux.
