# Feature plan

Plan format: 1
Issue: ORB-224
Flow: discovery
Review verdict: PASS

## Outcome

Agents can inspect and edit both kinds of App runtime definition through typed SDK requests and deterministic CLI commands without executing or applying the submitted definitions locally.

## Code boundaries

In:
- `packages/php-sdk/src/Requests/Apps/` and `packages/php-sdk/src/Responses/Apps/`: add the ten typed list, create, show, replace, and remove transports for App process and Schedule definitions, including exact App/UUID routes, raw JSON definition bodies, request IDs, and bounded immutable item and collection responses.
- `packages/php-sdk/tests/Unit/Requests/Apps/RuntimeDefinitionRequestsTest.php`, `packages/php-sdk/tests/Unit/RepositoryGuidanceTest.php`, `packages/php-sdk/.ai/rules/public-contract.md`, and `packages/php-sdk/README.md`: prove and describe the expanded 88-operation SDK surface while keeping the retired generic Schedule surface excluded.
- `apps/cli/app/Commands/Apps/`: add the plural list commands and singular operation commands, with shared mode validation, safe definition-file reads, typed SDK dispatch, and human and JSON rendering.
- `apps/cli/tests/Feature/Apps/RuntimeDefinitionCommandsTest.php` and `apps/cli/tests/Feature/CommandSurfaceTest.php`: prove the four new commands, every option mode, pre-HTTP refusals, output and request-ID behavior, command omission from lists, safe failures, and the expanded visible command surface.

Out:
- `apps/gateway/`: keep the existing App definition routes, validation, authorization, persistence, and response envelope unchanged; the SDK and CLI consume that contract.
- Existing AppInstance-owned Process and Schedule records and runtime artifacts: definition edits do not reconcile or mutate copies, and copy edits do not mutate definitions.
- Remote execution and automatic Process or timer startup: CLI file handling sends JSON only and starts no local or remote command.
- `apps/e2e/` and `bin/e2e-*`: no harness, topology, or isolated-proof changes are needed for this automated HTTP and CLI surface.

## Documentation

- `docs/reference/app-processes-and-schedules.md`: adds the four App definition commands, their valid option combinations, process and Schedule JSON file examples, request-ID and safe-error behavior, command-safe list output, no-execution behavior, and explicit remove/add guidance for existing AppInstance copies.
- `docs/generated/context.json`: rebuilt from the maintained page so the documentation context remains current.
- Audit: no other in-scope maintained page conflicts with the existing Gateway behavior, ORB-224, or accepted ADR 0048; no findings remain for another owner.

## Acceptance map

| Criterion | Boundary | Focused proof |
| --- | --- | --- |
| 1. Both definition kinds have typed list, create, show, replace, and remove clients with exact request fields and bounded immutable responses. | PHP SDK requests, responses, public-contract inventory, and SDK runtime-definition tests. | `packages/php-sdk/tests/Unit/Requests/Apps/RuntimeDefinitionRequestsTest.php` through `cd packages/php-sdk && composer test:affected`. |
| 2. The plural commands list definitions, and each singular command selects show, create, replace, or remove from the exact `--id`, `--file`, and `--remove` combination before HTTP. | CLI App definition commands, command-surface inventory, and CLI runtime-definition tests. | `apps/cli/tests/Feature/Apps/RuntimeDefinitionCommandsTest.php` and `apps/cli/tests/Feature/CommandSurfaceTest.php` through `cd apps/cli && composer test:affected`. |
| 3. Human and JSON output retain request IDs and safe errors, collection output omits commands, and submitted file content is never executed or applied to existing copies. | CLI shared validation, file ingress, rendering, and runtime-definition tests. | `apps/cli/tests/Feature/Apps/RuntimeDefinitionCommandsTest.php` through `cd apps/cli && composer test:affected`. |
| 4. Maintained guidance documents the commands, definition files, and explicit instance-copy update boundary, with current generated context. | `docs/reference/app-processes-and-schedules.md` and `docs/generated/context.json`. | `composer docs-build` followed by `composer docs-lint`. |
| 5. Changed projects and the repository candidate pass current local quality policy. | PHP SDK and CLI source, tests, and guidance plus the committed candidate. | `cd packages/php-sdk && composer check`, `cd apps/cli && composer check`, and the Builder's root `composer check` receipt for the exact clean candidate; focused affected tests run in both changed projects. |

## Incus observations

Incus: not required. Focused PHP SDK and CLI tests, changed-project checks, documentation lint, and the exact-candidate Builder gate cover this automated transport and command surface locally.

## Implementation order

1. Add the runtime-definition SDK response boundary and ten request classes under TDD, including raw JSON bodies, exact methods and routes, bounded data, request-ID propagation, and standard safe error behavior.
2. Update the SDK operation inventory, repository guidance, guidance tests, and README from 78 to 88 operations, naming App process and Schedule definitions while retaining the ban on the retired generic Schedule surface.
3. Add shared CLI definition behavior plus the four public commands under TDD, validating the operation mode and readable file before connector use, then dispatching only the matching typed SDK request.
4. Render command-safe collection tables and complete item or mutation results for humans and JSON, preserving request IDs and the shared safe-error boundary without executing file content.
5. Update the CLI command-surface inventory, then run affected tests, both changed-project checks, documentation lint, and the final exact-candidate Builder gate.

## Must preserve

- ADR 0048: an App owns reusable process and Schedule definitions with development and production applicability.
- ADR 0048: adding, editing, or removing a definition must not change existing AppInstance copies or their runtime state; an operator owns explicit changes to each copy.
- ADR 0048: modifying or removing an AppInstance copy must not change its App definition, and removing an AppInstance must retain App definitions.
- The SDK transports Gateway policy without recreating validation, authorization, execution, or presentation logic; it preserves exact methods, routes, JSON content, structured errors, and request IDs.
- List responses stay command-safe, while bounded item responses retain the complete definition returned by the Gateway.
- Every invalid CLI operation combination and unreadable definition file fails before HTTP, and no CLI command executes local or remote definition content.
- Existing App, Process, Schedule, and shared Gateway failure behavior remains unchanged outside the new definition surface.

## Open questions

none

## Deviations

- Policy mapping: the issue's stale request for all five full no-TIA CI suites maps in discovery flow to focused TIA development tests, both changed-project `composer check` commands, and the Builder's root `composer check` on the exact clean candidate. The acceptance outcome is unchanged; the orchestrator should align the issue text with the current implementation-loop policy.

## Review findings
