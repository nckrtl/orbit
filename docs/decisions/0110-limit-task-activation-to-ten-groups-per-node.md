---
title: "ADR 0110: Limit task activation to ten groups per Node"
sidebarTitle: "0110 Limit task activation to ten groups per Node"
description: "Proposed. Task scheduling keeps a Node ceiling of ten active groups and drops the per-Project ceiling of three."
---

# ADR 0110: Limit task activation to ten groups per Node

The Gateway scheduler activates queued Task groups until a Node has ten active groups. It does not count or refuse groups by Project.

## Status

Proposed.

This amends the concurrency ceilings in [ADR 0103](/decisions/0103-absorb-commander-tasks-as-a-gateway-extension). The `tasks` extension, TaskGroup model, shared Instance, and T3 spawn stay.

## Context

[ADR 0103](/decisions/0103-absorb-commander-tasks-as-a-gateway-extension) locked Gateway-owned ceilings of three active groups per Project and ten per Node. A Project with more than three reserved, running, reviewing, or settling groups left additional queued groups waiting even when the Node still had capacity.

LIVE work on the Orbit Project hit that Project ceiling while queued groups waited. The Node ceiling is the machine bound: up to ten tasks may run on one Node.

What matters is one Node-owned limit that the Gateway can enforce without counting `app_id`.

## Decision

- `TaskCeilings` stores only `PerNode = 10`.
- `TaskConcurrencyGuard::canActivate` enforces that Node ceiling. It does not count or limit by `app_id`.
- A group without an Instance can activate. The Node ceiling applies once `taskable` points at an Instance on that Node.
- Active statuses stay reserved, running, reviewing, and settling. Completed, failed, and cancelled groups do not count.

## Rejected alternatives

- Keep three active groups per Project: rejected because a busy Project waits while the Node still has capacity.
- Make the Project ceiling configurable: rejected because the product choice is to drop that limit, not to tune it.
- Count groups without an Instance toward the Node: rejected because placement is unknown until InstanceProvisioning assigns one.

## Consequences

- Many groups on the same Project can activate until the Node reaches ten active groups.
- A busy Project can occupy a whole Node. Other Projects on that Node wait until a slot opens.
- Operators still see queued groups when every eligible `app-dev` Node is at ten.

## Affects

- Components: apps/gateway, apps/docs
- ADRs: [ADR 0103](/decisions/0103-absorb-commander-tasks-as-a-gateway-extension)
- Detail: [Tasks](/reference/tasks)
- Verify: `apps/gateway/tests/Feature/Domain/Tasks/TaskConcurrencyGuardTest.php`, `apps/gateway/tests/Feature/Domain/Tasks/TaskSchedulerTest.php`, `apps/gateway/tests/Feature/Api/TasksRoutesTest.php`
