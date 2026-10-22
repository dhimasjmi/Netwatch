#!/usr/bin/env bash
# Menghapus NetWatch Agent dari Linux. Perangkat tetap tercatat di dashboard sampai dihapus/dicabut di sana.
set -euo pipefail

[[ $EUID -eq 0 ]] || { echo "ERROR: Jalankan sebagai root (sudo)." >&2; exit 1; }

systemctl disable --now netwatch-agent.service 2>/dev/null || true
rm -f /etc/systemd/system/netwatch-agent.service
systemctl daemon-reload
rm -rf /opt/netwatch-agent /etc/netwatch-agent
rm -f /var/log/netwatch-agent.log /var/log/netwatch-agent.log.1
echo "NetWatch Agent dihapus."
