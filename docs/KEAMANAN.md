# Keamanan dan pembaruan NetWatch

## 1. Pemeriksaan rutin

| Apa | Cara | Seberapa sering |
|---|---|---|
| Konfigurasi dan kondisi armada | Menu **Keamanan** (admin) atau `php artisan netwatch:security-check` | Mingguan, dan setelah mengubah `.env` |
| Kerentanan di pustaka PHP | `composer audit` di folder `server` | Mingguan |
| Pustaka yang punya versi baru | `composer outdated --direct` | Bulanan |
| Event keamanan (login gagal, token ditolak) | Menu **Event**, kategori Keamanan | Mingguan |
| Percobaan akses mencurigakan | Menu **Audit Log** | Saat ada indikasi |

`netwatch:security-check` mengembalikan kode keluar 1 bila ada yang "Berisiko", jadi bisa dipasang di cron dan diberi notifikasi.

## 2. Memperbarui server

1. **Cadangkan database dulu:** `pg_dump -U netwatch -Fc netwatch > netwatch-AAAAMMDD.dump`
2. Ganti kode dengan versi baru (jangan timpa `.env`).
3. Di folder `server`:
   - `composer install --no-dev --optimize-autoloader`
   - `php artisan migrate --force`
   - `php artisan config:cache && php artisan route:cache && php artisan view:cache`
4. Mulai ulang PHP-FPM / `php artisan serve` / penjadwal.
5. Buka menu **Keamanan** dan pastikan tidak ada status "Berisiko".

Perbarui PHP dan PostgreSQL ke versi patch terbaru dari sistem operasi (Linux: `apt upgrade`) paling tidak bulanan.

## 3. Memperbarui agent di PC pengguna

Saat ini dilakukan dengan memasang ulang installer baru (silent: `NetWatchAgentSetup.exe /VERYSILENT /SERVER=... /TOKEN=...`, bisa lewat GPO atau
alat deploy yang ada). Menu **Keamanan** menandai perangkat yang agent-nya di bawah `NETWATCH_AGENT_LATEST_VERSION`.

Installer membawa PHP sendiri. Setiap ada rilis keamanan PHP, bangun ulang installer dengan PHP terbaru lalu sebar ulang.

**Mengapa belum ada pembaruan otomatis agent:** saluran pembaruan otomatis adalah jalur serangan paling berharga, karena siapa pun yang bisa
menyuntik pembaruan mengendalikan semua PC sebagai SYSTEM. Fitur ini baru aman bila paket **ditandatangani** (kunci privat disimpan offline)
dan agent memverifikasi tanda tangan sebelum menjalankannya. Itu dikerjakan bersama agent Go (Fase 4).

## 4. Daftar periksa sebelum dipakai di jaringan sungguhan

- [ ] Server di Linux/VM, bukan laptop pribadi.
- [ ] HTTPS aktif (Nginx/Caddy + sertifikat CA internal), `APP_URL=https://...`, `NETWATCH_FORCE_HTTPS=true`, `SESSION_SECURE_COOKIE=true`.
- [ ] `APP_ENV=production`, `APP_DEBUG=false`.
- [ ] Bila di belakang reverse proxy: `NETWATCH_TRUSTED_PROXIES` diisi IP proxy.
- [ ] Aplikasi memakai role PostgreSQL khusus (bukan superuser); port 5432 tidak terbuka ke jaringan pengguna.
- [ ] Firewall: hanya port 443 (dashboard dan agent) yang terbuka ke jaringan pengguna.
- [ ] Penjadwal berjalan (`schedule:work` sebagai layanan atau cron tiap menit).
- [ ] Cadangan database terjadwal dan pernah diuji dipulihkan.
- [ ] Minimal 2 administrator aktif; kata sandi unik dan panjang.
- [ ] Token pendaftaran dicabut setelah pemasangan selesai.

## 5. Yang belum ada (jujur)

- 2FA/TOTP untuk login dashboard dan penguncian akun setelah gagal berulang (saat ini hanya pembatasan laju 5 percobaan/menit per email+IP). Direncanakan di Fase 0 (manajemen pengguna lanjutan).
- Pemberitahuan otomatis (email/Telegram) untuk event Kritis. Direncanakan di Fase 0 (alert engine).
- Tanda tangan paket agent dan pembaruan otomatis (lihat bagian 3).
