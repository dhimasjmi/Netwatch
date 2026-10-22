# Tiket bantuan IT dan penilaian layanan

## Alur

1. **Pengguna membuat tiket.** Installer agent (1.6.0+) memasang shortcut **Buat Tiket IT** di Desktop semua pengguna. Shortcut membuka
   `https://<server>/tickets/new?pc=<NAMA-PC>` (tanpa login). Nama PC dicocokkan ke perangkat terdaftar bila tepat satu yang cocok,
   sehingga teknisi langsung tahu asal tiket. Pemohon mengisi nama, email (opsional), jenis, judul, dan penjelasan.
2. **Tim IT menangani** di menu **Tiket** (admin dan operator): daftar dengan filter status/pencarian/"milik saya", tiket bisa dibuatkan
   oleh admin untuk laporan telepon, diberi prioritas dan penanggung jawab, dan diberi catatan (internal atau untuk pemohon).
   Tiket baru dikirim ke Telegram/email alert (`ticket.created` ada di `netwatch.alerts.codes`).
3. **Tiket diselesaikan.** Tombol **Tandai selesai** membuat **kode penilaian** sekali pakai (10 karakter, berlaku 14 hari) dan mengirimnya:
   - **Email** ke pemohon (butuh MAIL_* di .env dan email yang diisi pemohon),
   - **Popup** di PC pemohon (perintah "kirim pesan" ke agent; hanya jika tiket terkait perangkat. Perintah kedaluwarsa bila PC sedang offline),
   - dan kode/tautan juga ditampilkan **sekali** ke teknisi agar bisa disampaikan lewat telepon atau chat.
4. **Pemohon menilai** lewat tautan `/f/<kode>` atau mengetik kode di bagian bawah form Buat Tiket: nilai 1-5 dan komentar opsional.
   Satu tiket hanya bisa dinilai satu kali. Teknisi bisa membuat kode baru (yang lama otomatis tidak berlaku) selama belum dinilai.
5. **Laporan** (Tiket → Laporan penilaian): jumlah tiket, rata-rata waktu penyelesaian, nilai rata-rata, sebaran nilai, rekap per jenis masalah,
   dan komentar bernilai rendah (3 ke bawah) sebagai bahan perbaikan.

## Keamanan

- Form publik dibatasi 5 kiriman/menit per IP, memakai kolom jebakan untuk bot, validasi ketat, dan semua isi dikeluarkan dengan escaping.
- Kode penilaian hanya disimpan sebagai hash SHA-256; kode asli hanya ada di email/popup/layar teknisi sekali. Percobaan dibatasi 10/menit per IP.
- Pengiriman nilai atomik (`UPDATE ... WHERE submitted_at IS NULL AND expires_at > now()`), jadi dua kiriman bersamaan tidak menimpa.
- Pemohon tidak punya akun; mereka tidak bisa melihat tiket orang lain. Jangan menulis kata sandi di form (ada peringatan di halaman).
- Nama PC dari shortcut tidak dipercaya; hanya dipakai bila cocok dengan tepat satu perangkat aktif.

## Batasan

- Pemohon belum bisa melacak status tiketnya sendiri; kabar penyelesaian lewat email, popup, atau teknisi.
- Popup di PC berupa pesan teks (agent berjalan sebagai SYSTEM dan tidak bisa membuka browser di sesi pengguna); pengguna membuka tautan atau
  mengetik kode di halaman Buat Tiket.
- Tiket tidak dihapus otomatis (tidak ada retensi); ukurannya kecil.

## Deploy

```
php artisan migrate        # 2026_10_19_000002_create_ticket_tables
vendor\bin\phpunit
```
Pasang ulang agent (installer terbaru) agar shortcut Desktop terpasang. Untuk email: atur MAIL_MAILER/MAIL_HOST/dst. di .env.
