# Feature plan

Issue: ORB-160
Review verdict: implementation-author plan; independent review pending

## Outcome

`ToolResponse` and `ToolManagerResponse` validate and redact string content once in their public constructors. Their transport factories only decode mixed wire types before calling those constructors, so both construction paths accept and reject the same raw values.

## Code boundaries

In:
- `packages/php-sdk/src/Responses/Tools/ToolResponse.php`
- `packages/php-sdk/src/Responses/Tools/ToolManagerResponse.php`
- `packages/php-sdk/tests/Unit/Responses/Tools/ToolResponsesTest.php`

Out:
- Public Tool wire fields or constructor parameter types
- Shared error-code and request-ID validators
- Gateway, CLI, and Incus harness behavior
- Generic response-normalization frameworks

## Documentation

Audit scope: ORB-160 with `composer docs-context -- --component=packages/php-sdk --concept=Tool`; reviewed the returned maintained index, concept, Tool reference, and governing accepted ADR statements against the issue, SDK response code, and tests.

Fixed:
- None.

Reported:
- None.

Verification: no maintained page changes are needed. The issue corrects an internal SDK DTO normalization inconsistency without changing the public Tool fields or operator behavior documented in `docs/concepts.md` and `docs/reference/tools.md`.

## Acceptance map

| Criterion | Boundary | Focused proof |
| --- | --- | --- |
| Direct and factory construction share acceptance at input bounds, including redaction expansion and nullable empty strings | Both Tool response DTO constructors and factories | Boundary-parity regressions in `ToolResponsesTest.php` |
| Malformed transport types retain safe exceptions without raw sensitive trace arguments | Factory type decoders and `SensitiveParameter` annotations | Malformed sensitive transport regression in `ToolResponsesTest.php` |
| Token grammar, error codes, request IDs, and sensitive parameters retain their contracts | Constructor content validation and shared validators | Existing and expanded contract regressions in `ToolResponsesTest.php` |
| Owning-project checks pass | PHP SDK | `PHPRC=/dev/null composer check` |
| All repository suites pass | Monorepo | `PHPRC=/dev/null bin/test` |

## Implementation order

1. Add failing parity and trace-safety tests for both DTOs.
2. Restrict factory helpers to mixed-type decoding and move all string content validation and redaction to constructor ingress.
3. Run focused tests, the SDK checks, and the full repository suites.
4. Integrate current `origin/main`, commit the complete workspace, push the exact candidate, and retain `.loop/` for independent review.

## Must preserve

- Raw input bounds of 32 bytes for manager/name and tokens, and 255 bytes for text fields; redacted stored output may be longer than the accepted raw input.
- Explicit empty strings in nullable text fields.
- Exact token grammar.
- `GatewayErrorCode` and `GatewayRequestId` normalization.
- `SensitiveParameter` on credential-bearing constructor and factory ingress.
- Safe `InvalidArgumentException` messages for malformed transport fields.

## Open questions

- None. The existing public constructor contracts define the raw input bounds and settle redaction expansion.

## Proof decision

Automated tests only. ORB-160 has no `proof:incus` label and changes framework-neutral DTO normalization without a real-machine boundary.

## Deviations

- None.

## Review findings

- Independent review pending.
