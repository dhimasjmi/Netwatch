# Penyebaran Agent ke PC User (Windows)

## 1. Membangun installer (sekali, di komputer pengembang Windows)
1. Pasang **Inno Setup 6.3+** (jrsoftware.org/isinfo.php).
2. Siapkan PHP 8.2+ x64 (mis. folder PHP milik Laragon). Pastikan ada `ext\php_curl.dll`, `php_mbstring.dll`, `php_openssl.dll`.
3. PowerShell di folder `agent\installer\windows`:
   ```powershell
   Set-ExecutionPolicy -Scope Process Bypass -Force
   .\build-installer.ps1 -PhpDir C:\laragon\bin\php\php-8.3.xx-Win32-vs16-x64
   ```
   Hasil: `agent\dist\NetWatchAgent-Setup.exe` (+ nilai SHA256 yang sebaiknya dicatat).
   Jika server memakai sertifikat dari CA publik, tambahkan `-CaBundle C:\path\cacert.pem`.

## 2. Pemasangan manual di satu PC
Klik dua kali `NetWatchAgent-Setup.exe` (setuju prompt UAC), isi **alamat server** dan **token pendaftaran**, selesai.
Agent langsung jalan di latar belakang dan otomatis aktif setiap boot, tanpa perlu login.

## 3. Pemasangan senyap massal
Buat token dengan **jumlah pakai** sesuai banyaknya PC dan masa berlaku singkat di dashboard. Simpan token di berkas
teks yang hanya bisa dibaca akun admin penyebar, lalu:
```
NetWatchAgent-Setup.exe /VERYSILENT /SUPPRESSMSGBOXES /NORESTART /LOG=C:\Windows\Temp\netwatch-install.log ^
  /SERVER=https://netwatch.perusahaan.local /TOKENFILE=\\fileserver\netwatch$\token.txt /CAFILE=\\fileserver\netwatch$\ca.pem
```
Cocok untuk GPO (startup script), Intune, PDQ, atau PsExec. Kode keluar bukan nol berarti gagal: baca `/LOG` dan
`C:\ProgramData\NetWatch\agent.log`.

## 4. Pembaruan dan penghapusan
- Jalankan installer versi baru di atas yang lama: kode diperbarui, pendaftaran tidak diulang (pakai `/REENROLL=1` untuk mendaftar ulang).
- Hapus lewat **Settings → Apps → NetWatch Agent**. Setelah itu hapus perangkat di dashboard.

## 5. Yang harus diuji di PC pilot sebelum disebar
Pasang → perangkat online → restart tanpa login → online kembali → kirim pesan, akhiri proses → uninstall bersih.
Cek juga apakah antivirus/EDR kantor memblokir `php.exe` yang menjalankan `powershell.exe`.
