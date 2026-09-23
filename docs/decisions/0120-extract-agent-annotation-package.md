---
title: "ADR 0120: Extract the agent annotation package"
description: "Proposed. Share an injectable browser annotation package with explicit speech configuration."
---

# ADR 0120: Extract the agent annotation package

A standalone browser package owns the annotation overlay. Orbit web consumes it through its public API.

## Status

Proposed.

## Context

The web app embeds an annotation tool derived from the Laravel toolbar. Its speech endpoint points at one machine. Other projects need the same tool without importing Orbit application code.

## Decision

Move the overlay and its supporting code to `packages/agent-annotation`. Distribute a browser module and an injectable script with their styles and React runtime included. Expose explicit mount and teardown functions. Keep host context behind a callback and make Commander submission opt-in. Require a configured speech endpoint, retaining the existing Diction protocol and legacy toolbar configuration fields.

Expose an optional annotation service URL. The Gateway persists Instance annotations and status events, delivers messages through the authenticated T3 HTTP API, and broadcasts change notifications through the existing private Reverb channel. Each annotation captures its destination thread. Delivery waits for an idle thread and an explicit completion report before advancing its queue. Persist stable T3 command IDs to make delivery retries idempotent. The Gateway scheduler delivers through the existing T3 adapter on the Instance Node; no queue worker is required. Each annotation is backed by an Orbit Task in an existing-thread TaskGroup; execution mode, rather than task type, excludes it from the managed scheduler. Browser subscriptions share the host connection when available and recover through HTTP snapshots on reconnect, with polling as an outage fallback. Submission remains HTTP to preserve validation and durable acknowledgement. The package remains independent of the execution provider.

## Rejected alternatives

- Copy the overlay into each application: fixes and interaction behavior would diverge.
- Require every host to compile Tailwind or mount React: this prevents script injection.
- Add a desktop hotkey service now: it introduces a separate device connection and does not serve mobile browsers.

## Consequences

The package can run without Orbit or Laravel. Hosts own endpoint reachability and transcription model selection. The bundle includes its own React runtime. Laravel extension migration and a desktop bridge remain follow-up work.

## Affects

- Components: apps/gateway
- Browser code: `apps/web`, `packages/agent-annotation`
- ADRs: none
- Detail: [Agent annotation package](/reference/agent-annotation)
- Verify: Package build, web unit tests, browser annotation tests, web build.
