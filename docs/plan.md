# Pixelcast Client - Architecture Plan

## Context

Application Symfony CLI qui collecte des données depuis des APIs externes (météo, crypto, ETF/indices) et les pousse vers un écran matriciel ESP32 via son API REST. Hébergé sur un NUC, Docker-only (pas de PHP local), pas de serveur web.

**Premier batch** : Weather (OpenWeatherMap) + Trackers financiers (CoinGecko crypto + Twelve Data ETF/indices)
**Batch suivant** : Capteurs Netatmo

---

## Architecture Overview

```
[OpenWeatherMap] ---\
[CoinGecko]     ----+--> [Symfony CLI App] --HTTP--> [ESP32 Pixelcast]  (prod)
[Twelve Data]   ---/     (scheduler loop)        \-> [Simulator]         (dev, #15)
```

**Pattern central** : Provider (fetch + map) -> Message/Handler (orchestrate) -> PixelcastClient (push)

Le Scheduler Symfony dispatch des `RecurringMessage` consommés par `messenger:consume`. Chaque handler orchestre : fetch provider -> build DTO -> push via PixelcastClient.

---

## Stack technique

- PHP 8.5 CLI Alpine (Docker)
- Symfony 8.0 (skeleton, console, http-client, messenger, scheduler, cache, monolog-bundle)
- Twelve Data pour ETF/indices (800 req/jour gratuit, API stable et documentée)
- CoinGecko pour crypto (30 req/min gratuit)
- OpenWeatherMap One Call 3.0 (1000 req/jour gratuit, current + 8j forecast en 1 call)
- PHPStan ^2.1 + PHP-CS-Fixer

---

## Structure du projet

```
pixelcast-client/
|-- Dockerfile                        # PHP 8.5 CLI Alpine, cibles php_dev / php_prod
|-- compose.yaml                      # Prod : service php, target php_prod
|-- compose.override.yaml             # Dev : volume source, Xdebug
|-- Makefile
|-- composer.json
|-- phpunit.dist.xml
|-- phpstan.neon.dist
|-- sync/                             # Specs vendorées via make sync-api
|   |-- openapi.yaml
|   |-- asyncapi.yaml
|   |-- schemas/
|       |-- common.yaml
|       |-- weather.yaml
|       |-- tracker.yaml
|       |-- notification.yaml
|       |-- custom-app.yaml
|       |-- indicator.yaml
|       |-- icon.yaml
|       |-- zone.yaml
|       |-- stats.yaml
|       |-- settings.yaml
|-- .env                              # Defaults (PIXELCAST_DEVICE_BASE_URL, API keys placeholders)
|-- .env.local                        # Secrets (gitignored)
|-- config/
|   |-- pixelcast.yaml                # Assets trackés + weather location (chargé comme paramètres)
|   |-- packages/
|       |-- http_client.yaml          # 4 scoped clients (pixelcast, coingecko, openweathermap, twelvedata)
|       |-- messenger.yaml            # sync:// transport for scheduler
|       |-- cache.yaml                # filesystem adapter
|       |-- monolog.yaml              # stderr (Docker-friendly)
|-- src/
|   |-- Client/
|   |   |-- PixelcastClientInterface.php
|   |   |-- PixelcastClient.php       # HTTP client ESP32, 1 méthode par endpoint
|   |   |-- Dto/Request/
|   |   |   |-- WeatherPayload.php    # POST /weather body
|   |   |   |-- CurrentWeather.php
|   |   |   |-- ForecastDay.php
|   |   |   |-- TrackerPayload.php    # POST /tracker body
|   |   |   |-- NotificationPayload.php
|   |   |   |-- CustomAppPayload.php
|   |   |-- Dto/Response/
|   |   |   |-- DeviceResponse.php
|   |   |-- Exception/
|   |       |-- DeviceUnreachableException.php
|   |
|   |-- Configuration/
|   |   |-- PixelcastConfiguration.php  # Service qui lit les paramètres YAML (pas de config tree)
|   |   |-- TrackerAsset.php            # VO: symbol, icon, color, currency, type
|   |   |-- WeatherLocation.php         # VO: lat, lon, units
|   |
|   |-- Provider/
|   |   |-- Weather/
|   |   |   |-- WeatherProviderInterface.php  # -> WeatherPayload
|   |   |   |-- OpenWeatherMapProvider.php     # Fetch OWM One Call 3.0 + cache 25min
|   |   |   |-- WeatherIconMapper.php          # OWM icon code -> ESP32 icon name (mapping à ajuster côté firmware)
|   |   |-- Tracker/
|   |   |   |-- TrackerProviderInterface.php   # -> TrackerData[]
|   |   |   |-- CoinGeckoProvider.php          # Batch fetch + sparkline downsample
|   |   |   |-- TwelveDataProvider.php         # Quote + time_series sparkline
|   |   |   |-- TrackerData.php                # VO normalisé provider-agnostic
|   |   |-- Exception/
|   |       |-- ProviderFetchException.php
|   |
|   |-- Message/
|   |   |-- SyncWeatherMessage.php
|   |   |-- SyncTrackerMessage.php     # Carries assetType: 'crypto'|'etf'|'indices'
|   |-- MessageHandler/
|   |   |-- SyncWeatherHandler.php     # Provider -> PixelcastClient
|   |   |-- SyncTrackerHandler.php     # Iterates tagged providers by type
|   |
|   |-- Scheduler/
|   |   |-- PixelcastScheduleProvider.php  # #[AsSchedule] - toutes les fréquences
|   |
|   |-- Command/
|       |-- SyncCommand.php                # pixelcast:sync [type] [--all] - interactif si pas d'argument
|       |-- NotifyCommand.php              # pixelcast:notify "text" [--icon] [--duration]
|
|-- tests/
    |-- Unit/
    |   |-- Client/Dto/                # toArray() produit le bon JSON
    |   |-- Provider/Weather/          # Mapping OWM -> payload avec fixtures
    |   |-- Provider/Tracker/          # Mapping CoinGecko/TwelveData avec fixtures
    |-- Integration/
    |   |-- MessageHandler/            # Handler avec MockHttpClient + SpyPixelcastClient
    |-- Fixtures/
        |-- openweathermap_onecall.json
        |-- coingecko_markets.json
        |-- twelvedata_quote.json
        |-- twelvedata_timeseries.json
```

---

## Décisions d'architecture

### Pourquoi Twelve Data pour ETF/Indices (et pas Yahoo Finance)

- 800 req/jour gratuit : suffisant pour ~8 symbols à 15min (96 calls/jour/symbol)
- API officielle avec contrat stable et documentation claire
- Supporte ETFs européens (IWDA.AS) et indices US (IXIC, DJI)
- Yahoo Finance unofficial est fragile : pas de support, rate limiting sans warning, peut casser à tout moment
- Pour un daemon long-running sur un NUC, la fiabilité prime

### Pourquoi OpenWeatherMap One Call 3.0

- 1 seul appel retourne current + forecast 8 jours
- 1000 calls/jour gratuit, on en fait 48 (toutes les 30min)
- Met à jour toutes les 10min côté OWM

### Pourquoi pas de base de données

- L'ESP32 est la source de vérité pour l'affichage
- `symfony/cache` filesystem suffit pour le cache API
- Pas d'historique nécessaire pour le premier batch
- SQLite envisageable plus tard (Netatmo OAuth refresh tokens)

### Config légère : paramètres YAML, pas de Configuration tree

- `config/pixelcast.yaml` définit des `parameters:` Symfony
- Import via `services.yaml`
- `PixelcastConfiguration` est un simple service avec bind qui expose des méthodes typées
- Pas de boilerplate Configuration/Extension pour un projet perso

---

## Configuration

### .env (commité)

```dotenv
PIXELCAST_DEVICE_BASE_URL=http://pixelcast.local/api
OPENWEATHERMAP_API_KEY=
COINGECKO_API_KEY=
TWELVEDATA_API_KEY=
```

### config/pixelcast.yaml (commité)

```yaml
# Importé dans services.yaml via: imports: [{ resource: 'pixelcast.yaml' }]
parameters:
    pixelcast.weather.latitude: 48.8566
    pixelcast.weather.longitude: 2.3522
    pixelcast.weather.units: metric
    pixelcast.trackers:
        crypto:
            - { symbol: BTC, coingecko_id: bitcoin, icon: btc, symbol_color: "#F7931A", currency: EUR }
            - { symbol: ETH, coingecko_id: ethereum, icon: eth, symbol_color: "#627EEA", currency: EUR }
        etf:
            - { symbol: IWDA.AS, display_name: IWDA, icon: etf, symbol_color: "#0078D4", currency: EUR }
        indices:
            - { symbol: IXIC, display_name: NASDAQ, icon: nasdaq, symbol_color: "#00BCF2", currency: USD }
```

### config/packages/http_client.yaml (4 scoped clients)

```yaml
framework:
    http_client:
        scoped_clients:
            pixelcast.http_client:
                base_uri: '%env(PIXELCAST_DEVICE_BASE_URL)%'
                timeout: 5
            coingecko.http_client:
                base_uri: 'https://api.coingecko.com/api/v3/'
                timeout: 10
            openweathermap.http_client:
                base_uri: 'https://api.openweathermap.org/data/3.0/'
                timeout: 10
            twelvedata.http_client:
                base_uri: 'https://api.twelvedata.com/'
                timeout: 10
```

---

## Commandes CLI

2 commandes seulement. Elles ne dupliquent pas la logique des handlers : elles dispatch les mêmes messages sur le bus.

### `pixelcast:sync [type] [--all]`

Commande unique pour déclencher manuellement un sync. Découvre les types disponibles via le `PixelcastScheduleProvider`.

```
# Interactif : affiche un choice() avec les types disponibles
$ php bin/console pixelcast:sync

 Which sync do you want to run?
 [0] weather (every 30min)
 [1] crypto (every 5min)
 [2] etf (every 15min)
 [3] indices (every 15min)
 > 0

 Dispatching SyncWeatherMessage...
 Done.

# Non-interactif : argument direct
$ php bin/console pixelcast:sync weather
$ php bin/console pixelcast:sync crypto

# Tout synchroniser d'un coup
$ php bin/console pixelcast:sync --all
```

Implémentation : la commande injecte le `MessageBusInterface` et construit le message correspondant au type choisi. Aucune logique métier, juste du dispatch.

```php
#[AsCommand(name: 'pixelcast:sync', description: 'Manually trigger a data sync')]
final class SyncCommand extends Command
{
    public function __construct(
        private readonly MessageBusInterface $messageBus,
        private readonly PixelcastScheduleProvider $scheduleProvider,
    ) {
        parent::__construct();
    }
}
```

### `pixelcast:notify "text" [--icon] [--duration] [--color]`

Commande one-shot pour envoyer une notification overlay sur l'écran. Pas de schedule, purement à la demande. Appelle directement `PixelcastClient::pushNotification()`.

---

## Scheduler

```php
#[AsSchedule]
final class PixelcastScheduleProvider implements ScheduleProviderInterface
{
    public function getSchedule(): Schedule
    {
        return (new Schedule())
            ->with(
                RecurringMessage::every('30 minutes', new SyncWeatherMessage()),
                RecurringMessage::every('5 minutes', new SyncTrackerMessage('crypto')),
                RecurringMessage::every('15 minutes', new SyncTrackerMessage('etf')),
                RecurringMessage::every('15 minutes', new SyncTrackerMessage('indices')),
            )
            ->stateful($this->cache)          // Survit aux restarts container
            ->processOnlyLastMissedRun(true);  // Pas de flood après downtime
    }
}
```

---

## Docker

### Dockerfile (PHP 8.5 CLI Alpine, pas de web server)

- Cibles multi-stage : `php_upstream` -> `php_base` -> `php_dev` / `php_prod`
- `php_dev` : installe Xdebug, `ENTRYPOINT ["sleep", "infinity"]` (le container reste vivant pour les commandes manuelles)
- `php_prod` : installe les dépendances composer sans dev, dump-autoload classmap, `CMD: php bin/console messenger:consume scheduler_default --time-limit=3600`
- `--time-limit=3600` force un restart toutes les heures (évite les memory leaks PHP long-running)

### compose.yaml (prod)

```yaml
services:
  php:
    build:
      context: .
      target: php_prod
    image: pixelcast-client:prod
    restart: unless-stopped
    volumes:
      - ./var:/app/var  # Persistance cache + état scheduler
```

### compose.override.yaml (dev, auto-chargé par Docker Compose)

```yaml
services:
  php:
    build:
      context: .
      target: php_dev
    image: pixelcast-client:dev
    volumes:
      - .:/app          # Source montée pour dev
      - ./docker/xdebug.ini:/usr/local/etc/php/conf.d/docker-php-ext-xdebug.ini:ro
    extra_hosts:
      - "host.docker.internal:host-gateway"
    environment:
      - PHP_IDE_CONFIG=serverName=pixelcast
      - XDEBUG_MODE=${XDEBUG_MODE:-off}
```

### Makefile (raccourcis)

| Cible | Description |
|-------|-------------|
| `build` | Build image dev |
| `build-prod` | Build image prod (compose.yaml uniquement) |
| `up` | Démarre l'env dev |
| `up-prod` | Démarre l'env prod |
| `down` | Arrête compose dev |
| `down-prod` | Arrête compose prod |
| `logs` | Logs du service `php` (dev) |
| `logs-prod` | Logs du service `php` (prod) |
| `shell` | Shell dans le container dev (`exec php sh`) |
| `shell-prod` | Shell dans le container prod |
| `enable-xdebug` | Relance avec `XDEBUG_MODE=debug` |
| `disable-xdebug` | Relance avec `XDEBUG_MODE=off` |
| `test` | Lance PHPUnit |
| `lint` | PHPStan analyse |
| `cs-fix` | PHP-CS-Fixer (auto-correction) |
| `cs-check` | PHP-CS-Fixer (dry-run, sans modification) |
| `sync-api` | Télécharge les specs OpenAPI/AsyncAPI dans `sync/` |

---

## Dev infrastructure

### Vendoring des specs OpenAPI/AsyncAPI (#14)

Les specs du firmware ESP32 sont publiées sur `https://nicolas-codemate.github.io/esp32-pixelcast` et vendorées localement dans `sync/` via :

```
make sync-api
```

Fichiers téléchargés :

```
sync/
|-- openapi.yaml
|-- asyncapi.yaml
|-- schemas/
    |-- common.yaml
    |-- weather.yaml
    |-- tracker.yaml
    |-- notification.yaml
    |-- custom-app.yaml
    |-- indicator.yaml
    |-- icon.yaml
    |-- zone.yaml
    |-- stats.yaml
    |-- settings.yaml
```

Ces fichiers sont commités dans le dépôt et constituent la référence contractuelle pour le `PixelcastClient` et ses DTOs. Régénérer avec `make sync-api` après chaque mise à jour du firmware.

### Simulateur local (#15)

Pour le développement hors ligne (sans dispositif ESP32 physique), un simulateur HTTP sera ajouté par le ticket #15. Il reproduira l'API REST de l'ESP32 et sera déclaré comme service supplémentaire dans `compose.override.yaml`.

La variable `PIXELCAST_DEVICE_BASE_URL` peut pointer vers le simulateur local (`http://localhost:<port>/api`) au lieu du dispositif physique, sans aucune modification du code applicatif.

---

## API ESP32

Le `PixelcastClient` encapsule tous les appels REST vers l'ESP32. Les endpoints sont définis dans `sync/openapi.yaml` et les schémas de payload dans `sync/schemas/`.

### Endpoints

| Méthode | Chemin | Tag | Description |
|---------|--------|-----|-------------|
| GET | `/stats` | System | Statistiques du dispositif |
| GET | `/settings` | System | Paramètres courants |
| POST | `/settings` | System | Mise à jour partielle des paramètres |
| POST | `/brightness` | System | Luminosité (0-255) |
| POST | `/reboot` | System | Reboot différé du dispositif |
| GET | `/apps` | Apps | Liste des apps en rotation |
| POST | `/custom` | Apps | Créer/mettre à jour une app custom (zone simple ou multi-zones) |
| DELETE | `/custom` | Apps | Supprimer une app custom |
| GET | `/weather` | Weather | Données météo courantes |
| POST | `/weather` | Weather | Pousser conditions courantes + prévisions |
| GET | `/trackers` | Tracker | Lister tous les trackers actifs |
| GET | `/tracker` | Tracker | Obtenir un tracker |
| POST | `/tracker` | Tracker | Créer/mettre à jour un tracker |
| DELETE | `/tracker` | Tracker | Supprimer un tracker |
| POST | `/notify` | Notifications | Envoyer une notification |
| GET | `/notify/list` | Notifications | Lister les notifications actives |
| POST | `/notify/dismiss` | Notifications | Rejeter la notification courante |
| POST | `/indicator1` | Indicators | Définir l'indicateur de coin 1 |
| DELETE | `/indicator1` | Indicators | Éteindre l'indicateur 1 |
| POST | `/indicator2` | Indicators | Définir l'indicateur de coin 2 |
| DELETE | `/indicator2` | Indicators | Éteindre l'indicateur 2 |
| POST | `/indicator3` | Indicators | Définir l'indicateur de coin 3 |
| DELETE | `/indicator3` | Indicators | Éteindre l'indicateur 3 |
| GET | `/icons` | Icons | Lister les icônes sur le filesystem |
| POST | `/icons` | Icons | Uploader une icône PNG/GIF |
| DELETE | `/icons` | Icons | Supprimer une icône |
| GET | `/icons/{name}` | Icons | Servir un fichier icône |
| POST | `/icons/lametric` | Icons | Télécharger une icône depuis LaMetric |

### Contraintes de payload

| Champ | Contrainte | Source |
|-------|-----------|--------|
| Sparkline | max 24 points (`maxItems: 24`), floats envoyés tels quels (normalisation uint16 0-65535 côté device) | `schemas/tracker.yaml` |
| Notification text | max 128 caractères (`maxLength: 128`) | `schemas/notification.yaml` |
| Forecast | max 7 jours (`maxItems: 7`) | `schemas/weather.yaml` |
| Brightness | entier 0-255 | `sync/openapi.yaml` |
| Tracker symbol/currency | max 7 caractères | `schemas/tracker.yaml` |
| Tracker bottomText | max 31 caractères | `schemas/tracker.yaml` |
| Icône upload | PNG ou GIF, recommandé 8x8 ou 16x16 pixels | `sync/openapi.yaml` (POST /icons) |

**Icônes météo PROGMEM intégrées** (10 constantes firmware, pas d'upload nécessaire) :

```
w_clear_day  w_clear_night  w_partly_day  w_partly_night  w_cloudy
w_rain       w_heavy_rain   w_thunder     w_snow          w_fog
```

### Champs polymorphiques

#### `Color` (`schemas/common.yaml`)

Accepte trois formats :

- Tableau RGB : `[255, 147, 26]` (chaque composante 0-255)
- Chaîne hexadécimale : `"#F7931A"`
- Entier non signé 24 bits : `16225050` (encodage 0xRRGGBB, borné à `0xFFFFFF`)

Utilisé dans : `symbolColor`/`sparklineColor` des trackers, `color`/`background` des notifications, `color` des indicateurs, `color` des apps custom.

#### `PolymorphicTextField` (`schemas/common.yaml`)

Accepte trois formats :

- Chaîne simple : `"Hello"`
- Objet coloré : `{"text": "Hello", "color": "#FF0000"}`
- Tableau de segments : `[{"t": "Hello", "c": "#FF0000"}, {"t": " world", "c": "#FFFFFF"}]` (max 8 segments)

Utilisé dans les champs texte des apps custom et des zones.

---

## Error Handling

**Principe : log and continue, never crash the scheduler.**

- `DeviceUnreachableException` : log error, le prochain cycle retentera. L'ESP32 fallback sur l'horloge si data > 1h stale.
- API fetch failure (429, 5xx, timeout) : log error, retourner données cachées si disponibles, sinon skip.
- Exceptions inattendues : catch `\Throwable` dans chaque handler, log full trace, continue.
- Pas de retry explicite : le scheduler re-dispatch naturellement au prochain cycle.

---

## Extensibilité Netatmo (batch suivant)

L'ajout de Netatmo ne touche aucun code existant :

1. Nouveau `SensorProviderInterface` + `NetatmoProvider`
2. Nouveau `SyncSensorMessage` + `SyncSensorHandler`
3. Nouvelle entrée dans le `PixelcastScheduleProvider` (every 10 min)
4. Push via `PixelcastClient::pushCustomApp()` (pas d'endpoint sensor dédié côté ESP32)
5. **Point d'attention** : OAuth2 Netatmo nécessite un refresh token persistant -> fichier `.env.local` ou petit SQLite

---

## Séquence d'implémentation

1. **Skeleton** : `composer create-project`, deps, Docker, Makefile, config -> `bin/console list` fonctionne
2. **PixelcastClient + DTOs** : Client HTTP + tous les DTOs -> client prêt
3. **Weather pipeline** : Provider OWM + IconMapper + Handler -> weather fonctionnel
4. **Crypto trackers** : CoinGecko provider + Handler -> crypto fonctionnel
5. **ETF/Indices** : TwelveData provider -> ajoute IWDA, NASDAQ
6. **Scheduler + Commandes** : ScheduleProvider + SyncCommand + NotifyCommand -> `make sync-weather` et `make up` fonctionnent
7. **Polish** : PHPStan, CS-Fixer, tests, README

---

## Vérification

- `make sync-weather` -> vérifier visuellement sur l'écran que la météo s'affiche
- `make sync-crypto` -> vérifier les cours crypto sur l'écran
- `make sync` -> sync all, vérifier que tout s'enchaîne
- `docker compose exec php php bin/console debug:scheduler` -> vérifier les schedules
- `make up && make logs` -> observer les cycles de sync pendant 30min
- `make test` -> tous les tests passent
- `make lint` -> 0 erreur PHPStan

---

## Points à vérifier avant implémentation

- [ ] Noms d'icônes météo supportés par le firmware ESP32 (pour ajuster `WeatherIconMapper`)
- [ ] Créer les clés API : OpenWeatherMap, CoinGecko (demo), Twelve Data
- [ ] Définir les assets exacts à tracker (symbols, couleurs, devises)
- [ ] Vérifier la connectivité NUC -> ESP32 (mDNS ou IP fixe)
