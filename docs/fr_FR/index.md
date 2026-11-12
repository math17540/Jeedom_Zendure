# Plugin Zendure

Pilote les appareils **Zendure** en local, sans le cloud Zendure, et lit les **compteurs**
Zendure et Shelly.

| Famille | Modèles | Connexion |
|---|---|---|
| Modèles « anciens » | Hyper 2000, SolarFlow Hub 1200, Hub 2000, AIO 2400, ACE 1500 | MQTT local, via **MQTT Manager** ; redirection une fois par Bluetooth |
| zenSDK | SolarFlow 800, 800 Plus, 800 Pro, 1600 AC+, 2400 AC, 2400 AC+, 2400 Pro, 3000 Mix AC+, 4000 | API HTTP locale (option MQTT) |
| Compteurs Zendure | Smart Meter 1CT, 3CT, D0 | API HTTP locale |
| Compteurs Shelly | Pro 3EM, 3EM-63, Pro EM, EM Gen3, 3EM et EM Gen1, prises et relais avec mesure | HTTP |

> Le protocole de l'Hyper 2000 et du SolarFlow 2400 AC+ a été vérifié sur de vrais appareils.
> Cette version a été testée sur simulateurs (Hyper, Hub, SolarFlow, compteurs Zendure et Shelly) ;
> le Bluetooth de la box n'a pas été testé faute d'adaptateur. Les autres modèles suivent les
> informations publiées par Zendure et par les projets cités à la fin de cette page. Merci de
> signaler ce qui ne fonctionne pas.

## Installation

Depuis GitHub (mises à jour automatiques) :

1. *Réglages → Système → Configuration → Mises à jour/Market*, onglet **GitHub** : cochez **Activer**.
2. *Plugins → Gestion des plugins*, bouton **+** : source **GitHub**, ID logique **`zendure`**,
   utilisateur **`math17540`**, dépôt **`jeedom-zendure`**, branche **`main`**.
3. Ouvrez le plugin **Zendure**, cliquez sur **Activer**, puis démarrez le **démon**.

Depuis le fichier .zip : même chose avec la source **Fichier** et le fichier `zendure-x.y.z.zip`.

Les modèles MQTT ont besoin de **MQTT Manager** (installé, actif, mode *Broker local*, démon OK).
Les appareils zenSDK et les compteurs fonctionnent sans.

## Modèles MQTT : Hyper 2000, Hub, AIO, ACE

Ces appareils parlent au cloud Zendure. On les redirige **une fois** vers le broker de la box
Jeedom, par Bluetooth. Ils restent ensuite visibles en Bluetooth dans l'application Zendure
(mises à jour du firmware comprises), mais plus par Internet.

### 1. Préparer le broker

Dans *Plugins → Zendure → Configuration* :

* **Port 8883 (TLS) sans certificat client** : les firmwares récents se connectent en TLS sur le
  port 8883, sans certificat client. Le listener 8883 de MQTT Manager exige un certificat client,
  ce qui donne en boucle dans le log Mosquitto `peer did not return a certificate`. Le bouton
  **Autoriser les appareils en TLS (8883)** retire cette exigence ; le **mot de passe reste
  obligatoire**. Les firmwares plus anciens utilisent le port 1883.
* **Autoriser des appareils sur le broker** : chaque appareil se connecte avec
  **identifiant = deviceId** et un mot de passe dérivé de ce deviceId. Saisissez les deviceId (un
  par ligne) et cliquez sur **Ajouter à MQTT Manager**. Le deviceId est affiché par l'outil de
  redirection quand l'appareil le donne ; sinon, le log Mosquitto le montre à la première connexion
  refusée.

### 2. Rediriger l'appareil

Deux possibilités, au choix :

| Outil | Quand l'utiliser |
|---|---|
| **Outil Windows** (lien dans la configuration du plugin, `ZendureMqttTool.exe`) | depuis n'importe quel PC Windows avec Bluetooth, à quelques mètres de l'appareil |
| **Bluetooth de la box** (configuration du plugin) | si la box Jeedom a un adaptateur Bluetooth à portée de l'appareil ; cliquez d'abord sur **Installer le Bluetooth** |

Dans les deux cas :

1. Fermez l'application Zendure sur le téléphone (un seul client Bluetooth à la fois).
2. **Rechercher** : le modèle est reconnu par son nom Bluetooth ; comparez la fin du numéro de
   série avec l'étiquette.
3. Saisissez l'**IP de la box Jeedom** (sans port), le **nom** et le **mot de passe** du Wi-Fi
   **2,4 GHz**. Le mot de passe n'est transmis qu'à l'appareil : il n'est ni enregistré ni journalisé.
4. **Basculer vers le broker local**. L'appareil relance son Wi-Fi et se connecte en 15 à 60 s.
   Si rien n'arrive au bout de 2 minutes, **éteignez puis rallumez l'appareil**.

**Revenir au cloud Zendure** remet l'adresse du cloud.

L'outil Windows est un exécutable non signé : Windows SmartScreen ou l'antivirus peuvent
demander une confirmation.

### 3. L'équipement se crée tout seul

Au premier rapport, l'équipement est créé (option *Créer automatiquement les appareils MQTT
détectés*). Le modèle est reconnu par son **productKey** ; s'il est inconnu, l'équipement s'appelle
« Appareil Zendure MQTT » : choisissez alors son **modèle** dans l'équipement, puis sauvegardez.

## Appareils zenSDK : SolarFlow 800 à 4000

1. Activez l'API locale : dans l'application Zendure, ajoutez un **HEMS** puis quittez l'application.
2. **Rechercher (mDNS)** dans la page du plugin, ou attendez la recherche automatique (toutes les
   15 min) : l'appareil est créé avec son IP. Sinon, **Ajouter** et saisissez son IP.

Le **numéro de série** est lu automatiquement ; il est obligatoire pour envoyer des commandes.

*Commandes par* : **Automatique** utilise MQTT si l'appareil publie sur le broker (MQTT configuré
dans le zenSDK : port 1883, sans TLS, compte créé avec `identifiant:motdepasse` dans la
configuration du plugin), sinon HTTP.

**smartMode** (coché par défaut) : les changements de limite et de mode AC ne sont pas écrits en
mémoire flash, comme le recommande Zendure pour les réglages fréquents. Au redémarrage de
l'appareil, les valeurs enregistrées reviennent. Décochez pour des réglages permanents.

## Compteurs

| Compteur | Ajout | Lecture |
|---|---|---|
| **Zendure Smart Meter** 1CT / 3CT / D0 | trouvé par la recherche mDNS, ou **Ajouter** + IP | `total_power` = puissance au compteur ; les autres valeurs du compteur sont créées telles quelles (« champs bruts », masqués par défaut) |
| **Shelly** | **Ajouter** + IP | génération détectée automatiquement ; puissance totale et par phase, tensions, énergies soutirée et injectée |

* Puissance réseau : **positive = soutirage, négative = injection** (pinces côté réseau).
* Le Shelly ne doit pas avoir de mot de passe.
* *Lecture toutes les* : intervalle en secondes (5 par défaut) quand le démon tourne ; chaque minute sinon.

## Commandes des batteries

### Infos (selon le modèle)

| Commande | Unité | Remarque |
|---|---|---|
| En ligne | 0/1 | passe à 0 après *n* secondes sans données (180 par défaut) |
| Batterie | % | niveau global |
| Production solaire, PV 1…6 | W | PV créés à la première valeur reçue |
| Injection maison / Charge réseau | W | |
| Charge batterie / Décharge batterie | W | |
| Prise secours | W | sortie off-grid |
| Limite injection / Limite charge réseau | W | |
| SOC max / SOC min | % | convertis depuis les ‰ du firmware |
| Mode AC | | 1 = charge depuis le réseau, 2 = injection |
| État batterie | | 0 = repos, 1 = charge, 2 = décharge |
| Nombre de batteries, Autonomie restante, Température, Chauffage, Signal WiFi | | |
| *Modèle* *xxxx* SOC / Température / Tension / Courant / Santé | | une série par batterie (AB1000, AB2000, AB3000…), courant négatif = décharge |

### Actions

| Commande | Effet |
|---|---|
| Régler injection / Régler charge réseau | limites, plafonnées à la puissance du modèle |
| Régler SOC max / SOC min | 70 – 100 % / 0 – 50 % |
| Régler mode AC | Charger / Injecter |
| **Injecter** (W) | restitution pilotée (deviceAutomation en MQTT, mode AC + limite en zenSDK) |
| **Charger depuis le réseau** (W) | Hyper 2000 et appareils zenSDK |
| **Arrêter charge et injection** | |
| Régler puissance max onduleur, renvoi vers le réseau, mode prise secours | appareils zenSDK |
| Rafraîchir | relecture complète |

Les Hub 1200 / 2000 et l'AIO 2400 ne se chargent pas depuis le réseau : leurs commandes de
charge ne sont pas créées. L'ACE 1500 et un appareil dont le modèle n'est pas choisi n'ont que les
limites (pas de pilotage direct).

| Modèle | Injection max | Charge réseau max |
|---|---|---|
| Hyper 2000 | 1200 W | 1200 W |
| Hub 1200, Hub 2000, AIO 2400 | 1200 W | – |
| ACE 1500 | 800 W | 900 W |
| SolarFlow 800 / 800 Plus / 800 Pro | 800 W | 1000 W |
| SolarFlow 1600 AC+ | 1600 W | 1600 W |
| SolarFlow 2400 AC / AC+ | 2400 W | 2400 W |
| SolarFlow 2400 Pro, 4000 | 2400 W | 3200 W |
| SolarFlow 3000 Mix AC+ | 3000 W | 3000 W |

Évitez de modifier les limites plus d'une fois toutes les 10 à 30 secondes.

## Configuration du plugin

| Réglage | Détail |
|---|---|
| Recherche automatique | recherche mDNS toutes les 15 min (nouveaux appareils, changement d'IP) |
| Créer automatiquement les appareils MQTT détectés | au premier rapport |
| Interroger les appareils MQTT muets | `getAll` si un appareil se tait plus d'une minute |
| Hors ligne après | secondes sans donnée avant *En ligne* = 0 |
| Lecture des appareils zenSDK | intervalle du démon (5 s minimum) |
| Reconnexion forcée d'un appareil MQTT muet | voir ci-dessous (90 s, 0 = désactivé) |

**Reconnexion forcée** : un appareil MQTT publie environ une fois par seconde. S'il reste connecté
mais muet, sa session est figée côté broker : le démon coupe 3 s sa seule adresse pour qu'il se
reconnecte (journal *zendure_watchdog*). Au plus une tentative toutes les 2 minutes, puis délai
doublé jusqu'à 15 minutes.

## Page Santé

*Analyse → Santé*, ligne **Zendure** : démon, MQTT Manager et chaque appareil (en ligne, âge de la
dernière donnée).

## Fonctionnement

* **Modèles MQTT** : rapports sur `/<productKey>/<deviceId>/properties/report` (MQTT Manager transmet
  au plugin les topics commençant par `/` ; ceux qui ne viennent pas d'un Zendure lui sont rendus).
  Commandes sur `iot/<productKey>/<deviceId>/properties/write` et `…/function/invoke`.
* **zenSDK** : `GET http://<ip>/properties/report`, commandes par `POST http://<ip>/properties/write`.
* **Démon** : lecture rapide des appareils et des compteurs, surveillance des sessions MQTT.

## Dépannage

* **Log du plugin** : *Analyse → Logs → zendure* (niveau debug pour voir les données brutes).
* **Rien n'arrive après la redirection** : éteignez puis rallumez l'appareil. Vérifiez le Wi-Fi
  2,4 GHz, le compte de l'appareil sur le broker et, pour le port 8883, le bouton TLS.
* **`peer did not return a certificate` dans le log Mosquitto** : bouton **Autoriser les appareils
  en TLS (8883)**.
* **Appareil MQTT muet après quelques heures** : la reconnexion forcée s'en charge ; voir le journal
  *zendure_watchdog*.
* **SolarFlow injoignable** : `curl http://<ip>/properties/report` depuis la box. Pas de réponse →
  API locale non activée (HEMS) ou mauvaise IP.
* **Recherche mDNS vide** : la box doit être sur le même réseau local que l'appareil (le mDNS ne
  traverse pas les routeurs ni les VLAN). Ajoutez-le alors par son IP.
* **Bluetooth de la box** : il faut un adaptateur reconnu (`/sys/class/bluetooth/hci0`) ; journal
  *zendure_ble* pour l'installation.

## Remerciements

Les protocoles et les tables de modèles viennent de ces projets, merci à leurs auteurs :

* [Zendure-HA](https://github.com/Zendure/Zendure-HA) et [zenSDK](https://github.com/Zendure/zenSDK) (Zendure) ;
* [Zendure Cloud Disconnector](https://github.com/nograx/zendure-cloud-disconnector) et
  [ioBroker.zendure-solarflow](https://github.com/nograx/ioBroker.zendure-solarflow) (nograx) ;
* [solarflow](https://github.com/reinhard-brandstaedter/solarflow) (reinhard-brandstaedter) ;
* [evcc](https://github.com/evcc-io/evcc) pour le compteur Zendure 3CT.
