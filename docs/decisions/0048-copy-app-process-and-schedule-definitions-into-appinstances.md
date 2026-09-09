# ADR 0048: Copy App process and schedule definitions into AppInstances

In the context of cloning production AppInstances with workers and scheduled commands, facing candidate customizations that do not describe the intended production setup, we decided for App-owned definitions copied into independent AppInstance records and against copying candidate overrides or maintaining live inheritance, to make target preparation follow declared application intent, accepting explicit updates to existing instances.

## Status

Accepted on 2026-09-10. Extends [ADR 0047](0047-create-production-appinstances-from-candidates.md) for process and schedule selection and [ADR 0038](0038-cascade-appinstance-removal-through-processes-and-schedules.md) for ownership of instantiated copies. Supersedes [ADR 0013](0013-native-systemd-schedule-management.md) for automatic timer startup during cloning and its prohibition on explicit activation of an installed AppInstance Schedule timer.

## Context

A candidate can run a worker with testing options or a development-only server such as Vite. Those choices do not establish the processes and schedules declared for production. An App definition edit must not change running production instances, while each target still needs its own runtime identity and lifecycle. ADR 0047 requires cloned target processes and schedules to start stopped, but ADR 0013 requires timer activation during installation and provides no explicit activation operation.

## Decision

- An App owns reusable process and schedule definitions with development and production applicability.
- Orbit must select the App's production-applicable definitions when cloning a production AppInstance.
- Orbit must not use candidate-specific process or schedule overrides as target definitions during cloning.
- Orbit must create independent AppInstance-owned Process and Schedule records from the selected definitions.
- Orbit must derive each copy's execution placement and identity from its target AppInstance.
- Orbit must not change existing instance copies or their runtime state when an App definition is added, edited, or removed.
- The operating agent owns explicit changes to each AppInstance's process and schedule copies.
- Orbit must not change an App definition when its instance copy is modified or removed.
- Orbit must preserve completed instance copies and their runtime state when a clone request is repeated.
- Orbit must retain App definitions when an AppInstance is removed under ADR 0038.
- Orbit must permit a cloned AppInstance Schedule to complete installation with its timer disabled and stopped.
- Orbit must provide an explicit operation to enable and start an installed AppInstance Schedule timer.
- Orbit must keep manual Schedule execution separate from enabling its timer.

## Rejected alternatives

- Copy candidate process and schedule overrides: rejected because local testing choices would become production defaults.
- Maintain live inheritance from App definitions: rejected because editing one App definition would change existing production runtimes.
- Store reusable definitions only on AppInstances: rejected because every target would need a separate declaration of the App's production setup.
- Start every timer during installation: rejected because a cloned Schedule can execute before the agent finishes target data preparation.
- Use a manual Schedule run as activation: rejected because one execution does not enable recurring timer triggers.

## Consequences

- Cloning takes configuration values and optional SQLite data from the candidate, while it takes process and schedule definitions from the App.
- A customization intended for new production instances must be put in the App definition before cloning or applied explicitly to each target afterward.
- Existing copies can differ from App definitions; that difference is intentional and does not authorize automatic reconciliation.
- Development-only definitions are omitted from production clones, including when the candidate is itself a production instance.
- Process and Schedule records keep their own identifiers, runtime artifacts, desired state, and AppInstance-removal lifecycle.
- A clone with no production-applicable definitions creates no managed application processes or schedules.
- The Schedule runtime, API, SDK, CLI, and Doctor contracts must distinguish successful stopped installation from an enabled timer and support explicit activation.
- Schedules owned by Nodes retain ADR 0013's behavior. Application deployment steps retain AppInstance ownership under ADR 0046.

## Affects

- Components: apps/gateway, packages/php-sdk, apps/cli
- ADRs: extends [ADR 0047](0047-create-production-appinstances-from-candidates.md) for definition selection and [ADR 0038](0038-cascade-appinstance-removal-through-processes-and-schedules.md) for instance-copy ownership; supersedes [ADR 0013](0013-native-systemd-schedule-management.md) for timer startup during cloning and explicit AppInstance timer activation; retains [ADR 0046](0046-own-production-release-deployment-in-orbit.md) for deployment-step ownership
- Detail: docs/reference/app-processes-and-schedules.md
- Verify: `composer docs-lint`; implementation conformance through Gateway, PHP SDK, and CLI definition and lifecycle tests and issue-specific Incus proof
