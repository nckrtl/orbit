---
title: "ADR 0134: Resume Pi agent streams from a cursor"
sidebarTitle: "0134 Resume Pi agent streams"
description: "Proposed. A reconnecting Pi agent stream resumes after the viewer's last cursor instead of sending the whole transcript again. The Pi server keeps the cursor. It sends a full snapshot only on a first connection or when it cannot resume."
---

# ADR 0134: Resume Pi agent streams from a cursor

A reconnecting Pi agent stream sends only what the viewer missed. The Pi server numbers its stream events per run of a session and resumes after a cursor from the current run. It sends a full snapshot on a first connection and whenever it cannot resume.

## Status

Proposed.

This amends the Stream operation of the Pi server in [ADR 0116](/decisions/0116-run-task-implementers-on-pi), which starts every stream with a full snapshot. The rest of that record stays. The T3 driver keeps its full snapshot on each connection.

## Context

The web app streams a thread's conversation through the Gateway. Each Gateway connection to the Pi server lasts about 20 seconds, so the browser reconnects about every 23 seconds while a tab is open. Every connection started with a full snapshot. For one implementer that snapshot was 157 KB with 111 entries, and it grows with the conversation. Each open tab downloaded it again on every reconnect.

The browser already sends `Last-Event-ID` on reconnect, and the Gateway already writes each event's cursor as the SSE `id`. The Pi server numbered its events, but the numbers restart whenever it loads a session: after a server restart, and after an idle session is unloaded. A bare number cannot tell those runs apart.

## Decision

The Pi server owns the cursor. Each load of a session gets a random run ID, and every stream event carries it with its sequence number. The server remembers the sequence that each transcript entry was streamed with. The Gateway writes the cursor as `{run}.{sequence}`.

`GET /sessions/{id}/stream?run={run}&after={sequence}` resumes when the run is the current run and the sequence is not ahead of it. The stream then starts with a `resumed` event, followed by the entries streamed after the cursor and the latest state if it changed after it, in sequence order. The `resumed` event lists the entries the viewer already has whose tool calls have no result yet, so the Gateway can name the results that follow. In every other case the server sends a full snapshot, as before.

The Gateway passes a Pi cursor through and treats any other cursor as absent. When one Pi event becomes several Orbit entries, only the last one carries the cursor. A viewer that disconnects between them resumes before that event and receives all of them again. The browser merges entries by ID, so a repeated entry replaces itself.

## Rejected alternatives

- Keep sending a snapshot on each connection: the cost grows with every conversation and every open tab.
- Persist a sequence counter on disk: every streamed event would write a file, and a restart would still need to detect replayed events.
- Keep the cursor in the Gateway: the Gateway would need a per-viewer store of what each browser has seen. The Pi server already holds the transcript and its order.
- Resume T3 threads the same way: the T3 driver rebuilds its thread projection from a baseline snapshot on each connection, and T3's replay window after a sequence is not documented. Resuming it needs its own design.

## Consequences

- A reconnect without changes sends a few hundred bytes instead of the whole transcript.
- A restart or an idle unload changes the run, so the next reconnect sends one snapshot.
- The Pi server keeps one sequence number for each streamed entry of a loaded session.
- Stream events and snapshots carry `run`. An older Gateway ignores it, and an older Pi server without it makes the Gateway send no cursor, so each side can be deployed first.

## Affects

- Components: apps/gateway
- ADRs: [ADR 0116](/decisions/0116-run-task-implementers-on-pi)
- Detail: [Pi server](/reference/pi-server#stream), [Tasks](/reference/tasks#agent-viewer)
- Verify: `apps/pi-server/tests/server.test.ts` (resume), `apps/gateway/tests/Feature/Infrastructure/Tasks/Pi/PiDriverTest.php` (events), `apps/web/src/tasks/agent-stream.test.ts`, `apps/web/tests/browser/agents.test.tsx`
