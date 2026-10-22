# Policies situs dan IP lewat NetWatch

NetWatch bukan firewall jaringan. Ia mengatur aturan blokir, dan **agent di PC pengguna yang menerapkannya** (Windows saja).
Untuk blokir tingkat jaringan, tetap gunakan firewall perusahaan.

## Cara memakai

1. Pastikan PC memakai **agent 1.2.0 atau lebih baru** (bangun ulang installer, pasang ulang).
2. Buka menu **Policies** dan **Buat policy** bernama sesuai kelompoknya (mis. "Media sosial"), lalu pilih cakupan: semua perangkat, satu grup, atau satu perangkat.
3. Di kartu policy itu, isi kotak **Tambah situs atau IP**: satu per baris (atau dipisah koma), mis.
   `facebook.com`, `instagram.com`, `*.tiktok.com`, `203.0.113.10`, atau rentang `203.0.113.0/24`. Jenis (situs/IP) dikenali otomatis; URL lengkap dirapikan jadi domain.
   Target yang valid tetap disimpan walau sebagian lain ditolak (alasannya ditampilkan).
4. Dalam kurang dari 1 menit agent menerapkan policy. Tabel "Status penerapan per perangkat" menunjukkan *Diterapkan*, *Menunggu*, atau *Gagal*.
5. **Nonaktifkan** policy menghentikan semua blokir di dalamnya tanpa menghapusnya; **Hapus** policy mencabutnya beserta targetnya. Klik tanda × pada target untuk menghapus satu target.

Bila ada beberapa policy yang berlaku untuk satu PC (mis. "Umum" + "Lab 1"), semua targetnya digabung. Aturan blokir lama otomatis dimasukkan ke policy
bernama "Umum (semua perangkat)", "Grup: ...", atau "Perangkat: ..." saat `php artisan migrate`.

## Apa yang dilakukan agent di PC

| Lapisan | Yang diubah | Efek |
|---|---|---|
| File hosts | Satu blok bertanda `# BEGIN NETWATCH` ... `# END NETWATCH` (IPv4 dan IPv6, domain dan `www.`) | Nama situs tidak bisa di-resolve oleh semua aplikasi |
| Windows Firewall | Satu aturan keluar bernama `NetWatch-BlockIP` | Koneksi ke IP/rentang yang diblokir ditolak |
| Chrome dan Edge | Kebijakan `URLBlocklist` di registry | Domain beserta **semua subdomainnya** diblokir |

Isi file hosts di luar blok, dan aturan firewall lain, tidak disentuh. Saat agent dihapus, semua blokir NetWatch ikut dibersihkan.

## Pengamanan bawaan

- Server menolak aturan yang memuat **alamat server NetWatch** (IP, atau domain induk dari `APP_URL`), karena PC akan terputus dan tidak bisa menerima pencabutan aturan. Isi `NETWATCH_SERVER_IPS` di `.env` bila server punya lebih dari satu alamat.
- Rentang IP terlalu luas ditolak (IPv4 di bawah /8, IPv6 di bawah /16), begitu juga `0.0.0.0/0` dan loopback.
- Agent **memvalidasi ulang** semua yang dikirim server dan menolak kebijakan yang mencurigakan seluruhnya, tidak sebagian.
- Semua perubahan aturan tercatat di Audit Log; hasil penerapan di menu Event kategori "Kebijakan blokir".

## Cara memeriksa di PC pengguna (untuk uji coba)

- Hosts: `type C:\Windows\System32\drivers\etc\hosts`
- Firewall (PowerShell Administrator): `Get-NetFirewallRule -Name 'NetWatch-*' | Get-NetFirewallAddressFilter`
- Browser: buka `chrome://policy` (atau `edge://policy`), klik **Muat ulang kebijakan**, cari `URLBlocklist`.
- Log agent: `C:\ProgramData\NetWatch\agent.log`

## Batasan yang perlu diketahui

1. **Pengguna dengan hak Administrator bisa mematikannya.** Efektif untuk akun biasa.
2. Chrome/Edge membaca kebijakan saat dibuka dan secara berkala; tab yang sudah terbuka bisa baru terblokir setelah browser dimulai ulang atau kebijakan dimuat ulang.
3. Firefox dan browser lain: hanya terkena blokir hosts (nama persis dan `www.`) dan firewall IP, bukan subdomain.
4. **GPO/MDM:** (agent 1.5.0+) NetWatch hanya menambah dan menghapus entri `URLBlocklist` yang ia tulis sendiri; entri GPO/MDM/admin lain dibiarkan. Namun bila GPO menimpa seluruh kunci saat refresh, entri NetWatch hilang dan terdeteksi sebagai penyimpangan (lihat di bawah): satu sumber (GPO atau NetWatch) sebaiknya yang mengelola daftar itu.
5. Memblokir domain besar lewat IP tidak andal (alamatnya banyak dan berganti). Gunakan aturan jenis *Situs* untuk domain.
6. VPN, proxy, atau DNS-over-HTTPS di browser tidak mengubah blokir hosts dan URLBlocklist, tetapi akses lewat IP langsung ke situs hanya tertahan bila IP itu juga diblokir.
7. Hanya Windows. Linux belum didukung.
8. Memantau dan memblokir aktivitas pengguna sebaiknya diumumkan lewat kebijakan penggunaan perangkat perusahaan.

## Aturan tertinggal (stale) dan pemulihan

### Akar masalah yang ditemukan (agent ≤ 1.4.0)

1. **Server menganggap bersih hanya dari hash `null`.** Bila kebijakan di server kosong dan agent melapor `hash=null`, server tidak mengirim apa pun. Setelah reinstal atau `policy-state.json` hilang, agent melapor `null` padahal blok hosts, aturan firewall, dan URLBlocklist masih terpasang: Facebook/Instagram tetap terblokir tanpa ada yang membersihkan.
2. **Agent melaporkan catatan lokal, bukan kenyataan.** Hash yang dilaporkan berasal dari state; aturan yang dihapus/diubah manual tidak pernah terdeteksi.
3. **Uninstall rapuh.** `clear-policy` butuh `agent.json`, kegagalannya diabaikan, lalu folder data dihapus sehingga tidak ada jejak pemulihan.
4. **URLBlocklist dihapus utuh** (`Remove-Item -Recurse`), termasuk entri milik GPO/MDM.

### Perbaikan (agent 1.5.0 + server)

- Agent membaca aturan yang **benar-benar terpasang** (hosts, URLBlocklist, firewall) tiap 5 menit dan melaporkan hash dari kondisi itu. Kondisi bersih = hash kebijakan kosong, bukan `null`. Hasil antar-mekanisme yang tidak konsisten = hash "menyimpang" yang tidak pernah sama dengan server, sehingga kebijakan dikirim ulang. Server mencatat event `policy.drift`.
- Server tidak lagi menganggap `null` sebagai bersih; ia mengirim kebijakan (kosong) sekali, agent menerapkan dan membuktikan bersih.
- Kepemilikan dicatat di registry `HKLM\SOFTWARE\NetWatch\Policy` (terpisah dari state). URLBlocklist: hanya entri "nomor=domain" yang NetWatch tulis dan nilainya masih sama yang dihapus. Firewall: hanya aturan `NetWatch-*`. Hosts: hanya blok `# BEGIN NETWATCH ... # END NETWATCH` (daftar domain asli ikut tercatat di komentar blok).
- Setelah menerapkan, agent memverifikasi; bila tidak sesuai, statusnya **Gagal**, bukan Diterapkan.
- `clear-policy` tidak butuh `agent.json`, memverifikasi bersih, dan keluar dengan kode ≠ 0 bila gagal.

### Di luar jaringan kantor (server tidak terjangkau)

Server NetWatch ada di jaringan kantor, jadi "server tidak terjangkau" dipakai sebagai tanda PC berada di luar kantor (laptop dibawa pulang). Perilakunya (agent 1.5.0+):

- Heartbeat gagal terhubung (bukan error HTTP dari server) terus-menerus selama **10 menit** → agent **melepas semua blokir** NetWatch dan memverifikasinya. Pengguna bisa membuka Instagram/Facebook di luar kantor. Antivirus seperti ESET tidak ikut dipengaruhi.
- Saat PC terhubung ke server lagi, agent melaporkan kondisi bersih bertanda `offline_release`; server mencatat event info `policy.offline_release` (bukan peringatan penyimpangan) dan langsung mengirim kebijakan, lalu blokir terpasang kembali.
- Server menjawab error (mis. HTTP 500) berarti PC masih di jaringan kantor, jadi blokir tetap berlaku.
- Ubah lama jeda lewat `offline_release_seconds` di `C:\ProgramData\NetWatch\agent.json` (default 600). Isi `0` bila blokir tidak boleh pernah dilepas otomatis.
- Kelemahan yang diketahui: bila server mati saat PC ada di kantor, blokir ikut terlepas setelah jeda itu sampai server hidup lagi. Pengguna dengan VPN ke kantor terhubung ke server, jadi tetap terblokir.
- Selama server uji coba adalah laptop admin, PC yang tidak bisa menjangkau laptop itu dianggap "di luar" dan blokirnya dilepas.

### Uninstall

Installer menjalankan `clear-policy`; bila gagal menjalankan `recover-policy.ps1`; bila masih gagal, **folder data dipertahankan**, skrip pemulihan disalin ke `C:\ProgramData\NetWatch-Recovery\` dan pesan/petunjuk ditampilkan.

### Skrip pemulihan: `agent\install\windows\recover-policy.ps1`

Mandiri (tanpa PHP/agent). PowerShell sebagai Administrator:

```powershell
.\recover-policy.ps1            # PERIKSA saja, tidak mengubah apa pun
.\recover-policy.ps1 -Apply     # bersihkan yang terbukti milik NetWatch
.\recover-policy.ps1 -Apply -AssumeDomains facebook.com,instagram.com   # hanya bila catatan kepemilikan hilang dan Anda yakin
```

Tanpa catatan kepemilikan, entri URLBlocklist tidak dihapus otomatis (pemiliknya tidak bisa dipastikan); skrip memberi peringatan dan petunjuk. Skrip ini belum diuji pada Windows sungguhan (lingkungan pengembangan tanpa PowerShell): jalankan mode periksa lebih dulu pada PC uji.

### Status yang ditampilkan

Configured = ada aturan di server; Pending = menunggu agent / menyimpang; Applied = agent melaporkan hash aturan terpasang sama dengan server (terverifikasi); Error = galat penerapan/verifikasi.
