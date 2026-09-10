# Feature plan

Plan format: 1
Issue: ORB-218
Flow: discovery
Review verdict: PASS

## Outcome

An authorized agent can read and atomically replace one production AppInstance's configured deployment branch and ordered application steps without starting a deployment.

## Code boundaries

In:
- `apps/gateway/database/migrations/*deployment_config*` and `apps/gateway/app/Models/AppInstance.php`: persist a deployment branch separate from creation-source evidence and one current ordered step list, backfill existing production branches, expose empty steps for legacy rows, cast the stored list, and protect command-bearing attributes from generic model serialization.
- `apps/gateway/app/Domain/AppInstances/Deployment/**`: define the deployment phase, step, and complete configuration value objects; reuse `GitBranchName`; normalize the 300-second default; and enforce field, uniqueness, count, order, and total-timeout invariants without performing source or machine work.
- `apps/gateway/app/Http/Requests/AppInstances/*DeploymentConfig*`, `apps/gateway/app/Data/AppInstances/*DeploymentConfig*`, `apps/gateway/app/Actions/AppInstances/*DeploymentConfig*`, `apps/gateway/app/Http/Controllers/Api/AppInstanceDeploymentConfigsController.php`, and `apps/gateway/routes/api.php`: add strict GET and PUT boundaries, require a source-resolved or active production target with a valid recorded branch, reuse `ServingNode::InstanceOwning`, return the typed configuration, and replace both stored fields in one locked database transaction.
- `apps/gateway/app/Http/Middleware/RecordCommandActivity.php`: retain a bounded deployment-configuration Activity projection without command text and include submitted commands in generic output redaction.
- `apps/gateway/tests/Feature/Api/AppInstanceDeploymentConfigTest.php`, `apps/gateway/tests/Feature/Domain/DeploymentConfigTest.php`, `apps/gateway/tests/Feature/Database/AppInstanceDeploymentConfigMigrationTest.php`, and `apps/gateway/tests/Feature/Configuration/NodeAccessRouteScopeTest.php`: cover the endpoint, exact route authorization inventory, atomic persistence, prior-schema migration and rollback, incomplete production rows, compatibility defaults, strict input, domain limits, branch independence, ordering, and command-redaction contract.

Out:
- Deployment execution stays unchanged: do not fetch Git, create a release, execute a step, refresh PHP, or alter the production `current` link.
- App-level deployment templates stay unchanged: do not add configuration to `App`, App creation, or App response contracts.
- Historical state stays unchanged: do not add deployment runs, deployment history, or historical step snapshots.
- `packages/php-sdk/` stays unchanged because SDK transport is separate work.
- `apps/cli/` stays unchanged because CLI commands are separate work.

## Documentation

- `docs/architecture.md`: fixed audit drift that assigned later deployment ownership to the operator; it now matches accepted ADR 0046 and separates Orbit's deployment ownership from the operating agent's command selection.
- `docs/reference/deployments.md`: now states the GET and PUT contract, fields, limits, timeout defaults, ordering, full-replacement behavior, authorization, non-execution boundary, branch independence, and command-redaction behavior.
- `docs/generated/context.json`: rebuilt after the maintained-page changes so documentation routing is current.
- Audit reported findings: none.

## Acceptance map

| Criterion | Boundary | Focused proof |
| --- | --- | --- |
| 1. GET returns the branch and ordered steps; authorized PUT atomically replaces the complete valid configuration and rejects malformed, duplicate, unknown, or wrongly typed input before saving. | Gateway routes, `AppInstanceDeploymentConfigsController`, the strict deployment-config request/parser, show and update actions, typed response data, `AppInstance`, and the deployment-config migration. | `cd apps/gateway && vendor/bin/pest --compact tests/Feature/Api/AppInstanceDeploymentConfigTest.php` |
| 2. Every step has a unique valid name, a closed phase, a bounded nonempty UTF-8 command without NUL, and a timeout from 1 through 900 seconds with default 300. | `apps/gateway/app/Domain/AppInstances/Deployment/**` and the deployment-config request boundary. | `cd apps/gateway && vendor/bin/pest --compact tests/Feature/Domain/DeploymentConfigTest.php` |
| 3. The configuration accepts at most 32 steps and 3,600 total timeout seconds, preserves order within each phase, and accepts an empty list. | The deployment configuration aggregate and normalized model persistence. | `cd apps/gateway && vendor/bin/pest --compact tests/Feature/Domain/DeploymentConfigTest.php` |
| 4. The deployment branch uses `GitBranchName`, remains instance-owned across App default changes, leaves other AppInstances and creation-source evidence unchanged, and causes no Git fetch or `current` mutation. | The deployment configuration aggregate, update action, separate `deployment_branch` persistence, and unchanged production source lifecycle. | `cd apps/gateway && vendor/bin/pest --compact tests/Feature/Domain/DeploymentConfigTest.php` proves the validator and action boundary with other-instance records, changed App defaults, unchanged `deployment_branch`/`branch`/`branch_override` evidence, a no-source-executor sentinel, and an unchanged `current` sentinel. |
| 5. Legacy instances return empty steps with their recorded branch; current peer and owning-Node authorization protects reads and writes; command text stays out of Activity, validation errors, and generic diagnostics. | The prior-schema migration, backward-compatible model reader, source-resolved lifecycle guard, controller Node-access attribute, exact route-scope inventory, strict request messages, and `RecordCommandActivity`. | `cd apps/gateway && vendor/bin/pest --compact tests/Feature/Database/AppInstanceDeploymentConfigMigrationTest.php tests/Feature/Configuration/NodeAccessRouteScopeTest.php tests/Feature/Api/AppInstanceDeploymentConfigTest.php` proves migration compatibility and rollback, both routes' `ServingNode::InstanceOwning` scope, safe null-branch refusal, and redaction. |
| 6. Maintained documentation states fields, limits, ordering, and operating-agent command ownership, with current generated context. | `docs/architecture.md`, `docs/reference/deployments.md`, and `docs/generated/context.json`. | `composer docs-build && composer docs-lint` |
| 7. Focused acceptance tests, the changed Gateway checks, and the repository candidate gate pass. | All changed Gateway and documentation boundaries. | `cd apps/gateway && vendor/bin/pest --compact tests/Feature/Api/AppInstanceDeploymentConfigTest.php tests/Feature/Domain/DeploymentConfigTest.php tests/Feature/Database/AppInstanceDeploymentConfigMigrationTest.php tests/Feature/Configuration/NodeAccessRouteScopeTest.php`; `cd apps/gateway && composer check`; Builder root `composer check` on the exact clean candidate. |

## Incus observations

Incus: not required. Every acceptance item is covered by focused local Gateway tests, documentation checks, the changed-project check, and the Builder candidate gate; no topology or discovery observation is required.

## Implementation order

1. Add the backward-compatible AppInstance migration and model fields, with `AppInstanceDeploymentConfigMigrationTest.php` starting from the prior schema: copy each non-null production `branch` into `deployment_branch`; leave permitted null branches, every `branch`, and every `branch_override` unchanged; map missing steps to `[]`; and prove rollback refuses before discarding configured deployment state.
2. Add the typed deployment configuration domain objects and `DeploymentConfigTest.php` coverage for strict branch, step, UTF-8 byte, timeout, uniqueness, aggregate-limit, defaulting, empty-list, and stable-order behavior. In the same file, exercise the update action to prove another AppInstance is unchanged, a later App `default_branch` change leaves `deployment_branch`, `branch`, and `branch_override` unchanged, no source executor is called, and a `current` sentinel is unchanged.
3. Add a strict request parser that rejects malformed JSON and duplicate or unknown keys at the top level and within each step before it creates the typed configuration; keep validation messages value-free.
4. Add show and update actions plus response data; accept only a source-resolved or active production AppInstance with a valid recorded branch, return a bounded conflict for reserved or checkout-prepared rows including a permitted null branch, read the legacy branch fallback, and lock and update the deployment branch and normalized steps together in one database transaction.
5. Add `instance:deployment-config:show` and `instance:deployment-config:update` controller routes with `ServingNode::InstanceOwning`; update `NodeAccessRouteScopeTest.php` to assert both exact entries; then cover authorized, unauthenticated, unauthorized, non-production, incomplete-production, valid, invalid, and concurrent/atomic behavior in the API test.
6. Give deployment configuration Activity a command-free input projection, extend generic output redaction with submitted step commands, and prove command text is absent from success, validation-failure, and unhandled-failure records and responses.
7. Run all four focused test files, `cd apps/gateway && composer check`, `composer docs-build`, `composer docs-lint`, and `git diff --check`; the Builder later runs root `composer check` on the exact clean candidate.

## Must preserve

- ADR 0032: creation records explicit branch-selection intent even when it equals the App default; explicit selection wins over derived defaults; App default changes preserve that intent; AppInstance name, placement, and Route identity remain independent; and a conflicting creation retry is still refused. Deployment configuration therefore does not rewrite `branch` or `branch_override`.
- ADR 0046: a production AppInstance owns its configured deployment branch and ordered steps; the operating agent owns application command selection and ordering before and after activation; Orbit inserts no unconfigured command; configuring does not satisfy the separate explicit-deployment requirement; and Orbit stores no deployment runs, history, or historical step snapshots.
- Existing binary Node access remains enforced at the HTTP boundary through the active WireGuard peer and owning Node, without new granular permissions.
- Existing control-plane rows, production source evidence, other AppInstances, Route state, releases, and the `current` link remain unchanged on reads and on every rejected or accepted configuration write.
- Command bytes are returned only by an authorized configuration read and never appear in Activity input, validation text, exception arguments, generic error responses, or captured command diagnostics.

## Open questions

none

## Deviations

- Acceptance item 7's generic "all five full no-TIA CI suites" wording maps under the current discovery policy to focused acceptance tests, `cd apps/gateway && composer check`, and the Builder's root `composer check` with test impact analysis on the exact clean candidate. The orchestrator should align the Linear text with this current check policy; the acceptance outcome is unchanged.

## Review findings
