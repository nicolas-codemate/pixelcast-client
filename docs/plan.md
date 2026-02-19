# Pixelcast Client - Architecture Plan

## Context

Application Symfony CLI qui collecte des donnees depuis des APIs externes (meteo, crypto, ETF/indices) et les pousse vers un ecran matriciel ESP32 via son API REST. Heberge sur un NUC, Docker-only (pas de PHP local), pas de serveur web.

**Premier batch** : Weather (OpenWeatherMap) + Trackers financiers (CoinGecko crypto + Twelve Data ETF/indices)
**Batch suivant** : Capteurs Netatmo

---

## Architecture Overview

```
[OpenWeatherMap] ---\
[CoinGecko]     ----+--> [Symfony CLI App] --HTTP--> [ESP32 Pixelcast]
[Twelve Data]   ---/     (scheduler loop)            (LED matrix 64x64)
```

**Pattern central** : Provider (fetch + map) -> Message/Handler (orchestrate) -> PixelcastClient (push)

Le Scheduler Symfony dispatch des `RecurringMessage` consommes par `messenger:consume`. Chaque handler orchestre : fetch provider -> build DTO -> push via PixelcastClient.

---

## Stack technique

- PHP 8.3 CLI Alpine (Docker)
- Symfony 7.2 (skeleton, console, http-client, messenger, scheduler, cache, monolog-bundle)
- Twelve Data pour ETF/indices (800 req/jour gratuit, API stable et documentee)
- CoinGecko pour crypto (30 req/min gratuit)
- OpenWeatherMap One Call 3.0 (1000 req/jour gratuit, current + 8j forecast en 1 call)
- PHPStan level 8 + PHP-CS-Fixer

---

## Structure du projet

```
pixelcast-client/
|-- docker/
|   |-- Dockerfile                    # PHP 8.3 CLI Alpine
|-- docker-compose.yml
|-- docker-compose.override.yml       # Dev: volume mount source
|-- Makefile
|-- composer.json
|-- phpunit.xml.dist
|-- phpstan.neon
|-- .env                              # Defaults (PIXELCAST_DEVICE_URL, API keys placeholders)
|-- .env.local                        # Secrets (gitignored)
|-- config/
|   |-- pixelcast.yaml                # Assets trackes + weather location (charge comme parametres)
|   |-- packages/
|       |-- http_client.yaml          # 4 scoped clients (pixelcast, coingecko, openweathermap, twelvedata)
|       |-- messenger.yaml            # sync:// transport for scheduler
|       |-- cache.yaml                # filesystem adapter
|       |-- monolog.yaml              # stderr (Docker-friendly)
|-- src/
|   |-- Client/
|   |   |-- PixelcastClientInterface.php
|   |   |-- PixelcastClient.php       # HTTP client ESP32, 1 methode par endpoint
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
|   |   |-- PixelcastConfiguration.php  # Service qui lit les parametres YAML (pas de config tree)
|   |   |-- TrackerAsset.php            # VO: symbol, icon, color, currency, type
|   |   |-- WeatherLocation.php         # VO: lat, lon, units
|   |
|   |-- Provider/
|   |   |-- Weather/
|   |   |   |-- WeatherProviderInterface.php  # -> WeatherPayload
|   |   |   |-- OpenWeatherMapProvider.php     # Fetch OWM One Call 3.0 + cache 25min
|   |   |   |-- WeatherIconMapper.php          # OWM icon code -> ESP32 icon name (mapping a ajuster cote firmware)
|   |   |-- Tracker/
|   |   |   |-- TrackerProviderInterface.php   # -> TrackerData[]
|   |   |   |-- CoinGeckoProvider.php          # Batch fetch + sparkline downsample
|   |   |   |-- TwelveDataProvider.php         # Quote + time_series sparkline
|   |   |   |-- TrackerData.php                # VO normalise provider-agnostic
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
|   |   |-- PixelcastScheduleProvider.php  # #[AsSchedule] - toutes les frequences
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

## Decisions d'architecture

### Pourquoi Twelve Data pour ETF/Indices (et pas Yahoo Finance)

- 800 req/jour gratuit : suffisant pour ~8 symbols a 15min (96 calls/jour/symbol)
- API officielle avec contrat stable et documentation claire
- Supporte ETFs europeens (IWDA.AS) et indices US (IXIC, DJI)
- Yahoo Finance unofficial est fragile : pas de support, rate limiting sans warning, peut casser a tout moment
- Pour un daemon long-running sur un NUC, la fiabilite prime

### Pourquoi OpenWeatherMap One Call 3.0

- 1 seul appel retourne current + forecast 8 jours
- 1000 calls/jour gratuit, on en fait 48 (toutes les 30min)
- Met a jour toutes les 10min cote OWM

### Pourquoi pas de base de donnees

- L'ESP32 est la source de verite pour l'affichage
- `symfony/cache` filesystem suffit pour le cache API
- Pas d'historique necessaire pour le premier batch
- SQLite envisageable plus tard (Netatmo OAuth refresh tokens)

### Config legere : parametres YAML, pas de Configuration tree

- `config/pixelcast.yaml` definit des `parameters:` Symfony
- Import via `services.yaml`
- `PixelcastConfiguration` est un simple service avec bind qui expose des methodes typees
- Pas de boilerplate Configuration/Extension pour un projet perso

---

## Configuration

### .env (committe)

```dotenv
PIXELCAST_DEVICE_URL=http://pixelcast.local/api
OPENWEATHERMAP_API_KEY=
COINGECKO_API_KEY=
TWELVEDATA_API_KEY=
```

### config/pixelcast.yaml (committe)

```yaml
# Importe dans services.yaml via: imports: [{ resource: 'pixelcast.yaml' }]
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
                base_uri: '%env(PIXELCAST_DEVICE_URL)%'
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

2 commandes seulement. Elles ne dupliquent pas la logique des handlers : elles dispatch les memes messages sur le bus.

### `pixelcast:sync [type] [--all]`

Commande unique pour declencher manuellement un sync. Decouvre les types disponibles via le `PixelcastScheduleProvider`.

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

Implementation : la commande injecte le `MessageBusInterface` et construit le message correspondant au type choisi. Aucune logique metier, juste du dispatch.

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

Commande one-shot pour envoyer une notification overlay sur l'ecran. Pas de schedule, purement a la demande. Appelle directement `PixelcastClient::pushNotification()`.

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
            ->processOnlyLastMissedRun(true);  // Pas de flood apres downtime
    }
}
```

---

## Docker

### Dockerfile (PHP 8.3 CLI Alpine, pas de web server)

- Installe composer, intl, opcache, zip
- `CMD: php bin/console messenger:consume scheduler_default --time-limit=3600 -vv`
- `--time-limit=3600` force un restart toutes les heures (evite memory leaks PHP long-running)

### docker-compose.yml

```yaml
services:
  pixelcast:
    build:
      context: .
      dockerfile: docker/Dockerfile
    container_name: pixelcast-client
    restart: unless-stopped
    env_file: .env.local
    volumes:
      - ./var:/app/var  # Persistence cache + scheduler state
```

### docker-compose.override.yml (dev, auto-charge par Docker Compose)

```yaml
services:
  pixelcast:
    volumes:
      - .:/app          # Source montee pour dev
    command: php bin/console messenger:consume scheduler_default -vv
```

### Makefile (raccourcis)

```makefile
up:              docker compose up -d
down:            docker compose down
logs:            docker compose logs -f
shell:           docker compose exec pixelcast sh
console:         docker compose exec pixelcast php bin/console
sync:            docker compose exec pixelcast php bin/console pixelcast:sync --all -vv
sync-weather:    docker compose exec pixelcast php bin/console pixelcast:sync weather -vv
sync-crypto:     docker compose exec pixelcast php bin/console pixelcast:sync crypto -vv
notify:          docker compose exec pixelcast php bin/console pixelcast:notify "$(MSG)" -vv
test:            docker compose exec pixelcast php bin/phpunit
lint:            docker compose exec pixelcast vendor/bin/phpstan analyse
```

---

## Error Handling

**Principe : log and continue, never crash the scheduler.**

- `DeviceUnreachableException` : log error, le prochain cycle retentera. L'ESP32 fallback sur l'horloge si data > 1h stale.
- API fetch failure (429, 5xx, timeout) : log error, retourner donnees cachees si disponibles, sinon skip.
- Exceptions inattendues : catch `\Throwable` dans chaque handler, log full trace, continue.
- Pas de retry explicite : le scheduler re-dispatch naturellement au prochain cycle.

---

## Extensibilite Netatmo (batch suivant)

L'ajout de Netatmo ne touche aucun code existant :

1. Nouveau `SensorProviderInterface` + `NetatmoProvider`
2. Nouveau `SyncSensorMessage` + `SyncSensorHandler`
3. Nouvelle entree dans le `PixelcastScheduleProvider` (every 10 min)
4. Push via `PixelcastClient::pushCustomApp()` (pas d'endpoint sensor dedie cote ESP32)
5. **Point d'attention** : OAuth2 Netatmo necessite un refresh token persistant -> fichier `.env.local` ou petit SQLite

---

## Sequence d'implementation

1. **Skeleton** : `composer create-project`, deps, Docker, Makefile, config -> `bin/console list` fonctionne
2. **PixelcastClient + DTOs** : Client HTTP + tous les DTOs -> client pret
3. **Weather pipeline** : Provider OWM + IconMapper + Handler -> weather fonctionnel
4. **Crypto trackers** : CoinGecko provider + Handler -> crypto fonctionnel
5. **ETF/Indices** : TwelveData provider -> ajoute IWDA, NASDAQ
6. **Scheduler + Commandes** : ScheduleProvider + SyncCommand + NotifyCommand -> `make sync-weather` et `make up` fonctionnent
7. **Polish** : PHPStan 8, CS-Fixer, tests, README

---

## Verification

- `make sync-weather` -> verifier visuellement sur l'ecran que la meteo s'affiche
- `make sync-crypto` -> verifier les cours crypto sur l'ecran
- `make sync` -> sync all, verifier que tout s'enchaine
- `docker compose exec pixelcast php bin/console debug:scheduler` -> verifier les schedules
- `make up && make logs` -> observer les cycles de sync pendant 30min
- `make test` -> tous les tests passent
- `docker compose exec pixelcast vendor/bin/phpstan analyse` -> 0 erreur

---

## Points a verifier avant implementation

- [ ] Noms d'icones meteo supportes par le firmware ESP32 (pour ajuster `WeatherIconMapper`)
- [ ] Creer les cles API : OpenWeatherMap, CoinGecko (demo), Twelve Data
- [ ] Definir les assets exacts a tracker (symbols, couleurs, devises)
- [ ] Verifier la connectivite NUC -> ESP32 (mDNS ou IP fixe)
