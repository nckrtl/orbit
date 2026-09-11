# Feature plan

Plan format: 1
Issue: ORB-222
Flow: discovery
Review verdict: PASS

## Outcome

An operator can configure and inspect an AppInstance deployment, stream deploy or rollback progress safely in human or NDJSON form, and rely on the CLI exit status without the CLI taking deployment execution ownership from the Gateway.

## Code boundaries

In:
- New deployment command classes and a narrow shared deployment command base under `apps/cli/app/Commands/Instances/`: add `instance:deployment-config INSTANCE [--file=PATH]`, `instance:deploy INSTANCE`, `instance:rollback INSTANCE --release=NAME`, and `instance:releases INSTANCE`; validate the positive instance ID and required release, read one complete JSON configuration file into `DeploymentStepInput` values, and send exactly one corresponding typed SDK request for each invocation.
- The shared deployment stream renderer under `apps/cli/app/Commands/Instances/` and existing safe failure facilities in `apps/cli/app/Commands/GatewayCommand.php` and `apps/cli/app/Support/GatewayFailureRenderer.php` when needed: consume the SDK stream once; render each phase and named step; label and control-escape incremental stdout and stderr for human terminals; re-emit validated events as compact NDJSON with base64 output in JSON mode; retain request IDs and the last known selected release; close the stream on Ctrl-C; return success only for a succeeded terminal result; and map failed, malformed, truncated, interrupted, or transport outcomes to shared safe nonzero errors without retrying the request.
- `apps/cli/tests/Feature/Instances/DeploymentCommandsTest.php` and `apps/cli/tests/Feature/CommandSurfaceTest.php`: cover exact signatures, typed request bodies and DTOs, complete configuration parsing, human and JSON output, byte-safe incremental rendering, request-ID and safe-error behavior, terminal and truncation exits, explicit closure on interruption, and one request per command.

Out:
- `apps/gateway/` stays unchanged; Gateway authorization, validation, deployment phases, execution order, streaming, cancellation, release selection, and failure policy remain authoritative.
- `packages/php-sdk/` stays unchanged; the CLI uses the five shipped typed deployment operations and their closeable stream without changing transport, event validation, timeout, or replay behavior.
- `apps/e2e/`, `bin/e2e-*`, and `.loop/proof/` stay unchanged; the standard discovery topology and commands supply the `deployment-cli-lifecycle` observation without harness code, a proof plan, proof fixtures, or isolated proof instrumentation.
- Local SSH, shell, sudo, application command inference, and infrastructure mutation stay out of the CLI; the configuration file is data for one typed SDK request and is never executed locally.
- Automatic retries, stream replay, automatic rollback, database recovery, release deletion, deployment runs, output persistence, and deployment history stay unchanged and are not added.

## Documentation

### Documentation audit

Scope: ORB-222; the `apps/cli` and AppInstance documentation context plus the production deployment reference required by the `docs` label.

Fixed:
- `docs/reference/deployments.md`: the page described the Gateway and PHP SDK deployment surfaces but omitted the operator CLI contract; it now states every deployment command, the complete JSON configuration-file shape, human phase and escaped incremental output, JSON and NDJSON output, request IDs, safe errors, terminal success and nonzero failure rules, selected-release reporting, stream truncation, and Ctrl-C closure without resubmission.

Audited without changes:
- `docs/architecture.md` already keeps the CLI on the HTTP boundary, gives release ownership to Orbit, and gives application command selection to the operating agent.
- `docs/concepts.md` already defines AppInstance and effective web root consistently with production release selection.
- `docs/domains/applications.md` already routes production deployment details to the deployment reference and keeps explicit deployment separate from provisioning and layout conversion.
- `docs/reference/environment-variables.md`, `docs/reference/php-runtime.md`, and `docs/reference/appinstance-removal.md` already preserve the environment, SQLite, runtime-cache, release-selection, and retained-content boundaries touched by the commands.
- `docs/README.md` already routes operators to the production deployment reference.
- `docs/generated/context.json` was rebuilt by `composer docs-build` and had no byte change.

Reported:
- none.

Verification: `composer docs-build`, `composer docs-lint`, and `git diff --check` passed. The documentation change is committed as `b2adf64121620136d03f8517da5e543febaa3cbc` (`docs: describe deployment CLI commands`).

## Acceptance map

| Criterion | Boundary | Focused proof |
| --- | --- | --- |
| 1. Deployment configuration show and complete-file replacement, deploy, rollback, and retained-release listing use the corresponding typed SDK operations. | Deployment command classes, complete configuration-file decoder, `DeploymentStepInput` mapping, and command-surface inventory under `apps/cli/`. | `apps/cli/tests/Feature/Instances/DeploymentCommandsTest.php` and `apps/cli/tests/Feature/CommandSurfaceTest.php`, selected through `cd apps/cli && composer test:affected`, assert exact arguments, options, request classes, DTO input, one request, and response rendering. |
| 2. Human streaming renders phases and incremental stdout and stderr safely; JSON mode emits only the documented NDJSON events; every command preserves request IDs and shared safe errors. | Shared deployment stream renderer, terminal byte escaping, exact event projection, JSON output path, and `GatewayFailureRenderer`. | `apps/cli/tests/Feature/Instances/DeploymentCommandsTest.php`, selected through `cd apps/cli && composer test:affected`, uses chunked phase/output/result streams with control and invalid UTF-8 bytes and asserts arrival order, safe human text, exact NDJSON lines, no prompts or prose, request IDs, and bounded errors. |
| 3. Only a succeeded terminal result exits zero; failed, malformed, truncated, or interrupted streams exit nonzero, preserve the last known selected release when available, close on Ctrl-C, and never resubmit. | Stream lifecycle and terminal-result state in the shared deployment command base, SDK stream closure, signal trap, and failure mapping. | `apps/cli/tests/Feature/Instances/DeploymentCommandsTest.php`, selected through `cd apps/cli && composer test:affected`, covers succeeded and failed results, failure before and after a selected release, malformed and missing terminal events, simulated Ctrl-C, response closure, and an exact request count of one. |
| 4. A real operator-side command exposes the first step output before delayed completion, deploys updated branch code, lists retained releases, rolls back explicitly, and leaves persistent SQLite content unchanged. | The candidate CLI commands using the shipped SDK and Gateway deployment lifecycle on the standard Incus discovery topology. | Reproducible discovery action `deployment-cli-lifecycle` after independent plan approval: timestamp first output before a delayed step finishes, update the configured remote branch and deploy its changed response, list both retained releases and current selection, roll back to the named earlier release, and compare the persistent SQLite path and digest before and after. |
| 5. Maintained documentation covers commands, JSON streams, configuration files, exits, and interruption, with current generated context. | Documentation section above. | Root `composer docs-build && composer docs-lint`; the generated context remains byte-identical after the build. |
| 6. Focused affected tests, the CLI project checks, and the exact clean-candidate repository gate pass. | Changed CLI code, tests, documentation, and repository quality boundaries. | Run `cd apps/cli && composer test:affected`, then `cd apps/cli && composer check`; on the committed clean candidate the retained Builder runs root `composer check`, which runs all five project checks and TIA suites and records the exact-head `result.json`. |

## Incus observations

Incus: required; flow: discovery. After independent preflight approval, acquire the standard ORB-222 discovery topology and use the existing `bin/e2e-topology shell`, `exec`, `sync`, and `verify` commands for `deployment-cli-lifecycle`.

Create a production AppInstance whose configured pre-activation step prints a unique first marker, waits for a bounded delay, and prints a completion marker. Run the candidate `instance:deploy` command from the operator side and record command start, first-marker arrival, completion-marker arrival, terminal result, request ID, selected release, and exit status to show incremental output before completion.

Record the initial served response, retained release list, selected release, and persistent `database.sqlite` path and digest. Advance the configured remote branch with a distinct response, deploy again, record the changed served response and new selected release, list the retained releases, explicitly roll back by the earlier release name, and record the restored response and unchanged SQLite path and digest. Finish with topology verification and record exact setup, commands, identifiers, timestamps, outputs, exits, release names, HTTP responses, SQLite observations, cleanup, and restoration in `.loop/development.md`.

Use only disposable sample-application content and reversible configuration. Proof instrumentation and `observed_inputs` are not required. Discovery development only; isolated acceptance proof will not run.

## Implementation order

1. Add failing deployment command and command-surface tests for the four command names, exact arguments and options, positive instance IDs, required rollback release, active Gateway profile handling, typed SDK request selection, and one request per invocation.
2. Implement configuration show and replacement. Parse one readable complete JSON object with exact `branch` and `steps` data into typed SDK inputs, reject unreadable or malformed local input through bounded shared errors without exposing file content or commands, and render the correlated stored configuration in human and JSON forms.
3. Implement retained-release listing with the typed SDK response, deterministic human output, selected-release and request-ID display, and the exact JSON object response.
4. Add failing stream presentation tests for every phase, optional step name, split incremental stdout and stderr, terminal-control and invalid UTF-8 bytes, exact NDJSON event projection, selected-release reporting, and absence of prompts or extra prose in JSON mode.
5. Implement the shared one-pass stream renderer. Escape unsafe terminal bytes in human mode, preserve application bytes as `data_base64` in JSON mode, keep event order and request identity, render the final outcome once, and route refusals and invalid streams through the existing safe error boundary.
6. Add failure and interruption tests, then trap Ctrl-C around the active stream, close and clear it deterministically, return nonzero for interruption and every non-success terminal condition, and prove that no command retries, replays, or sends a second deployment request.
7. Run `composer test:affected` and `composer check` in `apps/cli`; after independent plan approval, acquire discovery and record `deployment-cli-lifecycle`; then commit the candidate and run the retained Builder's root `composer check` on the exact clean head.

## Must preserve

- ADR 0046: Orbit owns production release preparation, activation, and explicit code rollback within the AppInstance's recorded home, and every deployment remains an explicit request, including the first deployment.
- ADR 0046: the production AppInstance owns its configured deployment branch and ordered application steps; each deployment fetches that branch into one new release and accepts no caller-supplied commit identifier.
- ADR 0046: persistent environment configuration and optional SQLite data stay outside replaceable releases; deployment synchronizes stored environment values before steps and neither deploy nor rollback replaces production data.
- ADR 0046: the operating agent owns application command selection and ordering; Orbit adds no inferred application command and executes configured steps as the AppInstance Unix user with bounded execution time and streamed output.
- ADR 0046: pre-activation success precedes atomic release selection, PHP cache refresh remains between selection and post-activation steps, a pre-switch failure keeps the previous selection, and a post-switch failure keeps the new selection.
- ADR 0046: a failed deployment reports its boundary without automatic retry or rollback; explicit rollback only selects retained code and refreshes its runtime cache, while application and database recovery remain operator-owned.
- ADR 0046: Orbit stores no deployment run, deployment history, output history, or historical step snapshot, and it does not remove retained releases automatically.
- Existing CLI invariants: operator commands remain thin and HTTP-only, use typed SDK requests, expose JSON mode, preserve valid Gateway request IDs, and keep credentials, configured commands, application output, raw exceptions, and malformed local file contents out of generic errors and diagnostic state.
- Existing SDK invariants: deployment streams are lazy, single-use, correlated, strictly validated, closeable, and never retried or replayed; the CLI closes the stream when it stops consuming it and never treats a partial stream as success.

## Open questions

none

## Deviations

- Acceptance criterion 6 retains stale generic wording for all five full no-TIA CI suites. Current repository policy maps that wording to affected TIA tests during development, `apps/cli` `composer check`, and the retained Builder's root `composer check` with TIA on the exact clean candidate. The orchestrator should align the issue text with this policy; the acceptance outcome is unchanged and this policy correction is not a planning stop.

## Review findings
