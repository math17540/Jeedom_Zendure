# Plugin Zendure

Ce plugin permet à **Jeedom** de lire et de piloter vos batteries solaires **Zendure**
directement chez vous, **sans passer par le cloud Zendure** (pas d'Internet nécessaire,
réactions en quelques secondes). Il lit aussi les **compteurs** Zendure et Shelly, qui mesurent
ce que la maison consomme ou renvoie au réseau.

Vous pourrez ensuite, depuis Jeedom :

* voir l'état des batteries (niveau de charge, production solaire, puissance injectée…) ;
* régler la puissance injectée dans la maison, la charge depuis le réseau, les niveaux mini et maxi ;
* créer des scénarios (par exemple : charger la nuit en heures creuses, restituer le soir).

![Le tableau de bord Jeedom avec les batteries et les compteurs](images/dashboard.png)

## Avant de commencer : quelques mots de vocabulaire

| Mot | Ce que c'est |
|---|---|
| **Box Jeedom** | l'ordinateur (Raspberry, Atlas, Smart, PC…) sur lequel Jeedom tourne |
| **Plugin** | un module qu'on ajoute à Jeedom ; celui-ci s'appelle « Zendure » |
| **Équipement** | un appareil vu par Jeedom : une batterie, un compteur |
| **Commande** | une valeur lue (ex. « Batterie 64 % ») ou une action (ex. « Injecter 300 W ») |
| **Démon** | un petit programme du plugin qui tourne en permanence pour lire les appareils toutes les quelques secondes |
| **MQTT / broker** | un « bureau de poste » installé sur la box : certains appareils Zendure y déposent leurs mesures et y lisent leurs ordres |
| **MQTT Manager** | le plugin Jeedom (gratuit, officiel) qui installe ce bureau de poste |
| **Adresse IP** | l'adresse d'un appareil sur votre réseau, par exemple `192.168.1.50` |
| **Wi-Fi 2,4 GHz** | les batteries Zendure ne se connectent qu'en 2,4 GHz (pas en 5 GHz) |

## Quel est mon appareil ?

Selon votre appareil, une seule partie de cette page vous concerne :

| Mon appareil | Comment il se connecte | Partie à suivre |
|---|---|---|
| **Hyper 2000**, **SolarFlow Hub 1200**, **Hub 2000**, **AIO 2400**, **ACE 1500** | MQTT (il faut le « rediriger » une fois vers la box, par Bluetooth) | Partie A |
| **SolarFlow 800**, **800 Plus**, **800 Pro**, **1600 AC+**, **2400 AC**, **2400 AC+**, **2400 Pro**, **3000 Mix AC+**, **4000** | directement par le réseau local (API « zenSDK ») | Partie B |
| **Compteur Zendure** (Smart Meter 1CT, 3CT, D0) ou **compteur Shelly** | directement par le réseau local | Partie C |

Vous pouvez mélanger : par exemple un Hyper 2000 (partie A), un SolarFlow 2400 AC+ (partie B)
et un Shelly Pro 3EM (partie C).

> Le protocole de l'Hyper 2000 et du SolarFlow 2400 AC+ a été vérifié sur de vrais appareils ;
> les autres modèles suivent les informations publiées par Zendure et par les projets cités à la
> fin de cette page. Si quelque chose ne fonctionne pas, signalez-le sur la page GitHub du plugin.

## Étape 1 : installer le plugin

Le plugin n'est pas (encore) sur le Market Jeedom : on l'installe depuis GitHub. C'est la méthode
conseillée, car Jeedom proposera ensuite les mises à jour tout seul.

### 1.1 Autoriser les plugins venant de GitHub

1. Menu **Réglages → Système → Configuration**, onglet **Mises à jour/Market**.
2. En bas, sous **Configuration des dépôts**, cliquez sur l'onglet **Github**.
3. Cochez **Activer Github**, puis cliquez sur **Sauvegarder** (en haut à droite).

![Activer la source GitHub dans la configuration de Jeedom](images/installation_activer_github.png)

### 1.2 Ajouter le plugin

1. Menu **Plugins → Gestion des plugins**.
2. Cliquez sur le bouton **+ Plugins** (ajouter un plugin depuis une autre source).
3. Remplissez exactement :
   * **Type de source** : `Github`
   * **ID logique du plugin** : `zendure`
   * **Utilisateur ou organisation du dépôt** : `math17540`
   * **Nom du dépôt** : `jeedom-zendure`
   * **Branche** : `main`
4. Cliquez sur **Sauvegarder**, puis patientez : Jeedom télécharge le plugin (1 à 2 minutes).

![La fenêtre « Ajouter un plugin » remplie pour GitHub](images/installation_source_github.png)

Le message rouge est normal : Jeedom rappelle que son équipe n'assiste pas les plugins installés
hors du Market.

**Autre possibilité, sans GitHub** : téléchargez le fichier `zendure-x.y.z.zip` (page *Releases*
du dépôt GitHub), activez l'onglet **Fichier** à l'étape 1.1 au lieu de **Github**, puis choisissez
le type de source **Fichier**, l'ID logique `zendure` et envoyez le fichier. Dans ce cas, les mises
à jour se font à la main, de la même façon.

![Ajout du plugin par fichier .zip](images/installation_source_fichier.png)

## Étape 2 : activer le plugin et démarrer le démon

1. Menu **Plugins → Gestion des plugins**, cliquez sur **Zendure**.
2. Dans le cadre **Etat**, cliquez sur **Activer**.
3. Dans le cadre **Démon**, attendez que **Statut** passe à **OK** (sinon cliquez sur ▶ *(Re)Démarrer*).
   Laissez la **Gestion automatique** active : Jeedom relancera le démon si besoin.

![Plugin activé, démon OK](images/plugin_activer_demon.png)

Plus bas sur la même page, le cadre **Configuration** contient les réglages du plugin. Les valeurs
par défaut conviennent dans presque tous les cas ; on y reviendra dans la partie A.

![La configuration du plugin](images/plugin_configuration.png)

Les appareils se gèrent ensuite dans **Plugins → Énergie → Zendure** :

![La page des équipements Zendure](images/equipements_liste.png)

* **Ajouter** : créer un équipement à la main (choix du modèle, puis adresse IP ou deviceId) ;
* **Rechercher (mDNS)** : trouver automatiquement les SolarFlow et compteurs Zendure du réseau ;
* **Configuration** : ouvre la configuration du plugin.

## Partie A : Hyper 2000, Hub 1200 / 2000, AIO 2400, ACE 1500

Ces appareils envoient normalement leurs mesures au cloud Zendure. On va leur indiquer, **une seule
fois**, d'envoyer leurs mesures à la box Jeedom à la place. Comptez 15 minutes par appareil.

À savoir avant de commencer :

* l'appareil ne sera **plus visible dans l'application Zendure par Internet**. Il reste visible et
  réglable en **Bluetooth** à proximité, mises à jour du firmware comprises ;
* vous pourrez **revenir au cloud** à tout moment avec le même outil (bouton *Revenir au cloud Zendure*).

### A.1 Installer MQTT Manager

1. Menu **Plugins → Gestion des plugins → Market**, cherchez **MQTT Manager** et installez-le (gratuit).
2. Ouvrez MQTT Manager, cliquez sur **Activer**, choisissez le mode **Broker local**, sauvegardez,
   puis installez les **dépendances** et vérifiez que le **démon** est **OK**.

### A.2 Préparer la box (une seule fois)

Dans la configuration du plugin Zendure (Étape 2), cadre **Broker MQTT local** :

1. La ligne **État** doit afficher en vert **MQTT Manager · local**. Elle indique aussi l'**IP de la
   box** : notez-la, elle servira à l'étape suivante.
2. Si la ligne affiche en orange **8883 : certificat client exigé**, cliquez sur **Autoriser les
   appareils en TLS (8883)** et confirmez. Les appareils récents se connectent de façon chiffrée sur
   ce port ; ce bouton leur ouvre la porte, **le mot de passe restant obligatoire**.

### A.3 Rediriger l'appareil avec l'outil Windows

Il vous faut un **PC Windows avec Bluetooth**, placé à quelques mètres de l'appareil.

1. Dans la configuration du plugin, cadre **Redirection Bluetooth**, cliquez sur **télécharger
   l'outil Windows**. Enregistrez `ZendureMqttTool.exe` et lancez-le (aucune installation).
   Windows peut afficher « Windows a protégé votre ordinateur » : cliquez sur **Informations
   complémentaires** puis **Exécuter quand même** (l'outil n'est pas signé numériquement).
2. **Fermez l'application Zendure sur votre téléphone** (un seul appareil Bluetooth à la fois).
3. Cliquez sur **Rechercher les appareils**, puis sélectionnez le vôtre dans la liste. Le modèle
   s'affiche ; comparez la fin du numéro de série avec l'étiquette de l'appareil.
4. **IP du broker** : l'IP de la box notée à l'étape A.2. Cliquez sur **Tester le broker** : le
   journal doit indiquer au moins un port ouvert.
5. **SSID Wi-Fi** et **Mot de passe Wi-Fi** : votre Wi-Fi **2,4 GHz** (le mot de passe n'est envoyé
   qu'à l'appareil, il n'est ni enregistré ni affiché).
6. Cliquez sur **Basculer vers le broker local** et confirmez.

![L'outil Windows, onglet MQTT local](images/outil_windows.png)

À la fin, le journal de l'outil affiche normalement :

```
>>> deviceId de l'appareil : Ab12Cd34
```

Notez ce **deviceId** : c'est l'identifiant de l'appareil sur le broker.

### A.3 bis Ou bien : rediriger depuis la box Jeedom (sans PC)

Possible seulement si la box Jeedom a un **adaptateur Bluetooth** (intégré, ou clé USB Bluetooth)
et se trouve **à moins de 5 à 10 mètres** de l'appareil. Dans la configuration du plugin, cadre
**Redirection Bluetooth** :

1. La ligne **Bluetooth de la box** indique si un adaptateur est présent. Si elle affiche « aucun
   adaptateur Bluetooth », utilisez l'outil Windows (A.3).
2. La première fois, cliquez sur **Installer le Bluetooth** : le plugin installe les outils
   nécessaires (BlueZ et Python). Comptez quelques minutes ; la ligne passe à **prêt**.
3. Fermez l'application Zendure sur le téléphone, puis cliquez sur **Rechercher** (10 secondes) et
   choisissez l'appareil dans la liste (modèle, nom Bluetooth, fin du numéro de série).
4. **Adresse du broker** : déjà remplie avec l'IP de la box. Saisissez le **nom** et le **mot de
   passe** du Wi-Fi 2,4 GHz.
5. Cliquez sur **Basculer vers le broker local** et confirmez.

La box envoie alors à l'appareil, par Bluetooth, les mêmes deux ordres que l'outil Windows :
l'adresse du broker avec le Wi-Fi, puis « reconnecte-toi ». Si l'appareil renvoie son **deviceId**,
il s'affiche dans le message de confirmation et il est recopié tout seul dans **Autoriser des
appareils sur le broker** : il ne reste qu'à cliquer sur **Ajouter à MQTT Manager** (étape A.4).
Le mot de passe Wi-Fi n'est transmis qu'à l'appareil : il n'est ni enregistré ni écrit dans les
journaux. En cas de problème, le journal **zendure** (et **zendure_ble** pour l'installation)
indique ce qui s'est passé.

### A.4 Autoriser l'appareil sur le broker

1. Dans la configuration du plugin, cadre **Broker MQTT local**, collez le **deviceId** dans
   **Autoriser des appareils sur le broker** (un par ligne s'il y en a plusieurs).
2. Cliquez sur **Ajouter à MQTT Manager**. Le broker redémarre quelques secondes.

### A.5 L'équipement apparaît tout seul

En moins d'une minute, l'appareil apparaît dans **Plugins → Énergie → Zendure**. Le modèle est
reconnu automatiquement. S'il s'appelle « Appareil Zendure MQTT », ouvrez-le, choisissez le bon
**Modèle** dans la liste et cliquez sur **Sauvegarder**.

![Un Hyper 2000 créé automatiquement](images/equipement_hyper.png)

Choisissez l'**Objet parent** (la pièce, par exemple « Énergie ») pour qu'il apparaisse sur le
tableau de bord, puis **Sauvegarder**.

**Rien n'apparaît après 2 minutes ?** Éteignez l'appareil puis rallumez-le (bouton marche/arrêt) :
certains firmwares ne prennent la nouvelle adresse qu'après un redémarrage. Voir aussi les
*Questions fréquentes*.

## Partie B : SolarFlow 800 à 4000 (zenSDK)

Ces appareils se pilotent directement par le réseau local, sans redirection.

1. **Activer l'accès local** : dans l'application Zendure du téléphone, ouvrez votre appareil et
   ajoutez un **HEMS** (gestionnaire d'énergie), puis quittez l'application.
2. Dans **Plugins → Énergie → Zendure**, cliquez sur **Rechercher (mDNS)**. L'appareil est créé
   avec son adresse IP. La recherche se refait aussi toute seule toutes les 15 minutes.
3. S'il n'est pas trouvé (box et appareil sur des réseaux différents, par exemple) : **Ajouter**,
   choisissez le modèle, puis saisissez son **adresse IP** (visible dans votre box Internet).

   ![Ajouter : choix du modèle](images/equipements_ajouter.png)

4. Ouvrez l'équipement, choisissez l'**Objet parent** et **Sauvegardez**.

![Un SolarFlow 2400 AC+ trouvé par la recherche](images/equipement_solarflow.png)

Le **numéro de série** se remplit tout seul à la première lecture. Laissez **smartMode** coché :
les réglages envoyés par Jeedom n'usent pas la mémoire de l'appareil (au redémarrage de
l'appareil, ses réglages enregistrés reviennent).

## Partie C : compteurs Zendure et Shelly

Le compteur mesure la puissance **au tableau électrique** : **positive** quand la maison consomme
sur le réseau, **négative** quand elle renvoie de l'électricité au réseau.

**Compteur Zendure** (Smart Meter 1CT, 3CT ou D0) : comme un SolarFlow, il est trouvé par
**Rechercher (mDNS)**, ou ajouté avec **Ajouter** et son adresse IP.

![Un compteur Zendure](images/equipement_compteur_zendure.png)

**Compteur Shelly** (Pro 3EM, 3EM, Pro EM, EM, prise ou module avec mesure) :

1. **Ajouter**, choisissez **Compteur Shelly**, puis saisissez son **adresse IP**.
2. Le plugin reconnaît le modèle tout seul (ligne *Shelly détecté*).
3. Le Shelly ne doit **pas avoir de mot de passe** (réglage dans l'application Shelly).

![Un compteur Shelly Pro 3EM](images/equipement_shelly.png)

*Lecture toutes les* : fréquence de lecture en secondes (5 par défaut).

## Étape 3 : utiliser le plugin

### Sur le tableau de bord

Chaque équipement affiche ses valeurs (batterie, production solaire, injection…) et ses
réglages (curseurs et listes). Les curseurs **Injecter** et **Charger depuis le réseau** pilotent
directement la batterie ; **Arrêter charge et injection** remet tout à zéro.

### Les commandes

L'onglet **Commandes** d'un équipement liste tout ce qui est lu et tout ce qui peut être piloté.
Vous pouvez y renommer une commande, la masquer, ou l'**historiser** pour avoir des courbes.

![L'onglet Commandes d'un Hyper 2000](images/equipement_commandes.png)

### Exemples de scénarios

Dans **Outils → Scénarios** :

* **Charger en heures creuses** : programmation `30 23 * * *`, action
  `#[Énergie][Hyper 2000 …][Charger depuis le réseau]#` avec la valeur `800` ; puis un second
  scénario à `30 7 * * *` avec `#[Énergie][Hyper 2000 …][Arrêter charge et injection]#`.
* **Restituer le soir** : programmation `0 18 * * *`, action `[Injecter]` avec `300`.
* **Garder une réserve** : action `[Régler SOC min]` avec `20` (la batterie ne descendra pas
  sous 20 %).

Évitez de changer les réglages plus d'une fois toutes les 10 à 30 secondes.

## Questions fréquentes

**L'appareil n'apparaît pas après la redirection (partie A).**
1. Éteignez puis rallumez l'appareil.
2. Vérifiez que le deviceId a bien été ajouté (étape A.4) et que le Wi-Fi est en **2,4 GHz**.
3. Dans les journaux de MQTT Manager (**Analyse → Logs**), une ligne `peer did not return a certificate`
   signifie qu'il faut cliquer sur **Autoriser les appareils en TLS (8883)** (étape A.2).
4. Relancez l'outil et refaites la redirection en vérifiant l'IP de la box.

**L'outil Windows ne trouve pas l'appareil.** Fermez l'application Zendure sur **tous** les
téléphones, rapprochez le PC à moins de 5 mètres, vérifiez que le Bluetooth du PC est allumé.

**Je n'arrive pas à télécharger l'outil depuis Jeedom.** Rechargez la page (Ctrl+F5) ; l'outil est
aussi disponible sur la page *Releases* du dépôt GitHub.

**Un SolarFlow n'est pas trouvé par la recherche.** L'accès local (HEMS) n'est pas activé, ou la box
n'est pas sur le même réseau que l'appareil. Ajoutez-le par son adresse IP.

**Un équipement est « hors ligne ».** Il n'a rien envoyé depuis 3 minutes : Wi-Fi coupé, appareil
éteint, ou adresse IP changée (dans ce cas, la recherche automatique la corrige toute seule pour
les SolarFlow). Pour les appareils de la partie A, le plugin relance aussi tout seul les connexions
bloquées.

**Je veux revenir au cloud Zendure.** Outil Windows → **Revenir au cloud Zendure**, puis supprimez
l'équipement dans Jeedom.

**Où voir ce qui se passe ?** **Analyse → Logs → zendure**. Pour plus de détails, mettez le niveau de
log du plugin sur **Debug** (page du plugin, cadre *Logs et surveillance*), et pensez à le remettre
ensuite sur *Défaut*. **Analyse → Santé** résume l'état du démon et de chaque appareil.

## Référence

### Modèles et puissances

| Modèle | Connexion | Injection max | Charge réseau max |
|---|---|---|---|
| Hyper 2000 | MQTT | 1200 W | 1200 W |
| SolarFlow Hub 1200, Hub 2000, AIO 2400 | MQTT | 1200 W | – |
| ACE 1500 | MQTT | 800 W | 900 W (limites seulement) |
| SolarFlow 800 / 800 Plus / 800 Pro | zenSDK | 800 W | 1000 W |
| SolarFlow 1600 AC+ | zenSDK | 1600 W | 1600 W |
| SolarFlow 2400 AC / AC+ | zenSDK | 2400 W | 2400 W |
| SolarFlow 2400 Pro, 4000 | zenSDK | 2400 W | 3200 W |
| SolarFlow 3000 Mix AC+ | zenSDK | 3000 W | 3000 W |

Les Hub et l'AIO ne se chargent pas depuis le réseau : leurs commandes de charge ne sont pas
créées. L'ACE 1500, et un appareil MQTT dont le modèle n'est pas choisi, n'ont que les limites
(pas de pilotage direct *Injecter* / *Charger*).

### Commandes des batteries

| Commande | Unité | Remarque |
|---|---|---|
| En ligne | 0/1 | 0 après 3 min sans donnée (réglable) |
| Batterie | % | niveau global |
| Production solaire, PV 1…6 | W | |
| Injection maison / Charge réseau | W | |
| Charge batterie / Décharge batterie | W | |
| Prise secours | W | sortie de secours des SolarFlow |
| Limite injection / Limite charge réseau | W | |
| SOC max / SOC min | % | |
| Mode AC | | 1 = charge depuis le réseau, 2 = injection |
| État batterie | | 0 = repos, 1 = charge, 2 = décharge |
| Nombre de batteries, Autonomie restante, Température, Chauffage, Signal WiFi | | |
| *Modèle* *xxxx* SOC / Température / Tension / Courant / Santé | | une série par batterie additionnelle, courant négatif = décharge |
| Régler injection / charge réseau / SOC max / SOC min / mode AC | actions | valeurs ramenées dans les limites du modèle |
| Injecter, Charger depuis le réseau, Arrêter charge et injection | actions | pilotage direct |
| Régler puissance max onduleur, renvoi vers le réseau, mode prise secours | actions | SolarFlow zenSDK |
| Rafraîchir | action | relecture complète |

### Commandes des compteurs

| Commande | Unité |
|---|---|
| Puissance réseau (positive = soutirage) | W |
| Puissance phase 1 / 2 / 3, Tension phase 1 / 2 / 3 | W, V |
| Énergie soutirée / Énergie injectée (Shelly) | kWh |
| Champs bruts (compteur Zendure : toutes les autres valeurs reçues, masquées par défaut) | |

### Configuration du plugin

| Réglage | Détail |
|---|---|
| Recherche automatique | recherche mDNS toutes les 15 min (nouveaux appareils, changement d'IP) |
| Créer automatiquement les appareils MQTT détectés | au premier message reçu |
| Interroger les appareils MQTT muets | demande toutes les valeurs si un appareil se tait plus d'une minute |
| Hors ligne après | secondes sans donnée avant *En ligne* = 0 (180) |
| Lecture des appareils zenSDK | intervalle de lecture par le démon (15 s par défaut, 5 minimum) |
| Reconnexion forcée d'un appareil MQTT muet | un appareil connecté mais muet depuis 90 s est déconnecté 3 s pour qu'il se reconnecte (journal *zendure_watchdog*) ; 0 = désactivé |

### Fonctionnement technique

* **Partie A** : rapports sur `/<productKey>/<deviceId>/properties/report`, ordres sur
  `iot/<productKey>/<deviceId>/properties/write` et `…/function/invoke`. Chaque appareil se connecte
  au broker avec l'identifiant = deviceId et un mot de passe calculé à partir du deviceId.
* **Partie B** : `GET http://<ip>/properties/report` et `POST http://<ip>/properties/write`. Option
  *Commandes par* MQTT si le SolarFlow publie sur le broker (MQTT configuré dans le zenSDK : port
  1883, compte créé avec `identifiant:motdepasse` dans la configuration du plugin).
* **Partie C** : Shelly Gen2+ par `/rpc/EM.GetStatus`, `EM1.GetStatus`, `PM1.GetStatus` ou
  `Switch.GetStatus` ; Shelly Gen1 par `/status` ; compteur Zendure par `/properties/report`
  (`total_power`).

## Remerciements

Les protocoles et les tables de modèles viennent de ces projets, merci à leurs auteurs :

* [Zendure-HA](https://github.com/Zendure/Zendure-HA) et [zenSDK](https://github.com/Zendure/zenSDK) (Zendure) ;
* [Zendure Cloud Disconnector](https://github.com/nograx/zendure-cloud-disconnector) et
  [ioBroker.zendure-solarflow](https://github.com/nograx/ioBroker.zendure-solarflow) (nograx) ;
* [solarflow](https://github.com/reinhard-brandstaedter/solarflow) (reinhard-brandstaedter) ;
* [evcc](https://github.com/evcc-io/evcc) pour le compteur Zendure 3CT.
