---
title: "ADR 0152: Sample successful read activity"
sidebarTitle: "0152 Sample successful read activity"
description: "Proposed. The Gateway records every mutating request and every failed request as before, but keeps a successful read only once per command, caller, and minute. Credential reads are always kept."
---

# ADR 0152: Sample successful read activity

The Gateway keeps an Activity for every request that can change something and for every request that fails. A successful read (`GET` or `HEAD`) is kept only when it is the first read of that command from that caller in the last 60 seconds. A successful read of a credential is always kept.

## Status

Proposed.

## Context

The Gateway writes one `activity_log` row for every authorized API request: an insert when the request starts and an update when it ends. In the week before this decision, the Gateway handled about 611,000 API calls. It stored about 620,000 rows since 2026-08-25 in its SQLite database. Most of those rows were polls from browser tabs and agents. `firewall:list` alone was 144,800 calls in one week, mostly from two web tabs that polled every 10 seconds for about 33 hours after they lost realtime.

These rows have little audit value. They repeat the same command from the same caller, succeed, and change nothing. They cost two SQLite writes per request and make `activity:list` hard to read, because a mutating command is hidden between hundreds of polls.

The audit questions the log answers are: what changed, who changed it, and did it succeed. A read that succeeded does not change anything. A failed read can show a broken Node, a missing access edge, or an attack, so it is worth keeping. A read of a credential shows who holds a secret, so it is worth keeping every time.

## Decision

- The Gateway records every request whose method is not `GET` or `HEAD`, as before: a `running` row when it starts and the outcome when it ends.
- The Gateway records every failed `GET` or `HEAD` request, including an unhandled exception, with the same fields as before. It writes the row once, when the request ends.
- A successful `GET` or `HEAD` request is recorded when it is the first successful read of the same command from the same caller address in the last 60 seconds. The Gateway keeps this window in its cache with an atomic add. Other successful reads in the window write nothing.
- A successful read of a route whose name ends in `:credentials` is always recorded.
- A recorded read has the same fields as before. Its `created_at` is the time the request started, and it has no `running` state.
- Routes that already write no Activity, such as the Node agent and schedule callbacks, are unchanged.

## Rejected alternatives

- Keep every read: rejected because it keeps two SQLite writes per poll and hides the commands that matter.
- Drop every successful read: rejected because an operator then cannot see that a Node or an agent reads the API at all, or when it last did.
- Count dropped reads on the sampled row: rejected because every read would still write to SQLite.
- Remove old rows with a retention job: rejected for this change because it does not reduce write load. A retention policy needs its own decision.

## Consequences

- A polling client writes about one row per command per minute instead of one per poll. The web app's polls and agents that list records now cost almost no SQLite writes.
- `activity:list --request-id=<uuid>` finds no row for a successful read that fell inside another read's window. The request ID in the response stays valid for logs.
- The log cannot answer how many times a caller ran a read command. It still shows whether a caller ran it in a given minute.
- A read has no `running` row while it is in flight.
- `activity:list`, `activity:show`, and the MCP activity tools keep working, because the table and its fields do not change. Doctor does not read the log. It sends `POST`, so each Doctor run is still recorded.

## Affects

- Components: apps/gateway, apps/docs
- ADRs: MCP tool calls follow the same rule, because [ADR 0086](/decisions/0086-offer-the-api-as-mcp-tools) runs each one as an internal API request.
- Detail: [activity](/cli/activity), [Using the API](/api/overview)
- Verify: `apps/gateway` tests `ReadActivitySamplingTest` and `CommandActivityTest`
