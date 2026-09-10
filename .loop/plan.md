# Feature plan

Issue: ORB-209
Review verdict: PENDING

## Outcome

Expose typed, value-safe PHP SDK transport for AppInstance environment import, update, and synchronization.

## Code boundaries

In:
- `packages/php-sdk/src/Requests/Environment/`: three concrete typed Saloon requests and their shared safe environment transport boundary.
- `packages/php-sdk/src/Responses/Environment/`: immutable validation and serialization for the bounded operation result plus a safe Saloon response diagnostic boundary.
- `packages/php-sdk/tests/Unit/Requests/Environment/EnvironmentRequestsTest.php`: exact methods, routes, encoding, payload preservation, request IDs, and submitted-value diagnostics.
- `packages/php-sdk/tests/Unit/Responses/Environment/EnvironmentResponseTest.php`: result bounds, malformed response rejection, structured failures, and response-value diagnostics.
- `packages/php-sdk/tests/Unit/RepositoryGuidanceTest.php`, `packages/php-sdk/.ai/rules/public-contract.md`, `packages/php-sdk/.ai/rules/redaction-security.md`, and `packages/php-sdk/README.md`: the 72-operation inventory and the public environment transport and security guidance.

Out:
- Keep placeholder evaluation, dotenv parsing, encryption, target resolution, validation policy, remote execution, and CLI presentation outside the SDK.
- Keep Gateway, CLI, E2E harness, and every other Composer project unchanged.

## Documentation

- `docs/reference/environment-variables.md`: adds the typed PHP SDK methods, payload distinctions, bounded value-free result, safe error correlation, and environment-value diagnostic boundary.
- Audit scope: `docs/README.md`, `docs/concepts.md`, `docs/tech-stack.md`, `docs/domains/applications.md`, and `docs/reference/environment-variables.md` from the filtered `packages/php-sdk`, `Gateway`, and `AppInstance` context. Fixed: the environment reference lacked the SDK transport and result boundary required by the issue. Reported: none.

## Acceptance map

| Criterion | Boundary | Focused proof |
| --- | --- | --- |
| Exact import, update, and synchronization methods, routes, payloads, and once-only segment encoding | `src/Requests/Environment/` | `vendor/bin/pest --no-tia --compact tests/Unit/Requests/Environment/EnvironmentRequestsTest.php` |
| Preserve selectors and empty, multiline, false, zero, and placeholder inputs without lookup or resolution | `src/Requests/Environment/` | `vendor/bin/pest --no-tia --compact tests/Unit/Requests/Environment/EnvironmentRequestsTest.php` |
| Expose only the immutable bounded correlated operation result and reject malformed or value-bearing data | `src/Responses/Environment/EnvironmentOperationResponse.php` | `vendor/bin/pest --no-tia --compact tests/Unit/Responses/Environment/EnvironmentResponseTest.php` |
| Keep environment values out of request, response, serialization, validation, transport, exception, and debug diagnostics | `src/Requests/Environment/` and `src/Responses/Environment/EnvironmentOperationResponse.php` | `vendor/bin/pest --no-tia --compact tests/Unit/Requests/Environment/EnvironmentRequestsTest.php tests/Unit/Responses/Environment/EnvironmentResponseTest.php` |
| Preserve named structured Gateway failures and request IDs without remote content | `src/Requests/Environment/` | `vendor/bin/pest --no-tia --compact tests/Unit/Responses/Environment/EnvironmentResponseTest.php` |
| Add three public operations and document their typed payload and result boundary | `tests/Unit/RepositoryGuidanceTest.php`, package guidance, package README, and `docs/reference/environment-variables.md` | `vendor/bin/pest --no-tia --compact tests/Unit/RepositoryGuidanceTest.php` and `composer docs-lint` from the repository root |
| Pass focused affected tests and package quality checks | All listed SDK boundaries | `vendor/bin/pest --no-tia --compact tests/Unit/Requests/Environment/EnvironmentRequestsTest.php tests/Unit/Responses/Environment/EnvironmentResponseTest.php tests/Unit/RepositoryGuidanceTest.php`, `composer check`, and five full no-TIA CI suites on the submitted candidate |

## Implementation order

1. Update the maintained environment reference, build its generated context, lint it, and commit the documentation separately.
2. Add focused failing request tests for exact routes, payloads, path encoding, value preservation, and diagnostic safety.
3. Implement the shared safe environment request boundary and the three concrete requests.
4. Add focused failing response tests for exact immutable fields, bounds, correlation, rejection, structured failures, and diagnostic safety.
5. Implement the bounded operation response and connect all three requests to their expected operation token.
6. Update and test the package operation inventory, public guidance, security guidance, and README.
7. Run focused tests, package checks, docs lint, diff checks, signature verification, current-main integration, artifact publication, and remote-head verification.

## Must preserve

- ADR 0044: the Gateway owns authoritative AppInstance environment configuration and omits values from normal responses and diagnostics.
- ADR 0044: the Gateway validates keys, values, and references; import conflict policy, placeholder resolution, synchronization, placement, remote preflight, and file replacement remain outside the SDK.
- Existing `GatewayRequest` correlation, bounded error-code validation, disabled raw debugging, and request serialization denial remain intact.
- Existing SDK operations and framework-neutral Saloon transport remain unchanged.

## Open questions

- None.

## Deviations

- None.

## Review findings

- None.
