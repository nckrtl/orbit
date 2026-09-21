# Orbit web

The fleet as a live web page: the same screen `orbit top` draws, in a browser. It is a static single-page app that calls the Gateway API and subscribes to the Gateway's record-change channel. It keeps no state of its own and has no server.

## Run it locally

```bash
bun install
vp dev
```

`vp dev` prints a local URL. The dev server proxies `/api` and the WebSocket to the Gateway in the CLI's active profile (`~/.orbit/config.json`), with that profile's Orbit CA. The Gateway identifies the caller by its WireGuard address, so this machine must be a WireGuard peer, as it must be for `orbit top`. Set `ORBIT_GATEWAY` to use another profile, or `ORBIT_GATEWAY_URL` and `ORBIT_CA_PATH` to use no profile.

The Gateway names the Nodes. Node metrics come from the Metrics role's Grafana, as they do in `orbit top`: the page asks the Gateway for the Grafana credential once and then queries Prometheus through Grafana's datasource proxy, with one fleet-wide query for the dashboard. Prometheus only has samples for the Nodes whose exporter is enabled, so every other Node shows no metrics and no request goes out for it. The page reaches Grafana at the same-origin path `/grafana`, which the dev server proxies to the URL the credentials name.

The page acts on the real fleet. Process, schedule, database, and firewall actions send the same requests the matching commands send.

## Run it without a Gateway

```bash
bun run demo
```

Demo mode answers every request in the page from the fixture fleet under `fixtures/fleet`. Actions work and change only that in-memory fleet, so this is the mode for trying an action or building a screen. `src/demo/gateway.ts` is that Gateway; the tests run against it too.

The fixture files use the format of the recorded Gateway fixtures in `packages/php-sdk/fixtures`. `bin/api-fixtures --check` validates them against `docs/openapi.json`.

## Layout

- `src/api`: the fetch client, the TanStack Query definitions, the record actions, and `schema.d.ts`, generated from `docs/openapi.json` by `bun run types`.
- `src/metrics`: the PromQL, the Prometheus mapping, and the Grafana client, ported from the CLI.
- `src/realtime`: the Pusher-protocol subscription. Each event patches the query cache; the lists poll only while the socket is down.
- `src/fleet`: health rules, relations between records, and the "Needs attention" list.
- `src/ui`: the terminal-style primitives (`Frame`, `Pane`, `Bar`, `LogPane`), the keyboard handling, and the shell.
- `src/pages`: the dashboard, the section lists, the record pages, and the node form.

The URL carries the section, the open record, and the node and project filters. Hover, focus, and the selected row per pane live in `src/ui/store.ts`.

- `src/demo`: the in-memory Gateway for demo mode and tests.
- `tests/browser`: the browser tests and the expected screens.

## Annotation (toolbar pin tool)

The SPA includes the laravel-toolbar **Annotation** overlay (pin / comment / overlay), not the stock `agentation` npm package. Footer chrome: the ✎ control toggles annotation mode; the open pin count shows beside it.

### Commander one-shot

When you submit a pin, Orbit web:

1. Persists the annotation in `localStorage` (same toolbar storage prefix).
2. POSTs to same-origin `/__orbit/commander/one-shot`, which the Vite middleware forwards to Commander MCP `create-task` with `kind=one-shot` and `creation_key=annotation:{id}` — the same contract as toolbar `CommanderClient` → `SubmitOneShotTask`.

Environment (dev machine / shell that runs `vp dev`):

```bash
export COMMANDER_URL=https://commander.test          # default
export COMMANDER_MCP_TOKEN=…                         # required for a live task
export VITE_COMMANDER_PROJECT=commander              # project id (toolbar default)
export COMMANDER_CA_PATH=/path/to/herd-ca.pem     # optional; *.test defaults to insecure TLS for Node
# VITE_COMMANDER_ENABLED=0                           # disable one-shot submit
```

Without `COMMANDER_MCP_TOKEN`, the adapter returns a dry-run acknowledgment so the overlay UX still works locally.

## Tests

```bash
bun run test            # everything
bun run test:unit       # pure logic, in Node
bun run test:browser    # the whole app in headless Chromium
```

Both projects run on Vitest, which ships with Vite+. Unit tests sit next to the code as `*.test.ts`. Browser tests mount the whole app at a URL against a fresh demo Gateway, drive it with the keyboard and the mouse, and assert on the screen, the URL, and the requests the demo Gateway received.

`tests/browser/expected/*.txt` hold each screen as text, one block per frame and one line per row. Review a changed screen there, then accept it:

```bash
bun run test:browser -- -u
```

The browser project needs Playwright's Chromium once: `bunx playwright install chromium`.

## Checks

```bash
bun run check
bun run test
bun run build
```
