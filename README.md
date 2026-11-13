# Plugin Jeedom Zendure

Pilotage **local** des appareils Zendure dans [Jeedom](https://www.jeedom.com), sans le cloud
Zendure.

| Famille | Modèles | Connexion |
|---|---|---|
| Modèles « anciens » | Hyper 2000, SolarFlow Hub 1200, Hub 2000, AIO 2400, ACE 1500 | MQTT local (MQTT Manager), redirection par Bluetooth |
| zenSDK | SolarFlow 800, 800 Plus, 800 Pro, 1600 AC+, 2400 AC, 2400 AC+, 2400 Pro, 3000 Mix AC+, 4000 | API HTTP locale |
| Compteurs | Zendure Smart Meter 1CT / 3CT / D0, Shelly (Pro 3EM, EM, 3EM…) | HTTP |

Fourni avec le plugin :

- `ZendureMqttTool.exe` : outil Windows qui redirige un Hyper, Hub, AIO ou ACE vers le broker
  de la box par Bluetooth (le Bluetooth de la box Jeedom peut aussi le faire) ;
- un bouton qui autorise le port 8883 (TLS) sans certificat client dans MQTT Manager, pour les
  firmwares récents.

Protocole vérifié sur de vrais appareils : Hyper 2000, SolarFlow 2400 AC+. Cette version est
testée sur simulateurs ; les autres modèles suivent les informations publiées par Zendure et par
les projets ci-dessous. Les retours sont bienvenus (issues).

## Installation

Jeedom 4.4 ou plus. *Plugins → Gestion des plugins → +*, source **GitHub** :
utilisateur `math17540`, dépôt `Jeedom_Zendure`, branche `main`, ID logique `zendure`.
Ou source **Fichier** avec le zip d'une [release](../../releases).

La documentation complète est dans [docs/fr_FR/index.md](docs/fr_FR/index.md) ; elle s'ouvre aussi
depuis le bouton **Documentation** du plugin dans Jeedom.

## Remerciements

- [Zendure-HA](https://github.com/Zendure/Zendure-HA) et [zenSDK](https://github.com/Zendure/zenSDK) (Zendure)
- [Zendure Cloud Disconnector](https://github.com/nograx/zendure-cloud-disconnector) et
  [ioBroker.zendure-solarflow](https://github.com/nograx/ioBroker.zendure-solarflow) (nograx)
- [solarflow](https://github.com/reinhard-brandstaedter/solarflow) (reinhard-brandstaedter)
- [evcc](https://github.com/evcc-io/evcc)

Aucun code de ces projets n'est repris : seulement les protocoles et les tables de modèles.

## Licence

AGPL-3.0 (voir [LICENSE](LICENSE)). Projet indépendant, non affilié à Zendure ni à Jeedom.
