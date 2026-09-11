# ORB-221 development record

Issue: ORB-221
Flow: discovery
Incus: not required
Candidate: `a3cd7ff7b9e6bf65e6bb323850bddfaef9b83850`
Base: `71e7130ef3337e5e9d06e1e534835707507b5e2d`
Branch: `orb-221`

## Outcome

The PHP SDK now exposes typed deployment configuration, deploy, rollback, and retained-release operations. Deploy and rollback return a one-shot, closeable NDJSON reader that validates and bounds every event before yielding it.

## Acceptance evidence

1. Typed deployment requests and responses
   - `packages/php-sdk/tests/Unit/Requests/Deployments/DeploymentRequestsTest.php` covers all five exact methods and Gateway paths, bodyless reads, optional timeout omission, preserved explicit values, the empty deploy object, rollback release input, correlated configuration and retained-release DTOs, invalid typed responses, and command redaction.
   - The affected TIA run passed.

2. Incremental validated stream API
   - `packages/php-sdk/tests/Unit/Responses/Deployments/DeploymentStreamTest.php` covers a real Guzzle `StreamHandler` reading a local chunked HTTP/1.1 response, arbitrary PSR-7 chunk splits, valid phase, output, success, and failure events, exact schemas, the 32 KiB line and 16 KiB decoded-output limits, canonical base64, continuous sequence, request identity, terminal ordering, malformed and truncated streams, empty reads before EOF, cancellation closure, one-shot consumption, and output redaction.
   - The chunked-transport test sends the Gateway's 25 probe bytes, then waits 1.1 seconds after the first phase line before sending the result. It asserts that the first typed event is yielded within 0.75 seconds, before the result and EOF arrive.
   - A successful result is yielded only after EOF proves that it is terminal. Every malformed or truncated path closes the response and throws without yielding success.
   - The affected TIA run passed.

3. Bounded transport and no retry or replay
   - `packages/php-sdk/tests/Unit/Requests/Deployments/DeploymentTransportTest.php` covers both deploy and rollback with request-local `stream => true` and a finite 4,500-second socket idle-read timeout, inherited CA verification, disabled redirects, the 10-second connection timeout, NDJSON headers, response closure, pre-admission structured errors, and one-send/one-consumption behavior.
   - The structured-error test uses a non-seekable PSR-7 body and verifies the Gateway code, safe message, details, and request ID for both deploy and rollback. The body is captured and decoded once.
   - The affected TIA run passed.

4. Operation inventory, ordinary JSON transport, and diagnostics
   - `packages/php-sdk/tests/Unit/RepositoryGuidanceTest.php` verifies 78 concrete operations and the deployment inventory.
   - `packages/php-sdk/tests/Unit/Requests/Deployments/DeploymentTransportTest.php` verifies that ordinary JSON requests retain the connector's timeout and have no streaming option.
   - The request and stream tests verify that configured commands and decoded output stay out of generic debug, JSON, and serialization state.

5. Maintained documentation
   - `docs/reference/deployments.md` describes the five typed operations, ordinary JSON boundaries, incremental validated events, explicit closure, limits, TLS preservation, and no retry or replay.
   - `docs/tech-stack.md` links readers to the implementation-loop page that owns exact-candidate gate procedure.
   - `composer docs-lint` passed with 0 issues, 0 errors, and 0 warnings. Generated context remained current.

6. SDK public-contract guidance
   - `packages/php-sdk/.ai/rules/public-contract.md` permits configuration read and replacement, explicit deployment and rollback streams, and retained-release inspection. It preserves the retired schedule prohibition and all shipped Schedule behavior.
   - `composer guidance:check` passed with 7 tests and 103 assertions as part of the SDK check.

7. Focused checks and exact-candidate gate
   - `composer test:affected` in `packages/php-sdk` passed with 467 tests and 1,900 assertions.
   - `composer check` in `packages/php-sdk` passed: guidance 7 tests and 103 assertions, Rector 0 changed files and 0 errors, Pint passed, and PHPStan passed with 0 errors.
   - `composer validate --strict` in `packages/php-sdk` passed.
   - Root `composer docs-lint` passed with 0 issues, 0 errors, and 0 warnings.
   - `git diff --check -- packages/php-sdk` passed.
   - Root `composer check` passed on the clean exact candidate across `apps/cli`, `apps/docs`, `apps/gateway`, `apps/e2e`, and `packages/php-sdk`.
   - Builder gate receipt: `/home/nckrtl/orbit/.git/orbit-checks/a3cd7ff7b9e6bf65e6bb323850bddfaef9b83850/review-_9p7dvdp/result.json`.

## Review corrections

- F1 fixed: `DeploymentStream` now blocks for one byte so PHP's HTTP dechunk filter releases each arriving line, then drains only the bytes reported as already buffered. The real Guzzle chunked-transport regression test fails on the reviewed candidate and passes on this correction. A local diagnostic yielded phase events at about 0.003 and 1.002 seconds instead of yielding all events after EOF.
- F2 fixed: `GatewayRequest::decodeBody()` captures `Response::body()` once before decoding. Both deploy and rollback preserve the structured code, safe message, details, and request ID from a non-seekable refusal body. The reviewer's `diag-error.php` now reports `deployment.busy` for both seekable and non-seekable bodies.
- N1 corrected in the current development record and proposed PR body: Guzzle's `timeout => 4500` is described as a finite per-read socket idle timeout, not an overall operation deadline. The 10-second connection timeout remains separate.
- N2 retained as the existing ADR 0059 policy-alignment deviation below.
- N3 is observed drift in `packages/php-sdk/AGENTS.md` outside this candidate's diff and issue contract. It remains reported for its repository owner; this implementation does not change contributor guidance outside the approved plan.

## Documentation audit

Fixed:

- `docs/reference/deployments.md`: added the typed SDK deployment transport and stream-consumer contract.
- `docs/tech-stack.md`: replaced stale quality-gate ownership detail with a reader-facing link to the owning implementation-loop reference.

Reported: none.

## Deviations and limitations

- Policy alignment only: the issue says the independent reviewer retains the exact-candidate receipt. ADR 0059 requires the Builder to run and retain it, then requires the independent reviewer to validate that same receipt. The implementation follows ADR 0059.
- No Gateway, CLI, deployment-policy, remote-execution, retry, replay, resume, or recovery behavior changed.
- No helpers were used.
- Discovery development only; isolated acceptance proof not run.
