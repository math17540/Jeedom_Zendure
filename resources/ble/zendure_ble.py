#!/usr/bin/env python3
# -*- coding: utf-8 -*-
"""Redirection MQTT des appareils Zendure « anciens » par Bluetooth, depuis la box Jeedom.

Appareils : Hyper 2000, SolarFlow Hub 1200 / Hub 2000, AIO 2400, ACE 1500.
Méthode (Zendure-HA, Zendure Cloud Disconnector de nograx) : écriture JSON sur la caractéristique C304
  1. {"method": "token", "iotUrl": <broker>, "ssid", "password", "timeZone", "token", "messageId": 1002}
  2. {"method": "station", "messageId": 1003}
L'appareil se reconnecte au broker indiqué. Le port est choisi par le firmware : 1883, ou 8883 en TLS
(sans certificat client) sur les firmwares récents. Si rien n'arrive, redémarrer l'appareil.

Usage :
  zendure_ble.py scan [--timeout 10]
  zendure_ble.py setbroker --address AA:BB:... --broker 192.168.1.10 --ssid MonWifi [--timezone GMT+01:00]
      (le mot de passe Wi-Fi est lu sur l'entrée standard, jamais en argument ni dans les journaux)
  zendure_ble.py cloud --address AA:BB:... --ssid MonWifi       (retour au cloud Zendure)
Sortie : une ligne JSON {"ok": true, ...} ou {"ok": false, "error": "..."}.
"""
import argparse
import asyncio
import ipaddress
import json
import sys

COMMAND_CHAR = "0000c304-0000-1000-8000-00805f9b34fb"
NOTIFY_CHAR = "0000c305-0000-1000-8000-00805f9b34fb"
CLOUD_BROKER = "mq.zen-iot.com"
PREFIXES = {"zenp": "SolarFlow Hub 1200", "zenh": "SolarFlow Hub 2000", "zenr": "SolarFlow AIO 2400",
            "zene": "Hyper 2000", "zenf": "ACE 1500"}


def out(obj):
    print(json.dumps(obj, ensure_ascii=False))
    sys.stdout.flush()


def model_of(name):
    low = (name or "").lower()
    return PREFIXES.get(low[:4], "Zendure" if "zen" in low else None)


def decode_sn(manufacturer_data):
    """Fin du numéro de série diffusée dans les données constructeur."""
    for value in (manufacturer_data or {}).values():
        try:
            text = bytes(value).decode("utf-8")[:-1]
        except (UnicodeDecodeError, ValueError, TypeError):
            continue
        if len(text) >= 4 and text.isalnum():
            return text
    return None


async def scan(timeout):
    from bleak import BleakScanner
    found = await BleakScanner.discover(timeout=timeout, return_adv=True)
    result = []
    for address, (device, adv) in found.items():
        name = getattr(device, "name", None) or getattr(adv, "local_name", None) or ""
        model = model_of(name)
        if model is None:
            continue
        result.append({"address": str(address), "name": str(name), "model": model,
                       "rssi": getattr(adv, "rssi", None), "sn": decode_sn(getattr(adv, "manufacturer_data", None))})
    result.sort(key=lambda d: -(d["rssi"] or -999))
    return result


def check_broker(broker):
    if broker == CLOUD_BROKER:
        return broker
    if ":" in broker:
        raise ValueError("adresse IP seule, sans port : le port est choisi par l'appareil")
    ip = ipaddress.IPv4Address(broker)
    if ip.is_loopback or ip.is_unspecified:
        raise ValueError("adresse non joignable depuis l'appareil : %s" % ip)
    return str(ip)


async def set_broker(address, broker, ssid, password, timezone):
    from bleak import BleakClient
    broker = check_broker(broker)
    if not ssid or not password:
        raise ValueError("SSID et mot de passe Wi-Fi obligatoires (Wi-Fi 2,4 GHz)")
    replies = []
    async with BleakClient(address, timeout=30.0) as client:
        def on_notify(_char, data):
            try:
                replies.append(bytes(data).decode("utf-8"))
            except UnicodeDecodeError:
                replies.append(bytes(data).hex())
        try:
            await client.start_notify(NOTIFY_CHAR, on_notify)
        except Exception:
            pass  # notifications facultatives
        char = client.services.get_characteristic(COMMAND_CHAR)
        if char is None:
            raise RuntimeError("caractéristique C304 introuvable : appareil non compatible")
        response = "write-without-response" not in char.properties

        async def send(payload):
            await client.write_gatt_char(char, json.dumps(payload, separators=(",", ":")).encode("utf-8"), response=response)

        await send({"iotUrl": broker, "messageId": 1002, "method": "token", "password": password,
                    "ssid": ssid, "timeZone": timezone, "token": "abcdefgh"})
        await asyncio.sleep(1.5)
        await send({"messageId": 1003, "method": "station"})
        await asyncio.sleep(3.0)
    # la réponse peut contenir des données de l'appareil (deviceId) : on la renvoie, sans le mot de passe
    device_id = None
    for r in replies:
        try:
            j = json.loads(r)
        except ValueError:
            continue
        if isinstance(j, dict):
            props = j.get("properties") if isinstance(j.get("properties"), dict) else j
            device_id = device_id or props.get("deviceId")
    return {"address": address, "broker": broker, "deviceId": device_id, "replies": len(replies)}


def main():
    p = argparse.ArgumentParser()
    sub = p.add_subparsers(dest="cmd")
    s = sub.add_parser("scan")
    s.add_argument("--timeout", type=float, default=10.0)
    for name in ("setbroker", "cloud"):
        b = sub.add_parser(name)
        b.add_argument("--address", required=True)
        b.add_argument("--ssid", required=True)
        b.add_argument("--timezone", default="GMT+01:00")
        if name == "setbroker":
            b.add_argument("--broker", required=True)
    a = p.parse_args()
    try:
        if a.cmd == "scan":
            out({"ok": True, "devices": asyncio.run(scan(a.timeout))})
        elif a.cmd in ("setbroker", "cloud"):
            password = sys.stdin.readline().rstrip("\r\n")
            broker = CLOUD_BROKER if a.cmd == "cloud" else a.broker
            out(dict({"ok": True}, **asyncio.run(set_broker(a.address, broker, a.ssid, password, a.timezone))))
        else:
            p.print_help()
            return 2
    except Exception as e:  # message lisible pour la page du plugin
        out({"ok": False, "error": "%s: %s" % (type(e).__name__, e)})
        return 1
    return 0


if __name__ == "__main__":
    sys.exit(main())
