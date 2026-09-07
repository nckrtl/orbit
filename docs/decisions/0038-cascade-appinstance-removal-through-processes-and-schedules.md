# ADR 0038: Cascade AppInstance removal through processes and schedules

In the context of removing an AppInstance with owned processes and schedules, facing removal guards and running scheduled commands that can delay deletion, we decided for immediate cascading removal and against requiring separate child removal or waiting for scheduled commands to finish, to make AppInstance removal own its complete cleanup, accepting that an in-flight scheduled command may fail when its source or artifacts disappear.

## Status

Accepted on 2026-09-06. Extends [ADR 0036](0036-support-only-appinstances.md). Supersedes [ADR 0013](0013-native-systemd-schedule-management.md) for AppInstance target-deletion guards and waiting for active Schedule execution during cascading AppInstance removal.

## Context

Processes are being given AppInstance ownership as legacy Instance and Workspace support is removed. A Schedule can target either a Node or an AppInstance, although it executes on an installed Node. ADR 0013 refuses target deletion while a Schedule exists and retains removal artifacts until an active execution finishes; the chosen AppInstance removal behavior instead removes its owned children in the same operation.

## Decision

- The Gateway must cascade an authorized AppInstance removal through every Process and Schedule owned by that AppInstance, including stopped, failed, and removing children.
- The Gateway must apply the existing AppInstance source-removal preflight before starting child cleanup and must prevent new children from attaching once removal begins.
- The Gateway must stop and remove owned managed processes and disable Schedule timers as part of the cascade, without requiring separate operator removal commands.
- The Gateway must remove owned Schedule intent and persistent execution artifacts without waiting for an active scheduled command to finish.
- An active scheduled command may continue or fail as its AppInstance source and artifacts are removed; its completion must not delay AppInstance removal or recreate deleted Schedule state.
- A successful AppInstance removal must leave no Process or Schedule intent or persistent runtime artifacts owned by the removed AppInstance.
- The Gateway must retain resumable removal state when owned-resource cleanup fails and must not report successful AppInstance removal while that cleanup is incomplete.
- The cascade must leave Node-owned schedules, other AppInstances' children, and unrecognized runtime artifacts untouched.
- Standalone Schedule removal and changes to a Schedule target's placement or execution identity must retain ADR 0013's existing rules.

## Rejected alternatives

- Refuse AppInstance removal until the operator removes every child: rejected because child cleanup belongs to the requested AppInstance removal operation.
- Wait for scheduled commands to finish before removing the AppInstance: rejected because application execution would control when removal can complete.
- Delete every Schedule installed on the AppInstance's Node: rejected because execution placement does not establish AppInstance ownership.

## Consequences

- Removing an AppInstance cleans up its processes and schedules in one operation.
- In-flight scheduled work can fail or lose completion reporting when removal deletes its source and Schedule state.
- Process ownership and AppInstance removal must share the cascade contract across Gateway, PHP SDK, and CLI; ORB-72 must replace its AppInstance target-deletion refusal criterion.
- Cleanup failures remain retryable, so an unreachable Node or conflicting artifact can prevent successful completion even though active scheduled execution does not.

## Affects

- Components: apps/gateway, packages/php-sdk, apps/cli
- ADRs: extends [ADR 0036](0036-support-only-appinstances.md); supersedes [ADR 0013](0013-native-systemd-schedule-management.md) for cascading AppInstance removal
- Detail: docs/reference/appinstance-removal.md
- Verify: `composer docs-lint`; implementation conformance through Gateway lifecycle tests and declared Incus cascade-removal acceptance
