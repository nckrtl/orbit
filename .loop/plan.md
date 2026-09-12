# Feature plan

Plan format: 1
Issue: ORB-3
Flow: discovery
Review verdict: PASS

## Outcome

Expose typed PHP SDK transport and immutable bounded responses for all eight Schedule operations and the Schedule-enabled Doctor family contract without moving Gateway policy into the SDK.

## Code boundaries

In:
- `packages/php-sdk/src/Requests/Schedules/**`: add typed Node and AppInstance target inputs and concrete list, add, show, run, logs, complete, remove, and activate requests with the Gateway's exact methods, UUID-keyed paths, queries, JSON bodies, omission semantics, and completion's `204 No Content` result.
- `packages/php-sdk/src/Responses/Schedules/**` and the existing shared value-boundary support under `packages/php-sdk/src/Support/**`: add immutable collection, item, and log responses for the seven JSON-envelope operations, plus an immutable value-only completion result that contains no response body data; accept only the Gateway's bounded Schedule shape, validate identifiers and request IDs, reject malformed nested data, and redact credentials from command, log, error, and other untrusted text.
- `packages/php-sdk/src/GatewayRequest.php`: retain JSON `meta.request_id` extraction for the seven Schedule envelope responses and add one central validated `X-Orbit-Request-Id` success-header boundary that the no-content completion request uses to expose its request ID.
- `packages/php-sdk/src/Responses/Doctor/**`: define one shared closed Doctor family token boundary from the current Gateway set and use it for both family reports and issue resource types, including `schedule`, without a separate numeric family invariant.
- `packages/php-sdk/tests/Unit/Requests/Schedules/ScheduleRequestsTest.php`, `packages/php-sdk/tests/Unit/Responses/Schedules/ScheduleResponsesTest.php`, `packages/php-sdk/tests/Unit/GatewayRequestTest.php`, `packages/php-sdk/tests/Unit/Responses/Doctor/DoctorReportResponseTest.php`, and the applicable shared response-boundary tests: prove exact transport, target discrimination, optional-value preservation, seven unchanged JSON envelope contracts, completion's empty body and validated header-only request ID, immutable response bounds, credential redaction, malformed-data rejection, and the current Doctor family set.
- `packages/php-sdk/tests/Unit/RepositoryGuidanceTest.php`, `packages/php-sdk/.ai/rules/public-contract.md`, and `packages/php-sdk/README.md`: inventory exactly the eight new concrete operations, derive the new total from the pre-Schedule operation count, permit only the shipped typed Schedule and Doctor surface, and describe the transport contract.

Out:
- No target resolution, systemd or shell execution, Schedule policy, Doctor comparison or repair policy, or ambiguity handling; those remain Gateway responsibilities and require no change under `apps/gateway`.
- No Workspace target or generic Schedule surface, raw response access, transport debug data, or unbounded fallback value.
- No CLI presentation or operation, Gateway or deployment change, and no change under `apps/cli`, `apps/gateway`, or deployment transport paths.
- No harness, Incus topology, or proof fixture change under `apps/e2e`, `bin/e2e-*`, or `.loop/proof/`.

## Documentation

- `docs/reference/schedules.md`: now states that the PHP SDK exposes the same eight typed Schedule operations, distinguishes Node and AppInstance target shapes, preserves omission, returns immutable bounded and redacted responses, and accepts the current Doctor family set including `schedule` without taking policy ownership.
- Documentation audit scope: ORB-3's `packages/php-sdk`, Schedule, Doctor, Node, AppInstance, and Gateway contexts plus the Schedule page required by the `docs` label. Fixed `docs/reference/schedules.md`, whose Limits section denied the PHP SDK Schedule surface required by this issue. Reported findings: none.
- `composer docs-build` completed with no generated context change, and `composer docs-lint` passed with zero findings. Documentation commits: `a02b1ffc` (`docs: describe Schedule SDK transport`) and `ee98819d` (`docs: expand SDK acronym`).

## Acceptance map

| Criterion | Boundary | Focused proof |
| --- | --- | --- |
| 1. All eight operations send the exact Gateway method, UUID-safe path, query, and body; completion preserves `204 No Content` and exposes only its validated header request ID. | Schedule request classes, shared target inputs, immutable completion result, and the central Gateway success-header boundary. | `packages/php-sdk/tests/Unit/Requests/Schedules/ScheduleRequestsTest.php` and `packages/php-sdk/tests/Unit/GatewayRequestTest.php` through `cd packages/php-sdk && composer test:affected`. |
| 2. Node and AppInstance targets serialize distinctly; optional input is omitted only when absent; explicit invalid values reach the Gateway. | Typed target inputs and list/add/log request serialization. | `packages/php-sdk/tests/Unit/Requests/Schedules/ScheduleRequestsTest.php` through `cd packages/php-sdk && composer test:affected`. |
| 3. Response command and log values are bounded, credentials are redacted, and malformed nested data is rejected. | Schedule item, collection, and log JSON responses plus shared value-boundary support; the completion result remains bodyless. | `packages/php-sdk/tests/Unit/Responses/Schedules/ScheduleResponsesTest.php` through `cd packages/php-sdk && composer test:affected`. |
| 4. Doctor accepts exactly the nine current Gateway family tokens, including `schedule`, without a hard-coded family count. | Shared Doctor family token boundary and Doctor family and issue response validation. | `packages/php-sdk/tests/Unit/Responses/Doctor/DoctorReportResponseTest.php` through `cd packages/php-sdk && composer test:affected`. |
| 5. The public-operation inventory grows by exactly the eight Schedule operations and derives the total from the pre-Schedule count. | Repository guidance inventory test and concrete Schedule request directory. | `packages/php-sdk/tests/Unit/RepositoryGuidanceTest.php` through `cd packages/php-sdk && composer test:affected`. |
| 6. The transport reference and README state the Schedule operations and Schedule-enabled Doctor contract, and generated context is current. | `docs/reference/schedules.md`, `packages/php-sdk/README.md`, and generated documentation context. | `composer docs-build && composer docs-lint`, plus `packages/php-sdk/tests/Unit/RepositoryGuidanceTest.php` through `cd packages/php-sdk && composer test:affected`. |
| 7. PHP SDK checks pass. | Every changed path under `packages/php-sdk`. | `cd packages/php-sdk && composer check`. |
| 8. Public-contract guidance permits the shipped typed Schedule operations and Doctor token while retaining deployment restrictions. | `packages/php-sdk/.ai/rules/public-contract.md` and its guidance assertions. | `packages/php-sdk/tests/Unit/RepositoryGuidanceTest.php` through `cd packages/php-sdk && composer test:affected`. |
| 9. Focused acceptance suites pass and the exact clean candidate has the independent local quality receipt. | Finished candidate across the SDK and all five Composer projects. | The focused Schedule request, Schedule response, central Gateway request, Doctor response, and repository-guidance suites, followed by the Builder's root `composer check` receipt for the exact candidate. |
| 10. Add preserves omitted versus explicit `start`, activate uses `POST /schedules/{uuid}/activate`, and desired timer state is validated independently of lifecycle state without policy inference. | Add and activate requests, Schedule response value validation, and their focused request and response tests. | `packages/php-sdk/tests/Unit/Requests/Schedules/ScheduleRequestsTest.php` and `packages/php-sdk/tests/Unit/Responses/Schedules/ScheduleResponsesTest.php` through `cd packages/php-sdk && composer test:affected`. |

## Incus observations

Incus: not required. ORB-3 has no `incus` label, and its request serialization, response validation, redaction, Doctor tokens, guidance, and inventory outcomes are observable with Saloon fakes and local SDK tests. The discovery flow requires no topology or proof instrumentation.

## Implementation order

1. Add failing `ScheduleRequestsTest.php` cases for the eight concrete operations, typed Node and AppInstance targets, exact empty and JSON request bodies, optional query and body omission, explicit values, raw URL encoding of every UUID path segment, and completion's `204` empty response with a request ID only in `X-Orbit-Request-Id`.
2. Extend `GatewayRequestTest.php` with valid, missing, and malformed success-header correlation cases; add a central protected header reader in `GatewayRequest.php`, then add the typed Schedule target inputs and eight request classes so completion returns an immutable request-ID result without parsing a response body while the other seven requests retain their JSON envelope path.
3. Add failing `ScheduleResponsesTest.php` cases for list versus item command presence, the seven JSON envelope shapes, completion's value-only result, log data, every bounded scalar, credential-shaped text, invalid identifiers and request IDs, malformed nested values, and independent desired-timer and lifecycle states; then add the immutable Schedule response types and narrow shared value support they require.
4. Extend the Doctor response test with the exact token list read from the current Gateway, derive assertions from that list, and centralize family-token validation across family reports and issue resource types with `schedule` in canonical order.
5. Extend the repository inventory test from the captured pre-Schedule count by the discovered eight request classes, update the public-contract total and allowed surface without changing deployment restrictions, and add the Schedule and Doctor contract to the package README.
6. Run the focused suites through SDK TIA, `cd packages/php-sdk && composer check`, `git diff --check -- packages/php-sdk`, and prepare a clean candidate for the Builder's root `composer check` gate.

## Must preserve

- ADR 0004: Doctor remains synchronous, verify-only, and policy-free in the SDK; its report keeps only bounded typed families and issues, exposes no raw diagnostics or credentials, and preserves deterministic received data without repairing or persisting findings.
- ADR 0013: the Schedule UUID remains its only public identity; the SDK exposes only list, add, show, run, logs, complete, remove, and the later explicit activate extension; Node and AppInstance remain the only targets; command and journal content stay bounded and redacted; and transport never derives placement, execution context, authorization, or lifecycle policy.
- ADR 0038: AppInstance removal can cascade through owned Schedules while leaving Node-owned and other AppInstances' Schedules alone; this issue adds no SDK-side cascade, target-deletion, or ambiguity policy.
- ADR 0047: cloning leaves the candidate's processes and Schedules unchanged and keeps target application runtime state stopped until explicit operation; Schedule transport does not alter cloning, deployment, or application state by inference.
- ADR 0048: an AppInstance Schedule can be installed with its timer disabled, activation explicitly enables and starts it, manual run stays separate, Node Schedule installation retains its enabled behavior, and desired timer state is independent from lifecycle state.
- ADR 0053 as superseded by ADR 0059: focused acceptance evidence remains required, GitHub checks remain unnecessary, the Builder runs and retains the exact-candidate root quality gate, and the independent reviewer validates its receipt instead of repeating it solely for approval.
- ADR 0060: completion carries only the latest `success` or `error` result and adds no generation, run identity, retry protocol, history, or Schedule-removal inference.
- Existing Gateway Schedule transport: completion remains `204 No Content`, obtains successful correlation only from the validated `X-Orbit-Request-Id` header, and does not acquire a JSON envelope; list, add, show, run, logs, remove, and activate retain their existing JSON `data` and `meta.request_id` envelopes.
- Existing framework neutrality, Saloon 4 transport patterns, standard success and error envelopes, request correlation, credential redaction, disabled raw debugging and serialization, TIA checks, and restrictions on unimplemented deployment surfaces remain unchanged.

## Open questions

none

## Deviations

none

## Review findings
