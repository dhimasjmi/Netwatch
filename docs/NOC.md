# Network Operations Center (menu "Jaringan")

Memantau perangkat jaringan **tanpa agent** (switch, access point, router, printer, server) dengan ping atau cek port TCP dari server NetWatch.
Perangkat ber-agent tetap dipantau lewat heartbeat di menu Perangkat; kedua jenis tidak saling menggantikan.

## Cara pakai
1. Menu **Jaringan** → bagian **Tambah target**: isi nama, alamat IP, jenis, cara cek.
2. Cara cek **Ping (ICMP)** untuk perangkat yang membalas ping. Bila perangkat memblokir ping (mis. printer), pilih **Port TCP** dan isi port web admin/layanannya (80, 443, 9100, dst.).
3. Server mengecek semua target tiap menit (`netwatch:ping-targets`, sudah masuk scheduler). Tombol **Ping sekarang** mengecek satu target seketika.
4. Tiap target tampil sebagai **kartu**: nama, IP, status, latensi terakhir, batang riwayat 30 pengecekan terakhir (hijau = normal, kuning = lambat, merah = gagal), dan ketersediaan 24 jam.
5. Halaman dimuat ulang otomatis tiap 30 detik (ditunda saat Anda sedang mengisi formulir).

## Status
| Status | Arti |
|---|---|
| Online | Merespons, latensi di bawah batas |
| Lambat | Merespons tetapi lebih lambat dari `NETWATCH_MONITOR_WARN_MS` (bawaan 150 ms) |
| Offline | Gagal `NETWATCH_MONITOR_FAIL_THRESHOLD` kali beruntun (bawaan 2, jadi satu paket hilang tidak memicu alarm) |
| Belum dicek | Target baru atau baru diubah |

Saat target berubah menjadi Offline dan saat pulih, server mencatat event (`target.offline` / `target.online`, kategori Konektivitas) dengan lama gangguan.
Target yang berubah dari Online ke Lambat (dan kembali) juga dicatat sebagai event `target.slow` / `target.normal`.
Bila notifikasi Telegram/email aktif, kabar down dan lambat ikut terkirim (lambat hanya bila bertahan `NETWATCH_ALERT_SLOW_AFTER` detik, bawaan 180); kabar "pulih" hanya dikirim bila kabar gangguannya dulu terkirim. Lihat `docs/NOTIFIKASI.md`.

## Keamanan dan batasan
- Hanya **alamat IP** (bukan nama host). Loopback, 0.0.0.0, dan multicast ditolak.
- Bawaannya hanya **IP internal** (privat/link-local) agar server tidak dipakai memeriksa alamat di internet. Ubah dengan `NETWATCH_MONITOR_ALLOW_PUBLIC=true` bila memang perlu.
- Ping memakai perintah `ping` OS tanpa shell; server Linux perlu paket `iputils-ping` terpasang.
- Menambah, mengubah, menghapus, menghentikan, dan Ping sekarang hanya untuk Administrator; semua peran dapat melihat. Semuanya tercatat di Audit Log.
- Riwayat cek disimpan `NETWATCH_RETENTION_MONITOR` hari (bawaan 7) untuk angka ketersediaan 24 jam.
- Hasilnya adalah pandangan dari **server**. Bila server sendiri terputus dari jaringan, semua target tampak offline sekaligus; periksa jalur server lebih dulu.

## Pengaturan (.env)
`NETWATCH_MONITOR_ENABLED`, `NETWATCH_MONITOR_WARN_MS`, `NETWATCH_MONITOR_FAIL_THRESHOLD`, `NETWATCH_MONITOR_ALLOW_PUBLIC`, `NETWATCH_RETENTION_MONITOR`.
Setelah `git pull`/ganti paket: `php artisan migrate`.
