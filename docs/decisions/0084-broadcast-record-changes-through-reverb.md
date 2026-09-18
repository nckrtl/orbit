---
title: "ADR 0084: Broadcast record changes through Reverb"
sidebarTitle: "0084 Broadcast record changes through Reverb"
description: "Proposed. The Gateway broadcasts every record change synchronously on one private Reverb channel; Reverb deploys as a plain Orbit app; HTTP stays the contract for every action, events only invalidate or refresh."
---

# ADR 0084: Broadcast record changes through Reverb

The Gateway broadcasts one envelope, `{ type, id, at, data }`, for every record it changes, synchronously and on one private channel named `orbit`. A client reaches that channel through Laravel Reverb, deployed at `wss://reverb.orbit` as an ordinary Orbit-managed App instance, not as infrastructure baked into the Gateway. A client discovers the connection through `GET /api/v1/realtime` and authorizes as the same active WireGuard peer identity the rest of the API already requires. HTTP remains the one contract for every action; events exist only so a connected client can invalidate or refresh what it already fetched over HTTP.

## Status

Proposed.

## Context

`orbit top` ([ADR 0085](/decisions/0085-build-orbit-top-as-a-thin-tui-client)) and other realtime CLI views need the Gateway to push change events instead of polling every list on a fixed tick. Polling every family (Node, App, AppInstance, Process, Schedule, Database connection, firewall rule, Route, deploy step) at a rate fast enough to feel live would multiply Gateway load with the number of connected screens, and would still lag between ticks.

The Gateway already has a "no queues" rule: every action executes fully inside its own HTTP request, and nothing defers work to a worker process. A broadcast mechanism that fit the rest of the product had to honor that rule rather than introduce the first queued Gateway work.

Reverb needs a WebSocket server (the Pusher protocol) that a CLI client and the Gateway's own `reverb` broadcaster can both reach. Nothing in Orbit's architecture makes the Gateway itself capable of holding open WebSocket connections while also serving ordinary HTTP API requests, and folding a socket server into the Gateway process would make Reverb Orbit-specific infrastructure that every deployment carries whether or not it uses realtime.

Orbit already has a way to run an arbitrary Laravel app on a managed Node ([ADR 0072](/decisions/0072-add-and-remove-nodes-without-changing-the-machine) for Node management, and the App/AppInstance/Process/Route primitives it operates through today) and a way to expose a Node-local service that needs a WebSocket upgrade, which an ordinary App Route cannot admit ([ADR 0080](/decisions/0080-add-node-owned-custom-proxy-routes)).

## Decision

- Reverb must run as a plain Laravel app with no Orbit-specific code, no database, and no knowledge of Apps, App instances, Routes, or the CLI. Orbit deploys it exactly the way it deploys any customer app: as an App instance on a Node, with a `reverb:start` Process, following [`docs/solutions/realtime-reverb.mdx`](/solutions/realtime-reverb).
- The Gateway and the Reverb app must agree on one Reverb app identity through `REVERB_APP_ID`, `REVERB_APP_KEY`, and `REVERB_APP_SECRET` set identically on both sides. Channel authorization decisions stay on the Gateway at `/broadcasting/auth`; the Reverb app trusts any client that presents a valid signed request for its one configured app.
- The public hostname is `wss://reverb.orbit`, reached through a [node-owned custom proxy Route](/decisions/0080-add-node-owned-custom-proxy-routes), the one Route kind that admits a WebSocket upgrade today. The App instance's own generated App Route stays inert; it exists only because every active App instance requires one.
- The Gateway must broadcast through `App\Domain\Broadcasting\RecordBroadcast`, a `ShouldBroadcastNow` event, so every broadcast runs synchronously inside the same request that changed the record. The Gateway must not queue a broadcast job.
- Every event must broadcast on one private channel, `orbit`, defined in `routes/channels.php`. Any Gateway API caller that is an active WireGuard peer Node may subscribe; the channel does not scope events per Node.
- The envelope must be exactly `{ type, id, at, data }`. `type` is `<family>.<verb>` and doubles as the broadcast name. `data` is the same Spatie Data object shape the record's list or show endpoint already returns, so a client never learns a new response shape to consume an event.
- A broadcast failure (a Reverb outage, for example) must not fail the request that changed the record. The Gateway must log a warning and continue; a client cannot tell from an API response whether its change also broadcast.
- A client must discover the connection at `GET /api/v1/realtime`, which requires the same active WireGuard peer identity as the rest of the API and returns `{ url, key, channel }`, with `url` and `key` as `null` when `BROADCAST_CONNECTION` is not `reverb` or Reverb is not fully configured. `channel` is always `"orbit"`.
- HTTP stays the one contract for every action. A realtime event never carries an action a client can perform by itself; it exists only to tell an already-connected client that a record it fetched over HTTP has changed, so the client refetches or patches its own cached copy.

## Rejected alternatives

- Run Reverb inside the Gateway monorepo, as a service the Gateway process itself hosts: rejected because it would make Reverb Orbit-specific infrastructure baked into the Gateway, instead of a plain, independently deployable app any Orbit installation can run, replace, or omit.
- Queue broadcast jobs and dispatch them asynchronously: rejected because the Gateway has a standing no-queues rule, and a queued broadcast arrives to a client after the HTTP response that caused it, or after a second change to the same record, producing an event that looks newer than it is.
- Server-Sent Events (SSE) from the Gateway itself instead of a separate WebSocket server: rejected because it would hold long-lived connections open on the same process that serves every other Gateway HTTP request, and would still need the Gateway to fan events out to every connected client itself rather than delegating that to a purpose-built broadcast server.
- Polling only, with no realtime channel: rejected because a screen such as `orbit top` needs to feel live across every family at once; polling fast enough to feel live does not scale with the number of connected clients, and polling on a slow tick does not feel live.

## Consequences

- A connected client (the CLI's `realtime:tail`, `realtime:show`, or `orbit top`) sees every record change across every covered family as it happens, without adding load per poll.
- Reverb is deployed, updated, and observed with Orbit's own App instance, Process, and Route tooling, the same as any customer app; it needs no separate infrastructure story.
- `node.status` and `instance.status` do not broadcast yet, even though the enum-shaped catalogue has room for them: a Node's own recorded status only changes as a side effect of provisioning, removal, or a role change, each already covered by `node.created`/`updated`/`deleted`; hibernation, which tracks an AppInstance's dev runtime as awake or asleep, stores that state in a marker store outside the AppInstance and Process rows, so the Gateway has no record field to broadcast when it flips.
- `process.status` carries the Process's own persisted lifecycle status and desired state, not the live runtime string its show endpoint also reports; that string is polled at read time and is not recorded anywhere, so a change in it alone cannot be broadcast.
- Orbit has no App-instance-owned Route kind that admits a WebSocket upgrade yet. Reverb's own generated App Route carries no realtime traffic; the working setup depends on a Node-owned custom proxy Route pointed at the same Node, which means Reverb cannot move to a different Node without also moving that Route.
- This decision covers one Reverb app instance behind one custom proxy Route. It does not cover scaling Reverb across multiple Nodes, Redis-backed horizontal scaling, or rotating `REVERB_APP_SECRET` without downtime.
- A client cannot distinguish "my change did not broadcast because Reverb is down" from "my change did not broadcast because nothing changed"; the API response is still the only proof that the change itself succeeded.

## Affects

- Components: apps/gateway, apps/cli, packages/php-sdk, apps/docs
- ADRs: builds on [ADR 0080](/decisions/0080-add-node-owned-custom-proxy-routes) for the WebSocket-capable Route Reverb runs behind; depended on by [ADR 0085](/decisions/0085-build-orbit-top-as-a-thin-tui-client); its deployment decision is amended by [ADR 0087](/decisions/0087-run-reverb-through-a-websocket-role)
- Detail: [Realtime events](/reference/events), [Realtime events with Reverb](/solutions/realtime-reverb), [`realtime`](/cli/realtime)
- Verify: Gateway broadcasting tests for `RecordBroadcast`'s envelope and channel, `routes/channels.php` authorization, `RealtimeConfigController` and `RealtimeAuthController`; CLI `realtime:show` and `realtime:tail` contract tests
