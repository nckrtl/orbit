---
title: "ADR 0154: Recover the Gateway agent view without a membership change"
sidebarTitle: "0154 Recover the Gateway agent view"
description: "Proposed. The agent view subscriber joins a Node's channel again when agent events arrive without a complete snapshot or the agent's sequence goes back. The agent sends a complete snapshot every 60 seconds, refuses to run twice on one Node, pings Reverb, and bounds each join."
---

# ADR 0154: Recover the Gateway agent view without a membership change

The agent view subscriber asks an agent for a complete snapshot by leaving and joining that Node's channel again, when the agent's events arrive without a complete snapshot or its sequence goes back. The agent also sends a complete snapshot every 60 seconds, refuses to start a second time on one Node, and gives up a connection that Reverb stopped answering. A lost or corrupted view now heals within seconds instead of staying `missing` until the agent restarts.

## Status

Proposed.

This amends [ADR 0148](/decisions/0148-keep-a-gateway-view-of-node-agent-state) and [ADR 0129](/decisions/0129-publish-node-presence-and-process-state-on-per-node-presence-channels). ADR 0148 kept Node agents unchanged and assumed that every connection makes each agent send a snapshot. ADR 0129 sends a snapshot only when the agent joins and when a new member joins.

## Context

On 2026-09-25 Doctor reported `node.agent_view_stale` with `missing` for one production Node for about two hours. Its agent was connected and sent heartbeats, and the subscriber was connected. Restarting the agent fixed the view at once.

A test build of the agent had briefly run on that Node as a second agent process. An Incus reproduction showed that this leaves the view `missing`. The second process joined the channel as the same member, `agent.{id}`. Reverb announces a presence member only when its first connection joins and its last connection leaves, so neither the join nor the exit of the second process produced a member event. Both processes published into the channel. The second one started its `sequence` at 1, so the subscriber took each of its events as an agent restart and discarded the Node's state. After the second process exited, the real agent sent only heartbeats. No member joined, so no agent sent a snapshot, and the view stayed `missing`.

The same rule leaves any lost snapshot unrepaired: a snapshot is sent only for a join. The reproduction also showed a slower problem. After several Reverb drops, every agent waited about 30 seconds before it reconnected, because it never reset its backoff, even after a long healthy connection. The agent also had no timeout on its Gateway requests, its WebSocket handshake, or a connection that Reverb stopped answering.

Reverb client events carry the sender's member ID but no socket ID, and presence events count members, not connections. The subscriber therefore cannot tell two connections of one member apart.

## Decision

The subscriber repairs a view without waiting for a member to join, and the agent stops the two causes it controls: a second process and a dead connection.

### The subscriber asks for a snapshot

- A Node's channel owes a snapshot when agent events arrive without a complete snapshot, or when the agent's `sequence` goes back without a membership change. A snapshot that completes after the sequence went back does not settle it, because it can come from the second process.
- When a channel has owed a snapshot for 5 seconds, the subscriber sends `pusher:unsubscribe` and then `pusher:subscribe` for that channel on its existing connection. Reverb announces the subscriber as a new member, so every agent connection sends a complete snapshot. The subscriber keeps what it knows until that snapshot arrives.
- It asks each channel at most once every 5 seconds. It still never sends a client event and never acts on a report.

### The agent heals and guards its own state

- The agent sends a complete snapshot at least every 60 seconds. Every other snapshot restarts that period.
- The agent holds an exclusive lock on `/etc/orbit/agent` while it runs. A second agent on the same Node exits with an error and publishes nothing.
- After 15 seconds without a message from Reverb, the agent sends `pusher:ping`. When nothing arrives within 10 more seconds, it closes the connection and reconnects.
- Each join, from the TCP connection to `pusher_internal:subscription_succeeded`, must finish within 30 seconds. Each Gateway request must finish within 30 seconds.
- The agent retries with backoff from 2 seconds to 30 seconds. A session that stayed joined for 60 seconds starts the backoff again from 2 seconds. A session that fails sooner keeps backing off.

The agent version is 0.1.2. The subscriber change works with agent 0.1.1: it recovers a missing view, but the fleet keeps the slow reconnect and the unguarded second process until it runs 0.1.2.

## Rejected alternatives

- Track agent connections by socket ID in the subscriber: rejected because Reverb gives neither client events nor presence events a socket ID.
- Send a client event that asks the agent for a snapshot: rejected because agent 0.1.1 would ignore it, and the subscriber would start sending client events, which ADR 0148 rules out. A new membership already makes every agent answer.
- Rely only on the agent's 60-second snapshot: rejected because it needs a fleet upgrade, and the view would stay `missing` for up to a minute.
- Accept a lower `sequence` without starting over: rejected because a real agent restart without a member event, such as a restart after a network loss that Reverb has not noticed yet, would then mix two runs.

## Consequences

- A lost, discarded, or corrupted Node view recovers within about 10 seconds, with agent 0.1.1 or 0.1.2.
- Each request makes every member of that channel see the subscriber leave and join, and the agent sends one extra snapshot, as when a browser opens.
- While a second 0.1.1 agent process runs, its events keep resetting the subscriber's state, so the view stays `missing`. It turns fresh within about 10 seconds after that process exits. Agent 0.1.2 refuses the second process.
- Every agent sends one snapshot a minute and one ping about every 15 seconds while its channel is quiet.

## Affects

- Components: apps/gateway, apps/docs
- ADRs: amends [ADR 0148](/decisions/0148-keep-a-gateway-view-of-node-agent-state) and [ADR 0129](/decisions/0129-publish-node-presence-and-process-state-on-per-node-presence-channels)
- Detail: [Node agent](/reference/node-agent#gateway-view), [Realtime events](/reference/events#node-agent-channels)
- Verify: Gateway tests for the snapshot request; agent tests for the lock and the ping; an Incus proof with a second agent process and repeated Reverb drops against subscriber restarts
