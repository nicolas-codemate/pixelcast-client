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

The target decides how it is read, not the environment the command runs in.
The target is probed on `/api/__inspect`: only the simulator answers there, and
its answer carries every domain at once. Anything else is read from the firmware
REST API, one GET per domain; the firmware exposes no GET for indicator slots or
custom apps, so those two always come back empty. So `--target` is enough to
dump a real screen from the dev container, and a target that answers neither
shape produces an explanatory error instead of a dump full of `null`. Add `-v`
to see which of the two shapes was detected.

Like the firmware, the simulator serves every route under `/api`, `__inspect`
and `__reset` included, so a target URL always ends with `/api`.

The simulator runs under `php -S`, which starts a fresh PHP process per
request, so it persists its domain state and its request log in
`var/simulator/state-<env>.json` between calls. Set
`PIXELCAST_SIMULATOR_STATE_FILE` to store it elsewhere, and `POST /api/__reset`
to reset every domain and delete the file.

### Running the scheduler on a host

Publishing a release builds the `php_prod` stage and pushes it to
`ghcr.io/nicolas-codemate/pixelcast-client`. A release tagged `v1.4.0` yields
the image tags `1.4.0`, `1.4` and `latest`, so a host can follow `latest` and
still roll back to an exact version. Merging to `main` runs the checks but
publishes nothing.

```
gh release create v1.4.0 --generate-notes
```

The image carries the code only: the device address, the provider API keys and
the sync settings are supplied by the host at runtime.

The prod image runs a single process, the scheduler consumer, so the host needs
neither the repository nor a web server. Copy `deploy/compose.yaml` next to two
files you own:

- `pixelcast.env`, from `deploy/pixelcast.env.dist` — the device base URL and the
  API keys of the data providers
- `pixelcast.yaml`, from `pixelcast.yaml.dist` — the sync groups, their interval
  and their options

```
docker login ghcr.io
docker compose pull && docker compose up -d
```

`pixelcast.yaml` is read once at startup and validated against
`pixelcast.schema.json`. The `yaml-language-server` directive on its first line
points at the schema published on `main` and only serves editor completion; the
one that decides is the copy embedded in the image. API keys never belong in this
file: it rejects any key it does not declare, naming it.

An invalid configuration stops the consumer before it starts, with a message
naming the faulty key, such as `syncs.weather.interval`. Since `compose.yaml`
runs with `restart: unless-stopped`, the container then loops on restart and the
screen stays frozen on the last data pushed. `docker compose ps` then reads
`Restarting`, a state a running container never takes.

A network failure leaves the container running, so it surfaces through the
health state instead. The image declares a healthcheck that runs `app:health`
every five minutes, and that command exits non-zero past three times the
interval of a group — 90 minutes for a 30-minute cycle. Two failed probes in a
row flip the container, but the hourly recycle of the consumer puts the health
state back to `starting` and drops the count of failed probes, so a container
that stays broken across a recycle turns `unhealthy` between 95 and 105 minutes
after the last successful push.

| `docker compose ps` | Meaning |
|---|---|
| `Up (healthy)` | every enabled group pushed within its freshness window |
| `Up (unhealthy)` | an enabled group missed two cycles in a row |
| `Up (health: starting)` | the container has just restarted, the watch has not concluded yet |
| `Restarting (1)` | the configuration is invalid, the consumer never starts |

A fresh deployment reads `unhealthy` until the first push, at most one interval
after the start since the first scheduled run fires at `start + interval`. The
`app:sync weather` documented below records that first push right away and turns
the healthcheck green.

The words of the last probe are readable without opening the logs, and
`app:health` can be run by hand at any time:

```
docker inspect --format '{{json .State.Health}}' <container>
docker compose exec php bin/console app:health
```

On a `Restarting` container that field reads `unhealthy` with an empty log,
because no probe ever ran; `docker compose ps` is what settles the two apart.

Docker's `json-file` driver bounds nothing on its own, so
`deploy/compose.yaml` declares `max-size` and `max-file` and caps the container
at 30 MB — around five months of cycles even if every single one of them fails,
and years of a host that pushes normally. `docker compose logs` reads the
rotated files as well as the current one, so the window that stays readable is
that whole 30 MB, not the 10 MB of the last file.

The host owns its own copy of `deploy/compose.yaml`, so updating the repository
changes nothing there until the file is copied over again. Log options are read
when a container is created, not when it starts, so a `docker compose restart`
keeps the previous ones and only `docker compose up -d` applies the new ones, by
recreating the container — and the log written until then belonged to the old
container, so it goes away with it.

At default verbosity a successful cycle writes nothing at all, so a journal that
carries little more than the banner of each hourly consumer is the normal state
rather than the sign of a stopped one.

An enabled tracker group pushes one screen per tracked asset at every interval,
and turns the container `unhealthy` after three intervals without a successful
push, whatever the cause — the logs name it. An asset the provider does not
serve is logged and skipped, and the other assets of the group still reach the
screen. Both `coingecko` and `twelvedata` ship with `enabled: false` in
`pixelcast.yaml.dist`.

The `twelvedata` group covers stocks, ETFs and indices together, in a single
call to the provider per cycle whatever the number of assets, because the free
quota is 800 requests a day — hence no separate `etf` or `stocks` group. Twelve
Data quotes every asset in the currency of its exchange and converts nothing, so
the `currency` of the file is only a fallback for the responses that carry none,
indices first of all.

The percentage a `coingecko` tracker shows is the distance between the current
price and the price of the last midnight in Paris, not the rolling 24 hour
figure the provider serves: a reference that moves with the clock makes a rising
price show a falling percentage, which reads as a bug on a screen. That midnight
price is fetched once a day per asset and currency, then held in the cache until
the next midnight, which keeps the whole group well inside the free quota of
10 000 calls a month. An asset whose midnight price cannot be fetched is logged
and skipped for that cycle, and the screen keeps what it already shows.

A group with `enabled: false`, or a group left out of the file, is never
scheduled and cannot be dispatched by hand either. Editing the file on the host
takes effect at the next start of the consumer, which the image recycles every
hour.

`PIXELCAST_DEVICE_BASE_URL` must hold an IP address. Container name resolution
goes through musl, which implements no NSS, so an mDNS name such as
`pixelcast.local` never resolves inside the image; reserve a fixed lease for the
screen on the router instead.

To confirm a deployment without waiting for the next scheduled run:

```
docker compose run --rm php bin/console app:sync weather
```

A tracker cycle is checked the same way from the repository, against the local
simulator. The group must be `enabled: true` in the local `pixelcast.yaml`, and
`PIXELCAST_TWELVEDATA_API_KEY` must reach the `php` container — without a key
the cycle is logged and skipped.

```
make sync ARGS="twelvedata"
make inspect
```

`state.trackers.count` then holds one entry per asset the provider served, and
the request log carries one `POST /api/tracker` per asset. `make inspect` reads
the local simulator, on `PIXELCAST_SIMULATOR_HOST_PORT` (8088 by default), never
a real screen.

