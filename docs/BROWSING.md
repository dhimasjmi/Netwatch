# Riwayat browsing (IP dan domain)

Fitur ini memperlihatkan **situs/domain** yang dibuka pengguna dan **IP tujuan** koneksi keluar di tiap PC. Pastikan sudah sesuai
ketentuan perusahaan dan pengguna sudah diberi tahu sebelum diaktifkan.

## Apa yang dikumpulkan
| Sumber | Isi | Cara |
|---|---|---|
| Riwayat Chrome/Edge (semua profil semua pengguna di PC) | domain, dan alamat halaman bila `NETWATCH_BROWSING_DETAIL=path` | agent membaca salinan berkas `History` |
| Koneksi keluar (TCP established) | IP publik tujuan, port, nama proses, nama domain bila ada di cache DNS | `Get-NetTCPConnection` + `Get-DnsClientCache` |

**Selalu dibuang sebelum disimpan:** kata pencarian/query (`?q=...`), fragmen, username/password dalam URL, judul halaman,
alamat internal (`chrome://`, `edge://`, `file://`, `localhost`), IP LAN/loopback/multicast, dan navigasi iframe/iklan.

## Mengaktifkan
1. Bangun ulang installer agent **1.3.0** (butuh ekstensi SQLite yang kini ikut dibundel), pasang ulang di PC klien.
2. Di `.env` server:
   ```
   NETWATCH_BROWSING_ENABLED=true
   NETWATCH_BROWSING_DETAIL=domain      # atau path
   NETWATCH_RETENTION_BROWSING=14       # hari data mentah (URL dan jam persis)
   NETWATCH_RETENTION_BROWSING_DAILY=365 # hari ringkasan harian
   NETWATCH_BROWSING_MAX_PER_DEVICE_DAY=5000
   ```
   lalu `php artisan config:clear`.
3. Agent mulai mengirim paling lambat 2 menit setelah heartbeat berikutnya. Buka menu **Administrasi → Browsing**.
Mematikan `NETWATCH_BROWSING_ENABLED` menghentikan pengumpulan di semua agent pada heartbeat berikutnya.

## Halaman Browsing
Filter perangkat, jenis (situs/koneksi), pencarian domain/IP, dan tanggal (maks. 90 hari); peringkat situs dan IP terbanyak;
rincian per waktu; ekspor CSV (aman dari injeksi rumus Excel). Hanya administrator yang dapat membuka, dan **setiap pembukaan dan
ekspor tercatat di Audit Log**.

## Keterbatasan (jujur)
- Hanya Chrome dan Edge. Firefox dan browser lain belum.
- Mode InPrivate/Incognito tidak masuk riwayat browser; tetapi IP tujuannya tetap terlihat dari koneksi aktif.
- Koneksi dibaca tiap 2 menit, jadi koneksi yang sangat singkat bisa terlewat. Satu pasangan IP+proses hanya dicatat sekali per 30 menit.
- Angka di "Jumlah" untuk koneksi berarti kemunculan koneksi, bukan jumlah halaman dibuka. Satu IP CDN bisa melayani banyak situs.
- Berkas riwayat kadang terkunci browser; agent mencoba lagi pada siklus berikutnya dan mencatat peringatan di log (maks. sekali per jam).
- Agent harus berjalan sebagai SYSTEM/administrator agar bisa membaca profil semua pengguna (sudah demikian dari installer).
- Bila ekstensi SQLite tidak ada (installer lama), hanya koneksi IP yang dikumpulkan.

## Mengendalikan ukuran data

Riwayat browsing adalah tabel yang tumbuh paling cepat, jadi ada empat lapis pengendali (semua otomatis):

1. **Domain diabaikan** (Browsing → Domain yang diabaikan): update Windows, telemetri, cek sertifikat, dan sejenisnya
   beserta subdomainnya tidak disimpan. Daftar bawaan bisa diedit admin; berlaku untuk data baru.
2. **Batas per perangkat**: maksimal `NETWATCH_BROWSING_MAX_PER_DEVICE_DAY` baris mentah per 24 jam per PC; kelebihannya dibuang.
3. **Rollup harian**: `netwatch:prune` (harian 02:30) merangkum data mentah yang lebih tua dari `NETWATCH_RETENTION_BROWSING`
   menjadi satu baris per perangkat, hari, jenis, dan situs (jumlah kunjungan + waktu pertama/terakhir), lalu menghapus data mentahnya
   dalam satu transaksi. Ukurannya jauh lebih kecil, dan laporan "situs paling sering" tetap akurat karena menggabungkan data mentah dan ringkasan.
   Pada data yang sudah dirangkum URL lengkap dan jam persis tidak tersedia, dan IP tujuan hanya tersimpan bila koneksi tidak punya nama domain.
4. **Retensi ringkasan**: baris harian dihapus setelah `NETWATCH_RETENTION_BROWSING_DAILY` hari.

Halaman Browsing menampilkan ringkasan (situs dan IP teratas) lebih dulu; rincian per kunjungan hanya dimuat bila memilih perangkat
atau mengetik pencarian. Halaman Keamanan memberi peringatan bila tabel mentah melewati ±3 juta baris (biasanya scheduler tidak berjalan).
