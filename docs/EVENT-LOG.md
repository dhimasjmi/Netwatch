# Event Log perangkat (fitur 5, tahap 2)

Dinonaktifkan secara bawaan. Aktifkan di `.env` server: `NETWATCH_WINLOG_ENABLED=true`. Server memberi tahu agent lewat heartbeat (`winlog_enabled`); agent baru mengumpulkan saat flag menyala.

## Sumber yang aktif
- **Windows Event Log** (agent 1.4.0+): log System dan Application. Critical/Error dari semua penyedia; Warning hanya dari penyedia jaringan/hardware tertentu (DNS, DHCP, Tcpip, NIC/WLAN, dll.).
- **Linux journald** (agent 1.5.0+): prioritas err ke atas lewat `journalctl`.
- Pengumpulan inkremental per log dengan kursor (waktu + nomor record) yang disimpan di `winlog-state.json`; kunjungan pertama membaca 1 jam terakhir. Interval 5 menit, maks. 200 entri per kiriman, entri identik digabung dengan hitungan. Kursor baru maju setelah server menerima kiriman.
- Server memvalidasi, menolak log selain System/Application, dedupe berdasarkan log+record, mengklasifikasi kategori (DNS/DHCP, Wi-Fi/NIC, shutdown tak terduga, layanan, dll.) dan keparahan, dan menyimpan detail terstruktur (log, provider, event_id, record_id, level, count) beserta waktu asli dan waktu ingest.

## Hak akses
Event `winlog.*` dan kategori keamanan hanya terlihat oleh admin. Retensi mengikuti pengaturan retensi event yang ada.

## Belum diimplementasikan (jujur)
- **Penerima syslog UDP 514 dan SNMP trap UDP 162**: belum ada. Event Viewer tidak menampilkan sumber ini; statusnya tidak aktif. Komponen penerima terpisah (daemon, binding port, firewall, validasi sumber) masih perlu dirancang.
- Event Log Security Windows (login) sengaja tidak dikumpulkan (data sensitif).
- Pembacaan Event Log Windows dan journald belum divalidasi pada PC/server sungguhan; hanya parser yang diuji.
