# ADR 0057: Limit Metrics exporters to managed Nodes

In the context of Metrics exporter selection, facing a rule that allows any active Node while roleless operator clients receive no Gateway service management, we decided for exporter eligibility limited to Nodes managed over SSH and against installing exporters on operator clients, to preserve the client ownership boundary, accepting that Orbit supplies no managed host metrics for those clients.

## Status

Accepted on 2026-09-10. Supersedes [ADR 0003](0003-singleton-metrics-role.md) only where explicit exporter preference selects any active Node without a management eligibility check. Extends [ADR 0012 (platform support withdrawn)](0012-ubuntu-24-04-roleless-operator-clients.md) to make its operator-client boundary explicit in Metrics selection and Doctor expectations. Retains ADR 0003's selection defaults and preference rules within the eligible managed fleet.

## Context

ADR 0003 permits an explicit exporter preference on any active Node, including a Node without roles. ADR 0012 originally described operator clients that need no Gateway SSH and receive no managed service convergence. Ubuntu 24.04 support and that enrollment proposal are withdrawn; this decision retains the management eligibility restriction without authorizing client support. The exporter selector uses roles and preference without distinguishing those clients from Nodes managed by the Gateway.

## Decision

- The Gateway must select Metrics exporters only on active Nodes that use the supported managed-node platform and receive Gateway management over SSH.
- The Gateway may select a managed Node without roles when its exporter preference is explicitly enabled.
- The Gateway must exclude roleless operator clients from exporter selection regardless of any stored exporter preference.
- The Gateway must refuse an exporter enable request for an operator client before changing its preference or attempting SSH.
- Metrics convergence must not install, configure, start, or require an exporter on an operator client.
- Doctor must not treat an absent exporter or exporter SSH reachability on an operator client as drift.

## Rejected alternatives

- Enable exporters on every active Node when requested: rejected because an operator client has no Gateway-owned service-management contract.
- Exclude every Node without roles: rejected because a managed Node can host an exporter without carrying an application or infrastructure role.
- Convert an operator client into a managed Node during exporter enablement: rejected because a Metrics preference does not authorize changing that client's platform and management responsibilities.

## Consequences

- Nodes managed by the Gateway retain Metrics support with or without assigned roles.
- Operator clients do not receive managed exporters and cannot use preference changes to acquire Gateway service convergence.
- A stored enabled preference cannot make an ineligible client an exporter target.
- Metrics selection, request validation, convergence, and Doctor expectations need the same eligibility boundary.

## Affects

- Components: apps/gateway
- ADRs: supersedes [ADR 0003](0003-singleton-metrics-role.md) for unrestricted exporter eligibility; extends [ADR 0012 (platform support withdrawn)](0012-ubuntu-24-04-roleless-operator-clients.md) for Metrics and Doctor scope
- Detail: [Metrics role](../reference/metrics.md)
- Verify: Metrics selection, enablement, convergence, and Doctor tests covering managed roleless Nodes and operator clients; `composer docs-lint`
