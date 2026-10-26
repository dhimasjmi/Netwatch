# Deploy NetWatch ke server Linux (produksi)

Panduan ini untuk **Ubuntu Server 24.04 LTS** (PHP 8.3 dan PostgreSQL 16 sudah ada di repositori resminya). Debian 12 bisa dipakai, tetapi PHP 8.3 perlu repositori tambahan (mis. sury.org).
Semua perintah dijalankan dari user biasa yang bisa `sudo` (di panduan ini: `deploy`), bukan langsung sebagai root.

> **Status verifikasi:** setiap langkah memuat baris **Cek** supaya Anda tahu langkah itu berhasil sebelum lanjut. Panduan ini belum dijalankan di server Ubuntu kosong oleh penulisnya; jika ada perintah yang gagal di mesin Anda, berhenti di langkah itu dan laporkan pesan errornya.

---

## 0. Putuskan dulu (5 menit, jangan dilewati)

| Keputusan | Rekomendasi | Alasannya |
|---|---|---|
| Alamat server | **IP tetap** + nama DNS (mis. `netwatch.perusahaan.local`) | Agent menyimpan alamat server saat mendaftar. Mengganti alamat berarti mengubah semua PC. |
| Spesifikasi VM | 4 vCPU, **8 GB RAM**, SSD **100 GB** (minimum 2 vCPU / 4 GB / 50 GB untuk ≤ 100 PC) | Database tumbuh karena heartbeat, bandwidth, browsing. Retensi otomatis menjaganya, tetapi disk tetap perlu ruang untuk backup. |
| HTTPS | **Wajib** untuk produksi | Token API agent dan login admin lewat jaringan. |
| Lokasi backup | **Disk/mesin lain** dari database | Backup di disk yang sama ikut hilang bila disk rusak. |
| Server lama (laptop/Laragon) | Tetap hidup sampai server baru lolos Bagian 12 | Bisa dijadikan cadangan bila terjadi masalah. |

Catat dua nilai ini, dipakai berulang: **`NAMA_SERVER`** (mis. `netwatch.perusahaan.local`) dan **`IP_SERVER`** (mis. `172.21.110.14`).

---

## 1. Siapkan sistem operasi

```bash
sudo apt update && sudo apt full-upgrade -y
sudo timedatectl set-timezone UTC          # server tetap UTC; tampilan dashboard memakai NETWATCH_TIMEZONE
sudo apt install -y chrony ufw unzip curl git ca-certificates openssl
sudo systemctl enable --now chrony         # jam harus akurat (token, jadwal, laporan uptime)
```
**Cek:** `timedatectl` menampilkan `System clock synchronized: yes`.

## 2. Pasang PHP, Nginx, PostgreSQL, Composer

```bash
sudo apt install -y nginx postgresql postgresql-contrib \
  php8.3-fpm php8.3-cli php8.3-pgsql php8.3-mbstring php8.3-xml php8.3-curl php8.3-zip php8.3-intl php8.3-bcmath \
  composer iputils-ping
```
- `iputils-ping` dibutuhkan fitur Jaringan (NOC) dan "ping perangkat offline".

**Cek:**
```bash
php -v                  # PHP 8.3.x
php -m | grep -E "pdo_pgsql|mbstring|curl|xml"      # keempatnya muncul
psql --version          # 16.x
sudo -u www-data ping -c1 127.0.0.1                  # berhasil: user web boleh ping
```

## 3. Buat database

```bash
sudo -u postgres psql <<'SQL'
CREATE ROLE netwatch LOGIN PASSWORD 'GANTI-DENGAN-SANDI-ACAK-PANJANG';
CREATE DATABASE netwatch OWNER netwatch ENCODING 'UTF8';
SQL
```
Buat sandi acak: `openssl rand -base64 24`. Simpan di pengelola sandi.

**Cek:** `PGPASSWORD='sandi-tadi' psql -h 127.0.0.1 -U netwatch -d netwatch -c 'select 1'` mengembalikan `1`.

Terapkan penyetelan performa (lihat komentar di dalam file untuk VM 4 GB / 16 GB):
```bash
sudo cp /var/www/netwatch/deploy/postgresql/netwatch.conf /etc/postgresql/16/main/conf.d/netwatch.conf   # setelah Bagian 4
sudo systemctl restart postgresql
```
PostgreSQL **hanya** mendengarkan `localhost` secara bawaan; biarkan begitu (jangan buka port 5432 ke jaringan).

## 4. Pasang kode aplikasi

Dari komputer Anda (yang memegang `netwatch.tar.gz`):
```bash
scp netwatch.tar.gz deploy@IP_SERVER:/tmp/
```
Di server:
```bash
sudo mkdir -p /var/www && cd /var/www
sudo tar xzf /tmp/netwatch.tar.gz            # menghasilkan /var/www/netwatch/{server,agent,docs,deploy}
sudo chown -R deploy:deploy /var/www/netwatch
cd /var/www/netwatch/server
composer install --no-dev --optimize-autoloader --no-interaction --no-scripts
```
**Cek:** `ls vendor/autoload.php` ada.

Pembagian kepemilikan (aman): **kode dimiliki `deploy`** (web server tidak bisa mengubah kode), hanya dua folder yang boleh ditulis `www-data`:
```bash
sudo chown -R www-data:www-data storage bootstrap/cache
sudo chmod -R u+rwX,g+rX storage bootstrap/cache
```

## 5. Isi konfigurasi `.env`

```bash
cd /var/www/netwatch/server
cp .env.example .env
sudo chown www-data:www-data .env && sudo chmod 640 .env       # file ini dimiliki www-data; edit dengan sudo
sudo -u www-data php artisan key:generate --force
sudo nano .env
```
Nilai yang **wajib** diubah:

```ini
APP_ENV=production
APP_DEBUG=false
APP_URL=https://NAMA_SERVER

DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=netwatch
DB_USERNAME=netwatch
DB_PASSWORD=sandi-database-tadi

SESSION_SECURE_COOKIE=true
NETWATCH_FORCE_HTTPS=true
NETWATCH_TIMEZONE=Asia/Jakarta
NETWATCH_SERVER_IPS=IP_SERVER          # IP server ini tidak pernah boleh terblokir oleh policy

# Backup (lihat Bagian 9 dan docs/BACKUP-DATABASE.md)
NETWATCH_BACKUP_PATH=/var/backups/netwatch
NETWATCH_BACKUP_COPY_TO=               # isi setelah folder salinan kedua siap
```
Fitur opsional (nyalakan hanya bila sesuai kebijakan perusahaan): `NETWATCH_BROWSING_ENABLED`, `NETWATCH_WINLOG_ENABLED`, `NETWATCH_ALERTS_ENABLED` + token Telegram/email.

**Cek:** `sudo -u www-data php artisan config:show app.env` menampilkan `production`.

> **Simpan salinan `.env` di tempat aman** (bukan di server yang sama). `APP_KEY` di dalamnya dibutuhkan untuk membuka data terenkripsi bila server dipulihkan.

## 6. Migrasi database dan akun admin pertama

```bash
cd /var/www/netwatch/server
sudo -u www-data php artisan package:discover --ansi
sudo -u www-data php artisan migrate --force
sudo -u www-data php artisan netwatch:create-user admin@perusahaan.local --name="Admin" --role=admin
```
Perintah kedua menanyakan sandi (minimum 12 karakter, huruf besar/kecil + angka). Buat **minimal dua admin**.

```bash
sudo -u www-data php artisan config:cache
sudo -u www-data php artisan route:cache
sudo -u www-data php artisan view:cache
```
**Cek:** `sudo -u www-data php artisan migrate:status` menampilkan semua migrasi `Ran`.

## 7. Sertifikat HTTPS

**Opsi A (cepat): sertifikat self-signed** — cukup untuk jaringan internal; agent dan browser harus diberi file sertifikatnya.
```bash
sudo mkdir -p /etc/ssl/netwatch && cd /etc/ssl/netwatch
sudo openssl req -x509 -newkey rsa:3072 -nodes -days 825 \
  -keyout netwatch.key -out netwatch.crt -subj "/CN=NAMA_SERVER" \
  -addext "subjectAltName=DNS:NAMA_SERVER,IP:IP_SERVER"
sudo chmod 600 netwatch.key
```
- Browser mengeluh "tidak tepercaya" sampai `netwatch.crt` dipasang ke *Trusted Root* di PC admin (GPO bisa membagikannya ke semua PC).
- Agent memakai berkas ini lewat opsi `--ca-file` / `-CaFile` saat dipasang.
- Catat tanggal kedaluwarsa (825 hari) di kalender.

**Opsi B (lebih rapi): sertifikat dari CA internal perusahaan.** Minta tim PKI untuk sertifikat server (SAN: `NAMA_SERVER` dan `IP_SERVER`), letakkan sebagai `netwatch.crt` (gabungan sertifikat + chain) dan `netwatch.key` di folder yang sama. PC perusahaan biasanya sudah memercayai CA itu, jadi tidak perlu `--ca-file`.

## 8. Nginx

```bash
sudo cp /var/www/netwatch/deploy/nginx/netwatch.conf /etc/nginx/sites-available/netwatch
sudo nano /etc/nginx/sites-available/netwatch        # ganti NAMA_SERVER (2 tempat) bila belum
sudo ln -s /etc/nginx/sites-available/netwatch /etc/nginx/sites-enabled/netwatch
sudo rm -f /etc/nginx/sites-enabled/default
sudo nginx -t && sudo systemctl reload nginx
```
**Cek:** `curl -kI https://IP_SERVER/login` mengembalikan `HTTP/2 200`. Lalu buka `https://NAMA_SERVER` di browser dan login.

Jika `502 Bad Gateway`: `sudo systemctl status php8.3-fpm` dan pastikan `fastcgi_pass` cocok dengan `ls /run/php/`.

## 9. Penjadwal (WAJIB — tanpa ini status offline, alert, ping, backup tidak jalan)

```bash
sudo cp /var/www/netwatch/deploy/cron/netwatch /etc/cron.d/netwatch
sudo chmod 644 /etc/cron.d/netwatch && sudo chown root:root /etc/cron.d/netwatch
```
Siapkan folder backup:
```bash
sudo mkdir -p /var/backups/netwatch && sudo chown www-data:www-data /var/backups/netwatch && sudo chmod 700 /var/backups/netwatch
```
**Cek:**
```bash
sudo -u www-data php /var/www/netwatch/server/artisan schedule:list     # daftar tugas + kapan jalan berikutnya
sleep 70 && sudo -u www-data php /var/www/netwatch/server/artisan netwatch:db-stats | head -5
```
Tugas harian yang berjalan otomatis: pembersihan retensi 02:30, backup 01:30, rangkum bandwidth tiap jam.

## 10. Firewall

```bash
sudo ufw default deny incoming
sudo ufw default allow outgoing
sudo ufw allow from 172.21.0.0/16 to any port 22 proto tcp     # SSH hanya dari jaringan admin (sesuaikan)
sudo ufw allow from 172.21.0.0/16 to any port 443 proto tcp     # dashboard + agent (sesuaikan subnet kantor)
sudo ufw allow from 172.21.0.0/16 to any port 80 proto tcp      # hanya untuk redirect ke HTTPS
sudo ufw enable
```
**Cek:** `sudo ufw status verbose`. Port 5432 **tidak** boleh ada di daftar. Pastikan Anda masih bisa SSH sebelum menutup sesi.

## 11. Log

```bash
sudo cp /var/www/netwatch/deploy/logrotate/netwatch /etc/logrotate.d/netwatch
sudo logrotate -d /etc/logrotate.d/netwatch      # simulasi, harus tanpa error
```

## 12. Verifikasi akhir (sebelum memasang agent massal)

```bash
cd /var/www/netwatch/server
sudo -u www-data php artisan netwatch:security-check      # tidak ada status "Berisiko"
sudo -u www-data php artisan netwatch:backup              # membuat backup pertama
sudo -u www-data php artisan netwatch:backup --verify="$(ls -t /var/backups/netwatch/*.dump | head -1)"
sudo -u www-data php artisan netwatch:db-stats
```
Di dashboard (login admin) periksa:
- [ ] **Administrasi → Keamanan**: tidak ada "Berisiko".
- [ ] **Administrasi → Database & Backup**: backup terakhir terisi; peringatan "salinan kedua" akan hilang setelah Bagian 13.
- [ ] **Monitoring → Jaringan**: tambah satu IP internal (mis. gateway), muncul ONLINE dalam ±1 menit.
- [ ] Jam di halaman (zona Asia/Jakarta) sesuai.

## 13. Salinan kedua backup (jangan dilewati)

Pilih satu: disk kedua yang dipasang di server, share NFS/SMB dari server lain, atau `rsync` ke mesin lain. Contoh share NFS yang sudah di-mount di `/mnt/backup-netwatch`:
```bash
sudo mkdir -p /mnt/backup-netwatch/netwatch && sudo chown www-data:www-data /mnt/backup-netwatch/netwatch
# di .env:  NETWATCH_BACKUP_COPY_TO=/mnt/backup-netwatch/netwatch
sudo -u www-data php /var/www/netwatch/server/artisan config:cache
sudo -u www-data php /var/www/netwatch/server/artisan netwatch:backup     # sekarang ada salinan kedua
```
Cara memulihkan dan **latihan pemulihan** ada di `docs/BACKUP-DATABASE.md`.

## 14. Pasang agent di PC

1. Dashboard → **Token Pendaftaran** → buat token (pilih grup, masa berlaku pendek).
2. Pasang pada satu PC pilot dulu (lihat `docs/PENYEBARAN-AGENT.md`):
   - Windows, PowerShell Administrator, dari folder `agent\install\windows`:
     ```powershell
     .\install.ps1 -Server https://NAMA_SERVER -CaFile C:\temp\netwatch.crt
     ```
     (token ditanyakan interaktif; `netwatch.crt` hanya perlu untuk sertifikat self-signed.)
   - Atau installer `NetWatchAgent-Setup.exe` untuk penyebaran massal/GPO.
3. **Cek:** PC muncul di **Monitoring → Perangkat** dalam beberapa detik dengan status Online.
4. Setelah semua PC terpasang, **cabut token pendaftaran** yang tidak dipakai lagi.

### Memindahkan dari server uji (laptop/Laragon)
- **Agent sudah terpasang dengan alamat lama** (mis. `http://172.21.110.14:8000`): paling mudah jika server Linux **memakai IP yang sama** (matikan server lama, beri IP itu ke server baru) dan agent otomatis terhubung lagi — jika sebelumnya HTTP, ubah agent ke HTTPS dengan memasang ulang (`-Reenroll`).
- **Ingin membawa data lama** (perangkat, policy, tiket): di laptop `pg_dump -U postgres -Fc netwatch > netwatch-lama.dump`, salin ke server, lalu ikuti langkah "Pemulihan ke server baru" di `docs/BACKUP-DATABASE.md`, dan salin `APP_KEY` dari `.env` lama.
- Bila tidak butuh data lama: mulai bersih dan daftarkan ulang semua agent.

## 15. Memperbarui aplikasi di kemudian hari

1. `scp` paket baru ke server, ekstrak ke folder sementara, **salin `server/`, `agent/`, `docs/`, `deploy/` menimpa yang lama, kecuali `server/.env`**:
   ```bash
   mkdir /tmp/nw-baru && tar xzf /tmp/netwatch.tar.gz -C /tmp/nw-baru
   rsync -a --exclude 'server/.env' --exclude 'server/storage' --exclude 'server/bootstrap/cache' --exclude 'server/vendor' /tmp/nw-baru/netwatch/ /var/www/netwatch/
   ```
2. Jalankan skrip pembaruan:
   ```bash
   /var/www/netwatch/deploy/update.sh
   ```
   Skrip: backup → mode pemeliharaan → composer → migrasi → cache → aktif lagi. Bila gagal di tengah jalan, aplikasi diaktifkan kembali dan Anda bisa memulihkan dari backup yang dibuat di langkah pertama.
3. **Cek:** login, buka Database & Backup, dan Keamanan.

Pemeliharaan rutin lain: lihat `docs/KEAMANAN.md` (pemeriksaan mingguan, `apt upgrade` bulanan, `composer audit`).

---

## Masalah umum

| Gejala | Penyebab / solusi |
|---|---|
| 502 Bad Gateway | PHP-FPM mati atau socket beda: `systemctl status php8.3-fpm`, cek `fastcgi_pass`. |
| 500 setelah deploy | Lihat `storage/logs/laravel.log`; biasanya izin `storage/` (`chown -R www-data`) atau `.env` kosong. Jalankan `artisan config:clear`. |
| Perangkat selalu offline / alert tidak muncul | Penjadwal tidak jalan: `cat /etc/cron.d/netwatch`, `grep CRON /var/log/syslog`, `artisan schedule:list`. |
| Agent tidak bisa mendaftar | Sertifikat tidak dipercaya (pakai `-CaFile`), nama server tidak resolve (cek DNS), firewall 443, atau token kedaluwarsa. |
| "Ping perangkat" selalu gagal | `iputils-ping` belum terpasang atau firewall mem-blokir ICMP keluar. Tes: `sudo -u www-data ping -c1 IP`. |
| Halaman Database & Backup menampilkan "Pembersihan tertinggal" | Penjadwal belum jalan ≥ 1 hari, atau `netwatch:prune` gagal: jalankan manual dan baca pesannya. |
| `pg_dump` tidak ditemukan saat backup | `sudo apt install postgresql-client-16` atau set `NETWATCH_PG_DUMP=/usr/lib/postgresql/16/bin/pg_dump`. |

## Daftar periksa go-live

- [ ] IP tetap + DNS `NAMA_SERVER`; jam sinkron (chrony).
- [ ] HTTPS aktif; sertifikat didistribusikan; tanggal kedaluwarsa dicatat.
- [ ] `.env`: `APP_ENV=production`, `APP_DEBUG=false`; salinan `.env` tersimpan di luar server.
- [ ] Penjadwal berjalan (`schedule:list`, event "backup" tidak gagal).
- [ ] Backup pertama dibuat, **diverifikasi, dan salinan kedua ada**; latihan pemulihan sudah dilakukan sekali.
- [ ] Firewall: hanya 22/80/443 dari subnet yang diizinkan; 5432 tertutup.
- [ ] Dua admin aktif; token pendaftaran lama dicabut.
- [ ] 1 PC pilot Online dan menampilkan data bandwidth/event; baru kemudian penyebaran massal.

## Dokumen terkait
- `docs/BACKUP-DATABASE.md` — backup otomatis, pemulihan, latihan pemulihan.
- `docs/BASIS-DATA.md` — perkiraan ukuran database, pemeliharaan jangka panjang, menyesuaikan retensi.
- `docs/PENYEBARAN-AGENT.md` — memasang agent di PC.
