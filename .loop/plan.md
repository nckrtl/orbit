# Feature plan

Plan format: 1
Issue: ORB-227
Flow: discovery
Review verdict: PASS

## Outcome

An agent can clone an eligible candidate into a production AppInstance through typed SDK and CLI operations and receive the target identity, preview hostname, configured branch, and current release state without the CLI owning remote execution or deployment.

## Code boundaries

In:
- `packages/php-sdk/src/Requests/AppInstances/CloneAppInstanceRequest.php`: add the typed `POST /api/v1/instances/{candidate}/clone` request with numeric candidate and destination Node IDs, target name, preview name, and optional branch and SQLite source path; preserve supplied scalar values, omit only null optionals, decode the ordinary AppInstance response with its embedded sole Route and request ID, and apply no placement, source, Route, or filesystem policy.
- `packages/php-sdk/tests/Unit/Requests/AppInstances/CloneAppInstanceRequestTest.php`, `packages/php-sdk/.ai/rules/public-contract.md`, and `packages/php-sdk/tests/Unit/RepositoryGuidanceTest.php`: prove the exact endpoint, JSON body, omission and explicit-value boundaries, typed AppInstance and preview Route response, correlated safe error, and sensitive SQLite-path handling; add candidate cloning to the enumerated public SDK surface and keep its guidance assertion current.
- `apps/cli/app/Commands/Instances/CloneInstanceCommand.php`: add `instance:clone CANDIDATE NODE NAME --preview-name=NAME [--branch=BRANCH] [--sqlite-source-path=PATH] [--json]`; require explicit unambiguous positive numeric candidate and Node IDs in noninteractive and JSON use, send the new typed clone request, query the returned target through the existing typed retained-release operation, and render the target ID, configured branch, actual embedded Route hostname, selected release or its absence, and request IDs without a local or remote shell.
- `apps/cli/app/Commands/Nodes/ProvisionNodeCommand.php`: correct `node:provision --tld` help so it states that a production Node needs its own TLD when it will derive clone preview hostnames, without changing provisioning transport or Gateway validation.
- `apps/cli/tests/Feature/Instances/CloneInstanceCommandTest.php` and `apps/cli/tests/Feature/CommandSurfaceTest.php`: cover the exact command and help surface, numeric selector failures before clone mutation, typed clone and release-state requests, optional-value omission, human and JSON output, embedded preview Route use, no first selected release, request IDs, bounded safe failures, no prompts in noninteractive or JSON mode, and no local execution surface.

Out:
- `apps/gateway/` stays unchanged; the shipped endpoint remains authoritative for candidate eligibility, access, production placement, source inspection, branch selection, Node-TLD preview derivation, Route scope, environment and SQLite copying, runtime-definition selection, idempotency, checkpoints, and remote execution.
- `apps/e2e/`, `bin/e2e-*`, and `.loop/proof/` stay unchanged; the standard discovery topology and existing shell, exec, sync, and verify commands supply the `candidate-clone-cli` observation without harness changes, proof inputs, or isolated proof instrumentation.
- The existing AppInstance, Route, and deployment-release DTO contracts stay general; clone transport reuses them and does not add SDK placement validation, automatic retries, a generic transport escape hatch, or a remote executor.
- Direct SSH, shell, sudo, source queue operations, application readiness checks, framework commands, process starts, Schedule activation, automatic deployment, automatic hostname replacement, and external-storage preparation stay outside the SDK and CLI clone command.
- `instance:new` and the older direct creation API remain available and unchanged; this issue adds candidate cloning and does not retire or redirect either route.

## Documentation

### Documentation audit

Scope: ORB-227; the `apps/cli` and `packages/php-sdk` documentation context for App and Node, plus the AppInstance cloning reference required by the `docs` label.

Fixed:
- `docs/reference/appinstance-cloning.md`: the page described the Gateway clone API and lifecycle but omitted the operator CLI; it now states the clone command and options, production Node-TLD setup through `node:provision --tld`, unambiguous scripted identifiers, typed SDK-only transport, returned target and release-state output, candidate-versus-App definition selection, target-only environment and application cleanup, and separate explicit deployment.

Audited without changes:
- `docs/architecture.md` already keeps the CLI on the HTTP boundary and gives the Gateway ownership of Node changes and production placement.
- `docs/concepts.md` and `docs/domains/applications.md` already define App, AppInstance, Node, Route, configured branch, and separate explicit production deployment consistently with candidate cloning.
- `docs/reference/apps.md`, `docs/reference/routes.md`, `docs/reference/environment-variables.md`, `docs/reference/app-processes-and-schedules.md`, and `docs/reference/deployments.md` already preserve App source ownership, Node and Cluster Route scope, target-resolved environment values, App-owned production definitions, stopped target copies, persistent SQLite data, and explicit first deployment.
- `docs/README.md` already routes operators to the AppInstance cloning reference.
- `docs/generated/context.json` was rebuilt by `composer docs-build` and had no byte change.

Reported:
- none.

Verification: `composer docs-build`, `composer docs-lint`, and `git diff --check` passed. The documentation change is committed as `a1a3dc4eb6e8169c42dfd51b0d45adf9015039c7` (`docs: describe candidate clone CLI`).

## Acceptance map

| Criterion | Boundary | Focused proof |
| --- | --- | --- |
| 1. The SDK sends the exact clone payload, distinguishes omitted optional branch and SQLite path values, and returns the typed AppInstance and sole preview Route without placement policy. | `CloneAppInstanceRequest`, the existing AppInstance and Route DTOs, SDK public-contract guidance, and the clone request unit test. | `packages/php-sdk/tests/Unit/Requests/AppInstances/CloneAppInstanceRequestTest.php`, selected through `cd packages/php-sdk && composer test:affected`, asserts POST endpoint and JSON bytes, null omission versus supplied values, request ID, production AppInstance fields, embedded Route identity and hostname, bounded errors, and the absence of SDK placement or filesystem behavior. |
| 2. `instance:clone CANDIDATE NODE NAME` uses typed SDK operations, requires unambiguous scripted selectors, and performs no local shell execution. | `CloneInstanceCommand`, numeric candidate and Node input validation, `CloneAppInstanceRequest`, and the existing retained-release request. | `apps/cli/tests/Feature/Instances/CloneInstanceCommandTest.php` and `apps/cli/tests/Feature/CommandSurfaceTest.php`, selected through `cd apps/cli && composer test:affected`, assert the exact signature, positive numeric IDs for noninteractive and JSON calls, no prompt, request order and bodies, optional omission, one clone mutation, and no process, shell, SSH, sudo, or filesystem adapter use. |
| 3. Clone output identifies the target, configured branch, actual preview hostname, and absent first release; help covers source and App definition selection, target-only cleanup, production Node TLD, environment updates, and separate deployment. | Clone human and JSON renderers, the returned embedded Route, existing deployment-release DTO, clone help, and corrected `node:provision --tld` help. | `apps/cli/tests/Feature/Instances/CloneInstanceCommandTest.php` asserts exact human and JSON results for target ID, selected branch, Route hostname, null selected release, request IDs, and every required help statement; `apps/cli/tests/Feature/CommandSurfaceTest.php` fixes the registered surface. |
| 4. Real clones with and without SQLite are idempotent, can be configured and explicitly deployed, and leave source runtime state unchanged. | Candidate CLI and shipped SDK/Gateway operations on the standard Incus discovery topology. | Reproducible discovery observation `candidate-clone-cli` after independent plan approval: run each clone twice with identical input and compare target IDs, configure target environment and deployment steps, deploy explicitly, inspect the optional target SQLite seed, and compare source code, database, Process, Schedule, and served-state observations before and after. |
| 5. Maintained documentation covers candidate clone commands and production Node-TLD setup, with current generated context. | Documentation section above. | Root `composer docs-build && composer docs-lint`; the generated context remains byte-identical after the build. |
| 6. Focused affected tests, both changed-project checks, and the exact clean-candidate repository gate pass. | Changed SDK, CLI, guidance, tests, documentation, and repository quality boundaries. | Run `cd packages/php-sdk && composer test:affected` and `cd apps/cli && composer test:affected`, then each project's `composer check`; on the committed clean candidate the retained Builder runs root `composer check`, which executes all five project checks and TIA suites and records the exact-head `result.json`. |

## Incus observations

Incus: required; flow: discovery. After independent preflight approval, acquire the standard ORB-227 discovery topology and use the existing `bin/e2e-topology shell`, `exec`, `sync`, and `verify` commands for `candidate-clone-cli`.

Prepare two eligible clean candidate AppInstances and one active production Node with its own TLD, using separate Apps so both clone variants can target the same production Node without violating placement. Record each candidate's commit and configured branch, stored environment state, Process and Schedule identities and desired states, served response, and SQLite digest and queue marker where applicable.

From the operator side, run the candidate CLI command without a SQLite path and repeat the identical request. Record both exits, target IDs, configured branch, actual preview hostname, release state, request IDs, and source observations. Update and synchronize a target-only environment value, configure deployment steps, deploy explicitly, and record the first selected release and target response.

Repeat the sequence for the second App with an explicit candidate SQLite path. Confirm the repeated request returns the same target, inspect the installed target database and target-only queue cleanup boundary, configure and deploy it through the separate commands, and recheck that neither candidate's source code, database digest, Process or Schedule desired state, nor served response changed. Finish with topology verification and record exact setup, commands, identifiers, outputs, exits, before-and-after observations, and cleanup in `.loop/development.md`.

Use disposable sample data and reversible target configuration only. Proof instrumentation and `observed_inputs` are not required. Discovery development only; isolated acceptance proof will not run.

## Implementation order

1. Add the failing SDK clone request test for endpoint, exact JSON, omission and supplied-value handling, AppInstance and preview Route decoding, request ID, safe error, and SQLite-path redaction; update the SDK public-contract guidance and its repository test for the added operation.
2. Implement `CloneAppInstanceRequest` as a narrow Saloon JSON request that reuses the existing AppInstance response and leaves all clone policy to the Gateway; run the SDK affected tests before refactoring.
3. Add failing CLI command and surface tests for the signature, explicit candidate and Node IDs, required target and preview names, typed request ordering and bodies, optional values, errors, and noninteractive and JSON behavior.
4. Implement the thin clone command. Validate selectors before mutation, send the clone once, require the returned embedded Route for output, fetch the target's current release state through the existing SDK request, and render deterministic human and JSON results with correlated request IDs.
5. Extend clone and Node-provision help tests, then write the source-versus-App definition, target-only cleanup, production Node-TLD, environment-update, and separate-deployment guidance without adding those operations to clone execution.
6. Run affected tests and `composer check` in `packages/php-sdk` and `apps/cli`; after independent plan approval, acquire discovery and record `candidate-clone-cli`; then commit the candidate and let the retained Builder run root `composer check` on the exact clean head.

## Must preserve

- ADR 0044: the Gateway remains the authority for encrypted AppInstance environment configuration; cloning duplicates stored candidate values into independent target values, resolves references for the target during synchronization, exposes no values, and does not run application commands, refresh caches, or restart processes.
- ADR 0046: the target owns its configured deployment branch and ordered application steps, and every deployment remains a separate explicit request, including the first; cloning does not select a release, infer or run application commands, alter persistent target data during deployment, retry deployment, or create deployment history.
- ADR 0047: cloning requires an existing eligible development or production candidate, creates another AppInstance of that candidate's App on the selected production Node, inherits the candidate branch unless explicitly overridden, prepares source from the App repository, and copies no candidate working directory, generated files, dependencies, logs, caches, or local PHP-FPM tuning.
- ADR 0047: the Gateway refuses dirty or unavailable candidate source, owns production placement and preview availability, derives the sole private preview Route from the destination Node's own TLD, and keeps final hostname replacement separate; the SDK and CLI transport selectors and report the actual result without applying those policies locally.
- ADR 0047: optional SQLite cloning uses one explicit source path and a consistent live snapshot, while omission creates no database; source code, configuration, data, queues, processes, and schedules remain unchanged, and external storage and target application-data preparation remain operator-owned.
- ADR 0047: target managed Processes and Schedules stay stopped, cloning remains separate from application deployment and step execution, and an identical repeat returns the completed target without replacing its configuration, data, runtime state, or hostname.
- ADR 0048: production cloning selects production-applicable definitions from the App rather than candidate overrides, creates independent AppInstance-owned copies with target placement and identity, leaves App definitions and existing copies independent, preserves completed copies and their runtime state on retry, and installs Schedule timers disabled and stopped until a separate explicit activation.
- Existing SDK invariants: Saloon requests preserve caller values, omit only contract-defined nulls, return bounded typed DTOs, preserve structured errors and request IDs, redact credential-bearing values including SQLite paths, expose no generic transport escape hatch, and keep Gateway business and remote-execution policy outside the SDK.
- Existing CLI invariants: operator commands remain stateless, thin, HTTP-only, deterministic in human and JSON modes, prompt-free with explicit scripted input, correlated through request IDs, and bounded against credential or remote-value disclosure.

## Open questions

none

## Deviations

- Acceptance criterion 6 retains stale generic wording for all five full no-TIA CI suites. Current repository policy maps that wording to focused affected TIA tests during development, each changed project's `composer check`, and the retained Builder's root `composer check` with TIA on the exact clean candidate. The orchestrator should align the issue text with this policy; the acceptance outcome is unchanged and this policy correction is not a planning stop.

## Review findings
