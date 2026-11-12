#!/bin/bash
# Installe le Bluetooth du plugin Zendure : paquets BlueZ et environnement Python avec bleak.
# Usage (root) : install_ble.sh <fichier de progression>
set -u
PROGRESS="${1:-/tmp/zendure_ble_progress}"
DIR="$(cd "$(dirname "$0")" && pwd)/ble"
progress() { echo "$1" > "$PROGRESS"; echo "== $2"; }

progress 5 "Paquets système"
export DEBIAN_FRONTEND=noninteractive
apt-get update -q
apt-get install -y -q bluez python3-venv python3-pip || { progress 100 "échec apt"; exit 1; }

progress 40 "Environnement Python"
python3 -m venv "$DIR/venv" || { progress 100 "échec venv"; exit 1; }
"$DIR/venv/bin/pip" install -q --upgrade pip
progress 60 "bleak"
"$DIR/venv/bin/pip" install -q "bleak==0.22.3" || { progress 100 "échec bleak"; exit 1; }
chown -R www-data:www-data "$DIR/venv"

progress 90 "Service Bluetooth"
systemctl enable --now bluetooth 2>/dev/null || true
if command -v rfkill >/dev/null; then rfkill unblock bluetooth || true; fi

progress 100 "Terminé"
echo "OK"
