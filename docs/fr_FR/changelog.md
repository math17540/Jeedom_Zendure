# Changelog plugin Zendure

## 1.0.0 · 12/11/2026

Première version publique.

- Modèles MQTT local : Hyper 2000, SolarFlow Hub 1200, Hub 2000, AIO 2400, ACE 1500, reconnus par leur productKey.
- Modèles zenSDK : SolarFlow 800, 800 Plus, 800 Pro, 1600 AC+, 2400 AC, 2400 AC+, 2400 Pro, 3000 Mix AC+, 4000 ; recherche mDNS.
- Compteurs : Zendure Smart Meter (1CT, 3CT, D0) et Shelly (Gen1 à Gen4).
- Pilotage : limites, SOC, mode AC, injection et charge pilotées selon le modèle.
- Broker : ajout des comptes dans MQTT Manager, port 8883 (TLS) sans certificat client.
- Redirection Bluetooth : outil Windows fourni (`ZendureMqttTool.exe`) et Bluetooth de la box Jeedom.
- Démon : lecture rapide, reconnexion forcée des sessions MQTT figées.
- Page Santé de Jeedom, documentation locale.
