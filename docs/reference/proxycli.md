---
title: "proxycli"
description: "The optional Orbit extension that collects CLIProxyAPI quota and its model list into shared Valkey, and publishes provider pools at collector.cli-proxy-api.orbit."
covers:
  - apps/gateway/app/{Domain,Infrastructure}/ProxyCli/**
  - apps/gateway/app/{Actions,Data,Http/Requests}/ProxyCli/**
  - apps/gateway/app/Http/Controllers/Api/ProxyCliController.php
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

No Node role holds a cache. Shared Valkey runs as a [Valkey Database server](/reference/database-servers#a-valkey-server) on a Node with the [`database` role](/reference/database-role). The collector and the Gateway log in through a [Redis Database connection](/reference/database-connections) with its own ACL user.

Setup refuses a cache connection that does not fit:

| Condition | Error |
| --- | --- |
| No Database connection has the slug | `proxycli.cache_missing` |
| The connection driver is not `redis` | `proxycli.cache_invalid` |
| The connection names a Node that is not in the fleet or has no active `database` role | `proxycli.cache_unplaced` |

A connection that names no Node, such as an external Redis host, is accepted.

A Valkey server publishes its port on the Node's WireGuard address. The collector Node and the Gateway both connect to it with the host, port, username, and password of the connection.

## Set up

Create the Valkey server and the collector's user, enable the extension, then set up the collector. This example uses a loopback URL with task VMs off; with task VMs enabled, replace it with `ORBIT_TASK_VMS_MODEL_PROXY_ORIGIN`:

```bash
orbit node:role:add db-1 database
orbit database:server:create valkey --node=db-1 --driver=redis
orbit database:create proxycli-cache --server=valkey --pattern='orbit:proxycli:*' --allow=@connection --allow=@string --allow=@keyspace --allow=eval
orbit extension:enable proxycli
orbit proxycli:setup --node=beast --cache-connection=proxycli-cache --cliproxy-url=http://127.0.0.1:8317 --cliproxy-management-key-file=./management.key
```

The collector Node must be an active Linux Node with a WireGuard address. Otherwise setup fails with `proxycli.node_invalid`.

`--cliproxy-url` is the CLIProxyAPI Management API origin, as the collector Node sees it. With task VMs off, use `http://127.0.0.1:8317` when CLIProxyAPI runs on that Node.

When task VMs are enabled (`ORBIT_TASK_VMS_ENABLED`), the URL must equal `ORBIT_TASK_VMS_MODEL_PROXY_ORIGIN` (`task_vms.model_proxy_origin`), the CLIProxyAPI origin used by Pi on the VMs. Use that configured reachable origin, for example `http://10.44.0.3:8317`, rather than copying a collector's old loopback URL. Setup refuses a mismatching URL before saving settings or changing the collector, with HTTP 422 `validation.failed` for `cliproxy_url`. It never silently changes the task VM origin. This check belongs to proxycli setup; it does not change task VM provisioning or scheduling. [Configure task VMs](/reference/compute-drivers#configure-task-vms) owns that origin's format and reachability requirements.

The Gateway stores the management key as a secret setting and passes it to the collector Process. No API response contains it.

Setup can run again. It keeps the read and control tokens, writes the collector script, updates the collector Process in place, and publishes the hostname again. On the same Node, the collector keeps its Process ID rather than being destroyed and created again. The [fleet rollout](/reference/node-provisioning#converge-the-orbit-footprint) and `orbit node:converge` also write a changed script after a Gateway release and restart the collector Process.

A script that already matches stays, and a completed collector keeps running. A legacy collector without a source revision first compares the remote source without changing it; matching source does not require restart. Before publishing changed source, the Gateway records pending source activation on the Process; it clears that intent and records the revision only after activation succeeds. An interruption after publication leaves activation pending, so retry restarts an existing running collector even when the source file is already current. A completed unchanged retry does not restart the collector.

Setup with another `--node` sets up only the new Node and leaves the old Node's collector in place. To move the collector, run `proxycli:teardown` first.

## Follow the cache connection

The collector depends on the configured Database connection, not a permanent copy of its credentials. Changing that connection's host, port, username, or password reconverges the dependent Process environment. The Gateway rebuilds the collector's Redis environment from the current connection and updates the same Process in place. A running collector restarts to use the new values; a stopped collector stays stopped. Its Process ID, configured cache connection, management key, read token, and control token stay the same. No manual teardown or second setup is needed for a connection update.

The environment is a name-to-value map, including for this Gateway-owned systemd Process. The connection-derived keys are `PROXYCLI_CACHE_HOST`, `PROXYCLI_CACHE_PORT`, `PROXYCLI_CACHE_USERNAME`, and `PROXYCLI_CACHE_PASSWORD`; the port is a string value too. Rebuilding these keys preserves the collector's other required environment values. Responses, Activity, errors, and logs do not reveal credentials. [Processes and schedules](/reference/processes-and-schedules#environment-contract) owns the shared representation and redaction contract.

The Gateway reconverges only dependent Processes, not every Process on the Node. An invalid cache connection is refused by the same placement and driver checks as setup. Before accepting a connection update, the Gateway validates both the connection fields and these dependent-use constraints. A validation refusal leaves the stored connection and collector unchanged.

### Connection persistence and retry recovery

After validation, the Gateway persists the connection change before reconverging its dependent Processes. The connection record is the source of truth for rebuilding their environment. A failure in remote convergence does not roll back the connection or restore the old credentials. The caller receives failure, and each failed Process records its lifecycle failure rather than claiming that the collector has refreshed. Other dependents may already have converged; a Database connection update is not an atomic transaction across all runtimes.

Every accepted connection update, including an identical retry with no changed connection fields, rebuilds and checks the environment of its dependents. It must not skip this work merely because the stored connection already matches the request. This repairs a crash after the connection was saved but before any dependent was updated, as well as a failure partway through updating several dependents. Each Process follows the [persisted intent and retry recovery](/reference/processes-and-schedules#persisted-intent-and-retry-recovery) contract: stale or incomplete work reconverges in place, while an unchanged, completed runtime is not restarted.

If a response is lost or the Gateway crashes, inspect the saved connection and dependent Process states, then repeat the identical connection update after fixing the cause. Retry can resume from either the old collector environment or an already saved but unapplied environment; both are rebuilt from the current connection. Do not interpret a saved connection as proof of completion, or overwrite a newer connection change by blindly replaying an older request.

Setup on the same Node can also reconverge the collector with the current connection, preserving its ID and tokens. These retries may repeat an unrecorded restart; they do not promise exactly one remote operation.

## What setup deploys

Setup places these pieces on the collector Node and in Gateway settings.

| Piece | Where | Detail |
| --- | --- | --- |
| Process `cli-proxy-api-collector` | The collector Node | systemd, listens on `127.0.0.1:8787` |
| Collector script | The collector Node | `/var/lib/orbit/proxycli/server.py` |
| Orbit CA leaf and Caddy site | The collector Node | HTTPS on the Node's WireGuard address |
| Private DNS `host-record` | VPN DNS | `collector.cli-proxy-api.orbit` to the Node's WireGuard address |
| Management key, read token, and control token | Gateway settings | Stored as secrets |

The Process runs `/usr/bin/python3 /var/lib/orbit/proxycli/server.py`. systemd does not search `PATH`, so the command names the absolute path. The unit receives the `PROXYCLI_*` values as `Environment=` directives: the CLIProxyAPI URL and management key (`PROXYCLI_MANAGEMENT_KEY`), the read and control tokens, the port, and the Valkey host, port, username, and password. The script uses that management key to read auth-file models. It receives no client API key. [Processes and schedules](/reference/processes-and-schedules#environment-of-a-systemd-process) describes that environment.

While setup holds the collector, `process:destroy` refuses to remove the Process with `process.required_by_proxycli`.

`collector.cli-proxy-api.orbit` is a reserved platform name, so `route:create` and `route:update` refuse it with `route.domain_conflict`. The apex `cli-proxy-api.orbit` is not reserved. Publish the CLIProxyAPI management UI there as a [custom proxy Route](/reference/routes#custom-proxy-routes) to `http://127.0.0.1:8317`.

Before it changes anything, setup checks for a Route on `collector.cli-proxy-api.orbit`. It takes over a custom proxy Route on the collector Node to `http://127.0.0.1:8787` with no Process target: one Caddy reload moves the name to the collector site, and setup then removes the Route. Any other Route on that name fails setup with `proxycli.hostname_taken` (409).

Setup runs the shared [Caddy package step](/reference/node-provisioning#package-sources) before it publishes the Caddy site and updates the collector Process in place. An installed Caddy at or above the floor stays in place. The site retries the loopback collector for up to 5 seconds, so a request that arrives during the restart waits instead of failing.

The `proxycli` provider contributes the collector site to the [Node Caddy build](/reference/caddy-configuration#node-caddy-build) through a [plug-in point](/reference/extensions#plug-in-points). The site renders on the collector Node while setup holds the collector and the Node has the collector certificate.

The provider contributes three more pieces through [plug-in points](/reference/extensions#plug-in-points): the `proxycli` artifact of the [Node footprint](/reference/node-provisioning#converge-the-orbit-footprint), the private DNS record `collector.cli-proxy-api.orbit`, and the reservation of that name. The footprint artifact writes a changed collector script on the collector Node. VPN DNS publishes the record while setup holds the collector. The name stays reserved while the extension is off.

## Collection

The collector is the only process that calls CLIProxyAPI for quota. Every minute it takes the Valkey lock `orbit:proxycli:lock` for up to 120 seconds. When another holder has the lock, it skips the round. It lists the CLIProxyAPI auth files, fetches quota for each account that is due through `POST /v0/management/api-call`, and writes `orbit:proxycli:raw` and `orbit:proxycli:snapshot`. It remembers each auth file's models in `orbit:proxycli:model-files`.

| Rule | Value |
| --- | --- |
| Check interval per account | 5 minutes, or 15 minutes for Claude |
| Disabled account | Never checked |
| Failed check | Waits 1 hour, doubling per failure up to 1 day. A longer `Retry-After` wins. |
| Interrupted check | Waits 1 hour |

Each account's next check is stored in Valkey, so a restart or an account toggle does not reset the schedule. `collected_at` is the time of the last snapshot write. `checked_at` and `next_check_at` belong to each account.

The collector reads Claude, Codex, Grok, Kimi, and Antigravity windows from CLIProxyAPI's wrapped response. It names common windows by duration, such as `7d` and `5h`. It sorts by the last character of the label: labels that end in `d` or `w` first, then `h`, `m`, or `s`, then all others, such as `Monthly credits`. A window the provider did not return is absent. The collector never reports a missing window as zero use, and never labels a window Primary or Secondary. The collector's own endpoints leave out a window whose reset time has passed. Gateway reads still show it until the next check.

When Grok returns no percentage, the collector calls Grok's billing RPC through CLIProxyAPI. It accepts zero use only from a complete response with an active weekly or monthly period, as [CodexBar](https://github.com/steipete/CodexBar/pull/3325) does.

## Collector endpoints

The collector serves these paths on `https://collector.cli-proxy-api.orbit`. The read token and the control token are bearer tokens in Gateway settings.

Account updates trust only the Orbit CA at `ORBIT_HOME/ca/root.pem`. The Gateway checks that CA in place and sends through Laravel's HTTP client with the system CA directory disabled and a 10-second overall deadline. It does not copy the CA to a temporary file.

| Request | Token | Result |
| --- | --- | --- |
| `GET /health` | None | `{"ok": true}` |
| `GET /v1/quota-stats` | Read | The LLM Proxy quota-stats form of the snapshot, for CodexBar |
| `GET /v1/providers` | Read | Provider pools |
| `GET /api/v1/usage` | Read | The CodexBar plugin snapshot, in camelCase, with Grok as `xai` |
| `GET /api/v1/providers/{provider}` | Read | One provider of that snapshot |
| `PUT /api/v1/providers/{provider}/accounts/{account}` | Control | Sets `disabled` on one account and returns the CodexBar snapshot |
| `PATCH /v1/accounts/{account}` | Control | Sets `disabled` on one account, for the Gateway |

Every read uses the Valkey snapshot and never calls CLIProxyAPI. An account toggle reads `GET /v0/management/auth-files` to find the account's file, sends `PATCH /v0/management/auth-files/status`, then compiles the pools again from the cached snapshot. It fetches no quota. An account without a file returns 404. The CLIProxyAPI management key is not a CodexBar credential.

## Clients

The Gateway reads the snapshot from Valkey through the cache connection. `proxycli:list`, `proxycli:show`, `proxycli:models`, and the Quota pages use it. A read or toggle before setup, or after teardown, fails with `proxycli.disabled` (409).

`proxycli:update` checks that the account is in the snapshot, or fails with `resource.not_found`. The Gateway then sends `PATCH https://{node-wireguard-ip}:443/v1/accounts/{account}` with `Host: collector.cli-proxy-api.orbit`, Orbit CA verification, and the control token. The account ID must match `[A-Za-z0-9._-]+`. The Gateway then writes the snapshot again with the new state. That write sets `collected_at` to the toggle time and drops each account's `checked_at` and `next_check_at` until the next collection round. The schedule itself is unchanged. When the collector refuses or cannot be reached, the call fails with `proxycli.upstream_failed` (502).

The web app shows the Quota section while the `proxycli` extension is enabled, and reads it every 60 seconds. It shows provider quota when the collector is configured, whether or not the `tasks` extension is enabled. When the collector is not configured, the page names what is missing. A provider page lists the accounts, the remaining quota and reset time of each window, and the controls to enable or disable an account.

`proxycli:status` reports whether the collector is set up, its hostname, Node, cache connection, and `collected_at`.

## Models

`GET /api/v1/proxycli/models` (`proxycli:models`) lists the models CLIProxyAPI offers. Each item is `{id, provider}`. The route reads that list from the collector snapshot and does not call CLIProxyAPI.

On each poll, for every auth file from `GET /v0/management/auth-files`, the collector reads `GET /v0/management/auth-files/models?name=` that file's name. It sends the management key as `Authorization: Bearer`. It does not call `GET /v1/models`. That route checks a client API key, and the management key is valid only for `/v0/management`. When that request fails, the collector keeps the models it stored for that auth file on the previous poll. A file that answers replaces only its own models. A file the auth list does not include drops out of the union.

A `models` entry always has `id`. It includes `owned_by`, `display_name`, and `type` only when the registry set them. The collector stores one `{id, provider}` per id. It does not store `display_name` or `type`. A repeated id keeps the provider from the first auth file.

When `owned_by` is present, the collector maps it with the table below. When `owned_by` is absent, it maps the auth file's `provider` with that same table. The management list sets that file's `provider` and `type` to one value. The entry's `type` is not a provider, so the collector does not map it. A model with neither `owned_by` nor an auth-file provider is omitted.

`provider` is the ProxyCli provider that serves the model:

| `owned_by` | `provider` |
| --- | --- |
| `openai` | `codex` |
| `anthropic` | `claude` |
| `xai` | `grok` |
| `moonshot` | `kimi` |
| `google` | `google`. No ProxyCli provider serves it |
| `meta` | `meta`. No ProxyCli provider serves it |
| Any other value | That same value |

`antigravity` keeps its name, and it is a ProxyCli provider. The route refuses with `proxycli.disabled` (409) while the collector is not set up. A snapshot that has no models returns an empty list. A collector deployed before this read has no models until `proxycli:setup` runs again and the next poll stores them.

## Check the collector

After setup, confirm that exactly one collector polls:

| Check | Expected result |
| --- | --- |
| `orbit process:list --node=<node>` | One Process `cli-proxy-api-collector` runs. |
| `orbit proxycli:status` | `enabled` is true and `collected_at` has a time. |
| `GET https://collector.cli-proxy-api.orbit/v1/quota-stats` with the read token | Quota groups from the snapshot. |
| The CLIProxyAPI access log | Quota `api-call` requests arrive only at the collector's schedule, not on a page refresh or an account toggle. |

## Teardown

Tear down the collector when collection should stop for the whole fleet.

```bash
orbit proxycli:teardown
```

Teardown removes the collector Process and script, withdraws the Caddy site and its certificate, and publishes private DNS without the collector name. It deletes the stored management key and the read and control tokens. A later setup needs the key file again and gives CodexBar a new read token. Valkey data and the Redis connection stay. Teardown does not change the extension switch.

Teardown refuses with HTTP 409 and changes nothing while it would strand Tasks:

- `extension.required` while the `tasks` extension is enabled. Tasks depends on `proxycli`, because Pi on a task VM reaches models only through this CLIProxyAPI. `error.details.required_by` is `tasks`.
- `proxycli.keys_outstanding` while a task VM holds a model key. Only the management key can revoke that key. `error.details.task_vms` is the number of such task VMs. Run teardown after they are destroyed.

Teardown holds the model key lock `orbit:compute:model-keys` from these checks until it has removed the collector and deleted the keys. Task VMs take the same lock to issue or revoke a key, so no task VM gets a key during a teardown. When a key operation holds the lock for more than 60 seconds, teardown refuses with `proxycli.keys_busy` and changes nothing. Run it again.

`extension:enable tasks` refuses with `proxycli.disabled` (409) while no management key is stored: before the first setup or after a teardown. A task VM stores its key before CLIProxyAPI registers it, so Tasks must not start without a key that can revoke it. Task VM preparation checks the same thing under the model key lock before it stores a key, and refuses with `task_vm.model_key_failed` (409).

Task VM code reaches CLIProxyAPI only through the core interface `App\Domain\TaskVms\ModelKeyIssuer`. This extension binds it to `ProxyCliModelKeyIssuer`, which holds the management key and the anchor key. See [Configure task VMs](/reference/compute-drivers#configure-task-vms).

## Errors

These codes come from the Gateway on setup, teardown, reads, and toggles.

| Code | Status | When |
| --- | --- | --- |
| `extension.disabled` | 409 | The `proxycli` extension is disabled. |
| `proxycli.disabled` | 409 | A read, the model list, a toggle, or `extension:enable tasks` runs while the collector is not set up. |
| `validation.failed` | 422 | Invalid setup input, including a `cliproxy_url` that differs from `task_vms.model_proxy_origin` while task VMs are enabled. |
| `proxycli.cache_missing` | 422 | No Database connection has the cache slug. |
| `proxycli.cache_invalid` | 422 | The cache connection is not Redis. |
| `proxycli.cache_unplaced` | 422 | The cache connection's Node is not in the fleet or has no active `database` role. |
| `proxycli.node_invalid` | 422 | The collector Node is missing, inactive, not Linux, or has no WireGuard address. |
| `proxycli.source_publication_failed` | 422 | Setup could not write the collector script on the Node. |
| `proxycli.certificate_publication_failed` | 422 | Setup could not place the Orbit CA leaf on the Node. |
| `proxycli.certificate_read_failed` | 422 | The Gateway could not read the certificate it issued. |
| `proxycli.hostname_taken` | 409 | A Route that setup cannot take over holds `collector.cli-proxy-api.orbit`. Setup changes nothing. |
| `proxycli.caddy_publication_failed` | 422 | Setup could not install Caddy or build the Node's Caddy configuration. |
| `proxycli.upstream_failed` | 502 | The collector refused or did not answer an account toggle. |
| `extension.required` | 409 | Teardown runs while the `tasks` extension is enabled. Teardown changes nothing. |
| `proxycli.keys_outstanding` | 409 | Teardown runs while a task VM holds a model key. Teardown changes nothing. |
| `proxycli.keys_busy` | 409 | A model key operation holds the lock for more than 60 seconds. Teardown changes nothing. Run it again. |
| `resource.not_found` | 404 | The provider or account is not in the snapshot. |

## Why it works this way

These reasons explain the design. Check them before you propose a change.

### An extension, not a Node role

Quota collection is optional fleet infrastructure, not a capability of one Node. A `proxycli` or `valkey` role would split cache placement from the other database Processes on the `database` role. So the extension places one Process and reuses a registered Redis connection.

### One collector, one snapshot

A refresh of the web page or CodexBar must never start an upstream poll. A second polling loop in the Gateway would call CLIProxyAPI on every refresh. So one collector polls under a Valkey lock, and every client reads the snapshot. `proxycli:models` reads the model list from that same snapshot.

### A reserved name, not a Route

If a custom proxy Route served the collector, anyone who changed or destroyed that Route would change the CodexBar endpoint outside the extension lifecycle. So the Gateway reserves `collector.cli-proxy-api.orbit` and publishes it itself. The collector name sits under `cli-proxy-api.orbit`, the name of the management service it reports on, and the apex stays free for the management Route.

### Missing quota is not zero

A window the provider did not return is unknown. Showing it as zero use would tell the operator that quota is free when it may be exhausted. So a missing window is absent, and the collector names windows by duration, as the CLIProxyAPI management UI does.

## Related

- [`proxycli` commands](/cli/proxycli)
- [`extension`](/cli/extension)
- [Database role](/reference/database-role)
- [Private DNS](/reference/private-dns)
