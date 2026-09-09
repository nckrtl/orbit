# Feature plan

Issue: ORB-169
Review verdict: PENDING

## Outcome

Trusting an existing Gateway updates only the certificate pin of the profile that the command read. A same-name URL or pin replacement that completes first remains authoritative and makes the stale save fail after reporting that operating-system trust may already be complete.

## Code boundaries

In:
- `apps/cli/app/Repositories/GatewayConfigRepository.php`: compare and update one existing profile pin under the stable configuration lock.
- `apps/cli/app/Services/Trust/GatewayRootCaTrustService.php`: use the guarded pin update after TLS verification and operating-system trust, while retaining a separate full replacement path.
- `apps/cli/app/Commands/Gateway/GatewayAddCommand.php`: select the full replacement path used by registration.
- `apps/cli/tests/Unit/GatewayConfigRepositoryTest.php`: repository conflict and idempotency coverage.
- `apps/cli/tests/Feature/Gateway/GatewayTrustCommandTest.php`: late contention, partial completion, active selection, TLS, request ID, and redaction coverage.
- `docs/reference/gateway-trust.md`, `docs/README.md`, and `docs/generated/context.json`: maintained operator contract and generated routing context.

Out:
- Gateway or CLI architecture redesign.
- Unrelated command behavior and generic workflow abstractions.
- Incus harness implementation.

## Documentation

Audit scope: ORB-169; pages selected by `composer docs-context -- --component=apps/cli --concept=Gateway`, plus the required trust reference.

Fixed:
- `docs/reference/gateway-trust.md`: the trust lifecycle, guarded save conflict, partial operating-system completion, and recovery contract were absent; add the owning reference.
- `docs/README.md`: the reference router could not lead readers to Gateway trust; add the page link.
- `docs/generated/context.json`: rebuild generated context for the new page and component/concept coverage.

Reported:
- None.

Verification: `composer docs-build` and `composer docs-lint` passed with zero findings.

## Acceptance map

| Criterion | Boundary | Focused proof |
| --- | --- | --- |
| Same-name URL or pin replacement wins and stale save fails | Repository guarded update and trust service | Repository unit conflict cases; command feature contention cases |
| Active-only switch and identical pin update do not conflict | Repository comparison excludes active selection and accepts the desired pin | Repository unit cases; command feature cases |
| Partial OS trust is visible; TLS order, request IDs, and redaction remain intact | Trust service order and bounded command failure | Command feature assertions for installed CA, two TLS phases, exact request ID, and secret absence |
| Isolated OS boundary | Existing three-node Incus profile, no harness change | `gateway-trust-profile-contention` discovery and immutable proof action |
| Maintained reference and context are current | Root docs | `composer docs-build`; `composer docs-lint` |
| Owning project passes | CLI | `PHPRC=/dev/null composer check` |
| Repository suites pass | Root | `PHPRC=/dev/null bin/test` |

## Implementation order

1. Audit and write the maintained Gateway trust reference, then rebuild its context.
2. Add the repository guarded pin operation and unit regressions.
3. Split existing-profile trust from Gateway registration/replacement in the service and command.
4. Add feature regressions for late profile contention, idempotency, active selection, partial OS completion, TLS order, request IDs, and redaction.
5. Run focused and CLI project checks and commit the clean product/docs checkpoint.
6. Write the Incus action, exercise it on discovery, then coordinate current-main integration and immutable proof with the root orchestrator.

## Must preserve

- `gateway:add` continues to register or fully replace a same-name profile.
- A switch of `active_gateway` remains independent of profile content.
- A concurrent save of the same target pin is idempotent.
- Bootstrap fetch, pinned HTTPS verification, operating-system installation, and profile save remain ordered.
- Every failure preserves the valid Gateway request ID and emits no certificate, path, URL credential, or underlying exception detail.
- The operating-system trust store and local profile file are not presented as one transaction.
- The existing stable lock, atomic publish, file validation, and private permissions remain unchanged.

## Open questions

- None.

## Deviations

- None.

## Review findings

- None yet.

## Validation

- Focused repository and trust command tests: 37 passed, 172 assertions.
- Gateway registration regression: 20 passed, 82 assertions.
- CLI `PHPRC=/dev/null composer check`: passed; 609 tests and 3,620 assertions, with three unchanged analysis warnings outside this issue's paths.
- Documentation build and lint: passed with zero findings.
- Root suite, discovery, and immutable Incus proof: pending the coordinated windows.
