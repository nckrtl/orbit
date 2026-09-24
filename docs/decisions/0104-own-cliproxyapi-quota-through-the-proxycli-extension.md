---
title: "ADR 0104: Own CLIProxyAPI quota through the proxycli extension"
sidebarTitle: "0104 Own CLIProxyAPI quota through the proxycli extension"
description: "Proposed. Orbit owns CLIProxyAPI quota collection as the optional proxycli fleet extension, stores snapshots in shared Valkey on the database role, and publishes proxycli.orbit for CodexBar."
---

# ADR 0104: Own CLIProxyAPI quota through the proxycli extension

Orbit treats CLIProxyAPI quota collection as an optional extension named `proxycli` that the Gateway owns. Enable deploys one collector Process and publishes `https://proxycli.orbit`. Disable stops that Process, withdraws the hostname, and hides the web quota UI. Shared Valkey on a `database` role Node is the only cache; there is no `valkey` or `redis` RoleRegistry role.

## Status

Proposed.

This decision extends [ADR 0069](/decisions/0069-allow-node-process-targets) for the collector Process, [ADR 0070](/decisions/0070-keep-the-database-role-as-a-docker-baseline) for Valkey placement, and [ADR 0080](/decisions/0080-add-node-owned-custom-proxy-routes) for the reserved private hostname shape. It does not add a Node role. [ADR 0109](/decisions/0109-publish-the-proxycli-collector-on-a-subdomain) amends the reserved hostname to `collector.proxycli.orbit` and leaves apex `proxycli.orbit` free for a management Route. [ADR 0148](/decisions/0148-keep-extension-commands-local-and-confirm-the-proxycli-fleet-stop) amends the disable path: `extension:disable proxycli` changes only the local gate, and `proxycli:disable` needs explicit consent.

## Context

Operators already collect CLIProxyAPI account quota with hand-rolled `proxy-quota-*` Processes and a private name such as `proxy-cli-usage.test`. Those collectors poll upstream, pool remaining windows, and serve CodexBar. Refreshing a dashboard or toggling an account must not start a second upstream poll.

Orbit already has two nearby shapes. A singleton Node role such as `analytics` or `websocket` owns always-on fleet infrastructure and a reserved `*.orbit` hostname. A local CLI extension such as `herdr` only reveals commands on one operator machine and does not change the fleet.

Quota collection is optional fleet infrastructure, not a Node capability, and not a local command family alone. Enable must deploy a Process and a hostname. Disable must stop both and hide the web UI. The cache must be the shared Valkey an operator already places as a Docker Process on a `database` Node and registers as a Redis Database connection. RoleRegistry has no cache role; inventing one would split database placement.

`nckrtl/proxy-cli-usage` is not in the public ecosystem. The collector therefore follows the proven CLIProxyAPI Management API and window-classification rules used by management.html#/quota: duration labels such as `7d` and `5h`, longer window first, never Primary or Secondary, and never a missing window shown as zero.

## Decision

- Add `proxycli` as a fleet extension that the Gateway owns. Local CLI `extension:enable proxycli` reveals the `proxycli:*` family. `proxycli:enable` deploys the fleet feature. `proxycli:disable` and `extension:disable proxycli` stop the collector, withdraw publication, and hide the web UI.
- Enable requires an active Linux Node, a CLIProxyAPI Management API URL and key, and a registered Redis Database connection that points at the shared Valkey. If that connection names a fleet Node, the Node must hold an active `database` role. Enable fails closed when the connection is missing, is not Redis, or the named Node lacks `database`.
- Enable creates one Node-targeted systemd Process named `proxycli` on the chosen Node. The Process binds loopback, runs `/usr/bin/python3` plus the Orbit-written collector, and is the only upstream poller. Enable persists `PROXYCLI_*` on the Process specification; [ADR 0108](/decisions/0108-persist-managed-environment-on-systemd-processes) projects that map into the unit. The collector writes raw account snapshots, compiled pools, per-target backoff, and a distributed lock into the shared Valkey. Disable stops and removes that Process.
- The collector reads Valkey through one RESP stream so a large snapshot bulk reply returns. CodexBar `GET /v1/quota-stats` uses that snapshot. The Gateway list and status actions use the PHP Valkey client and do not share that Python read path.
- The Gateway reserves `proxycli.orbit`. It issues an Orbit CA leaf, renders a Caddy site on the Process Node that reverse-proxies HTTPS to the loopback collector, and publishes an exact private DNS `host-record` for the serving Node. A Route cannot own the name. CodexBar reads `https://proxycli.orbit/v1/quota-stats` with a Gateway-generated read token. Account control uses a separate control token. The CLIProxyAPI management key never leaves the Gateway or the collector Process.
- The Orbit web app and the Gateway `proxycli:*` API read only the Valkey snapshot. A UI refresh does not call CLIProxyAPI. An account toggle updates CLIProxyAPI `PATCH /auth-files/status`, then recompiles pools from the cached snapshot without fetching quota.
- Polling keeps one collector through the Valkey lock. Intervals, Retry-After, exponential backoff, and per-account dedup follow the CLIProxyAPI collector patterns. Cutover migrates the cache key space, stops the old `proxy-quota-*` Processes and `proxy-cli-usage.test` site, then enables this extension so exactly one collector remains.

## Rejected alternatives

- A `proxycli` or `valkey` RoleRegistry role: rejected because Valkey is a database server and already belongs on a `database` Node as a Docker Process. A new role would split cache placement and conflict with the closed role set.
- Reuse the Herdr model, where the CLI only reveals commands on one machine: rejected because enable must deploy a Process and a hostname, and disable must stop them for every operator, not only hide commands on one machine.
- A custom proxy Route without a reserved name: rejected because an operator who creates a Route for that name takes or destroys `proxycli.orbit` independently of the extension lifecycle.
- Gateway-side polling in addition to the Node Process: rejected because a second loop would hit CLIProxyAPI on every UI refresh and break the single-collector cutover.
- Showing missing windows as zero or labeling them Primary and Secondary: rejected because management.html#/quota names windows by duration and omits a window the provider did not return.

## Consequences

- One enable, after Valkey exists, gives the fleet a private CodexBar endpoint and a web quota UI.
- The collector unit starts with `/usr/bin/python3` and receives `PROXYCLI_*` from the Process specification. A bare `python3` does not start under systemd.
- Operators must place Valkey themselves on a `database` Node and register it as a Redis connection before enable.
- CodexBar and the web app share one snapshot. Toggling an account is visible after the cache recompile without a quota refetch.
- Hand-rolled `proxy-quota-*` Processes and `proxy-cli-usage.test` become unmanaged leftovers until the operator removes them during cutover.

## Affects

- Components: apps/cli, apps/gateway, packages/php-sdk, apps/docs
- ADRs: extends [ADR 0069](/decisions/0069-allow-node-process-targets), [ADR 0070](/decisions/0070-keep-the-database-role-as-a-docker-baseline), and [ADR 0080](/decisions/0080-add-node-owned-custom-proxy-routes)
- Detail: [proxycli](/reference/proxycli)
- Verify: Gateway enable, fail-closed placement, pool compiler, toggle-from-cache, publication, and CLI extension tests; `composer docs-lint`
