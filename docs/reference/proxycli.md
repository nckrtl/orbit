---
title: "proxycli"
description: "The optional Orbit extension that collects CLIProxyAPI quota into shared Valkey and publishes provider pools at collector.cli-proxy-api.orbit."
covers:
  - apps/gateway/app/{Domain,Infrastructure}/ProxyCli/**
  - apps/gateway/app/{Actions,Data,Http/Requests}/ProxyCli/**
  - apps/gateway/app/Http/Controllers/Api/ProxyCliController.php
  - apps/gateway/app/Infrastructure/Caddy/Build/Sources/ProxyCliCaddySiteSource.php
  - apps/gateway/resources/proxycli/**
  - apps/web/src/pages/Quota.tsx
---

# proxycli

`proxycli` is an optional Gateway extension. It collects CLIProxyAPI account quota into one snapshot in shared Valkey. The Orbit web app, the CLI, MCP, and CodexBar read provider pools from that snapshot.

## Switch and setup

The extension has one switch and one fleet setup. They are separate.

- `orbit extension:enable proxycli` shows the `proxycli:*` commands, MCP tools, API operations, and the Quota section to every client. It deploys nothing. See [`extension`](/cli/extension).
- `orbit proxycli:setup` deploys the collector Process and publishes `https://collector.cli-proxy-api.orbit`.
- `orbit proxycli:teardown` stops the collector and withdraws the hostname.
- `orbit extension:disable proxycli` hides and refuses the extension. It does not tear down the collector.

## Valkey placement

No Node role holds a cache. Shared Valkey runs on the [`database` role](/reference/database-role) as a Node-targeted Docker Process, and a [Redis Database connection](/reference/database-connections) registers it.

Setup refuses a cache connection that does not fit:

| Condition | Error |
| --- | --- |
| No Database connection has the slug | `proxycli.cache_missing` |
| The connection driver is not `redis` | `proxycli.cache_invalid` |
| The connection names a Node that is not in the fleet or has no active `database` role | `proxycli.cache_unplaced` |

A connection that names no Node, such as an external Redis host, is accepted.

Bind Valkey on the `database` Node's WireGuard address. The collector Node and the Gateway both connect to it with the host, port, username, and password of the connection.

## Set up

Place Valkey, register it, enable the extension, then set up the collector:

```bash
orbit node:role:add db-1 database
orbit process:create valkey --node=db-1 --runtime=docker --image=valkey/valkey:8 --command=valkey-server --volume=valkey-data:/data --publish=10.44.0.20:6379:6379
orbit database:create valkey --driver=redis --node=db-1 --host=10.44.0.20 --port=6379
orbit extension:enable proxycli
orbit proxycli:setup --node=beast --cache-connection=valkey --cliproxy-url=http://127.0.0.1:8317 --cliproxy-management-key-file=./management.key
```

The collector Node must be an active Linux Node with a WireGuard address. Otherwise setup fails with `proxycli.node_invalid`.

`--cliproxy-url` is the CLIProxyAPI Management API origin, as the collector Node sees it. Use `http://127.0.0.1:8317` when CLIProxyAPI runs on that Node. The Gateway stores the management key as a secret setting and passes it to the collector Process. No API response contains it.

Setup can run again. It keeps the read and control tokens, writes the collector script, replaces the collector Process, and publishes the hostname again.

## What setup deploys

| Piece | Where | Detail |
| --- | --- | --- |
| Process `cli-proxy-api-collector` | The collector Node | systemd, listens on `127.0.0.1:8787` |
| Collector script | The collector Node | `/var/lib/orbit/proxycli/server.py` |
| Orbit CA leaf and Caddy site | The collector Node | HTTPS on the Node's WireGuard address |
| Private DNS `host-record` | VPN DNS | `collector.cli-proxy-api.orbit` to the Node's WireGuard address |
| Management key, read token, and control token | Gateway settings | Stored as secrets |

The Process runs `/usr/bin/python3 /var/lib/orbit/proxycli/server.py`. systemd does not search `PATH`, so the command names the absolute path. The unit receives the `PROXYCLI_*` values as `Environment=` directives: the CLIProxyAPI URL and management key, the read and control tokens, the port, and the Valkey host, port, username, and password. [Processes and schedules](/reference/app-processes-and-schedules#environment-of-a-systemd-process) describes that environment.

While setup holds the collector, `process:destroy` refuses to remove the Process with `process.required_by_proxycli`.

`collector.cli-proxy-api.orbit` is a reserved platform name, so `route:create` refuses it with `route.domain_conflict`. The apex `cli-proxy-api.orbit` is not reserved. Publish the CLIProxyAPI management UI there as a [custom proxy Route](/reference/routes#custom-proxy-routes) to `http://127.0.0.1:8317`.

Setup publishes the Caddy site before it replaces the collector Process. The site retries the loopback collector for up to 5 seconds, so a request that arrives during the restart waits instead of failing.

## Collection

The collector is the only process that calls CLIProxyAPI for quota. Every minute it takes the Valkey lock `orbit:proxycli:lock` for up to 120 seconds. When another holder has the lock, it skips the round. It lists the CLIProxyAPI auth files, fetches quota for each account that is due through `POST /v0/management/api-call`, and writes `orbit:proxycli:raw` and `orbit:proxycli:snapshot`.

| Rule | Value |
| --- | --- |
| Check interval per account | 5 minutes, or 15 minutes for Claude |
| Disabled account | Never checked |
| Failed check | Waits 1 hour, doubling per failure up to 1 day. A longer `Retry-After` wins. |
| Interrupted check | Waits 1 hour |

Each account's next check is stored in Valkey, so a restart or an account toggle does not reset the schedule. `collected_at` is the time of the last snapshot. `checked_at` and `next_check_at` belong to each account.

The collector reads Claude, Codex, Grok, Kimi, and Antigravity windows from CLIProxyAPI's wrapped response. It names common windows by duration, such as `7d` and `5h`, and sorts day and week windows before hour windows. A window the provider did not return is absent. The collector never reports a missing window as zero use, and never labels a window Primary or Secondary. A window whose reset time has passed drops out of reads until the next check.

When Grok returns no percentage, the collector calls Grok's billing RPC through CLIProxyAPI. It accepts zero use only from a complete response with an active weekly or monthly period, as [CodexBar](https://github.com/steipete/CodexBar/pull/3325) does.

## Collector endpoints

The collector serves these paths on `https://collector.cli-proxy-api.orbit`. The read token and the control token are bearer tokens in Gateway settings.

| Request | Token | Result |
| --- | --- | --- |
| `GET /health` | None | `{"ok": true}` |
| `GET /v1/quota-stats` | Read | The LLM Proxy quota-stats form of the snapshot, for CodexBar |
| `GET /v1/providers` | Read | Provider pools |
| `GET /api/v1/usage` | Read | The CodexBar plugin snapshot, in camelCase, with Grok as `xai` |
| `GET /api/v1/providers/{provider}` | Read | One provider of that snapshot |
| `PUT /api/v1/providers/{provider}/accounts/{account}` | Control | Sets `disabled` on one account and returns the CodexBar snapshot |
| `PATCH /v1/accounts/{account}` | Control | Sets `disabled` on one account, for the Gateway |

Every read uses the Valkey snapshot and never calls CLIProxyAPI. An account toggle sends `PATCH /v0/management/auth-files/status` to CLIProxyAPI, then compiles the pools again from the cached snapshot. It fetches no quota. The CLIProxyAPI management key is not a CodexBar credential.

## Clients

The Gateway reads the snapshot from Valkey through the cache connection. `proxycli:list`, `proxycli:show`, and the Quota pages use it. A read or toggle before setup, or after teardown, fails with `proxycli.disabled` (409).

`proxycli:update` checks that the account is in the snapshot, or fails with `resource.not_found`. The Gateway then sends `PATCH https://{node-wireguard-ip}:443/v1/accounts/{account}` with `Host: collector.cli-proxy-api.orbit`, Orbit CA verification, and the control token. It writes the new state into the snapshot. When the collector refuses or cannot be reached, the call fails with `proxycli.upstream_failed` (502).

The web app shows the Quota section while the `proxycli` extension is enabled, and reads it every 60 seconds. It shows provider pools only when the collector is set up and the `tasks` extension is also enabled, because the page includes token spend from Tasks. Otherwise it names what is missing. A provider page lists the accounts, the remaining quota and reset time of each window, and the controls to enable or disable an account.

`proxycli:status` reports whether the collector is set up, its hostname, Node, cache connection, and `collected_at`.

## Check the collector

After setup, confirm that exactly one collector polls:

| Check | Expected result |
| --- | --- |
| `orbit process:list --node=<node>` | One Process `cli-proxy-api-collector` runs. |
| `orbit proxycli:status` | `enabled` is true and `collected_at` has a time. |
| `GET https://collector.cli-proxy-api.orbit/v1/quota-stats` with the read token | Quota groups from the snapshot. |
| The CLIProxyAPI access log | Quota `api-call` requests arrive only at the collector's schedule, not on a page refresh or an account toggle. |

## Teardown

```bash
orbit proxycli:teardown
```

Teardown removes the collector Process and script, withdraws the Caddy site and its certificate, and publishes private DNS without the collector name. It deletes the stored management key and the read and control tokens. A later setup needs the key file again and gives CodexBar a new read token. Valkey data and the Redis connection stay. Teardown does not change the extension switch.

## Errors

| Code | Status | When |
| --- | --- | --- |
| `extension.disabled` | 409 | The `proxycli` extension is disabled. |
| `proxycli.disabled` | 409 | A read or toggle runs while the collector is not set up. |
| `proxycli.cache_missing` | 422 | No Database connection has the cache slug. |
| `proxycli.cache_invalid` | 422 | The cache connection is not Redis. |
| `proxycli.cache_unplaced` | 422 | The cache connection's Node is not in the fleet or has no active `database` role. |
| `proxycli.node_invalid` | 422 | The collector Node is missing, inactive, not Linux, or has no WireGuard address. |
| `proxycli.source_publication_failed` | 422 | Setup could not write the collector script on the Node. |
| `proxycli.certificate_publication_failed` | 422 | Setup could not place the Orbit CA leaf on the Node. |
| `proxycli.caddy_publication_failed` | 422 | Setup could not install Caddy or build the Node's Caddy configuration. |
| `proxycli.upstream_failed` | 502 | The collector refused or did not answer an account toggle. |
| `resource.not_found` | 404 | The provider or account is not in the snapshot. |

## Why it works this way

These reasons explain the design. Check them before you propose a change.

### An extension, not a Node role

Quota collection is optional fleet infrastructure, not a capability of one Node. A `proxycli` or `valkey` role would split cache placement from the other database Processes on the `database` role. So the extension places one Process and reuses a registered Redis connection.

### One collector, one snapshot

A refresh of the web page or CodexBar must never start an upstream poll. A second polling loop in the Gateway would call CLIProxyAPI on every refresh. So one collector polls under a Valkey lock, and every client reads the snapshot.

### A reserved name, not a Route

If a custom proxy Route served the collector, anyone who changed or destroyed that Route would change the CodexBar endpoint outside the extension lifecycle. So the Gateway reserves `collector.cli-proxy-api.orbit` and publishes it itself. The collector name sits under `cli-proxy-api.orbit`, the name of the management service it reports on, and the apex stays free for the management Route.

### Missing quota is not zero

A window the provider did not return is unknown. Showing it as zero use would tell the operator that quota is free when it may be exhausted. So a missing window is absent, and the collector names windows by duration, as the CLIProxyAPI management UI does.

## Related

- [`proxycli` commands](/cli/proxycli)
- [`extension`](/cli/extension)
- [Database role](/reference/database-role)
- [Private DNS](/reference/private-dns)
