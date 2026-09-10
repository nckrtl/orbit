# Feature plan

Issue: ORB-210
Review verdict: PENDING

## Outcome

Operators can import, update, and synchronize an AppInstance environment through three safe SDK-backed CLI commands with value-free output.

## Code boundaries

In:
- `apps/cli/app/Commands/Environment/`: define the three thin SDK-backed commands and their shared selector, output, and failure behavior.
- `apps/cli/tests/Feature/Environment/EnvironmentCommandsTest.php`: prove request cardinality, exact value preservation, safe failures, and human and JSON output.
- `apps/cli/tests/Feature/CommandSurfaceTest.php`: add the three commands and their exact options to the public inventory.
- `apps/cli/.ai/rules/commands.md`, `apps/cli/AGENTS.md`, and `apps/cli/tests/Feature/BoostGuidanceTest.php`: state and verify package guidance for the environment command boundary.
- `.loop/proof/ORB-210.json` and flat proof fixtures: prove the development and production CLI flows on the standard topology without adding product files to the candidate branch.

Out:
- Keep `apps/gateway/` and `packages/php-sdk/` unchanged because the CLI consumes the existing typed operations and leaves target and value policy with the Gateway.
- Keep harness code under `apps/e2e/` and `bin/e2e-*` unchanged; issue-specific fixture contract tests may be added only under permitted `apps/e2e/tests/Feature/` or `apps/e2e/tests/Unit/` paths if a fixture needs them.
- Do not add local or remote file handling, SSH, dotenv parsing, placeholder resolution, encryption, target policy, value display, framework-cache changes, or process restarts to the CLI.

## Documentation

- `docs/reference/environment-variables.md`: add the public CLI commands, selectors, options, value-preserving shell quoting, stored-only import/update behavior, explicit synchronization, safe output, and separate cache/process steps.
- Audit scope: `docs/README.md`, `docs/concepts.md`, `docs/architecture.md`, `docs/domains/applications.md`, `docs/reference/environment-variables.md`, and attached ADR 0044. Fixed: the environment reference lacked the CLI behavior delivered by this issue. Reported: none.

## Acceptance map

| Criterion | Boundary | Focused proof |
| --- | --- | --- |
| 1. Import, update, and sync by hostname or numeric ID | `apps/cli/app/Commands/Environment/`, environment feature tests | `cd apps/cli && vendor/bin/pest --no-tia --compact tests/Feature/Environment/EnvironmentCommandsTest.php` |
| 2. Exactly one typed SDK request and no local target or workload operation | Environment commands and feature tests | `apps/cli/tests/Feature/Environment/EnvironmentCommandsTest.php` |
| 3. Exact string preservation and omitted-value refusal | Update command and feature tests | `apps/cli/tests/Feature/Environment/EnvironmentCommandsTest.php` |
| 4. Value-free human and JSON result contract | Shared environment command output and feature tests | `apps/cli/tests/Feature/Environment/EnvironmentCommandsTest.php` |
| 5. Bounded, value-free failures without prompts | Shared Gateway boundary and environment feature tests | `apps/cli/tests/Feature/Environment/EnvironmentCommandsTest.php` |
| 6. Development import, update, unchanged file, sync, and idempotent sync | `.loop/proof/ORB-210.json` and lifecycle fixture | Incus action `environment-cli-lifecycle` |
| 7. Production flow by ID and hostname without a database or restart | `.loop/proof/ORB-210.json` and production fixture | Incus action `environment-cli-production` |
| 8. Help, inventory, package guidance, and environment reference | command signatures, command rules, surface/guidance tests, `docs/reference/environment-variables.md` | `cd apps/cli && vendor/bin/pest --no-tia --compact tests/Feature/CommandSurfaceTest.php tests/Feature/BoostGuidanceTest.php`; `composer docs-lint` |
| 9. CLI checks and repository CI suites | all CLI boundaries | `cd apps/cli && composer check`; five full no-TIA suites in CI; focused affected Pest tests locally |

## Incus observations

`observed_inputs: true`. Setup exercises the CLI on `gateway` and `app-dev`, both acceptance actions reach Gateway FPM, the development action runs on `app-dev`, and the production action runs on `gateway`, so both observed phases collect all required PHP surfaces.

## Implementation order

1. Add and test the shared environment command boundary plus import, update, and sync request wiring.
2. Update the exact command inventory and generated package guidance, then verify help text.
3. Create fixture-backed standard-topology proof actions for the development and production flows.
4. Run focused CLI and fixture tests, CLI checks, and documentation checks.
5. Integrate current main, commit the clean candidate, publish artifacts, prove, capture, release, push, and verify the remote head.

## Must preserve

- ADR 0044 keeps Gateway storage authoritative, encrypted, validated, and value-free; imports are explicit and conflicts require explicit replacement.
- ADR 0044 keeps stored changes separate from synchronization, resolves references at the destination, preserves literal values including APP_KEY, and derives file placement from the recorded AppInstance.
- ADR 0044 forbids local edit import during sync, application commands, cache refreshes, and process restarts.
- Existing shared Gateway output preserves request IDs and bounded JSON errors while environment values remain absent from output and exception text.
- ADRs 0049 and 0050 keep `.loop` artifacts off the candidate, bind them to the exact commit, capture complete proof evidence, and release successful proof and idle discovery resources before handoff.

## Open questions

- None.

## Deviations

- None.

## Review findings

- None.
