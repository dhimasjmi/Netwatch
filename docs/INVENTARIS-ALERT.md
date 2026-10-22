# Inventaris perangkat, alert ambang, dan grafik tren

## 1. Inventaris perangkat keras & lunak (agent 1.6.0+)

- Agent membaca **perangkat keras** (produsen, model, nomor seri, CPU, RAM, disk, OS) dan **program terpasang**
  (Windows: registri Uninstall 64/32-bit tanpa komponen sistem dan pembaruan; Linux: dpkg/rpm) tiap **5 menit**.
- Data dikirim ke `POST /api/agent/v1/assets` **hanya bila berubah** (hash), plus penanda "masih terkini" tiap 6 jam.
  Endpoint baru bersifat aditif: agent lama tetap berjalan normal, halaman inventaris menampilkan "belum dilaporkan".
- Server menyimpan kondisi terkini (`device_hardware`, `device_software`). Snapshot software **otoritatif**: program yang
  tidak ada di laporan berikutnya **dihapus dari daftar**, sehingga uninstall tercermin maksimal ±5 menit + 30 detik
  setelah program dihapus (bukan menunggu heartbeat 30 detik: pembacaan registri dijadwalkan terpisah agar ringan).
- Bagian yang gagal dibaca dikirim `null` → data lama di server **tidak dihapus**.
- Event (setelah laporan pertama sebagai baseline): `software.installed`, `software.removed` (maks. 10 per laporan),
  `hardware.changed` (CPU/RAM/model/disk berbeda; berguna mendeteksi komponen dicabut atau perangkat tertukar).
  Perubahan versi program (nama sama) dianggap pembaruan, bukan instal/uninstall.
- UI (admin): **Administrasi → Inventaris** (tab Perangkat keras / Perangkat lunak, cari "PC mana yang memasang X"),
  dan tombol **Inventaris** di halaman tiap perangkat.

Batasan: program yang dipasang per-user (di profil pengguna, mis. sebagian aplikasi portable atau instalasi HKCU) tidak
terbaca karena agent berjalan sebagai SYSTEM; aplikasi Microsoft Store (UWP) belum didukung.

## 2. Alert ambang (CPU / RAM / disk)

- **Administrasi → Alert**: buat aturan (metrik, ambang %, durasi menit, tingkat warning/critical, cakupan semua/grup/perangkat).
- Evaluasi tiap menit (`netwatch:evaluate-alerts`, butuh scheduler: `php artisan schedule:run` tiap menit).
- Menyala bila **semua** sampel dalam jendela durasi ≥ ambang dan jendela sudah tercakup data (perangkat yang baru online
  tidak langsung memicu). Pulih bila semua sampel 3 menit terakhir < ambang. Satu kejadian = satu event `alert.firing`
  dan satu `alert.resolved`.
- Perangkat offline atau dalam mode maintenance tidak dinilai.
- Notifikasi Telegram/email memakai pengaturan alert yang ada (`NETWATCH_ALERTS_ENABLED`); `alert.resolved` hanya
  dikirim bila `alert.firing`-nya sempat terkirim.

## 3. Grafik tren

Tombol **Tren CPU/RAM/Disk** di halaman perangkat: grafik 1 jam / 6 jam / 24 jam / 7 hari dari riwayat heartbeat
(rata-rata per interval, maks. ±120 titik). Garis terputus = perangkat tidak mengirim data. Data mengikuti retensi heartbeat.

## Deploy

```
php artisan migrate        # 2026_10_16_000001 (alert) dan 2026_10_16_000002 (inventaris)
vendor\bin\phpunit
```
Untuk inventaris, pasang ulang/perbarui agent ke 1.6.0 (zip agent terbaru).
