# Feature plan

Issue: ORB-157
Review verdict: implementation authorized by dispatch

## Outcome

Each Gateway FPM convergence validates and publishes from one invocation-local
stage. Concurrent convergence cannot replace or clean another invocation's
candidate.

## Code boundaries

In:
- `apps/gateway/app/Infrastructure/Gateway/NativeGatewayFpmConverger.php`
- `apps/gateway/tests/Feature/Infrastructure/Gateway/NativeGatewayWebConvergerTest.php`

Out:
- Architecture redesign and generic publication frameworks.
- Other subsystems.
- Incus harness implementation.

## Documentation

Audit scope: `docs/reference/php-runtime.md`, backed by the Gateway FPM
converger, its feature test, and accepted ADR 0021.

Fixed:
- None.

Reported:
- None.

Unchanged:
- The reference already owns the operator-visible effective validation,
  root-managed publication, reload, and runtime behavior. Invocation-local
  temporary paths are an internal correctness mechanism and add no maintained
  product contract.

## Acceptance map

| Criterion | Boundary | Focused proof |
| --- | --- | --- |
| Interleaved invocations keep distinct main and pool candidates. | `NativeGatewayFpmConverger::converge()` local stage paths. | Reentrant contention scenario in `NativeGatewayWebConvergerTest.php`. |
| Failure cleans only its own stage and preserves the primary failure. | Exception cleanup in `NativeGatewayFpmConverger`. | Validation-failure scenario in `NativeGatewayWebConvergerTest.php`. |
| Effective pool conflict blocks replacement/reload while root ownership, permissions, and atomic installation remain. | Stage shell, `php-fpm8.5 --test`, and same-filesystem `mv`. | Feature regression plus Incus fixture. |
| OS boundary passes on an isolated topology. | Gateway host PHP-FPM files and service. | Incus action `gateway-fpm-stage-contention`. |
| Gateway checks pass. | `apps/gateway`. | `composer check`. |
| Repository suites pass. | Monorepo. | `PHPRC=/dev/null bin/test`. |

## Implementation order

1. Add focused contention and failure-cleanup regressions.
2. Derive one random stage root per convergence and pass its main and pool paths through stage, validation, publication, and cleanup.
3. Validate the shell scenario on discovery and commit the proof plan and fixture.
4. Wait for the orchestrator's reserved current-main evidence window before final integration, root suites, and immutable proof.

## Must preserve

- Validation covers the replacement plus every other effective live pool.
- Candidate files and directories remain root-owned with modes `0644` and `0755`.
- Installation remains an atomic move on the `/etc/php/8.5/fpm` filesystem.
- A validation failure remains the reported `gateway.fpm_config_invalid` failure.
- A failed invocation never reloads PHP-FPM.

## Proof decision

- `.loop/proof/ORB-157.json` is mutating because the action publishes temporary
  Gateway pool variants and injects a conflicting effective pool before it
  restores the original root-owned live pool and service.
- `observed_inputs` is `false`. The acceptance action executes the changed
  Gateway CLI boundary and real PHP-FPM validation, but it cannot produce the
  complete `app-dev:cli`, `gateway:cli`, and `gateway:fpm` PHP observations
  required for honest PCOV collection.
- Discovery attempt `8f3cb64f79ee819b548983095b90b848` passed the complete
  fixture and post-recovery topology verification from product checkpoint
  `603bfad080c89d4f14fafb1d7937e657d5f09206`.

## Open questions

- None.

## Deviations

- None.

## Review findings

- None.
