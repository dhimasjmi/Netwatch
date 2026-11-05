# Perintah Artisan dan jadwal

Sumber: `server/app/Console/Commands/*`, `server/routes/console.php`. Jalankan sebagai pengguna web: `sudo -u www-data php artisan <perintah>` dari folder `server/`. Satu entri cron menjalankan semuanya: `* * * * * www-data cd /var/www/netwatch/server && php artisan schedule:run >> /dev/null 2>&1`.

## 1. Perintah terjadwal

| Perintah | Jadwal | Fungsi | Tanda gagal | Alur |
|---|---|---|---|---|
| `netwatch:check-status` | tiap menit | Tandai offline perangkat yang `last_seen_at` ≤ sekarang − `offline_after` | Perangkat mati tetap "online"; halaman Keamanan → Penjadwal | A4 |
| `netwatch:ping-offline` | tiap menit | Ping ICMP perangkat offline (maks 500) | `ping_checked_at` kosong | A4 |
| `netwatch:ping-targets` | tiap menit | Cek target NOC | Target "Belum dicek" | S3 |
| `netwatch:evaluate-alerts` | tiap menit | Evaluasi aturan ambang | Event `alert.*` tak muncul | S2 |
| `netwatch:send-alerts` | tiap menit | Kirim Telegram/email | Event `notified_at` kosong menumpuk | S4 |
| `netwatch:expire-commands` | tiap menit | Perintah > 600 dtk → `expired` | Perintah `pending` menumpuk | A5 |
| `netwatch:rollup-bandwidth` | tiap jam, menit 05 | Rangkum bandwidth per jam | Grafik > 3 hari bolong | A8 |
| `netwatch:backup` | harian 01:30 | `pg_dump` + rotasi | Event `backup.failed` | S7 |
| `netwatch:prune` | harian 02:30 | Hapus data lewat retensi | DB membengkak | S8 |

Semua memakai `withoutOverlapping` (tidak bertumpuk).

## 2. Perintah manual

| Perintah | Kegunaan |
|---|---|
| `netwatch:create-user` | Buat pengguna dashboard (admin pertama). |
| `netwatch:db-stats` | Ukuran data NetWatch, tabel terbesar, kesegaran retensi, status backup. |
| `netwatch:security-check` | Pemeriksaan keamanan; kode keluar 1 bila ada yang berisiko. |
| `netwatch:test-alert` | Kirim pesan uji ke Telegram/email. |
| `netwatch:backup --verify=NAMA` | Periksa checksum dan keterbacaan backup. |
| `schedule:list`, `schedule:run` | Lihat/jalankan jadwal manual. |
| `migrate --force`, `migrate:status` | Skema database. |
| `config:clear`, `config:cache`, `optimize:clear` | Setelah mengubah `.env`. |

## 3. Diagnosis cepat scheduler

```bash
crontab -l -u www-data ; cat /etc/cron.d/netwatch
sudo -u www-data php artisan schedule:list
sudo -u www-data php artisan schedule:run -v        # jalankan sekarang, lihat hasil
sudo -u www-data php artisan netwatch:security-check
grep CRON /var/log/syslog | tail                      # Debian/Ubuntu
```
