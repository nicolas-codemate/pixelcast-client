# pixelcast-client

### TUI Scenarios

The `[1] Scenarios` menu in `bin/console app:tui` sends predefined REST calls
to the configured ESP32 target. It is available in both dev and prod modes;
the only difference is the target URL, taken from `PIXELCAST_DEVICE_BASE_URL`
(the local simulator in dev, the real device in prod). The `Reset simulator`
entry is dev-only and is hidden when the TUI runs against a real device.

Each scenario payload is validated against `sync/openapi.yaml` before the
request leaves the process; validation failures show inline as
`VALIDATION ...` and the HTTP call is skipped. Successful dispatches show
`OK <status>`, transport errors show `FAIL HTTP <status>: <snippet>` (or
`FAIL <reason>` for non-HTTP errors), and unreachable targets show
`UNREACHABLE <reason>`.

Scenarios live in `src/Tui/Scenarios/ScenarioCatalog.php` — add new entries
there to extend the menu.
