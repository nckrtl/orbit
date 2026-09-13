# ORB-308 development record

Flow: discovery. Incus: not required.

## Acceptance checks

1. Process API/model AppInstance or Node: `apps/gateway/tests/Feature/Api/ProcessesTest.php` node add/list and workspace rejection; `ProcessTargetType` Node case.
2. CLI add/list `--node=`: `apps/cli/tests/Feature/Processes/ProcessCommandsTest.php` numeric and `beast` name resolution.
3. Deterministic runtime identities: existing `orbit-process-{id}-{name}` plus `SystemdProcessRendererTest` node unit without EnvironmentFile.
4. Node Processes survive AppInstance removal: `AppInstanceProcessCascadeTest`.
5. Node removal cleanup: `RemoveNodeTest` cascade, `node.process_cleanup_failed` retry, offline forget.
6. AppInstance compatibility: existing Processes API and action tests.
7. Doctor both targets: `ProcessDoctorProbeTest`.
8. Docs: ADR 0069, `docs/concepts.md`, `docs/reference/app-processes-and-schedules.md`, `docs/reference/node-provisioning.md`.

## Project checks

- Gateway process-family Pest: 237 passed.
- Gateway PHPStan, Rector, Pint: passed.
- PHP SDK `composer check` and `composer test:affected`: passed.
- CLI Process/surface/error tests: 134 passed.
- CLI PHPStan, Rector, Pint: passed.
- `composer docs-build` and `composer docs-lint`: passed.

## Limits

- This clone has no published TIA baselines under `.git/orbit-tia/v1/published`. Fresh Gateway TIA selects the full suite and fails host WireGuard/bootstrap checks that are outside this change.
- CLI `composer guidance:check` / Boost ApplicationInfo fails here because Laravel Zero does not bind `db`.
- Root `composer check` was not completed as a Builder receipt for those reasons.
