# ADR 0060: Record the latest Schedule run status

In the context of native Schedule execution, facing a need to show the latest outcome without tracking individual runs, we decided for one completion report that replaces the latest status and against retry, run identity, or projection generations, to keep Schedule reporting small, accepting that a failed report leaves stale metadata.

## Status

Accepted on 2026-09-11. Supersedes [ADR 0013](0013-native-systemd-schedule-management.md) for Schedule projection generations and completion reporting.

## Context

[ADR 0013](0013-native-systemd-schedule-management.md) gives systemd ownership of recurring execution and limits the Gateway to the latest completion metadata. Its callback fields cannot distinguish a repeated report from the next run with the same result, although it also requires duplicate and stale reports to be ignored. Orbit does not need that distinction because completion reporting is informational, Schedules cannot be changed in place, and the Node does not retry a report.

## Decision

- The Node that runs a Schedule must make one completion report after the command finishes.
- The Gateway must authenticate the report as coming from the Node recorded as the Schedule host.
- The Gateway must replace the Schedule's latest run time and status with its receipt time and the reported `success` or `error` status.
- The completion operation must update only the Schedule's latest run metadata.
- The Gateway must not assign a projection generation or require a run identity, ordering value, or retry protocol for Schedule completion.
- The Node must not retry a failed Schedule completion report.
- A failed completion report must not change the command result or subsequent Schedule execution.
- Schedule removal must inspect the systemd service when it needs current execution state and must not depend on a completion report.
- The Gateway owns latest Schedule run metadata and must not store Schedule run history.

## Rejected alternatives

- Give every run an ordered sequence: rejected because it adds durable Node state and lifecycle work only to deduplicate informational metadata.
- Give every Schedule projection a generation: rejected because Schedules cannot be changed in place and removal can inspect systemd directly.
- Retry completion reports: rejected because a retry can be indistinguishable from the next run with the same result without adding per-run identity.
- Remove completion reporting: rejected because operators would lose the bounded latest-run signal in the Gateway.

## Consequences

- Orbit can show when the Gateway last received a Schedule result and whether that result was `success` or `error`.
- A failed report leaves the prior latest-run metadata unchanged or empty.
- Schedule execution remains independent of Gateway availability.
- ORB-72 must remove duplicate, stale-order, projection-generation, and callback-retry requirements before implementation resumes.

## Affects

- Components: apps/gateway, apps/docs
- ADRs: supersedes [ADR 0013](0013-native-systemd-schedule-management.md) for Schedule projection generations and completion reporting
- Detail: docs/reference/schedules.md
- Verify: Gateway Schedule lifecycle tests and `composer docs-lint`
