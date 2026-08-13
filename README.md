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
neither the repository nor a web server. Copy `deploy/compose.yaml` next to three
things you own:

- `pixelcast.env`, from `deploy/pixelcast.env.dist` — the device base URL and the
  API keys of the data providers
- `pixelcast-config/pixelcast.yaml`, from `pixelcast.yaml.dist` — the sync groups,
  their interval and their options. The directory is one you create yourself, and
  what the container mounts is that directory rather than the file: a single-file
  mount does not follow the `rename()` an editor saves with, so the container would
  go on reading the version from before an edit
- `claude/`, an empty directory you create yourself — where the `claude` group
  keeps the credentials of its session, and the only mount the container writes
  into. Only that group needs it, and `mkdir claude` before the first
  `docker compose up -d` is all there is to it. Create it **before** starting the
  container, not after: the container runs as root and creates it `0700` if it gets
  there first, and you would then need `sudo` to put anything in it — including the
  file the bootstrap below copies through

```
docker login ghcr.io
docker compose pull && docker compose up -d
```

A host already running keeps its `pixelcast.yaml` next to `compose.yaml`, where the
single-file mount of the previous versions expected it. Copy the new
`deploy/compose.yaml` over, add the `PIXELCAST_CONFIG_FILE` line of
`deploy/pixelcast.env.dist` to `pixelcast.env`, then move the file and recreate the
container:

```
mkdir pixelcast-config && mv pixelcast.yaml pixelcast-config/
docker compose up -d
```

`PIXELCAST_CONFIG_FILE` names the file the client reads. Without it the file is
`pixelcast.yaml` at the root of the checkout, which is what dev uses;
`deploy/pixelcast.env.dist` sets it to the mounted path,
`/app/pixelcast-config/pixelcast.yaml`, since the built-in default sits outside that
mount.

`pixelcast.yaml` is read at startup, validated against `pixelcast.schema.json`,
and read again whenever its modification time changes, so an edit takes effect on
the next sync cycle without restarting the container. What is picked up straight
away are the options of a group — colours, tracker items, thresholds. The
interval of a group, its `enabled` flag and the sleep window are held by the
scheduler for the life of the consumer, so those wait for its hourly recycle. The
`yaml-language-server` directive on the first line points at the schema published
on `main` and only serves editor completion; the one that decides is the copy
embedded in the image. API keys never belong in this file: it rejects any key it
does not declare, naming it.

An invalid configuration stops the consumer before it starts, with a message
naming the faulty key, such as `syncs.weather.interval`. Since `compose.yaml`
runs with `restart: unless-stopped`, the container then loops on restart and the
screen stays frozen on the last data pushed. `docker compose ps` then reads
`Restarting`, a state a running container never takes.

An invalid *edit* does not stop anything: the consumer keeps the last valid
configuration and writes `The PixelCast configuration could not be reloaded` in
its logs. So a screen that ignores an edit is explained by
`docker compose logs php` rather than by a restarting container.

A network failure leaves the container running, so it surfaces through the
health state instead. The image declares a healthcheck that runs `app:health`
every five minutes, and that command exits non-zero past three times the
interval of a group — 90 minutes for a 30-minute cycle. Two failed probes in a
row flip the container, but the hourly recycle of the consumer puts the health
state back to `starting` and drops the count of failed probes, so a container
that stays broken across a recycle turns `unhealthy` between 95 and 105 minutes
after the last successful push.

A group outside its `activeWindow` is not judged at all: `app:health` prints
`boursorama: outside its active window, not watched` and moves on. Inside the
window the count restarts at the reopening rather than at the last push, so a
group that closed on Friday at 17:45 gets three full cycles from Monday 09:00
before it reads as late — 45 minutes for a 15-minute one — instead of being
declared stale on the sixty-three hours of the weekend. A market group therefore
never turns the container `unhealthy` for a night or a weekend. A group the
`sleep:` section suspends is spared the same way, and counted from the moment
the panel comes back on.

| `docker compose ps` | Meaning |
|---|---|
| `Up (healthy)` | every enabled group pushed within its freshness window |
| `Up (unhealthy)` | an enabled group missed two cycles in a row inside its active window |
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
screen. `coingecko`, `twelvedata` and `boursorama` all ship with
`enabled: false` in `pixelcast.yaml.dist`.

The `twelvedata` group covers stocks, ETFs and indices together, in a single
call to the provider per cycle whatever the number of assets, because the free
quota is 800 requests a day — hence no separate `etf` or `stocks` group. Twelve
Data quotes every asset in the currency of its exchange and converts nothing, so
the `currency` of the file is only a fallback for the responses that carry none,
indices first of all.

The `boursorama` group covers the European ETFs the free Twelve Data plan does
not quote, and it is the only tracker group that asks for no API key at all. It
pays for that with one request per asset and per cycle, since the source quotes
a single symbol at a time — six tracked assets mean six calls where
`twelvedata` still makes one. `symbol` holds the Boursorama code of the asset,
`1rTDCAM`, not its ISIN; the code behind an ISIN is read once, by hand, on
`https://www.boursorama.com/recherche/ajax?query=<ISIN>`. What the screen shows
by default is that code stripped of its leading `1rT`-shaped prefix, so
`1rTDCAM` reads `DCAM`, while the tracker keeps the whole code as its name. The response carries
no currency whatsoever, so here the `currency` of the file is authoritative
rather than a fallback.

That endpoint is internal to the Boursorama website: undocumented, under no
commitment, and free to change or vanish without notice. The trade is a
deliberate one — the alternative for European coverage is the Twelve Data plan
at $229 a month.

`label`, `labelColor` and `bottomText` are optional on any asset of the three
tracker groups. `label` replaces the text derived from `symbol` on the top row,
and there only: the tracker keeps the same name on the device, so renaming a
label updates the screen that already exists instead of creating a second one.
Up to 31 characters are accepted, and the screen scrolls whatever is wider than
the panel, pausing at each end — which is what makes that length usable rather
than merely legal. The matrix carries no accented glyphs: `Santé` reads
`Sante`, `Cœur` reads `Cur`, and a typographic apostrophe disappears
altogether. The client sends the text exactly as it is written, the
transposition being the work of the screen. `bottomText` writes the row under
the price, within the same 31 characters; on `coingecko` it takes the place of
the 24 hour volume shown there by default. The device also accepts a colored
footer, as a single colored string or as up to eight colored segments, but the
configuration exposes the plain string alone — a deliberate limit of the
current scope rather than an omission.

`bottomLine` chooses what that row shows when `bottomText` is absent: `ath`
writes the all-time high of the asset, `volume` its 24 hour traded volume. Each
group only accepts what it can serve, and a value it cannot serve is refused at
startup, naming the item: `coingecko` takes both, `boursorama` takes `ath`, and
`twelvedata` takes neither. A group offers the all-time high only when it knows
a high it did not observe itself. Twelve Data has none here — no API key was
available to check what it serves — and a high built from what the client alone
has seen reads "at the all-time high" on any asset from the first day, with a
stock split freezing a wrong value for good; that stays open until the Twelve
Data API is tested with a real key. Without `bottomLine` nothing changes:
`coingecko` keeps showing the 24 hour volume and the other two groups keep
showing nothing. Ten characters of that row are read at a glance and the device
contract accepts 31, anything longer scrolling rather than being cut.

The all-time high is written with the month and the year it was reached, as in
`ATH 107.7K$ 10/2025`. A high of a thousand or more is condensed on its
magnitude — `K`, `M`, `B`, `T` — so that a six-digit price leaves the date the
room it needs; below that it keeps the two decimals the price row above it
shows. A high the source never dated carries no date, and one so wide that the
date no longer fits drops the date rather than the price. That row scrolls: only
an undated high stays under the ten characters read at a glance.

On `boursorama` the row only tells the whole story once `app:tracker:ath` has
run: until then it shows the highest of the last thirty sessions, which is all
the sync cycle downloads.

`volumeBars` decides whether the device draws the traded volume as bars behind
the price curve. Without the key the bars are drawn wherever the provider serves
one volume per point of the curve; the firmware scales each series against its
own largest value, a price in hundreds of euros and a volume in millions of
units sharing no axis. Boursorama and Twelve Data both carry the volume on the
daily bars they already serve, so the series costs no request — except on an
index or a forex pair, which Twelve Data quotes with a volume of zero on every
bar and which therefore gets no bars at all. On `coingecko` the series is absent
from the group call: it takes one request per coin, held in the cache for an
hour, and it is the rolling 24 hour volume sampled hourly rather than one bar per
session, so the bars read smoother than an exchange volume. A coin whose series
comes back shorter than its price curve keeps the curve alone and says so in the
log, the two series coming from two endpoints that need not agree on their length.

That extra request is the one place where the bars cost something: 720 calls a
month per asset, against the 10 000 of the free CoinGecko tier and the 8 600 the
group call alone spends at the five minute interval of `pixelcast.yaml.dist`.
Bars on more than one or two coins therefore ask for a longer interval, an API
key on a paid tier, or `volumeBars: false` on the assets that can do without
them.

Colors follow the trend unless they are told otherwise. By default the name and
the sparkline both turn green above zero and red below it, which paints the
whole screen red on a variation of -0.03% even when the curve has been
climbing for weeks. `labelColor` pins the color of the name and takes it out of
that rule; the sparkline keeps the trend color, the one place where green or
red still reads as the direction of the day. The expected form is a `#RRGGBB`
hex string, `#4CAF50` for instance.

The percentage a `coingecko` tracker shows is the distance between the current
price and the price of the last midnight in Paris, not the rolling 24 hour
figure the provider serves: a reference that moves with the clock makes a rising
price show a falling percentage, which reads as a bug on a screen. That midnight
price is fetched once a day per asset and currency, then held in the cache until
the next midnight, so it costs thirty calls a month per asset. An asset whose
midnight price cannot be fetched is logged and skipped for that cycle, and the
screen keeps what it already shows.

#### The `claude` group

The `claude` group pushes the four counters of a Claude Code subscription as a
single gauge named `claude`: the rolling five hours, the rolling seven days, the
weekly allowance scoped to the Fable model, and the extra-usage credits. Each row
carries a bar coloured from the percentage — green under 50, yellow to 79, red
from 80 — and, next to it, a pace such as `x1.2^`: the multiple of the rate that
would use the window up exactly at its reset, with an arrow for the direction.
Above `x1.0` the window is being spent faster than it renews. The first hour of a
window carries no pace at all, because a divisor that small says nothing.

The header and the labels carry colours of their own, none of them configurable.
The title reads `Claude` in the Anthropic orange `#D97757`. Each row name then
carries a tint of its own, so that a row is recognised before it is read: `5h`
in cyan `#4DD0E1`, `7j` in slate blue `#7C9CB0`, `fable` in the violet `#AF5FFF`
the Claude Code statusline writes that same word in, and `credits` in pink
`#E86AA6`. None of the four is the green, yellow or red the bars and the pace
notes own, so a name is never read as a level.

The reset instant sits next to the name, in the `info` column, which the device
contract declares as a plain string: it carries no colour of its own and none can
be given to it. The name is also the only field the row cuts short once the value
and the info are served, so it stands alone rather than being followed by a word
that would lose its last letters.

The counters are account-wide, not machine-wide. So the host reports the usage of
every machine signed in to that account, and it keeps the reading correct around
the clock — a window that resets at three in the morning is drawn right at three
in the morning, whether or not a workstation is running.

The group ships `enabled: false` in `pixelcast.yaml.dist`, because a group enabled
before it has a session reports a failure at every cycle. Authorise the host
first, then flip `enabled` to `true` and restart the consumer:

```
claude                                             # on the host, once: log in with the CLI
cp ~/.claude/.credentials.json claude/from-cli.json
docker compose exec php bin/console app:claude:login --from=/app/claude/from-cli.json
rm claude/from-cli.json
```

The session has to be created by the Claude Code CLI itself, and the command adopts
what it wrote. The copy through `claude/` is what carries the file across: the CLI
runs on the host, the command runs in the container, and the only path both of them
see is that bind mount. Delete the copy once the pair is written — the container has
its own file from then on, and two copies of the same session is one too many.

Run on a machine where the CLI's file *is* visible to the container, the command
finds it on its own: with no `--from` it reads `$HOME/.claude/.credentials.json`.

Adopting is not a shortcut around a login of our own — it is the only thing that works.
The authorization server grants the scopes that open the usage endpoint,
`user:sessions:claude_code` among them, to the approval of the Claude Code CLI and to
nothing else. Three routes were tried against the live server, and all three are closed:

- **A PKCE authorization-code flow of our own**, browser and paste-back included, is
  approved and does return a session — carrying `user:profile` and `user:file_upload`
  and nothing more, which the usage endpoint answers with

  ```
  403 oauth_not_allowed_for_organization
  ```

  which reads like an account problem and is not one: the same account gets a 200 with
  a session the CLI created.
- **The device-code grant**, which the host having no browser makes the obvious choice,
  is refused to this client — `unauthorized_client`, where an invented client id gets
  `invalid_client`. The client is real; the grant is simply not allowed it.
- **`claude setup-token`**, the long-lived token meant for headless machines, is refused
  by this endpoint too: `403 OAuth token does not meet scope requirement user:profile`.
  It carries fewer scopes than a browser approval, not more — it is scoped to model
  requests, and reading a quota is not one.

Every statusline tool that reports these counters reads the CLI's credentials file the
same way. It is not a shortcut around a supported path; it is the only path there is.

**After the login, do not run `claude` on that host again.** The poller now owns
the token family and rotates it; the CLI would rotate the same family, and whichever
renews first leaves the other holding a token the server has retired. Nothing is
printed that carries a token.

`GET https://api.anthropic.com/api/oauth/usage` is the endpoint the Claude Code
CLI calls to feed its own statusline: undocumented, under no commitment, and free
to change or vanish without notice — the same standing as the Boursorama source
above. The trade is deliberate: there is no public Anthropic API for subscription
limits at all, the Admin API's `/v1/organizations/usage_report/…` covering console
API spend rather than subscription quotas. Reading it consumes no quota, so a
15-minute interval costs nothing.

The credentials file is written by the container and never edited by hand. It
holds a pair of tokens, and the refresh token **rotates**: every renewal issues a
new one and retires the one that was used. So the host must have a login of its
own, and a workstation's `~/.claude/.credentials.json` must never be copied onto
it — the first of the two machines to refresh logs the other out, and which one
that is depends on nothing more than timing. The file is written through a
temporary file, flushed to the disk and renamed into place, and it keeps the pair
it replaced, so a rotation interrupted halfway has something left to retry from.
Losing the file means running `app:claude:login` again.

It is written `0600` and owned by root, because the image declares no `USER` and
the container runs as one. That is the right owner for a credential, but it does
mean the host account cannot read or back the file up without `sudo`.

The refresh token expires too, on a horizon of its own that no refresh extends
indefinitely. A host left off past that horizon cannot refresh its way back: the
renewal is refused, the group fails every cycle, and `app:claude:login` has to be
run again on it. That is a normal end of life for a session, not a fault to
diagnose.

A session the server refuses on both its stored pairs is recorded as such in the
credentials file, and the cycles that follow stop before reaching the network.
Nothing is gained by asking again — only a new login revives it — and the token
endpoint rate-limits the very exchange `app:claude:login` needs, so a poller that
kept trying would be competing with the repair.

`SyncHealthChecker` watches every enabled group, this one included, so a session
that has been revoked, has expired, or that Anthropic cannot renew turns the
container `unhealthy` after three cycles — 45 minutes for the 15-minute interval.
That is the intended behaviour and is not to be worked around: a group that failed
silently forever would be worse than one that goes red. Confirm with
`docker compose exec php bin/console app:health`, which names the group, then
re-authorise with `app:claude:login`.

#### The `github` group

The `github` group counts the pull requests whose review is asked of one account
and draws that count as a single-zone custom app named `github`: the number, a
label under it, and nothing else. The count is the `total_count` of one search
query, `query` in the file, sent to `GET /search/issues` as it stands — which is
what makes it adjustable without a redeployment, and what makes a malformed
query visible only when the group runs. Reviews asked of a team are not counted:
`team-review-requested:` is a second query the group does not run. The app name
is fixed, so a second GitHub group would overwrite the first.

`label` is required and drawn under the count, 31 characters at most. `icon` and
`color` are optional and default to the `github` icon and the GitHub purple
`#8957E5`, both applied when the configuration loads rather than written in the file.

Nothing left to review leaves no app on the screen at all, rather than an app
showing a zero: a count of zero deletes the app through
`DELETE /api/custom?name=github`. The first quiet cycle on a device that was
never pushed to gets a 404 back, and that is the state the group wanted anyway,
so it counts as a done cycle rather than a failure. A deletion also records a
successful sync, so a quiet week does not turn the group stale in the
healthcheck.

The token is read from `PIXELCAST_GITHUB_TOKEN`, never from `pixelcast.yaml`. A
classic token needs the `repo` scope to see the pull requests of private
repositories, a fine-grained one read access to their pull requests. Without the
variable the group logs a warning naming it, pushes nothing, and does not fail
the cycle — the same behaviour as a tracker group without its API key. The group
ships `enabled: false` in `pixelcast.yaml.dist`, since a group enabled before its
token exists warns at every cycle.

Any group may declare an `activeWindow` — `days`, `from`, `to` and a `timezone`
— and is then scheduled during those hours only: outside them no provider is
called and the healthcheck does not watch the group. Both bounds are inclusive,
so a `to: '17:45'` against a 15-minute cycle still fires the 17:45 run, and
`days` is optional, the seven of the week otherwise. A window spanning midnight
is refused at startup: `timezone` exists precisely so the window is written in
the local hours of the market, where it does not cross midnight, rather than in
the UTC of the container clock. `app:sync <type>` ignores the window and pushes
the group at any hour, which is what keeps it usable to test a closed market in
the evening. A consumer stopped while the window was open and started again
after it closed pushes nothing at that restart: the cycles missed in between are
dropped and the group waits for its first run following the reopening. A quote
read hours after the close is worth nothing, so it is not caught up.

The `sleep:` section, at the top level of the file rather than inside a group,
turns the panel off between hours of the day: `black` blanks it, `clock` leaves
a dimmed clock on it, which in a dark room is still a source of light. The
device stores the schedule and goes on applying it on its own, and nothing in
the scheduler ever sends it — only `bin/console app:device:sleep` does, so
editing the section changes nothing on the screen until that command has run,
and running it again is the repair if the device ever comes back without its
schedule. `days` is optional there too, the seven of the week otherwise, and a
window whose `to` is earlier than its `from` runs past midnight into the next
day, so `from '22:00'` to `'07:00'` is a single night — the exact opposite of a
group `activeWindow`, which is refused at startup when it spans midnight. The
two keys do not do the same thing, and the confusion is worth naming: an
`activeWindow` stops a group from running outside its hours but turns nothing
off, so the screen keeps showing the last image that was pushed to it, while
`sleep` turns the panel off and stops the cycles with it, an hour with nothing
to look at being an hour with nothing to fetch.

An enabled window suspends every group while it covers the current instant: no
group is scheduled, no provider is called, and a night of blank panel now costs
the quota it looks like it costs. At the end of the window
every enabled group pushes at once, within the minute, instead of waiting for
its next tick — seven hours of silence outlive every reasonable `staleAfter`, so
a panel that came back on its own schedule would light up on a wall of STALE
badges rather than on fresh data. The grid of the regular cycles stays anchored
on the start of the consumer rather than on the wake-up, so a group can push
twice in the first minutes of the morning: one extra call per group per night,
against a whole night of calls into the dark saved.

The sleep window and a group `activeWindow` stack rather than replace one
another: a group carrying both runs inside its own hours and outside the sleep
window, and a wake-up falling before its opening pushes nothing — that group
waits for its first run once its own hours are open. `app:sync <type>` ignores
the sleep window exactly as it ignores an `activeWindow`, so a group is still
pushed by hand at three in the morning to check it. `app:health` leaves a
suspended group alone and prints `weather: asleep, not watched`, then counts
from the wake-up
rather than from the last push, so the group keeps its full tolerance for the
first minutes of the morning and a night of sleep never turns the container
`unhealthy`. A section carrying `enabled: false`, or no `sleep:` section at all,
suspends nothing and leaves the cycles strictly as they were.

`timezone` is required as soon as the section is enabled, and must name the
timezone the device itself is set to. The container clock runs on UTC, so a
Paris night written `00:00` to `07:00` and read as UTC would suspend the cycles
from 02:00 to 09:00 local in summer: the panel dark and the cycles still running
for two hours, then the panel lit and the cycles suspended for two more, which
is the very wall of STALE badges the wake-up push exists to avoid, moved to the
morning. The client does not guess it, so an existing `pixelcast.yaml` carrying
an enabled `sleep:` section needs that one line added — without it the consumer
stops at startup naming `sleep.timezone`. A section left on `enabled: false`
suspends nothing and is asked for nothing, so it loads untouched. The file is what the client obeys: it suspends
its cycles whether or not `app:device:sleep` has ever run, while the panel
itself only goes off once that command has pushed the schedule to the device.

The same three keys — `activeWindow`, `staleAfter` and `staleBehavior` — also
exist on a tracker item, where each overrides the value of the group. A single
provider covers markets that do not open together: a Euronext ETF trades from
09:00 to 17:30 in Paris while a US-listed one trades from 15:30 to 22:00 Paris
time, and a provider cannot be declared twice. An item outside its own window is
left out of the cycle, which spares the quota it would have cost — Boursorama
bills one call per asset, Twelve Data one credit per requested symbol. Run by
hand, `app:sync <type>` fetches every item whatever its own window, exactly as
it ignores the group one. Declare
the group window as the envelope of the item windows: the group is only
scheduled during its own hours, and a cycle waking with no active item pushes
nothing and logs `Tracker sync skipped, the provider returned no tracker` every
time. The healthcheck follows the same rule and leaves a group alone while none
of its items is open.

Every pushed payload carries a `staleAfter`, the silence in seconds the device
tolerates before it treats the app as stale, and a `staleBehavior` when the
group declares one. Without a `staleAfter` key the value is three times the
interval — the same rule as the healthcheck, so 45 minutes for a 15-minute cycle
— capped at seven days, and `0` tells the device to never age the app out.
`staleBehavior` is `hide`, `dim`, `badge` or `none` on a tracker group and on the
`claude` group, and `hide` or `none` on the weather and `github` groups, the two
others being drawn by the tracker and gauge layouts alone; without the key the
firmware default applies. `pixelcast.yaml.dist` carries the exact shape of the three keys.
No `staleAfter` worth writing survives a sleep window, which is why the wake-up
push described with the `sleep:` section is immediate.

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

`boursorama` is checked the same way and needs no environment variable at all,
only `enabled: true` in the local `pixelcast.yaml`.

`state.trackers.count` then holds one entry per asset the provider served, and
the request log carries one `POST /api/tracker` per asset. `make inspect` reads
the local simulator, on `PIXELCAST_SIMULATOR_HOST_PORT` (8088 by default), never
a real screen.

The sleep schedule is sent by hand as well, to whatever
`PIXELCAST_DEVICE_BASE_URL` names — the local simulator from the repository, the
screen on a host:

```
docker compose run --rm php bin/console app:device:sleep
```

The command reads the `sleep:` section, pushes the seven days of the week, then
reads the device back and prints what it now holds: whether the panel is awake
or asleep and for what reason, whether the schedule is enabled and in which
display mode, and one line per day carrying a window. A device that takes the
push but cannot be read back leaves a warning and a successful run, since the
schedule did leave. Against the simulator, `make inspect` shows the same
schedule under `state.sleep`. The command decides the panel alone: the cycles
follow the section from the file, so a screen that never received the schedule
stays lit all night while the client has already stopped pushing to it.

The all-time high a `bottomLine: ath` row shows is caught up by hand, outside
the scheduler: Boursorama serves it as twenty years of daily bars, a few hundred
kilobytes per asset, which has no place in a cycle that runs every few minutes.

```
docker compose run --rm php bin/console app:tracker:ath --all
```

Without `--all` a symbol picks the assets to catch up, and every item carrying
that symbol is processed whatever its group and its currency — the same asset is
often tracked in two currencies, and each currency holds its own high. Without
either the command asks which asset to take, listing them as
`boursorama 1rTCW8 (EUR)`. Every tracker group is covered, including the
disabled ones, so a group can be caught up before it is turned on. `coingecko`
has nothing to catch up, since it serves its own all-time high on every sync,
and `twelvedata` serves no history at all until its API is checked with a real
key; both are reported and neither fails the run.

The highs are kept in `var/share/tracker-all-time-high.sqlite`, on the named
volume `deploy/compose.yaml` mounts, so they survive a redeployment and
`cache:clear` never touches them. Back that file up to keep them, or pass
`--reset` to the command to drop the high of an asset and let it be rebuilt.

What the command writes only rises, exactly like a sync: the bars it reads end
at the previous close, so a catch-up launched during a session knows nothing of
the high of the day and must not crush what the morning sync observed. Bringing
a wrong value down — an aberrant tick, a stock split — is therefore two
deliberate steps, `--reset` on the asset then a catch-up:

```
docker compose run --rm php bin/console app:tracker:ath 1rTCW8 --reset
```

`--reset` consults no source, so it works on any group, and it combines with
`--all`. On `coingecko` it is the whole cure: the next sync rewrites the value
the source serves.

`claude` is checked the same way, with `enabled: true` in the local
`pixelcast.yaml` and a credentials file the container can read —
`bin/console app:claude:login` writes one under `claude/` at the root of the
checkout by default, so no environment variable is needed locally either. That
directory is git-ignored; the pair must never reach a commit.

```
make sync ARGS="claude"
make inspect
```

`state.gauges.claude.rows` then holds up to four entries and the request log
carries one `POST /api/gauge`.

`github` is checked the same way, with `enabled: true` in the local
`pixelcast.yaml` and `PIXELCAST_GITHUB_TOKEN` reaching the `php` container.

```
make sync ARGS="github"
make inspect
```

`state.customApps.apps.github` then holds the count and the request log carries
one `POST /api/custom`. Running it again with a query nobody answers — appending
`is:draft` to the configured one empties it on purpose — leaves no
`state.customApps.apps.github` at all and one `DELETE /api/custom` in the log.

