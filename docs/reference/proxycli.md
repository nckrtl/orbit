---
title: "proxycli"
description: "The optional Orbit extension that collects CLIProxyAPI quota into shared Valkey and publishes provider pools at collector.proxycli.orbit."
---

# proxycli

This page tells an operator how the optional `proxycli` extension collects CLIProxyAPI account quota, stores one snapshot in shared Valkey, and exposes provider pools to the Orbit web app and CodexBar. [ADR 0104](/decisions/0104-own-cliproxyapi-quota-through-the-proxycli-extension) records the extension boundary. [ADR 0109](/decisions/0109-publish-the-proxycli-collector-on-a-subdomain) owns the collector hostname. [Cut over proxy-quota collectors](/solutions/cutover-proxycli) owns the migration from hand-rolled Processes.

## What the extension owns

`proxycli` is a Gateway-owned fleet feature. Enabling the local CLI extension reveals the `proxycli:*` family. Enabling the fleet feature deploys one collector Process and publishes `https://collector.proxycli.orbit`. Disabling the fleet feature stops that Process, withdraws the hostname, and hides the web quota UI.

The collector is the only process that calls CLIProxyAPI for quota. The web app, the Gateway API, and CodexBar read the Valkey snapshot. A refresh does not start a second poll. An account toggle sends `PATCH https://{node-wireguard-ip}:443/v1/accounts/{id}` to the collector site on its Node, with `Host: collector.proxycli.orbit`, Orbit CA verification, the account's `auth_index`, and the collector control token. The Gateway then recompiles pools from the cached snapshot. It does not call CLIProxyAPI directly.

## Valkey placement

RoleRegistry has no `valkey` or `redis` role. Shared cache lives on the [`database` role](/reference/database-role): a Node-targeted Docker Process running Valkey or Redis, registered as a [Redis Database connection](/reference/database-connections).

Enable fails closed when any of these are true:

| Condition | Error |
| --- | --- |
| The named Database connection does not exist | `proxycli.cache_missing` |
| The connection driver is not `redis` | `proxycli.cache_invalid` |
| The connection names a fleet Node that has no active `database` role | `proxycli.cache_unplaced` |

An external Redis host that is not a fleet Node is accepted. A connection that names a Node must place that cache on a `database` Node so Doctor and backups stay with the other database Processes.

Bind Valkey on the Node WireGuard address when the Gateway and the collector Process run on different Nodes. A loopback-only bind is enough only when the collector and the Gateway share that Node.

## Enable

Place Valkey, register it, then enable the local commands and the fleet feature:

```bash
orbit node:role:add db-1 database
orbit process:create valkey --node=db-1 --runtime=docker --image=valkey/valkey:8 --command=valkey-server --volume=valkey-data:/data --publish=10.44.0.20:6379:6379
orbit database:create valkey --driver=redis --node=db-1 --host=10.44.0.20 --port=6379
orbit extension:enable proxycli
orbit proxycli:enable --node=beast --cache-connection=valkey --cliproxy-url=http://127.0.0.1:8317 --cliproxy-management-key-file=./management.key
```

`cliproxy-url` is the CLIProxyAPI Management API origin. The collector Process uses that URL from the chosen Node, so `http://127.0.0.1:8317` is correct when CLIProxyAPI already listens on that Node. The management key stays on the Gateway and in the Process environment. The API never returns it.

Enable is idempotent. A second enable on the same Node and cache connection converges the Process and `collector.proxycli.orbit` again. It does not publish or reclaim the management dashboard at `cli-proxy-api.orbit`.

## What enable deploys

Enable places these four pieces on the chosen Node and in Gateway settings. The collector Process is the only one that talks to CLIProxyAPI.

| Piece | Owner | Bind |
| --- | --- | --- |
| Node Process `cli-proxy-api-collector` | The chosen Node | `127.0.0.1:8787` |
| Orbit CA leaf and Caddy site | The chosen Node | HTTPS on the Node WireGuard address |
| Private DNS `host-record` | VPN DNS | `collector.proxycli.orbit` → the Node WireGuard address |
| Read token and control token | Gateway settings | Server-side only |

`collector.proxycli.orbit` is a reserved platform name beside `gateway.orbit`, `metrics.orbit`, `reverb.orbit`, and `analytics.orbit`. A Route cannot own it. Publish CLIProxyAPI management at `cli-proxy-api.orbit` as a custom proxy Route to a loopback upstream such as `http://127.0.0.1:8317`. [Custom proxy Routes](/reference/routes#custom-proxy-routes) owns that Route kind.

The Process command is `/usr/bin/python3 /var/lib/orbit/proxycli/server.py`. systemd does not search an operator `PATH`, so a bare `python3` does not start. Enable persists `PROXYCLI_*` on the Process specification and the unit receives those values as `Environment=` directives. The map includes the CLIProxyAPI URL and management key, the CodexBar read and control tokens, the loopback port, and the Valkey host, port, username, and password. [ADR 0108](/decisions/0108-persist-managed-environment-on-systemd-processes) owns that projection. HTTP `process:create` still accepts environment only for Docker.

The management server is a separate Node Process named `cli-proxy-api`, listening on port 8317. Its dashboard is `/management.html` on `cli-proxy-api.orbit`. The collector keeps `collector.proxycli.orbit` for existing clients. The extension slug, API paths, and cache keys remain `proxycli`. Enable retires the old `proxycli` Process name before starting the renamed collector.

The collector takes a Valkey lock, lists CLIProxyAPI auth files, fetches each account's quota through `POST /v0/management/api-call`, writes the raw snapshot and compiled pools, and sleeps. It honors `Retry-After`, backs off a failing account, and skips a fetch when another poll already holds the lock. HTTP reads, including authenticated `GET /v1/quota-stats`, load that snapshot through one RESP stream and never fetch upstream.

## Collection intervals

The collector checks its schedule every minute. It fetches each enabled account at most every five minutes, or every fifteen minutes for Claude. Disabled accounts are skipped. Each account's next check is persisted in Valkey, including across restarts and account controls. Failed checks wait at least one hour, with exponential backoff capped at one day; a longer provider `Retry-After` still wins. An interrupted request waits one hour before retrying.

The web page reads the cache every ten seconds. It shows all reported provider windows, remaining percentages, and collection errors on the overview. `collected_at` is the snapshot compilation time; individual `checked_at` and `next_check_at` values track quota retrieval. A cache refresh does not reset the upstream schedule.

The Python runtime decodes CLIProxyAPI's wrapped status, headers, and JSON-string body. It supports Claude, Codex, Grok, Kimi, and Antigravity windows. Missing quota is reported as unavailable, never assumed to mean zero use. If Grok omits its JSON percentage, the collector uses Grok’s billing RPC through CLIProxyAPI. It accepts zero only from a complete response with a recognized active period, matching [CodexBar’s validated-zero fix](https://github.com/steipete/CodexBar/pull/3325). The text encoding preserves protobuf bytes through CLIProxyAPI’s JSON response.

## Disable

Disable the fleet feature when you want collection and the Quota UI to stop. The local CLI extension can stay enabled if you still need the commands later.

```bash
orbit proxycli:disable
orbit extension:disable proxycli
```

`proxycli:disable` stops and removes the Process, withdraws the Caddy site, certificate, and DNS record, and hides the web quota UI. `extension:disable proxycli` also calls that fleet disable when the CLI can reach the Gateway, then hides the local commands. Valkey data and the Redis connection stay until the operator removes them.

## Clients

The Orbit web app shows a Quota section while the fleet feature is enabled. The overview lists each provider pool. A provider page lists accounts, window remaining, reset times, and enable or disable controls. Window titles are duration labels in management.html#/quota order: the longer window first (`7d` then `5h`). A window the provider omitted is absent. The UI never renders a missing window as zero and never labels a window Primary or Secondary.

CodexBar uses the LLM Proxy quota-stats contract at `https://collector.proxycli.orbit/v1/quota-stats` with the read token as a bearer token. The custom CodexBar plugins also use cache-only `GET /api/v1/usage` and `GET /api/v1/providers/{provider}` on this collector. Their existing camelCase snapshot contract is preserved, including the `xai` alias for Grok. Native account control uses `PUT /api/v1/providers/{provider}/accounts/{account}` with the distinct control token. The collector compiles a snapshot every minute. CodexBar rejects snapshots older than three minutes; this does not increase quota polling. Account control at `https://collector.proxycli.orbit` uses the control token. The CLIProxyAPI management key is not a CodexBar credential.

`orbit proxycli:status` reports whether the fleet feature is enabled, which Node and cache connection it uses, and when the snapshot was last written. `orbit proxycli:list` and `orbit proxycli:show` read the same snapshot. `orbit proxycli:update` toggles one account.

## Errors

These codes appear on enable, disable, reads, and the CLI family. Placement failures stay 422. A disabled fleet feature stays 409.

| Code | When |
| --- | --- |
| `proxycli.cache_missing` | Enable names no Redis Database connection. |
| `proxycli.cache_invalid` | The named connection is not Redis. |
| `proxycli.cache_unplaced` | The connection's Node has no active `database` role. |
| `proxycli.disabled` | A read or toggle runs while the fleet feature is disabled. |
| `proxycli.node_invalid` | The collector Node is missing, inactive, or has no WireGuard address. |
| `proxycli.source_publication_failed` | Enable could not install the collector script on the Node. |
| `proxycli.certificate_publication_failed` | Enable could not publish the Orbit CA leaf on the Node. |
| `proxycli.caddy_publication_failed` | Enable could not install the `collector.proxycli.orbit` Caddy site. |
| `extension.disabled` | A `proxycli:*` CLI command runs before `extension:enable proxycli`. |
| `extension.unknown` | The slug is not a known extension. |

## Related

- [`proxycli` commands](/cli/proxycli)
- [`extension`](/cli/extension)
- [Database role](/reference/database-role)
- [Database connections](/reference/database-connections)
- [Private DNS](/reference/private-dns)
- [Cut over proxy-quota collectors](/solutions/cutover-proxycli)
