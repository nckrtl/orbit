---
title: "ADR 0069: Allow Process targets of AppInstance or managed Node"
sidebarTitle: "0069 Allow Process targets of AppInstance or managed Node"
description: "Accepted on 2026-09-13. Extends ADR 0036."
---

# ADR 0069: Allow Process targets of AppInstance or managed Node

In the context of shared Node infrastructure that has no AppInstance owner, facing AppInstance-only Process targeting, we decided for AppInstance or managed Node Process targets and against Workspace targets or synthetic AppInstances, to give those services a Node lifecycle, accepting that Process commands must select exactly one target type.

## Status

Accepted on 2026-09-13. Extends [ADR 0036](/decisions/0036-support-only-appinstances). Supersedes [ADR 0036](/decisions/0036-support-only-appinstances) for the Process-target boundary only.

## Context

Orbit's application model remains AppInstance-only under ADR 0036. Shared Node services such as a Docker database or a node-scoped observer have no AppInstance owner. Binding them to a synthetic AppInstance gives them the AppInstance removal lifecycle. [ADR 0038](/decisions/0038-cascade-appinstance-removal-through-processes-and-schedules) already leaves Node-owned Schedules in place; Process targeting had no equivalent Node owner.

## Decision

- A Process must have exactly one target: an AppInstance or a managed Node.
- Orbit must not accept a Workspace Process target.
- Orbit must not create a synthetic AppInstance to host a Node-scoped Process.
- The Gateway must derive a Node Process's execution host and runtime identity from that Node.
- The Gateway must keep Node Processes when it removes an AppInstance.
- The Gateway must remove Node-owned Processes during Node decommissioning and must retain unfinished cleanup for retry.
- App process definitions and AppInstance Process copies must remain AppInstance-owned under [ADR 0048](/decisions/0048-copy-app-process-and-schedule-definitions-into-appinstances) and [ADR 0038](/decisions/0038-cascade-appinstance-removal-through-processes-and-schedules).
- Apps must remain AppInstance-only under ADR 0036.

## Rejected alternatives

- Keep AppInstance-only Process targets: rejected because shared Node services would inherit AppInstance removal.
- Create a synthetic AppInstance for infrastructure: rejected because that AppInstance lifecycle is unrelated to the service.
- Restore Workspace Process targets: rejected because ADR 0036 removed Workspace as an application model and a Workspace is not a host.

## Consequences

- Operators can install shared databases and node-scoped observers as Node Processes.
- Process add and list require an explicit AppInstance or Node selector.
- Node decommissioning fails while owned Process cleanup is incomplete.
- A Herdr observation grant is outside this decision; this record owns the Process-target primitive only.

## Affects

- Components: apps/cli, apps/gateway, packages/php-sdk
- ADRs: extends [ADR 0036](/decisions/0036-support-only-appinstances); supersedes [ADR 0036](/decisions/0036-support-only-appinstances) for the Process-target boundary
- Detail: [App process and Schedule definitions and copies](/reference/app-processes-and-schedules)
- Verify: `composer docs-lint`; Gateway, PHP SDK, and CLI Process target and Node-removal tests
