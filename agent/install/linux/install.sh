#!/usr/bin/env bash
# Memasang NetWatch Agent di Linux sebagai layanan systemd. Jalankan sebagai root.
#   sudo NETWATCH_ENROLL_TOKEN=... ./install.sh --server=https://netwatch.perusahaan.local [--ca-file=/path/ca.pem]
set -euo pipefail

readonly INSTALL_DIR="/opt/netwatch-agent"
readonly CONFIG_DIR="/etc/netwatch-agent"
readonly CONFIG_FILE="${CONFIG_DIR}/agent.json"
readonly SERVICE_FILE="/etc/systemd/system/netwatch-agent.service"
readonly SOURCE_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"

server="" ca_file="" insecure="" reenroll=""

die() { echo "ERROR: $*" >&2; exit 1; }

for arg in "$@"; do
  case "$arg" in
    --server=*) server="${arg#*=}" ;;
    --ca-file=*) ca_file="${arg#*=}" ;;
    --allow-insecure-http) insecure="--allow-insecure-http" ;;
    --reenroll) reenroll="yes" ;;
    *) die "Argumen tidak dikenal: $arg" ;;
  esac
done

[[ $EUID -eq 0 ]] || die "Jalankan sebagai root (sudo)."
[[ -n "$server" ]] || die "--server wajib diisi."
command -v systemctl >/dev/null || die "systemd diperlukan."
command -v php >/dev/null || die "PHP CLI belum terpasang (butuh PHP >= 8.2 dengan ekstensi curl dan mbstring)."

php -r 'exit(PHP_VERSION_ID >= 80200 ? 0 : 1);' || die "PHP minimal 8.2."
for ext in curl mbstring json; do
  php -m | grep -qix "$ext" || die "Ekstensi PHP '$ext' belum aktif."
done

# Salin kode agent (tanpa berkas konfigurasi/rahasia).
install -d -m 0755 "$INSTALL_DIR"
rm -rf "${INSTALL_DIR}/src" "${INSTALL_DIR}/bin"
cp -r "${SOURCE_DIR}/src" "${SOURCE_DIR}/bin" "$INSTALL_DIR/"
chmod 0755 "${INSTALL_DIR}/bin/netwatch-agent"
chown -R root:root "$INSTALL_DIR"

install -d -m 0700 "$CONFIG_DIR"

ca_args=()
if [[ -n "$ca_file" ]]; then
  [[ -r "$ca_file" ]] || die "Berkas CA tidak bisa dibaca: $ca_file"
  install -m 0644 "$ca_file" "${CONFIG_DIR}/ca.pem"
  ca_args=(--ca-file="${CONFIG_DIR}/ca.pem")
fi

if [[ ! -f "$CONFIG_FILE" || -n "$reenroll" ]]; then
  token="${NETWATCH_ENROLL_TOKEN:-}"
  if [[ -z "$token" ]]; then
    read -r -s -p "Token pendaftaran: " token; echo
  fi
  [[ -n "$token" ]] || die "Token pendaftaran kosong."
  # Token lewat environment, bukan argumen, supaya tidak muncul di `ps`.
  NETWATCH_ENROLL_TOKEN="$token" php "${INSTALL_DIR}/bin/netwatch-agent" enroll \
    --server="$server" --config="$CONFIG_FILE" "${ca_args[@]}" $insecure
  unset token
else
  echo "Agent sudah terdaftar; lewati pendaftaran (pakai --reenroll untuk mendaftar ulang)."
fi
chmod 0600 "$CONFIG_FILE"

cat > "$SERVICE_FILE" <<UNIT
[Unit]
Description=NetWatch Agent
After=network-online.target
Wants=network-online.target

[Service]
Type=simple
ExecStart=$(command -v php) ${INSTALL_DIR}/bin/netwatch-agent run --config=${CONFIG_FILE}
Restart=always
RestartSec=10
NoNewPrivileges=true
PrivateTmp=true
ProtectHome=true

[Install]
WantedBy=multi-user.target
UNIT

systemctl daemon-reload
systemctl enable --now netwatch-agent.service
echo "NetWatch Agent terpasang dan berjalan. Cek: systemctl status netwatch-agent"
