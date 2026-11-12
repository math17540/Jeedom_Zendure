#!/bin/bash
# Force la reconnexion MQTT d'un Hyper figé (session bloquée côté broker) : rejette 3 s les paquets
# de son adresse vers le broker, ce qui coupe la connexion ; l'Hyper se reconnecte de lui-même.
# Usage (root) : mqtt_reconnect.sh <deviceId>  — affiche l'adresse coupée.
set -u
ID="${1:-}"
if ! [[ "$ID" =~ ^[A-Za-z0-9]{4,32}$ ]]; then
    echo "deviceId invalide" >&2
    exit 2
fi
LOG=/var/log/mosquitto/mosquitto.log
IP=$(grep -a "New client connected from .* as ${ID} " "$LOG" 2>/dev/null | tail -n 1 | sed -E 's/.*connected from ([0-9.]+):[0-9]+ as .*/\1/')
if ! [[ "$IP" =~ ^[0-9]{1,3}(\.[0-9]{1,3}){3}$ ]]; then
    echo "adresse de ${ID} introuvable dans ${LOG}" >&2
    exit 3
fi
RULES=()
for PORT in 8883 1883; do
    RULES+=("-s $IP -p tcp --dport $PORT -j REJECT --reject-with tcp-reset")
done
cleanup() {
    for R in "${RULES[@]}"; do
        # shellcheck disable=SC2086
        while iptables -C INPUT $R 2>/dev/null; do iptables -D INPUT $R; done
    done
}
trap cleanup EXIT INT TERM
for R in "${RULES[@]}"; do
    # shellcheck disable=SC2086
    iptables -I INPUT 1 $R
done
sleep 3
echo "$IP"
