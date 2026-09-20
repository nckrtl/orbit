---
title: "ADR 0087: Run Reverb through a websocket role"
sidebarTitle: "0087 Run Reverb through a websocket role"
description: "Proposed. Reverb runs as a new websocket role that a Node owns, instead of an App instance behind a custom proxy Route, so it never conflicts with the Gateway role and needs one command to set up."
---

# ADR 0087: Run Reverb through a websocket role

The Gateway installs and runs Laravel Reverb itself, as a new singleton, mutable `websocket` node role, instead of deploying it as an App instance behind a node-owned custom proxy Route. `orbit node:role:add <node> websocket` is the one command that makes realtime work end to end: no App, App instance, deploy step, custom proxy Route, or hand-edited Gateway `.env` is involved.

## Status

Proposed.

This amends the deployment decision in [ADR 0084](/decisions/0084-broadcast-record-changes-through-reverb). ADR 0084's channel, envelope, discovery, and no-queues rules are unchanged.

## Context

[ADR 0084](/decisions/0084-broadcast-record-changes-through-reverb) deployed Reverb as an ordinary Orbit-managed App instance on a Node, reached through a Node-owned custom proxy Route ([ADR 0080](/decisions/0080-add-node-owned-custom-proxy-routes)). That plan cannot work on the topology Orbit actually runs: the Gateway role and the `app-prod`/`app-dev` roles conflict (`App\Domain\Nodes\RoleRegistry`), so the only Node available to host the Reverb App instance today is the Gateway's own Node, and the Gateway role cannot combine with `app-prod` there.

Reaching Reverb also took six manual steps: create the App, create the App instance, clone it, register the `reverb:start` deploy step, create the custom proxy Route, and set `BROADCAST_CONNECTION`/`REVERB_APP_ID`/`REVERB_APP_KEY`/`REVERB_APP_SECRET` identically on both the Gateway's `.env` and the App instance's environment. Every one of those steps is a place setup can drift, and the App instance framing (PHP handle, release checkout, deploy steps) exists for customer apps with releases and rollbacks, which a single always-on realtime server does not need.

Orbit already has a role-based shape for a singleton, mutable, node-owned service the Gateway operates directly rather than through the App model: the `metrics` role converges Prometheus and Grafana, issues an Orbit CA leaf, and publishes a reserved private hostname (`App\Infrastructure\Nodes\Roles\MetricsRoleBaseline`, `App\Infrastructure\Metrics\MetricsPublicationManager`). Reverb fits that shape better than it fits the App model: it is fleet infrastructure, not a customer app, and today it must run on the Gateway's own Node.

## Decision

- Add `RoleName::WebSocket` (`websocket`) as a singleton, mutable role, assignable during provisioning, with no role conflicts, so it combines with `gateway` and `vpn` today; moving it to a dedicated Node needs only adding the role to that Node and removing it from the current one.
- `orbit node:role:add <node> websocket` converges a plain, unmodified Laravel app (`https://github.com/nckrtl/orbit-reverb.git`, ref configurable, default `main`) checked out at `/opt/orbit/websocket` by default: the Gateway ensures `git`, `composer`, `caddy`, `php-curl`, and `php-xml` are present, clones or fetches the configured ref, runs `composer install --no-dev`, writes the app's own `.env` (`APP_ENV`, `APP_DEBUG`, a generated `APP_KEY`, and the Reverb credentials), and installs and enables a `orbit-websocket.service` systemd unit running `php artisan reverb:start --host=127.0.0.1 --port=<port>` (default port `8790`) with `Restart=always`. `php-curl` and `php-xml` provide `ext-curl` and `ext-dom` so that Composer platform check succeeds on a fresh Ubuntu 26.04 Node that does not already run the Gateway PHP stack. The same two packages are role prerequisites for the shared `app-dev` and `app-prod` PHP app hosts. The Reverb app itself carries no Orbit-specific code, no database, and no knowledge of Apps, App instances, Routes, or the CLI, matching ADR 0084's requirement.
- The Gateway reserves `reverb.orbit` as a private hostname next to `gateway.orbit` and `metrics.orbit` (`App\Domain\Routes\ReservedPrivateHostname`), issues it an Orbit CA leaf certificate, and renders a Caddy site on the role's own Node that terminates that certificate and reverse-proxies to the local Reverb process; `reverse_proxy` admits a WebSocket upgrade by default. Gateway private DNS answers `reverb.orbit` with that Node's own WireGuard address, the same way a [custom proxy Route](/decisions/0080-add-node-owned-custom-proxy-routes) answers its domain, so moving the role to a different Node repoints the hostname, the certificate, and the Caddy site together. A firewall rule opens 443/tcp on the role's Node to the WireGuard interface, matching the shape other private HTTPS listeners already use.
- The Gateway generates one Reverb application identity (an app id, a public key, and a secret) the first time the role converges, and every converge after that reuses the same stored values. Credentials are stored the way Metrics stores its Grafana credentials: encrypted at rest, never logged, and never returned over the API except the public key, which `GET /api/v1/realtime` already returns under ADR 0084.
- The Gateway's own broadcasting resolves from the active `websocket` role assignment instead of environment configuration: `App\Domain\Broadcasting\RealtimeConnection` returns the connection (host, port, scheme, credentials) when a role is active and `null` otherwise, resolved lazily and memoized for the life of one request. `RecordEventBroadcaster` configures the `reverb` broadcast connection from it before every broadcast; `RealtimeConfigController` and the channel-auth endpoint answer from it. The `BROADCAST_CONNECTION`/`REVERB_*` environment contract from ADR 0084 no longer exists; there is no legacy fallback. The Gateway's outbound HTTPS calls to `reverb.orbit` verify against the Orbit CA, the same as every other Gateway-to-node HTTPS call.
- Removing the role stops and disables the systemd unit, removes the checkout (respecting `purge-data`), the Caddy site, the certificate, the DNS record, and the stored credentials. Removing an unreachable Node's `websocket` role removes only the Gateway-side pieces: the private DNS record and the stored credentials.

## Rejected alternatives

- Keep Reverb as an App instance behind a custom proxy Route, and instead relax the `gateway`/`app-prod` role conflict so both can share one Node: rejected because that conflict protects a real boundary (the Gateway's own PHP-FPM pool and Caddy site against arbitrary customer app-prod releases on the same Node), and relaxing it for Reverb's sake would let any other App-instance-hosted service claim the same exception.
- Keep the custom proxy Route and move Reverb to a second, dedicated Node today: rejected because it adds a mandatory second machine to every Orbit installation that wants realtime, when the existing fleet already has spare capacity on the Gateway's own Node.
- A generic "singleton service" role framework that Metrics and the new role would both implement: rejected as premature generalization; the metrics role's shape is a proven, copyable pattern, and a shared abstraction across a sample of two invites a design nobody has validated yet.
- Keep `BROADCAST_CONNECTION`/`REVERB_*` as an operator-set fallback alongside the new role: rejected because Orbit has no legacy-support requirement, and a working fallback that most installations never exercise is a second, untested path to the same behavior.

## Consequences

- `orbit node:role:add gateway websocket` is the entire realtime setup; there is no App, App instance, deploy step, or custom proxy Route to create or keep in sync.
- Moving the websocket role to a dedicated Node needs only adding the role there and removing it from the Gateway's Node; the hostname, certificate, DNS record, and Caddy site all repoint automatically. Role prerequisites on that Node include `php-curl` and `php-xml`, so `composer install` does not depend on the Gateway PHP stack already being present.
- Reverb still runs as a plain, unmodified Laravel app with no Orbit-specific code, preserving ADR 0084's requirement that it be independently deployable, replaceable, or omitted.
- Every Gateway request that changes a broadcastable record resolves the active `websocket` role assignment once, memoized for that request; a request that neither broadcasts nor reaches a realtime endpoint performs no extra lookup.
- This decision covers one Reverb instance behind one websocket role assignment. It does not cover scaling Reverb across multiple Nodes, Redis-backed horizontal scaling, or rotating the generated Reverb secret without downtime; these gaps carry over unchanged from ADR 0084.

## Affects

- Components: apps/gateway, apps/cli, packages/php-sdk, apps/docs
- ADRs: amends [ADR 0084](/decisions/0084-broadcast-record-changes-through-reverb)'s deployment decision; the [ADR 0080](/decisions/0080-add-node-owned-custom-proxy-routes) custom proxy Route kind is unaffected and remains available for other Node-local services
- Detail: [Realtime events](/reference/events), [Realtime events with Reverb](/solutions/realtime-reverb), [`node`](/cli/node)
- Verify: Gateway role registry, baseline converge/remove/removeUnreachable, publication, and credential tests; `NodeBootstrapPackageCatalog` and role-prerequisite package lists for `websocket`, `app-dev`, and `app-prod`; `RealtimeConnection`, `RecordEventBroadcaster`, `RealtimeConfigController`, and channel-auth tests; CLI `node:role:add`/`node:role:remove` and `realtime:show` contract tests
