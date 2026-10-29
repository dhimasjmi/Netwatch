# Notifikasi, ping, dan mode maintenance

## Cara kerja
- Saat perangkat offline lebih dari `NETWATCH_OFFLINE_AFTER` detik, server mencatat event `device.offline`.
- Tiap menit server mem-ping perangkat yang offline. Hasilnya muncul di halaman perangkat dan di pesan notifikasi:
  - **ping merespons** → PC menyala, kemungkinan layanan agent berhenti;
  - **ping tidak merespons** → PC mati/sleep atau terputus dari jaringan.
- Tiap menit pula `netwatch:send-alerts` mengirim notifikasi untuk event yang belum diberitahukan. Gangguan Telegram/email tidak pernah mengganggu pencatatan status.
- Kabar "online" hanya dikirim bila kabar "offline"-nya dulu terkirim. Gangguan sesaat yang sudah pulih tidak dikirim.
- Bila 5 perangkat atau lebih offline bersamaan, diringkas jadi satu pesan (biasanya masalah switch/AP/uplink).
- Selama **mode maintenance**, notifikasi perangkat itu ditahan (event dan riwayat uptime tetap tercatat).

## Mengaktifkan Telegram
1. Di Telegram buka `@BotFather` → `/newbot` → salin **token**.
2. Kirim satu pesan apa saja ke bot Anda. Buka `https://api.telegram.org/bot<TOKEN>/getUpdates` di browser dan salin angka `"chat":{"id":...}`.
3. Isi `.env`:
   ```
   NETWATCH_ALERTS_ENABLED=true
   NETWATCH_TELEGRAM_TOKEN=isi-token
   NETWATCH_TELEGRAM_CHAT_ID=isi-chat-id
   ```
4. Uji: `php artisan netwatch:test-alert`. Pesan uji harus masuk ke Telegram.

## Jaringan down dan lambat (Monitoring → Jaringan)
Target jaringan (switch, AP, printer, server) ikut dikirim ke Telegram/email bila `NETWATCH_ALERTS_ENABLED=true`:
- 🔴 **Down**: dikirim saat target gagal dicek `NETWATCH_MONITOR_FAIL_THRESHOLD` kali beruntun (bawaan 2 menit). Saat pulih dikirim ✅ "merespons kembali" (hanya bila kabar down-nya terkirim).
- 🐢 **Lambat**: dikirim bila respons lebih lambat dari `NETWATCH_MONITOR_WARN_MS` (bawaan 150 ms) **terus-menerus selama `NETWATCH_ALERT_SLOW_AFTER` detik** (bawaan 180). Respons yang hanya naik-turun sesaat di sekitar batas tidak dikirim. Saat membaik dikirim ✅ "kembali normal" (hanya bila kabar lambatnya terkirim).
- Matikan kabar lambat saja dengan `NETWATCH_ALERT_SLOW=false`; kabar down tetap jalan.
- Target yang dihentikan (Stop) tidak dikirim. Satu target yang memburuk dari lambat menjadi down mengirim kabar down saja.
- Setelah mengubah `.env`: `php artisan config:clear`. Perintah `netwatch:send-alerts` berjalan tiap menit lewat scheduler (lihat di bawah), jadi kabar bisa terlambat sampai ±1 menit setelah status berubah.

Email: isi `MAIL_*` seperti biasa dan `NETWATCH_ALERT_MAIL_TO=a@x.com,b@x.com`.

## Penjadwal wajib berjalan
Perintah terjadwal membutuhkan scheduler:
- Windows/Laragon pilot: `php artisan schedule:work` di jendela terminal terpisah.
- Linux: cron `* * * * * php /path/artisan schedule:run`.

Halaman **Keamanan** memberi peringatan bila scheduler tidak berjalan.

## Ping dari server
Memakai perintah `ping` bawaan OS tanpa shell. Bila firewall PC memblokir ICMP, hasilnya "tidak merespons" walau PC menyala. Aktifkan aturan "File and Printer Sharing (Echo Request - ICMPv4-In)" di PC, atau matikan fitur dengan `NETWATCH_PING_ENABLED=false`.

## Mode maintenance
Halaman perangkat → "Mode maintenance" → pilih durasi (1–72 jam) dan catatan → Mulai. Berakhir otomatis atau diakhiri manual. Tercatat di Audit Log dan Event.
