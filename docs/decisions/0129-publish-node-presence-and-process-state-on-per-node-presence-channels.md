---
title: "ADR 0129: Publish Node presence and Process state on per-Node presence channels"
sidebarTitle: "0129 Publish agent state on per-Node presence channels"
description: "Proposed. Each Node agent keeps one Reverb connection and publishes client events on its own presence channel. The Gateway signs memberships but never handles the messages. Browsers trust only events from the Node's own agent member."
---

# ADR 0129: Publish Node presence and Process state on per-Node presence channels

Each Node agent keeps one WebSocket connection to the existing Reverb server and joins its own presence channel, `presence-node.{id}`. It publishes heartbeats and Process state as client events on that channel. The web app subscribes to each Node's channel and shows presence and Process state live. The Gateway signs channel memberships, but no agent message passes through it. The Gateway API, the CLI, and `orbit top` keep reading Process state from Prometheus.

## Status

Proposed.

Amended by [ADR 0148](/decisions/0148-keep-a-gateway-view-of-node-agent-state) and [ADR 0151](/decisions/0151-push-task-and-process-usage-changes-over-realtime).

This extends [ADR 0084](/decisions/0084-broadcast-record-changes-through-reverb). Its rules for the channel, the envelope, and queues stay unchanged for Gateway broadcasts. It adds a second kind of realtime traffic: client events that a Node agent from [ADR 0128](/decisions/0128-run-a-visibility-only-agent-on-managed-nodes) publishes. Reverb stays a plain, unmodified Laravel app, as ADR 0084 and [ADR 0087](/decisions/0087-run-reverb-through-a-websocket-role) require.

## Context

Only the web app needs live Node and Process state. The CLI and the API serve inspection, where a 10-second Prometheus delay is fine.

Frequent messages must not cost Gateway requests. An agent that reports over HTTP would send one Gateway request every few seconds from every Node, and more during bursts. A persistent WebSocket carries the same messages without a request per message.

Reverb facts shape the design:

- A Pusher-protocol client never holds the app secret. It asks a trusted server to sign each channel subscription. Whoever holds the secret can sign any membership and publish any event, so the secret must stay on the Gateway.
- Messages never cross from one Reverb app to another, and Reverb loads its apps from configuration at startup. An app per Node would force a browser to open one connection per Node and force a Reverb restart on every `node:add`.
- The Orbit Reverb app accepts client events from channel members (`accept_client_events_from` is `members`). On a presence channel, Reverb adds the sender's signed `user_id` to every client event it relays. A private channel carries no sender identity.
- Reverb checks for dead connections on a fixed 60-second timer. With the default `ping_interval` of 60 seconds, it drops a connection that vanished without closing after 120 to 180 seconds. A clean close is immediate.
- The realtime auth endpoint requires a Gateway access edge (`RequireNodeAccess`), and most workload Nodes have none. `Node` is not a Laravel broadcast user, so presence channels fail today.

## Decision

- Each Node has one presence channel, `presence-node.{id}`, on the existing Reverb app.
- The Gateway signs one agent membership, member ID `agent.{id}`, on `presence-node.{id}`, and only for a request from Node `{id}`'s own WireGuard address when `ManagedNodeEligibility` allows that Node. It signs through two agent-only endpoints that need no access edge: one returns the Reverb connection, its serving address, and the Node's channel, and one signs the membership.
- The agent never uses system DNS. The Gateway writes its WireGuard address to required `gateway_address` in `config.toml` on every converge. Gateway HTTP requests connect to that address on port 443 while retaining `gateway.orbit` as the TLS server name and validating its certificate against the installed Orbit CA. For Reverb, the agent connects to the returned serving address on port 443 while retaining `reverb.orbit` as the TLS server name and validating its certificate against the same CA. The public connection URLs remain `https://gateway.orbit` and `wss://reverb.orbit`.
- Every other subscriber, such as a browser, joins through the existing auth endpoint with its existing rules and receives a viewer member ID, `viewer.{socket id}`. The existing endpoint never signs an `agent.*` member.
- The agent publishes three client events on its own channel: a heartbeat every 5 seconds, a Process change as soon as it sees one, and a full snapshot when it joins and whenever a new member joins.
- A subscriber must accept an agent event on `presence-node.{id}` only when Reverb's `user_id` equals `agent.{id}`. It must accept a Process entry only when the Process runs on Node `{id}` and the reported unit or container name matches that Process's name.
- The web app shows a Node as online while `agent.{id}` is a member and a heartbeat arrived in the last 15 seconds. It shows the Node offline when the agent leaves or its heartbeats stop. A Node whose agent it has not seen falls back to Prometheus.
- While a Node's agent is online, the web app uses the agent's Process state for that Node and ignores the polled `runtime_status`. Otherwise it uses the polled value.
- The Gateway does not receive, store, or forward agent events. `runtime_status` in the API and the CLI keeps coming from Prometheus.
- Reverb keeps its default timers and stays unmodified. The browser's heartbeat timeout, not Reverb's cleanup timer, detects a Node that vanished without closing its connection.

## Rejected alternatives

- Report to the Gateway over HTTP and let the Gateway broadcast: rejected because heartbeats would cost a Gateway request per Node every few seconds, and only the web app needs the data live. The API and CLI would gain live state, which they do not need.
- Publish to Reverb's HTTP API with the app secret on each Node: rejected because a Node that holds the secret can sign any membership and publish any event, including forged Gateway events on `orbit`.
- A Reverb app and secret per Node: rejected because a browser would need one WebSocket per Node, and every `node:add` would rewrite Reverb's configuration and restart it, dropping every connection.
- Client events on the shared private `orbit` channel: rejected because a client event on a private channel carries no sender identity, so any member can forge another Node's state.
- Detect dead agents with Reverb presence alone, after shortening its cleanup timer in `orbit-reverb`: rejected because the timer is fixed in Reverb, and changing it would make `orbit-reverb` Orbit-specific. The heartbeat timeout detects a hard drop in about 15 seconds without a change to Reverb.

## Consequences

- A clean agent stop or start shows in the web app at once. A Node that loses power or network shows offline within about 15 to 20 seconds.
- No agent message reaches the Gateway. The Gateway handles one membership request per agent connection and one per browser channel subscription.
- A Node can publish false state only about itself. Another Node or a browser cannot pose as its agent, because the Gateway signs `agent.{id}` only for Node `{id}`'s address.
- The API, CLI, and `orbit top` do not see live presence or Process state. A non-browser client that needs live state requires a new decision for a Gateway view.
- A browser subscribes to one channel per active Node and makes one auth request for each at page load.
- Agent traffic depends on the `websocket` role. Without it, the agent waits and retries, and the web app polls as today.
- Moving the Gateway role changes its WireGuard address. `node:add` or a role converge rewrites `gateway_address`, and changed configuration restarts the agent.
- The Gateway and Reverb connection host checks remain `https://gateway.orbit` and `wss://reverb.orbit`; address pinning does not weaken TLS hostname verification.
- Client events are limited by Reverb's 10,000-byte message size, so the agent splits a large snapshot into parts.

## Affects

- Components: apps/gateway, apps/docs
- ADRs: extends [ADR 0084](/decisions/0084-broadcast-record-changes-through-reverb) with agent client events; keeps [ADR 0087](/decisions/0087-run-reverb-through-a-websocket-role)'s unmodified Reverb app; transport for [ADR 0128](/decisions/0128-run-a-visibility-only-agent-on-managed-nodes)
- Detail: [Realtime events](/reference/events#node-agent-channels), [Node agent](/reference/node-agent), [Web app](/reference/web-app#live-node-and-process-state)
- Verify: Gateway tests for the agent endpoints and viewer and agent membership signing; agent protocol tests against a Pusher test server; web app tests for event acceptance, heartbeat timeout, and Prometheus fallback; the agent Incus proof
