# Feature plan

Issue: ORB-211
Review verdict: Direct implementation authorized; independent review remains external.

## Outcome

Add one reusable Gateway filesystem boundary that can preflight a recorded AppInstance for either read or write access and can atomically install a supplied complete `.env` file without exposing its bytes.

## Code boundaries

In:
- `apps/gateway/app/Domain/AppInstances/Environment`: typed write result and reusable preflight/writer contracts.
- `apps/gateway/app/Infrastructure/AppInstances/RemoteAppInstanceEnvironmentAccess.php`: placement-bound remote read/write preflight and protected atomic writer.
- `apps/gateway/tests/Feature/Infrastructure`: focused preflight and writer behavior, including actual trace arguments.
- `docs/reference/environment-variables.md`: placement, preflight, protection, repeat, failure, and acknowledgement boundaries.
- `.loop/proof`: seven Incus actions named by the issue and fresh ORB-211-only fixtures.

Out:
- Public synchronization endpoints, SDK or CLI commands.
- Stored-value reads or decryption, placeholder resolution, dotenv rendering, and configuration snapshots.
- Configuration, owner, or Route operation locks.
- Import/update semantics, storage schema, local-file adoption, framework execution, cache refresh, and service or process restart.
- Harness code and production resources.

## Documentation

Audit scope: ORB-211; `docs/reference/environment-variables.md`, `docs/domains/applications.md`, `docs/architecture.md`, `docs/concepts.md`, and the accepted attached ADRs.

Fixed:
- `docs/reference/environment-variables.md`: added the current reusable remote replacement behavior, placement, preflight, protection, repeat, failure, and lost-acknowledgement boundaries.

Reported:
- None.

Verification: `composer docs-build` and `composer docs-lint` passed.

## Acceptance map

| Criterion | Boundary | Focused proof |
| --- | --- | --- |
| 1. Operation-selected placement preflight | Extend the existing preflight interface and recorded context path | `AppInstanceOperationPreflightTest.php`; `environment-file-placement` |
| 2. Affirmative write and capacity checks | Descriptor-based fixed Python observation through pinned SSH | `AppInstanceOperationPreflightTest.php`; `environment-file-preflight` |
| 3. Preserve import and teardown | Keep read semantics and existing teardown code unchanged | `AppInstanceOperationPreflightTest.php`; Gateway regressions |
| 4. Complete protected atomic install | Add the narrow writer and result contract | `AppInstanceEnvironmentWriterTest.php`; `environment-file-replacement` |
| 5. Confirmed failures and scoped cleanup | Candidate identity tracking, pre-rename revalidation, and atomic replace | `AppInstanceEnvironmentWriterTest.php`; `environment-file-failure` |
| 6. Lost acknowledgement and retry | Return an unconfirmed outcome for ambiguous transport results | `AppInstanceEnvironmentWriterTest.php`; `environment-file-retry` |
| 7. Repeat and mode repair | Compare complete candidate and retain matching protected destination identity | `AppInstanceEnvironmentWriterTest.php`; `environment-file-repeat` |
| 8. Secret and side-effect boundary | Sensitive method arguments, protected SSH input, bounded fixed observations | `AppInstanceEnvironmentWriterTest.php`; `environment-file-without-application-bootstrap` |
| 9. Documentation and suites | Update the owned reference and run all required gates | docs lint, Gateway check, root `bin/test` |

## Implementation order

1. Preserve import while extending the reusable preflight contract.
2. Add focused preflight tests and implementation.
3. Add the protected writer result, tests, and implementation.
4. Run focused and documentation checks.
5. Exercise every proof scenario on discovery and correct fixture failures together.
6. Run Gateway `composer check`, docs build/lint, and root `bin/test`.
7. Commit and push the exact candidate with the complete `.loop` workspace.

## Must preserve

- Existing environment import/update responses and storage behavior.
- Read preflight does not require write access or capacity.
- Teardown reachability and every non-environment source path.
- Recorded Node, runtime user, and placement remain the only authority.
- No raw environment bytes or remote output in diagnostics or trace arguments.
- ORB-212 owns synchronization orchestration, rendering/decryption, and concurrency.

## Open questions

- None. The nine criteria and attached accepted ADRs define all material behavior.

## Deviations

- None.

## Review findings

- None yet.

## Proof decision

Use the standard gateway, app-dev, and app-prod topology with no extension. The plan is mutating because fixtures change Gateway and guest state. Set `observed_inputs: true`; setup and acceptance cover Gateway CLI/FPM and app-dev CLI observations. Discovery remains separate from the final immutable proof.
