# pixelcast-client

### Scenarios

`bin/console app:scenario` sends a predefined REST payload to the configured
PixelCast target, so a change can be checked on the matrix display itself.

```
bin/console app:scenario                 # list every scenario id
bin/console app:scenario weather         # send one scenario
bin/console app:scenario weather --target=http://192.168.1.42/api
```

Without `--target`, the destination comes from `PIXELCAST_DEVICE_BASE_URL`
(the local simulator in dev, the real device in prod). The `reset-simulator`
scenario only appears when `APP_ENV=dev`.

Each payload is validated against `sync/openapi.yaml` before the request
leaves the process; validation failures print `VALIDATION ...` and the HTTP
call is skipped. Successful dispatches print `OK <status>`, transport errors
print `FAIL HTTP <status>: <snippet>` (or `FAIL <reason>` for non-HTTP
errors), and unreachable targets print `UNREACHABLE <reason>`. The exit code
is non-zero when the scenario fails.

Scenarios live in `src/Scenario/ScenarioCatalog.php` — add new entries there
to extend the list.

### Reading the device state

`bin/console app:device:dump` prints the raw device state as JSON.

```
bin/console app:device:dump                    # every domain
bin/console app:device:dump --domain=weather   # one domain
bin/console app:device:dump --target=http://192.168.1.42/api
```

In dev the state is read from the simulator's `/api/__inspect` endpoint, which
returns every domain at once. In prod it is read from the firmware REST API,
one GET per domain; the firmware exposes no GET for indicator slots or custom
apps, so those two always come back empty.

Like the firmware, the simulator serves every route under `/api`, `__inspect`
and `__reset` included, so a target URL always ends with `/api`.

The simulator runs under `php -S`, which starts a fresh PHP process per
request, so it persists its domain state and its request log in
`var/simulator/state-<env>.json` between calls. Set
`PIXELCAST_SIMULATOR_STATE_FILE` to store it elsewhere, and `POST /api/__reset`
to reset every domain and delete the file.

### Running the scheduler on a host

Every push to `main` builds the `php_prod` stage and pushes it to
`ghcr.io/nicolas-codemate/pixelcast-client`, tagged `latest` and
`sha-<commit>`. The image carries the code only: the device address and the
weather settings are supplied by the host at runtime.

The prod image runs a single process, the scheduler consumer, so the host needs
neither the repository nor a web server. Copy `deploy/compose.yaml` next to two
files you own:

- `pixelcast.env`, from `deploy/pixelcast.env.dist` — the device base URL
- `pixelcast.yaml`, from `pixelcast.yaml.dist` — coordinates, units, intervals

```
docker login ghcr.io
docker compose pull && docker compose up -d
```

`PIXELCAST_DEVICE_BASE_URL` must hold an IP address. Container name resolution
goes through musl, which implements no NSS, so an mDNS name such as
`pixelcast.local` never resolves inside the image; reserve a fixed lease for the
screen on the router instead.

To confirm a deployment without waiting for the next scheduled run:

```
docker compose run --rm php bin/console app:sync weather
```

