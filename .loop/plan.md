# Feature plan

Issue: ORB-161
Review verdict: IMPLEMENTATION AUTHORIZED

## Outcome

Route and Metrics success DTOs normalize `error_code` through the existing
`GatewayErrorCode::fromTransport()` boundary. Valid tokens survive unchanged.
Malformed, credential-shaped, non-string, or oversized values become `null`
before DTO state or diagnostics are produced.

## Code boundaries

In:
- `packages/php-sdk/src/Responses/Routes/RouteResponse.php`
- `packages/php-sdk/src/Responses/Metrics/MetricsStatusResponse.php`
- The three acceptance-proof test files named by ORB-161

Out:
- Architecture or public API redesign
- Gateway, CLI, and Incus harness behavior
- Unrelated SDK response parsing

## Documentation

Audit scope: ORB-161; `composer docs-context -- --component=packages/php-sdk
--concept=Route` plus the Metrics and Routes reference pages and the SDK README.

Fixed:
- None.

Reported:
- None.

Verification: no maintained documentation changes are required. The pages
already state the structured-error and secret-safe diagnostics contract; the
common validator is an internal transport boundary.

## Acceptance map

| Criterion | Boundary | Focused proof |
| --- | --- | --- |
| Valid tokens survive; malformed and credential-shaped values become null | Direct Route, nested AppInstance Route, and Metrics assignment DTO factories | `ResponseTransportBoundaryTest.php`, `RouteRequestsTest.php`, `MetricsResponseTest.php` |
| Control characters, whitespace, non-string values, and oversized codes do not reach arrays, serialization, or debug output | DTO state and all diagnostic forms | The same three focused test files |
| Other nested Route and Metrics strict state checks remain intact | Nested AppInstance Route transport and Metrics assignment/status validation | `ResponseTransportBoundaryTest.php` and existing plus added Route/Metrics response tests |
| SDK checks pass | `packages/php-sdk` | `PHPRC=/dev/null composer check` |
| Repository suites pass | All Composer projects | `PHPRC=/dev/null bin/test` |

## Implementation order

1. Add focused failing regressions for unsafe and valid Route and Metrics codes.
2. Route both factories through `GatewayErrorCode::fromTransport()`.
3. Run focused tests, SDK checks, and the full repository suite without TIA.
4. Integrate current `origin/main`, commit `.loop/` with the candidate, and push.

## Must preserve

- Existing strict Metrics assignment and enabled-state validation.
- Nested AppInstance Route mapping and every non-error-code DTO field.
- Valid structured error tokens and request IDs.
- Framework-neutral SDK and unchanged public constructor shapes.

## Open questions

- None.

## Deviations

- None.

## Review findings

- None; independent review belongs to the root orchestrator.

## Verification

- `PHPRC=/dev/null ./vendor/bin/pest tests/Unit/ResponseTransportBoundaryTest.php`: 12 passed, 91 assertions.
- `PHPRC=/dev/null ./vendor/bin/pest tests/Unit/Requests/Routes/RouteRequestsTest.php`: 10 passed, 40 assertions.
- `PHPRC=/dev/null ./vendor/bin/pest tests/Unit/Responses/Metrics/MetricsResponseTest.php`: 24 passed, 45 assertions.
- `PHPRC=/dev/null composer validate --strict`: passed.
- `PHPRC=/dev/null composer check`: passed; 382 tests, 1,445 assertions; Rector and Mago gates completed with only the repository's two existing source-analysis warnings and one existing test-complexity warning.
- `PHPRC=/dev/null bin/test`: all five suites passed: CLI 590 tests/3,526 assertions; Docs 32/75; Gateway 2,443/13,020; E2E 1,106/5,701; PHP SDK 382/1,445.
- `git diff --check -- packages/php-sdk` and `.loop`: passed.
- Proof decision: automated tests only; ORB-161 has no `proof:incus` label.
