# Port terbuka dan layanan (fitur 7)

Agent (1.4.0+) mengirim snapshot tiap 10 menit (pertama sekitar 15 detik setelah mulai) ke `POST /api/agent/v1/inventory` dengan token perangkat.

- **Windows:** `Get-NetTCPConnection -State Listen`, `Get-NetUDPEndpoint` (port < 49152), proses pemilik, layanan dari `Win32_Service`.
- **Linux:** `ss -tlnp` dan `ss -ulnp`, serta `systemctl list-units/list-unit-files`.
- Hanya port **listening**; koneksi keluar/established tidak dihitung sebagai port terbuka. Tidak ada pemindaian jaringan dari server.
- Server membatasi ukuran payload dan memvalidasi tiap entri; snapshot disimpan di `device_ports` dan `device_services` (upsert, entri yang hilang dihapus).
- **Perubahan:** setelah baseline (snapshot pertama) tercatat, port TCP baru yang terbuka ke jaringan (bukan loopback, < 49152) membuat event `device.port_opened` (maks. 10 per snapshot, jeda 24 jam per port).
- **UI:** Perangkat → **Port & layanan** (tab Port / Layanan, filter, pengelompokan). Hanya peran yang punya hak `manage-fleet`.
- Kegagalan satu pengumpul tidak menghentikan heartbeat.

Belum ada: deteksi eksplisit "port hilang" dan "layanan berhenti" sebagai event (data terkini selalu terlihat; riwayat snapshot tidak disimpan), serta penyaringan berdasarkan tanggal.
