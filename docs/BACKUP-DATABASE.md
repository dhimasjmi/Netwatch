# Backup dan pemulihan database NetWatch

## Cara kerjanya
- `php artisan netwatch:backup` memakai **pg_dump** (format custom, terkompres) dan berjalan otomatis **tiap hari 01:30** lewat penjadwal.
- Berkas ditulis sebagai `.partial`, diperiksa dapat dibaca `pg_restore --list`, baru diganti nama menjadi `netwatch-AAAAMMDD-JJMMDD.dump` (waktu UTC). Backup setengah jadi tidak pernah tampak valid.
- Setiap backup punya berkas `.sha256` (checksum). Bila **salinan kedua** diatur, berkas disalin dan checksum-nya dibandingkan.
- Sebelum mulai, ruang disk diperiksa (butuh ≥ 1,5× backup terakhir dan ≥ `NETWATCH_BACKUP_MIN_FREE_MB`).
- Jika gagal, tercatat event `backup.failed` (ikut notifikasi bila alert diaktifkan) dan terlihat di halaman **Administrasi → Database & Backup**.

### Yang disimpan dan yang tidak
Struktur **semua** tabel selalu ikut. **Isi** tabel mentah berumur pendek tidak ikut (besar dan tidak kritis): `device_heartbeats`, `process_snapshots`, `monitor_target_checks`, `interface_samples`, `sessions`, `cache`. Setelah pemulihan, grafik CPU/RAM beberapa hari terakhir dan ping jaringan mulai kosong dan terisi lagi sendiri. Ringkasan bandwidth per jam, ringkasan browsing harian, perangkat, policy, tiket, event, audit log, dan akun **ikut dipulihkan**.
Ubah dengan `NETWATCH_BACKUP_EXCLUDE_DATA` (daftar tabel, pisahkan koma).

### Pengaturan (`.env`)
| Variabel | Bawaan | Keterangan |
|---|---|---|
| `NETWATCH_BACKUP_ENABLED` | `true` | Matikan hanya bila backup ditangani alat lain. |
| `NETWATCH_BACKUP_PATH` | `storage/app/backups` | **Ganti** ke disk lain, mis. `/var/backups/netwatch`. |
| `NETWATCH_BACKUP_COPY_TO` | kosong | Salinan kedua (share/disk lain). Sangat dianjurkan. |
| `NETWATCH_BACKUP_KEEP_DAILY/WEEKLY/MONTHLY` | 7 / 4 / 6 | Rotasi: 7 harian, 4 mingguan, 6 bulanan terbaru; yang terbaru tak pernah dihapus. |
| `NETWATCH_PG_DUMP`, `NETWATCH_PG_RESTORE` | `pg_dump`, `pg_restore` | Isi path penuh bila tidak ada di PATH (Windows/Laragon: `C:\laragon\bin\postgresql\...\bin\pg_dump.exe`). |
| `NETWATCH_BACKUP_MIN_FREE_MB` | 2048 | Ruang kosong minimum sebelum backup. |
| `NETWATCH_BACKUP_STALE_HOURS` | 36 | Backup lebih tua dari ini ditandai basi. |

Perkiraan ukuran: database dengan 300 PC dan data bertahun-tahun biasanya beberapa GB di disk, tetapi backup terkompres jauh lebih kecil karena data mentah dikecualikan. Pantau ukuran sebenarnya di halaman Database & Backup, lalu atur disk backup minimal **10×** ukuran satu backup (rotasi menyimpan hingga 17 berkas).

## Perintah
```bash
sudo -u www-data php artisan netwatch:backup                          # backup sekarang
sudo -u www-data php artisan netwatch:backup --verify=NAMA_ATAU_PATH   # periksa backup lama (checksum + isi terbaca)
sudo -u www-data php artisan netwatch:db-stats                         # ukuran tabel, retensi, status backup
```

## Yang harus disimpan terpisah dari database
1. **`server/.env`** (berisi `APP_KEY` dan sandi database). Simpan salinannya di pengelola sandi/brankas, bukan hanya di server.
2. **Sertifikat TLS** (`/etc/ssl/netwatch/`) dan CA yang dibagikan ke agent.
3. Catatan IP/nama server.

## Pemulihan ke server baru (atau setelah kerusakan)
Anggap server Linux baru sudah melewati **Bagian 1–6** di `docs/DEPLOY-LINUX.md` (aplikasi terpasang, database `netwatch` **kosong** dan role `netwatch` ada). Gunakan `.env` lama (terutama `APP_KEY`).

1. Hentikan penjadwal agar tidak menulis saat pemulihan:
   ```bash
   sudo mv /etc/cron.d/netwatch /root/cron-netwatch.bak
   ```
2. Salin backup ke server, lalu **periksa**:
   ```bash
   sha256sum -c netwatch-20261026-013000.dump.sha256
   pg_restore --list netwatch-20261026-013000.dump | grep -E "TABLE (DATA )?public (users|devices) "
   ```
3. Pastikan database kosong (jika bukan, buat ulang):
   ```bash
   sudo -u postgres psql -c "DROP DATABASE IF EXISTS netwatch;"
   sudo -u postgres psql -c "CREATE DATABASE netwatch OWNER netwatch ENCODING 'UTF8';"
   ```
4. Pulihkan:
   ```bash
   PGPASSWORD='sandi-db' pg_restore --host=127.0.0.1 --username=netwatch --dbname=netwatch \
     --no-owner --exit-on-error netwatch-20261026-013000.dump
   ```
   `--exit-on-error` menghentikan proses bila ada kesalahan, supaya tidak ada pemulihan setengah jadi.
5. Samakan skema bila backup dari versi lebih lama, lalu hidupkan cache:
   ```bash
   cd /var/www/netwatch/server
   sudo -u www-data php artisan migrate --force
   sudo -u www-data php artisan optimize:clear && sudo -u www-data php artisan config:cache
   ```
6. Kembalikan penjadwal dan periksa:
   ```bash
   sudo mv /root/cron-netwatch.bak /etc/cron.d/netwatch
   sudo -u www-data php artisan netwatch:db-stats
   ```
7. Buka dashboard: perangkat, policy, dan tiket harus ada. Agent yang online akan melapor lagi sendiri dalam hitungan menit; perangkat yang sudah mati ditandai offline oleh penjadwal.

Server baru dengan **IP/nama yang sama** membuat agent terhubung tanpa pemasangan ulang. Jika IP berubah, ubah alamat server di `C:\ProgramData\NetWatch\agent.json` tiap PC atau pasang ulang agent.

## Memulihkan satu tabel saja (mis. tidak sengaja menghapus policy)
```bash
pg_restore --list backup.dump | grep policies            # pastikan nama tabel
pg_restore --data-only --table=policies --dbname=netwatch_pulih backup.dump
```
Lakukan di database **sementara** (`netwatch_pulih`), periksa, lalu salin barisnya; jangan menimpa database produksi tanpa backup terbaru.

## Latihan pemulihan (WAJIB, minimal tiap 3 bulan)
Backup yang belum pernah dipulihkan belum bisa dianggap aman.
1. Di VM/komputer lain, pasang PostgreSQL dan buat database kosong `netwatch_uji`.
2. `pg_restore --no-owner --dbname=netwatch_uji backup-terbaru.dump`
3. Cek jumlah baris: `select count(*) from devices;` `select count(*) from users;` sesuai dugaan.
4. Catat tanggal latihan dan berapa lama waktunya (target pemulihan Anda).

## Jika backup gagal
| Pesan | Tindakan |
|---|---|
| `pg_dump tidak bisa dijalankan` | Pasang `postgresql-client-16` atau isi `NETWATCH_PG_DUMP` dengan path penuh. |
| `Ruang disk kurang untuk backup` | Kosongkan disk backup atau perbesar; kurangi `KEEP_*`. |
| `Folder backup tidak bisa ditulis` | `sudo chown www-data:www-data /var/backups/netwatch`. |
| `salinan kedua gagal` | Cek mount/share dan izin tulis `www-data`; backup utama tetap berhasil. |
| `pg_dump gagal ... version mismatch` | Versi `pg_dump` harus ≥ versi server PostgreSQL; samakan paket klien. |
