# Feature plan

Plan format: 1
Issue: ORB-221
Flow: discovery
Review verdict: PASS

## Outcome

PHP clients can configure deployment steps, start one typed deploy or rollback stream, and inspect retained releases without taking deployment policy or execution ownership from the Gateway.

## Code boundaries

In:
- Add the five deployment operations and their typed JSON inputs and responses under `packages/php-sdk/src/Requests/Deployments/` and `packages/php-sdk/src/Responses/Deployments/`: GET and PUT deployment configuration, POST deploy and rollback, and GET retained releases. Cover their exact methods, `/api/v1/instances/{instance}` paths, body omission, explicit empty deploy object, rollback release, normalized configuration response, and retained-release response in `packages/php-sdk/tests/Unit/Requests/Deployments/DeploymentRequestsTest.php`.
- Add an incremental, closeable NDJSON response reader and typed phase, output, and result events under `packages/php-sdk/src/Responses/Deployments/`. Cover arbitrary transport chunk boundaries, exact event schemas, 32 KiB line and 16 KiB decoded-output limits, strict base64, continuous sequence, correlated request identity, one terminal result, malformed and truncated input, and explicit closure in `packages/php-sdk/tests/Unit/Responses/Deployments/DeploymentStreamTest.php`.
- Configure only deploy and rollback requests for streamed response delivery and a bounded overall timeout that covers the Gateway's accepted operation deadline. Preserve connector TLS verification and redirect settings, close the underlying HTTP response on consumer cancellation, and add no retry or replay path. Cover the effective request options and response lifecycle in `packages/php-sdk/tests/Unit/Requests/Deployments/DeploymentTransportTest.php`.
- Update the operation inventory and deployment-specific diagnostic rules in `packages/php-sdk/README.md` and `packages/php-sdk/.ai/rules/public-contract.md`, with executable guidance coverage in `packages/php-sdk/tests/Unit/RepositoryGuidanceTest.php`. Keep configured commands and streamed application output out of generic debug and serialization state.

Out:
- Do not change `apps/gateway/`; ORB-220's routes, validation, operation deadlines, event protocol, cancellation, deployment policy, and remote execution remain authoritative.
- Do not change `apps/cli/` or add command presentation; this issue exposes PHP SDK transport only.
- Do not change connector-wide ordinary JSON handling in `packages/php-sdk/src/GatewayConnector.php`; deployment streaming options stay request-local, and existing typed JSON requests retain their current envelopes, timeouts, TLS verification, and error behavior.
- Do not add automatic retries, resume, stream replay, deployment history, deployment policy, command execution, or recovery decisions to the SDK.

## Documentation

Audit scope: ORB-221; the pages selected by `composer docs-context -- --component=packages/php-sdk --concept=Gateway` plus the deployment page required by the `docs` label.

Changed:
- `docs/reference/deployments.md`: now describes the five typed SDK operations, ordinary JSON boundaries, incremental validated event handling, explicit response closure, transport limits, TLS preservation, and no retry or replay.
- `docs/tech-stack.md`: fixed in-scope drift by replacing its stale gate-owner statement with a reader-facing link to the implementation loop, which owns the repository-wide local gate and review workflow.

Reported: none.

Verification: `composer docs-build` passed and `composer docs-lint` passed. The documentation changes are committed as `4f117c81` (`docs: describe typed deployment streams`) and `98fd7809` (`docs: link local gate workflow`).

## Acceptance map

| Criterion | Boundary | Focused proof |
| --- | --- | --- |
| Typed requests cover GET/PUT deployment configuration, POST deploy/rollback, and GET retained releases with exact paths and omitted-value behavior. | `packages/php-sdk/src/Requests/Deployments/` and typed JSON DTOs in `packages/php-sdk/src/Responses/Deployments/` | `packages/php-sdk/tests/Unit/Requests/Deployments/DeploymentRequestsTest.php` through `composer test:affected` in `packages/php-sdk` |
| The stream API incrementally yields validated typed events across arbitrary NDJSON chunk splits and rejects malformed, over-limit, miscorrelated, out-of-sequence, misencoded, misordered, or truncated streams without success. | Incremental reader and event DTOs in `packages/php-sdk/src/Responses/Deployments/` | `packages/php-sdk/tests/Unit/Responses/Deployments/DeploymentStreamTest.php` through `composer test:affected` in `packages/php-sdk` |
| Deploy and rollback cover the Gateway deadline with bounded transport timeouts, preserve TLS verification, close on cancellation, and never retry or replay. | Request-local deployment stream configuration in `packages/php-sdk/src/Requests/Deployments/` and response closure in `packages/php-sdk/src/Responses/Deployments/` | `packages/php-sdk/tests/Unit/Requests/Deployments/DeploymentTransportTest.php` through `composer test:affected` in `packages/php-sdk` |
| The operation inventory and guidance include the typed deployment operations without changing ordinary JSON transports, and sensitive commands and output stay out of generic diagnostics. | `packages/php-sdk/README.md`, `packages/php-sdk/.ai/rules/public-contract.md`, and deployment response diagnostic state | `packages/php-sdk/tests/Unit/RepositoryGuidanceTest.php` and `packages/php-sdk/tests/Unit/Responses/Deployments/DeploymentStreamTest.php` through `composer test:affected` in `packages/php-sdk` |
| Maintained documentation describes typed deployment operations and streamed-result handling, with current generated context. | `docs/reference/deployments.md`, `docs/tech-stack.md`, and generated documentation context | Root `composer docs-build` and `composer docs-lint` |
| The public-contract guide permits the shipped typed deployment configuration, retained-release, and streaming operations while preserving any shipped Schedule operations. | `packages/php-sdk/.ai/rules/public-contract.md` and its inventory assertions | `packages/php-sdk/tests/Unit/RepositoryGuidanceTest.php` through `composer test:affected` in `packages/php-sdk` |
| Focused acceptance tests and the independent exact-candidate quality contract pass. | All listed SDK tests and repository quality tooling | `composer test:affected` and `composer check` in `packages/php-sdk`, followed on the clean committed candidate by root `composer check`; the Builder retains its receipt and the independent reviewer validates that exact receipt |

## Incus observations

Incus: not required. Saloon fakes, controlled PSR-7 streams, focused SDK tests, the SDK project checks, and the Builder candidate gate cover every acceptance item without a machine topology. Discovery development only; isolated acceptance proof not run.

## Implementation order

1. Add failing request tests for the five exact Gateway routes, HTTP methods, positive numeric AppInstance ID path segments, bodyless reads, optional step-timeout omission, the empty deploy object, rollback release input, correlated response DTOs, and sensitive configuration diagnostics.
2. Implement the typed configuration, retained-release, deploy, and rollback request and response objects, reusing the established JSON envelope, structured error, request-ID, omission, and strict typed-response boundaries.
3. Add failing stream tests using controlled PSR-7 bodies whose reads split lines at arbitrary bytes; cover each valid event variant and every schema, size, encoding, sequence, identity, terminal-order, truncation, close, and no-success failure boundary.
4. Implement lazy NDJSON buffering and typed event parsing. Keep leading probe whitespace valid, bound an unterminated buffer before it can grow past one line, yield only fully validated events, require exactly one final result for success, and make explicit close release the underlying Saloon/PSR-7 response.
5. Add transport tests and request-local streaming configuration for deploy and rollback. Use the accepted Gateway maximum operation budget as the finite timeout, preserve connector CA verification and disabled redirects, and prove that neither HTTP retries nor event replay occurs.
6. Update the SDK README, public-contract operation count and AppInstance inventory, retired-surface wording, diagnostic requirements, and repository guidance assertions without weakening unrelated retired-surface or Schedule constraints.
7. Run `composer test:affected` and `composer check` in `packages/php-sdk`, confirm `composer docs-lint`, commit the candidate, then run the root `composer check` Builder gate on that exact clean commit and retain its receipt for independent review.

## Must preserve

- ADR 0046: the production AppInstance, not the SDK, owns its configured branch and ordered steps; deploy remains an explicit request that selects the latest configured branch without a caller commit.
- ADR 0046: Orbit runs only configured application commands, uses bounded execution time and streamed output, and leaves command selection, ordering, compatibility, and recovery decisions with the operating agent.
- ADR 0046: a failed deployment reports its boundary without automatic rollback or retry; rollback only selects retained code and refreshes runtime cache, without database recovery or inferred application commands.
- ADR 0046: deployment transport does not add deployment runs, history, historical step snapshots, or automatic resumption.
- ADR 0053 as extended and superseded for gate ownership by ADR 0059: focused acceptance tests remain required, the Builder runs and retains the all-project local gate for the exact clean candidate, and the independent reviewer validates that receipt and reviews the candidate without requiring GitHub checks.
- Existing `GatewayRequest` success envelopes, structured `GatewayApiException` data, valid request-ID correlation, credential redaction, connector TLS verification, disabled redirects, and ordinary JSON request behavior remain unchanged outside the deployment stream requests.
- The SDK remains framework-neutral and transports typed values only. Gateway validation, Node authorization, deployment execution, cancellation policy, and CLI presentation stay outside this package.
- Configured deployment commands and application output can enter only their intended request or event values; request, response, exception, debug, serialization, and trace state must not expose them.

## Open questions

none

## Deviations

Policy alignment only: the final issue criterion says an independent reviewer retains the exact-candidate local quality receipt. Current ADR 0059 and `docs/reference/implementation-loop.md` require the Builder to run and retain root `composer check`, then require the independent reviewer to validate that same receipt. The orchestrator should align the issue text to: "Focused acceptance tests pass, the Builder retains the successful exact-candidate local quality receipt across all five projects, and the independent reviewer validates that receipt."

## Review findings
