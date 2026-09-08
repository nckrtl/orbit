# Feature plan

Issue: ORB-159
Review verdict: PENDING

## Outcome

Role-removal activity records preserve every structurally valid removal field, including the SDK's `offline` field, on successful and failed requests without moving authorization or operational work into pre-authentication parsing.

## Code boundaries

In:
- Add one removal-specific structural input parser under `apps/gateway/app/Http/Requests/Nodes`.
- Reuse it from `RemoveNodeRoleRequest` and the role-removal branch of `RecordCommandActivity`.
- Add focused regression coverage to `CommandActivityTest.php` and `NodeRolesTest.php`.

Out:
- Architecture redesign, generic request parsing, and unrelated activity behavior.
- Authorization, model lookup, remote operation, and role-removal business rules in the parser.
- Incus harness changes.

## Documentation

Audit scope: ORB-159; `docs/architecture.md`, `docs/concepts.md`, `docs/reference/metrics.md`, and their governing accepted role decisions returned by component/concept context and relevant to generic role removal or activity.

Fixed:
- None.

Reported:
- None.

Documentation remains unchanged because the issue has no `docs` label and changes the safe internal activity projection, while the maintained operator pages already state the current removal inputs and outcomes.

## Acceptance map

| Criterion | Boundary | Focused proof |
| --- | --- | --- |
| SDK `offline: false` and valid explicit true preserve the exact accepted input shape | Shared role-removal input parser and activity middleware | `CommandActivityTest.php` records exact false/true shapes; `NodeRolesTest.php` proves both requests retain their existing endpoint behavior |
| Preview, denied authorization, and operation failure record the same safe structure | Activity starts before route authorization and completes each outcome | `CommandActivityTest.php` covers all three outcomes; `NodeRolesTest.php` proves action and authorization behavior remains intact |
| Malformed and duplicate keys stay refused; omitted fields stay omitted | Top-level JSON inspector through the shared parser | Both named feature files cover malformed, literal duplicate, escaped duplicate, and omission cases |
| Parsing performs no authorization, lookup, or remote operation before authentication | Parser depends only on the raw JSON inspector and role enum | `CommandActivityTest.php` records valid input for an unknown peer; `NodeRolesTest.php` asserts no lifecycle call on pre-auth rejection |
| Gateway checks pass | `apps/gateway` | `composer check` |
| Repository suites pass | repository root | `PHPRC=/dev/null bin/test` |

## Implementation order

1. Add focused failing feature regressions for accepted, preview, denied, failure, malformed, duplicate, escaped duplicate, and omitted inputs.
2. Add the narrow role-removal structural parser.
3. Reuse the parser from the FormRequest and activity recorder, removing the duplicated removal schema from middleware.
4. Run focused tests, Gateway checks, and the complete repository suite.
5. Integrate current `origin/main`, commit the full `.loop` workspace, push, and verify the remote head.

## Must preserve

- Authentication and authorization order.
- Existing preview, refusal, validation, and operation-failure responses.
- Refusal of malformed JSON, unsupported keys, literal duplicate keys, and escaped duplicate keys.
- Omitted optional fields remain absent from activity input.
- The structural parser performs only raw JSON inspection and role-enum validation.
- No harness or unrelated subsystem changes.

## Open questions

- None.

## Deviations

- None.

## Proof decision

Automated tests only. ORB-159 has no `proof:incus` label and changes no real-machine boundary.

## Review findings

- Pending independent review.
