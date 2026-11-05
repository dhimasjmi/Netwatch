# Menggabungkan database NetWatch ke database bersama (satu database, beda schema)

Panduan ini untuk kasus: **NetWatch memakai database PostgreSQL yang juga dipakai aplikasi Laravel lain**, di server database terpisah (mis. server pusat Linux). Semua tabel NetWatch **dipertahankan utuh** dan dipisahkan dari aplikasi lain oleh **schema** PostgreSQL bernama `netwatch`.

```
Server NetWatch (Ubuntu)                 Server pusat PostgreSQL 14
  Nginx + PHP-FPM + scheduler   ──5432──▶  database NAMA_DB
  (tanpa PostgreSQL server)                  ├─ schema public    → tabel aplikasi lain (tidak disentuh)
                                             └─ schema netwatch  → SEMUA tabel NetWatch
```

> **STATUS PENERAPAN (baca dulu).** Perubahan kode di Lampiran A **sudah ada di paket ini** (konfigurasi `DB_SCHEMA`, backup per schema, ukuran dan daftar tabel per schema), lengkap dengan tes baru. **Tes tersebut belum dijalankan** di sisi saya (PHPUnit butuh `vendor/` yang tidak ada di tempat saya membuatnya): jalankan `vendor\bin\phpunit` di laptop dan pastikan lulus sebelum memakai panduan ini untuk produksi. Bagian SQL-nya sudah diuji langsung di PostgreSQL 16 uji.
>
> Pastikan `.env` berisi `DB_SCHEMA=netwatch` **sebelum** menjalankan `migrate`. Tanpa itu tabel NetWatch dibuat di schema `public`, bercampur dengan aplikasi lain.

> **Status pengujian.** Perintah SQL, `pg_dump --schema`, `pg_restore -L`, trik ganti nama schema, dan pembatasan hak role diuji di **PostgreSQL 16** (database uji sementara, sudah dihapus). Belum diuji di PostgreSQL **14.21** dan belum diuji bersama aplikasi Laravel penuh. Jika ada perintah yang gagal di server Anda, berhenti di langkah itu dan laporkan pesan errornya. Setiap langkah punya baris **Cek**.

---

## 0. Putuskan dan catat dulu

| Keputusan | Nilai di panduan ini | Catatan |
|---|---|---|
| Jalur | **A. Deploy baru** (tanpa data lama) atau **B. Bawa data pilot** dari Laragon | Produksi belum berjalan → **A paling sederhana dan aman**. Pilih B hanya bila riwayat pilot perlu. |
| Nama schema | `netwatch` | Jangan memakai `public`. |
| Role database | `netwatch` (bukan superuser) | Satu role khusus; tidak berbagi dengan aplikasi lain. |
| Nama database bersama | `NAMA_DB` | Tanyakan ke pengelola server pusat. |
| Alamat server database | `DB_SERVER` (IP atau nama DNS) | |
| Alamat server NetWatch | `IP_NETWATCH` | Dipakai untuk membatasi akses di firewall dan `pg_hba.conf`. |

Siapa mengerjakan apa: Bagian 1–3 butuh akses **superuser/pengelola server database**; Bagian 4–7 dikerjakan di server NetWatch.

### Versi PostgreSQL (penting)
- Server tujuan: **14.21**. Kode NetWatch tidak memakai sintaks khusus PostgreSQL 15/16 (sudah diperiksa pada seluruh migrasi dan query), jadi jalan di 14.
- Di server NetWatch (Ubuntu 24.04) pasang **`postgresql-client-16`** (bawaan Ubuntu). Klien yang lebih baru boleh menyambung ke server 14; **`pg_dump` yang lebih lama dari server ditolak**. Jangan memasang klien versi di bawah server.
- Jalur B: berkas dump dari Laragon (PostgreSQL 16) **tidak bisa dibaca `pg_restore` 14**. Gunakan `pg_restore` versi 16 (cukup dari server NetWatch, Bagian 6).

---

## 1. Pengecekan awal di server database (hanya melihat, tidak mengubah)

Sebagai superuser, terhubung ke `NAMA_DB`:
```bash
sudo -u postgres psql -d NAMA_DB
```
```sql
SELECT version();                 -- harus 14.x
\dn+                              -- daftar schema + hak akses; catat pemilik schema public
\du                               -- daftar role; catat role yang dipakai aplikasi lain
SHOW max_connections;
SELECT count(*) FROM pg_stat_activity;   -- koneksi yang sedang terpakai
\dx                               -- ekstensi terpasang (NetWatch tidak membutuhkan ekstensi)
```
Simpan **daftar tabel sebelum** NetWatch masuk, untuk pembanding di Bagian 7:
```bash
sudo -u postgres psql -d NAMA_DB -At -c "SELECT schemaname||'.'||tablename FROM pg_tables WHERE schemaname NOT IN ('pg_catalog','information_schema') ORDER BY 1" > ~/tabel-sebelum.txt
```
Tanyakan/cek: apakah aplikasi lain memakai `search_path` selain `public`? Jika ya, pastikan tidak ada yang memuat `netwatch` (Bagian 9).

**Backup database bersama dulu** (pengelola server pusat): `pg_dump -Fc NAMA_DB > sebelum-netwatch.dump`.

---

## 2. Buat role dan schema (superuser, terhubung ke `NAMA_DB`)

```sql
CREATE ROLE netwatch LOGIN PASSWORD 'GANTI-DENGAN-SANDI-ACAK-PANJANG';
CREATE SCHEMA netwatch AUTHORIZATION netwatch;
REVOKE ALL ON SCHEMA netwatch FROM PUBLIC;                        -- aplikasi lain tidak bisa membaca/membuat di schema ini
ALTER ROLE netwatch IN DATABASE NAMA_DB SET search_path = netwatch;
ALTER ROLE netwatch CONNECTION LIMIT 30;                          -- sesuaikan; lihat Bagian 9
```
Buat sandi acak dengan `openssl rand -base64 24` dan simpan di pengelola sandi. Bila hak `CONNECT` pada database dicabut dari `PUBLIC` oleh pengelola, tambahkan `GRANT CONNECT ON DATABASE NAMA_DB TO netwatch;`.

**Pengetatan opsional (PostgreSQL 14):** di versi 14 semua role bawaannya boleh membuat tabel di schema `public`. Role `netwatch` tidak akan memakainya karena `search_path`-nya hanya `netwatch`, jadi ini bukan syarat. Mencabutnya (`REVOKE CREATE ON SCHEMA public FROM PUBLIC;`) hanya dilakukan **bersama pemilik aplikasi lain**, karena aplikasi itu bisa bergantung pada izin tersebut dan migrasinya akan rusak.
> Perintah `REVOKE ... FROM netwatch` **tidak berefek** pada `public` karena hak itu diberikan ke grup `PUBLIC`, bukan ke role `netwatch` secara langsung.

**Cek (sebagai superuser):**
```sql
\dn+ netwatch                       -- pemilik netwatch, Access privileges netwatch=UC/netwatch (tanpa baris untuk PUBLIC)
```
Dan dari sisi role (setelah Bagian 3): `search_path` harus `netwatch`, dan role **tidak boleh** membaca tabel aplikasi lain:
```bash
PGPASSWORD='sandi' psql "host=DB_SERVER dbname=NAMA_DB user=netwatch sslmode=require" \
  -c "SHOW search_path" -c "SELECT * FROM public.NAMA_TABEL_APLIKASI_LAIN LIMIT 1"
# baris pertama: netwatch; baris kedua harus: ERROR: permission denied for table ...
```

---

## 3. Jaringan dan keamanan koneksi

Di **server database**:
1. `postgresql.conf` (Ubuntu: `/etc/postgresql/14/main/postgresql.conf`): pastikan `listen_addresses` mencakup alamat server (mis. `'localhost,DB_SERVER'`). Mengubahnya butuh `sudo systemctl restart postgresql` — **koordinasikan**, aplikasi lain ikut terputus sebentar. Bila sudah mendengarkan jaringan, tidak perlu diubah.
2. `pg_hba.conf`: tambahkan **satu baris spesifik** (tidak membuka seluruh jaringan), di atas aturan umum yang mungkin lebih longgar:
   ```
   hostssl  NAMA_DB  netwatch  IP_NETWATCH/32  scram-sha-256
   ```
   Lalu `sudo systemctl reload postgresql`.
3. SSL aktif: `SHOW ssl;` harus `on`. Bila `off`, minta pengelola mengaktifkannya (sertifikat server) sebelum NetWatch dipakai, agar sandi dan data tidak lewat jaringan polos.
4. Firewall server database hanya mengizinkan server NetWatch:
   ```bash
   sudo ufw allow from IP_NETWATCH to any port 5432 proto tcp
   ```

Di **server NetWatch** (Ubuntu): pasang hanya klien, **bukan** server PostgreSQL:
```bash
sudo apt install -y postgresql-client-16
psql --version
```
**Cek** dari server NetWatch:
```bash
PGPASSWORD='sandi' psql "host=DB_SERVER port=5432 dbname=NAMA_DB user=netwatch sslmode=require" -c "select current_user, current_schema()"
```
Hasil: `netwatch | netwatch`. Bila `connection refused/timeout` → firewall atau `listen_addresses`; bila `no pg_hba.conf entry` → baris `pg_hba.conf` salah (IP/nama database/role).

---

## 4. Siapkan server NetWatch

Ikuti `docs/DEPLOY-LINUX.md` **Bagian 1, 2 (tanpa `postgresql postgresql-contrib`, lihat tabel varian di sana), 4, 5, 7, 8, 9, 10, 11**. Bagian 3 (membuat database lokal) **dilewati** karena digantikan Bagian 1–3 dokumen ini.

Nilai `.env` yang berbeda dari panduan deploy:
```ini
DB_HOST=DB_SERVER
DB_PORT=5432
DB_DATABASE=NAMA_DB
DB_USERNAME=netwatch
DB_PASSWORD=sandi-role-netwatch
DB_SCHEMA=netwatch
DB_SSLMODE=require            # atau verify-full bila memakai sertifikat CA internal
```
`DB_SCHEMA` didukung oleh kode (Lampiran A). **Isi dulu, baru jalankan `migrate`.** Satu nilai saja (`netwatch`); jangan menulis `netwatch,public`.

**Cek:** `sudo -u www-data php artisan config:show database.connections.pgsql.search_path` menampilkan `netwatch`.

---

## 5. Jalur A — deploy baru (tanpa data lama)

```bash
cd /var/www/netwatch/server
sudo -u www-data php artisan package:discover --ansi
sudo -u www-data php artisan migrate --force
sudo -u www-data php artisan netwatch:create-user admin@perusahaan.local --name="Admin" --role=admin
sudo -u www-data php artisan config:cache && sudo -u www-data php artisan route:cache && sudo -u www-data php artisan view:cache
```
**Cek:**
```bash
sudo -u www-data php artisan migrate:status        # semua "Ran"
PGPASSWORD='sandi' psql "host=DB_SERVER dbname=NAMA_DB user=netwatch sslmode=require" -c '\dt netwatch.*'   # semua tabel NetWatch
```
Lalu lanjut ke **Bagian 7**.

---

## 6. Jalur B — bawa data pilot dari Laragon

Data pilot ada di schema `public` database `netwatch` di laptop. Kita salin ke **database sementara**, ganti nama schema-nya menjadi `netwatch`, lalu pindahkan hanya schema itu. Database pilot asli **tidak diubah**.

**B1. Di laptop (cmd; sesuaikan folder `bin` PostgreSQL Laragon):**
```bat
set PATH=C:\laragon\bin\postgresql\postgresql\bin;%PATH%
pg_dump -U postgres -Fc --no-owner --no-privileges -f netwatch-pilot.dump netwatch
createdb -U postgres nw_tmp
pg_restore -U postgres --no-owner --exit-on-error -d nw_tmp netwatch-pilot.dump
psql -U postgres -d nw_tmp -c "ALTER SCHEMA public RENAME TO netwatch" -c "CREATE SCHEMA public"
pg_dump -U postgres -Fc --schema=netwatch --no-owner --no-privileges --exclude-table-data=netwatch.device_heartbeats --exclude-table-data=netwatch.process_snapshots --exclude-table-data=netwatch.monitor_target_checks --exclude-table-data=netwatch.interface_samples --exclude-table-data=netwatch.sessions -f netwatch-schema.dump nw_tmp
dropdb -U postgres nw_tmp
```
Baris `--exclude-table-data` melewatkan **isi** tabel mentah yang besar dan tidak kritis (strukturnya tetap ikut), sama seperti backup resmi (`NETWATCH_BACKUP_EXCLUDE_DATA`). Hapus baris itu jika Anda ingin membawa semuanya.

**B2. Pindahkan `netwatch-schema.dump` ke server NetWatch** (`scp`) dan salin juga nilai `APP_KEY` dari `.env` laptop ke `.env` server (data terenkripsi butuh kunci yang sama).

**B3. Di server NetWatch**, periksa isi arsip, buang baris pembuatan schema, lalu pulihkan sebagai role `netwatch`:
```bash
pg_restore --list netwatch-schema.dump | grep -E "TABLE DATA netwatch (devices|users) "     # data inti ada
pg_restore --list netwatch-schema.dump | grep -v -E ' SCHEMA (- )?netwatch ' > daftar.txt
export PGPASSWORD='sandi-role-netwatch'
pg_restore --host=DB_SERVER --username=netwatch --dbname=NAMA_DB --no-owner --exit-on-error \
  --use-list=daftar.txt netwatch-schema.dump
```
> **Kenapa daftar disaring?** Arsip memuat perintah `CREATE SCHEMA netwatch`. Schema sudah kita buat di Bagian 2 dan role `netwatch` sengaja tidak punya hak membuat schema di database, jadi tanpa penyaringan pemulihan berhenti dengan `permission denied for database`. Dengan `--exit-on-error` tidak ada pemulihan setengah jadi.

Pakai `pg_restore` versi 16 (paket `postgresql-client-16`) — lihat catatan versi di Bagian 0.

**B4. Verifikasi isi.** Bandingkan jumlah baris tabel inti di laptop (schema `public`) dan di server (schema `netwatch`); angkanya harus sama (kecuali tabel yang isinya dilewati):
```sql
SELECT 'devices' t, count(*) FROM devices
UNION ALL SELECT 'users', count(*) FROM users
UNION ALL SELECT 'events', count(*) FROM events
UNION ALL SELECT 'tickets', count(*) FROM tickets
UNION ALL SELECT 'audit_logs', count(*) FROM audit_logs;
```
Di server: jalankan lewat role `netwatch` (search_path sudah `netwatch`). Cek juga penghitung ID agar tidak bentrok: `SELECT last_value FROM devices_id_seq;` harus ≥ `SELECT max(id) FROM devices;`.

**B5. Samakan struktur dengan kode terbaru, lalu siapkan cache:**
```bash
cd /var/www/netwatch/server
sudo -u www-data php artisan migrate --force      # menjalankan migrasi yang belum ada; tabel migrations ikut terpindah
sudo -u www-data php artisan optimize:clear && sudo -u www-data php artisan config:cache
```

**B6. Agent.** Alamat server NetWatch berubah dari laptop ke server Linux, kecuali Anda mengarahkan DNS/IP lama ke server baru. Ikuti bagian "Memindahkan dari server uji" di `docs/DEPLOY-LINUX.md` (IP sama, atau ubah `agent.json`/pasang ulang agent). Selama alamat belum berpindah, agent akan terlihat offline.

Lanjut ke **Bagian 7**.

---

## 7. Verifikasi akhir

1. **Tidak ada yang berubah di aplikasi lain.** Bandingkan daftar tabel dengan Bagian 1:
   ```bash
   sudo -u postgres psql -d NAMA_DB -At -c "SELECT schemaname||'.'||tablename FROM pg_tables WHERE schemaname NOT IN ('pg_catalog','information_schema') ORDER BY 1" > ~/tabel-sesudah.txt
   diff ~/tabel-sebelum.txt ~/tabel-sesudah.txt
   ```
   Semua baris hasil `diff` harus berawalan `> netwatch.` dan **tidak ada** baris `<` (tidak ada tabel yang hilang).
2. `php artisan migrate:status` semuanya `Ran`; `php artisan netwatch:security-check` tanpa status "Berisiko" (role bukan superuser).
3. Dashboard: login, **Monitoring → Perangkat**, grafik Bandwidth, **Administrasi → Database & Backup** (ukuran dan daftar tabel hanya milik NetWatch, bukan seluruh database bersama).
4. `php artisan netwatch:backup` lalu `--verify` (lihat Bagian 8) — arsip hanya berisi schema `netwatch`:
   ```bash
   pg_restore --list "$(ls -t /var/backups/netwatch/*.dump | head -1)" | grep -c " public "     # harus 0
   ```
5. Satu PC pilot Online dan datanya masuk; periksa **Event** dan **Audit Log** sehari penuh.

---

## 8. Backup dan pemulihan setelah digabung

Setelah Lampiran A, `netwatch:backup` hanya mem-backup schema `netwatch` (aplikasi lain tidak ikut), dan data mentah tetap dilewati. Jadwal, rotasi, salinan kedua, dan `--verify` sama dengan `docs/BACKUP-DATABASE.md`.

**Pemulihan schema `netwatch` (diuji di PostgreSQL 16):**
1. Hentikan penjadwal: `sudo mv /etc/cron.d/netwatch /root/cron-netwatch.bak`, dan `php artisan down`.
2. Periksa backup: `sha256sum -c NAMA.dump.sha256`
3. Pengelola database (superuser) mengosongkan dan membuat ulang **hanya schema NetWatch**:
   ```sql
   DROP SCHEMA netwatch CASCADE;                      -- menghapus semua data NetWatch; aplikasi lain tidak tersentuh
   CREATE SCHEMA netwatch AUTHORIZATION netwatch;
   REVOKE ALL ON SCHEMA netwatch FROM PUBLIC;
   ```
4. Pulihkan sebagai role `netwatch`, dengan daftar yang menyaring baris `CREATE SCHEMA` (alasan sama dengan B3):
   ```bash
   pg_restore --list NAMA.dump | grep -v -E ' SCHEMA (- )?netwatch ' > daftar.txt
   PGPASSWORD='sandi' pg_restore --host=DB_SERVER --username=netwatch --dbname=NAMA_DB --no-owner --exit-on-error \
     --use-list=daftar.txt NAMA.dump
   ```
5. `php artisan migrate --force`, `optimize:clear`, `config:cache`, `php artisan up`, kembalikan `/etc/cron.d/netwatch`.
6. Periksa dashboard dan `netwatch:db-stats`.

Pemulihan hanya satu tabel: sama seperti `docs/BACKUP-DATABASE.md`, tetapi ke database sementara dan dengan `--schema=netwatch`.

**Latihan pemulihan tiap 3 bulan** tetap wajib: lakukan ke database/schema uji, jangan ke `netwatch` produksi.

---

## 9. Pemeliharaan di server bersama

- **`deploy/postgresql/netwatch.conf` jangan disalin ke server pusat.** Isinya (`shared_buffers`, `max_wal_size`, `autovacuum_naptime`, ...) berlaku untuk **seluruh server** dan memengaruhi aplikasi lain. Serahkan daftar itu ke pengelola server pusat sebagai **saran** (terutama `max_wal_size`, `checkpoint_completion_target`, dan autovacuum), biar mereka yang memutuskan.
- **Yang bisa Anda atur sendiri tanpa mengganggu orang lain**, sebagai role `netwatch` (pemilik tabel): vacuum lebih agresif hanya untuk tabel NetWatch yang banyak INSERT/DELETE:
  ```sql
  ALTER TABLE device_heartbeats      SET (autovacuum_vacuum_scale_factor = 0.05, autovacuum_analyze_scale_factor = 0.02);
  ALTER TABLE interface_samples      SET (autovacuum_vacuum_scale_factor = 0.05, autovacuum_analyze_scale_factor = 0.02);
  ALTER TABLE browsing_visits        SET (autovacuum_vacuum_scale_factor = 0.05, autovacuum_analyze_scale_factor = 0.02);
  ALTER TABLE monitor_target_checks  SET (autovacuum_vacuum_scale_factor = 0.05, autovacuum_analyze_scale_factor = 0.02);
  ALTER TABLE process_snapshots      SET (autovacuum_vacuum_scale_factor = 0.05, autovacuum_analyze_scale_factor = 0.02);
  ```
  Tabel yang dibuat ulang (mis. setelah pemulihan) kehilangan pengaturan ini; jalankan lagi.
- **Jangan** memberi `statement_timeout` pada role `netwatch`: `pg_dump` dan pembersihan retensi bisa panjang dan akan terputus.
- **Koneksi:** tiap proses PHP-FPM aktif memakai satu koneksi, ditambah satu untuk penjadwal. Cek `pm.max_children` (bawaan Ubuntu 5) dan sesuaikan `CONNECTION LIMIT` role (Bagian 2) agar tidak menghabiskan `max_connections` milik aplikasi lain.
- **Jam sibuk:** pembersihan retensi 02:30 dan backup 01:30 menambah beban singkat. Bila aplikasi lain punya pekerjaan berat di jam itu, jam `netwatch:backup` dan `netwatch:prune` bisa digeser di `routes/console.php` (`dailyAt(...)`); cek hasilnya dengan `php artisan schedule:list`.
- **Pembaruan PostgreSQL oleh pengelola server** (mis. 14 → 16): beri tahu agar mencatat schema `netwatch`, dan jalankan `netwatch:backup` + verifikasi sebelum dan sesudah.
- **Bahaya `migrate:fresh` / `db:wipe`:** perintah Laravel ini menghapus **semua tabel di schema pada `search_path` koneksinya**. Di NetWatch hanya `netwatch`. Di aplikasi Laravel lain **`search_path` tidak boleh memuat `netwatch`** (mis. `public,netwatch`), jika tidak `migrate:fresh` milik mereka menghapus data NetWatch. Jangan pernah menjalankan `migrate:fresh` di produksi.

---

## 10. Rollback

- **Jalur A:** tidak ada data yang hilang karena belum ada. Untuk mengeluarkan NetWatch dari database bersama (pengelola, superuser; **menghapus semua data NetWatch**, backup dulu):
  ```sql
  DROP SCHEMA netwatch CASCADE;
  DROP ROLE netwatch;
  ```
  Aplikasi lain tidak terpengaruh.
- **Jalur B:** database pilot di laptop tidak diubah. Kembalikan alamat server agent (atau `.env`) ke laptop. Data yang masuk ke server baru **tidak ikut kembali**; ekspor dulu bila perlu.
- Jangan menghapus database pilot selama 1–2 minggu setelah pindah, dan simpan `netwatch-schema.dump` serta `sebelum-netwatch.dump`.

---

## 11. Masalah umum

| Gejala | Penyebab / tindakan |
|---|---|
| `connection refused` / timeout dari server NetWatch | Firewall server database (port 5432 dari `IP_NETWATCH`) atau `listen_addresses` belum mencakup alamat server. |
| `no pg_hba.conf entry for host ...` | Baris `pg_hba.conf` salah (IP, nama database, atau role) atau belum di-reload. |
| `SSL required` / `server does not support SSL` | `ssl = off` di server, atau `DB_SSLMODE` tidak cocok. Aktifkan SSL di server; jangan turunkan ke `disable` di produksi. |
| `permission denied for schema netwatch` | Schema dibuat tanpa `AUTHORIZATION netwatch`. Perbaiki: `ALTER SCHEMA netwatch OWNER TO netwatch;` |
| Tabel NetWatch muncul di `public` setelah `migrate` | `DB_SCHEMA` tidak terbaca (config cache lama, atau `DB_SCHEMA` belum diisi di `.env`). `php artisan config:clear`, periksa `.env`. Tabel salah tempat dipindah dengan `ALTER TABLE public.X SET SCHEMA netwatch;` oleh superuser. |
| `pg_restore: error: ... permission denied for database` | Baris `CREATE SCHEMA` belum disaring dari daftar (Bagian 6 B3 / Bagian 8 langkah 4). |
| `pg_restore: unsupported version (1.15) in file header` | `pg_restore` lebih lama dari `pg_dump` yang membuat berkas (mis. restore 14 untuk dump 16). Pakai `pg_restore` versi 16. |
| `pg_dump: error: aborting because of server version mismatch` | `pg_dump` di server NetWatch lebih lama dari server database. Pasang `postgresql-client-16`. |
| Ukuran/daftar tabel di halaman Database & Backup memuat tabel aplikasi lain | `DB_SCHEMA` kosong/`public` pada database bersama, atau kode lama (sebelum rilis 29 Okt 2026). |
| `relation "migrations" does not exist` setelah ganti `.env` | `DB_SCHEMA` kosong/salah atau schema belum berisi tabel. Jalankan Jalur A atau B. |

---

## 12. Daftar periksa

- [ ] Backup database bersama dibuat sebelum mulai; backup NetWatch/pilot disimpan.
- [ ] Role `netwatch` bukan superuser; `search_path` role = `netwatch`; **tidak bisa** membaca tabel aplikasi lain.
- [ ] `pg_hba.conf` hanya baris `hostssl` spesifik untuk `IP_NETWATCH`; firewall 5432 hanya dari `IP_NETWATCH`; SSL aktif.
- [ ] `vendor\bin\phpunit` lulus di laptop (Lampiran A sudah ada di kode); `.env` server berisi `DB_SCHEMA=netwatch` sebelum `migrate`.
- [ ] Setelah `migrate`: `diff` daftar tabel hanya menambah `netwatch.*`; tidak ada tabel yang hilang.
- [ ] Backup pertama diverifikasi dan arsipnya tidak memuat schema lain; salinan kedua ada.
- [ ] Latihan pemulihan schema dilakukan sekali di database uji.
- [ ] `search_path` aplikasi Laravel lain tidak memuat `netwatch`; pemiliknya diberi tahu.
- [ ] Pengelola server pusat menerima daftar saran konfigurasi (Bagian 9) dan batas koneksi disepakati.

---

## Lampiran A — Perubahan kode (sudah diterapkan di paket ini)

Perubahan kecil dan terisolasi. Jalankan `vendor/bin/phpunit` (tes memakai database uji `netwatch_test` di schema `public` lewat `DB_SCHEMA=public` di `phpunit.xml`, tidak terpengaruh `.env` Anda).

1. **`config/database.php`** (koneksi `pgsql`): `'search_path' => env('DB_SCHEMA', 'public'),`; `DB_SCHEMA=public` ditambahkan di `.env.example` dan `phpunit.xml`. Nilai tetap `public` di kode lama menimpa pengaturan role di database.
2. **`app/Support/PgDumpCommand.php`**: `schema()` membaca schema dari `search_path` (nama tidak aman jatuh ke `public`), `dump()` menambahkan `--schema=<schema>` dan `--exclude-table-data=<schema>.<tabel>`, `hasUsersTable()` dipakai pemeriksaan isi backup di `BackupService`. Tes: `tests/Unit/PgDumpCommandTest.php`. Pemasangan lokal (`public`) berperilaku sama seperti sebelumnya.
3. **`app/Services/DatabaseHealthService.php`**: ukuran = jumlah ukuran tabel di schema aktif (label di halaman menjadi "Ukuran data NetWatch"), `pg_stat_user_tables` difilter `schemaname = current_schema()`. Tes: `DatabaseHealthTest`.
4. **`app/Services/SecurityChecker.php`**: query `pg_class ... relname = 'browsing_visits'` dibatasi ke schema aktif (gabung `pg_namespace`).
5. **Dokumentasi**: `docs/BACKUP-DATABASE.md` dan `docs/DEPLOY-LINUX.md` sudah merujuk dokumen ini.

## Lampiran B — Ringkasan yang sudah diuji

Diuji di PostgreSQL 16 (database uji sementara): ganti nama schema pada salinan lalu `pg_dump --schema`; arsip memuat entri `SCHEMA - netwatch`; pemulihan sebagai role non-superuser gagal tanpa penyaringan dan berhasil dengan `--use-list`; data, sequence (`nextval` lanjut dari nilai terakhir), indeks parsial, dan FK ikut pulih; role `netwatch` mendapat `search_path = netwatch` dan ditolak membaca tabel `public` milik role lain; `--exclude-table-data=netwatch.<tabel>` melewatkan isi tabel dengan strukturnya tetap ikut; tabel aplikasi lain tidak berubah. **Belum diuji:** PostgreSQL 14.21, `pg_restore` 16 dari berkas ke server 14, pengaturan `pg_hba.conf`/SSL, dan aplikasi Laravel penuh di atas schema ini.
