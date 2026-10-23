# Menu Monitoring & Saklar Fitur

## Menu Monitoring
Satu menu **Monitoring** berisi tab **Perangkat | Bandwidth | Browsing** (+ Event dan Laporan Uptime di dropdown). **Jaringan (NOC)** tetap menu sendiri.

- **Bandwidth** (`/monitoring/bandwidth`): KPI, grafik tren gabungan (hover untuk tooltip), tabel per perangkat. Bisa melihat semua perangkat atau sebagian: cari, filter grup, centang beberapa PC ("Hanya yang dipilih"), urut kolom, per halaman 5–100, pagination bernomor. Rentang 1 jam/6 jam/24 jam/7 hari.
- **Browsing** (`/monitoring/browsing`, hanya Admin, diaudit): KPI, tren akses, situs teratas, tabel aktivitas terbaru (cari, filter perangkat & jenis, pagination). Total baris dibatasi hitungannya (tampil "100.000+").
- Auto refresh: Mati / 5 / 10 / 30 detik / 1 menit (rentang ≥ 24 jam minimal 30 detik). Pilihan tersimpan di browser.

### Data yang tidak ada di agent (sengaja tidak ditampilkan)
Kategori situs, akses diblokir, durasi sesi, kapasitas link (Network Usage %). Diganti metrik yang tersedia (perangkat melapor, total aktivitas, situs unik, situs teratas, total transfer).

## Saklar fitur (Administrasi → Fitur)
Admin dapat menonaktifkan: Bandwidth, Browsing, Inventaris, Event Log Windows, Jaringan (NOC), Tiket, Alert ambang. Nilai bawaan dari `.env`; saklar menimpa dan bisa "Kembalikan ke bawaan".
- Fitur mati: menu hilang, halaman 404, API web 404 JSON. Agent tetap kompatibel (API membalas 200 dan membuang data; heartbeat memberi tahu agent berhenti mengirim).
- **Data lama tidak dihapus**; saat dinyalakan lagi data muncul kembali.
- Setiap perubahan tercatat di Audit Log (`feature.enabled/disabled/reset`).

## Pasang
`php artisan migrate` (tabel `feature_flags`), lalu Ctrl+F5 di browser (JS/CSS baru). Opsional `NETWATCH_FLEET_CACHE` (detik).

## Jaringan di dalam Monitoring & mode layar penuh (22 Okt)
- **Jaringan (NOC)** kini tab keempat Monitoring (dan ada di dropdown Monitoring), tetap bisa dimatikan lewat Fitur.
- Tombol **Layar penuh** (kanan atas tab) tersedia di Perangkat, Bandwidth, Browsing, dan Jaringan. Menyembunyikan menu, filter, pager, dan form admin; menampilkan angka/grafik lebih besar. Keluar: tombol samar di kanan atas atau tekan Esc.
- Halaman server-rendered (Jaringan) diperbarui di tempat saat mode layar penuh sehingga layar penuh tidak tertutup oleh reload.
- `?tv=1` pada URL (mis. `/monitoring/bandwidth?tv=1`) membuka tampilan monitor langsung, tetapi layar penuh sungguhan tetap butuh satu klik/F11 karena aturan browser. Untuk TV/kios: buka halaman, tekan F11.

## Dashboard yang bisa dipilih (22 Okt)
Dashboard berisi widget dari menu Perangkat, Bandwidth, Browsing, dan Jaringan. Klik **Atur tampilan dashboard** lalu centang data yang ingin dilihat; pilihan disimpan per akun.
- Bawaan = dashboard lama (3 widget Perangkat). Tombol "Kembalikan bawaan" membatalkan pilihan.
- Widget: ringkasan/offline/perintah (Perangkat), ringkasan + tren 1 jam dan pengguna terbesar (Bandwidth), ringkasan dan situs teratas (Browsing, hanya Admin), ringkasan dan perangkat bermasalah (Jaringan).
- Widget dari fitur yang dimatikan admin tidak muncul, tetapi pilihan lama tersimpan dan kembali saat fitur dinyalakan.
- Data hanya dihitung untuk widget yang dipilih; satu widget yang gagal dimuat tidak merusak halaman. Ada tombol Layar penuh juga di dashboard.
- Pasang: `php artisan migrate` (kolom `users.dashboard_widgets`).

## Browsing per perangkat vs Browsing Monitoring
- Tombol **Browsing** di halaman perangkat membuka `/devices/{id}/browsing`: riwayat satu perangkat (tanpa tab Monitoring, ada tombol kembali ke perangkat).
- **Monitoring → Browsing** (`/monitoring/browsing`): semua perangkat, bisa difilter per perangkat.
- Keduanya membaca data yang sama (`BrowsingDashboardService`), hanya difilter berbeda. Akses diaudit; hanya Admin; ikut saklar fitur Browsing.
