# Basis data: pertumbuhan, performa, dan pemeliharaan jangka panjang

## Ringkasan
Database **tidak tumbuh tanpa batas**. Setiap tabel yang bertambah terus punya masa simpan dan dibersihkan otomatis tiap malam (`netwatch:prune`, 02:30). Data lama dirangkum dulu (bandwidth per jam, browsing per hari) sehingga grafik jangka panjang tetap ada tetapi kecil. Ukuran akan naik selama beberapa minggu pertama lalu **mendatar** setelah semua masa simpan terlampaui.

## Perkiraan ukuran (300 PC, 2 interface jaringan, 50 target jaringan)
Angka ini **perkiraan dari lebar baris + indeks**, ditambah uji muat parsial di PostgreSQL 16 (8 juta baris heartbeat termuat dalam ±2 menit). Ukuran sebenarnya lihat di **Administrasi → Database & Backup**.

| Data | Masa simpan | Perkiraan baris | Perkiraan ukuran |
|---|---|---|---|
| Heartbeat (CPU/RAM/disk, tiap 30 dtk) | 14 hari | ±12 juta | ±2 GB |
| Bandwidth mentah | 3 hari | ±5 juta | ±0,6 GB |
| Bandwidth per jam (ringkasan) | 365 hari | ±5 juta | ±0,5 GB |
| Browsing mentah (±1.500/PC/hari) | 14 hari | ±6 juta | ±1,2 GB |
| Browsing harian (ringkasan) | 365 hari | ±11 juta | ±1,5 GB |
| Cek jaringan (per menit) | 7 hari | ±0,5 juta | <0,1 GB |
| Event, audit log, perintah, tiket, riwayat uptime | 90–730 hari | kecil | <0,2 GB |
| **Total saat mendatar** | | | **±6–7 GB** |

Untuk 100 PC kira-kira sepertiganya. Siapkan disk database ≥ 3× angka ini (ruang VACUUM, WAL, pertumbuhan) dan disk backup terpisah.

## Mengapa tidak melambat
- Semua kueri dashboard memakai rentang waktu + indeks `(perangkat, waktu)`; tabel besar tidak dipindai penuh.
- Rentang panjang membaca **ringkasan** (bandwidth per jam: ±100 ribu baris untuk 7 hari; browsing harian), bukan data mentah.
- Pembersihan dilakukan per 10.000 baris agar tidak mengunci tabel; autovacuum diatur lebih agresif di `deploy/postgresql/netwatch.conf`.
- Halaman Monitoring menyimpan hasil beberapa detik (`NETWATCH_FLEET_CACHE`) agar banyak penonton dengan auto-refresh tidak melipatgandakan beban.
- Jumlah baris tabel browsing dihitung dibatasi (tampil "100.000+") sehingga rentang besar tanpa filter tetap cepat.

## Yang belum terukur (jujur)
Waktu kueri nyata pada data bertahun-tahun **belum diukur** (uji beban terhenti sebelum bagian kueri). Karena itu ada alat pemantauan agar masalah terlihat sebelum terasa:
- Halaman **Database & Backup** dan `php artisan netwatch:db-stats`: ukuran tabel, baris mati, **data tertua vs retensi** (tanda TERTINGGAL bila pembersihan macet), status backup.
- `log_min_duration_statement = 1000` di PostgreSQL mencatat semua kueri > 1 detik di log PostgreSQL. Periksa bulanan: `sudo grep "duration:" /var/log/postgresql/postgresql-16-main.log | tail`.

## Pemeliharaan rutin
| Kapan | Apa |
|---|---|
| Mingguan | Lihat halaman Database & Backup: tidak ada peringatan, backup terakhir < 36 jam. |
| Bulanan | `netwatch:db-stats`; cek ruang disk (`df -h`), log kueri lambat, `apt upgrade`. |
| Tiap 3 bulan | **Latihan pemulihan backup** (lihat `docs/BACKUP-DATABASE.md`). |
| Tahunan | Evaluasi retensi, perbesar disk, pertimbangkan upgrade PostgreSQL. |

## Menyesuaikan bila disk terlalu besar
Kurangi masa simpan di `.env` (lalu `php artisan config:cache`); data lebih tua dibersihkan pada pembersihan malam berikutnya:
```ini
NETWATCH_RETENTION_HEARTBEATS=7        # grafik CPU/RAM
NETWATCH_RETENTION_BROWSING=7          # rincian URL mentah (ringkasan harian tetap)
NETWATCH_RETENTION_BROWSING_DAILY=180
NETWATCH_RETENTION_BANDWIDTH=180       # ringkasan bandwidth per jam
NETWATCH_BROWSING_MAX_PER_DEVICE_DAY=2000
```
Ruang yang dibebaskan dipakai ulang oleh PostgreSQL (tidak langsung mengecilkan file). Mengecilkan file butuh `VACUUM FULL` (mengunci tabel) — jarang perlu; lakukan di luar jam kerja bila memang perlu.

## Batas desain
Untuk > ±1.000 PC atau retensi jauh lebih panjang, pertimbangkan partisi tabel per waktu (`device_heartbeats`, `browsing_visits`, `interface_samples`) dan memisahkan database dari server web. Di bawah itu, satu PostgreSQL pada VM 8 GB cukup.
