# Feature plan

Issue: ORB-177
Review verdict: PENDING

## Outcome

Operators request the existing destructive AppInstance source-removal choice with `instance:remove --force`, while the Gateway API, activity input, and PHP SDK use the matching `force` boolean without a compatibility alias.

## Code boundaries

In:
- Gateway AppInstance removal request validation and the existing removal call chain.
- PHP SDK removal request encoding.
- CLI removal option, help, request construction, and exact command surface.
- Existing AppInstance removal tests and maintained applications documentation.

Out:
- Removal eligibility or source-deletion decisions.
- Route reconciliation, cascades, progress, migrations, or response-envelope changes.
- Harness, topology, source adoption, and branch deletion behavior.

## Documentation

Audit scope: ORB-177; generated context for `apps/gateway`, `packages/php-sdk`, and `apps/cli` with the AppInstance, Gateway, and Route concepts; the maintained removal section in `docs/domains/applications.md`.

Fixed:
- `docs/domains/applications.md`: the removal command and request used the replaced source-discard spelling -> the page now documents `--force`, the strict API boolean, SDK encoding, omission, explicit false, and the current intermediate removal limits.

Reported:
- None.

Generated context decision: rebuild the index because the changed page adds API and SDK surface terms; commit it only if generation changes it.

## Acceptance map

| Criterion | Boundary | Focused proof |
| --- | --- | --- |
| Gateway accepts only optional strict boolean `force`, defaults omission to false, and rejects `discard_source`. | `RemoveAppInstanceRequest`, controller/action call chain, API activity input | `apps/gateway/tests/Feature/Api/AppInstancesTest.php` |
| SDK encodes optional `force`; CLI exposes only `--force`; omission and false keep normal mode. | SDK request and CLI removal command/surface | SDK and CLI focused request/command tests |
| Human, JSON, and removal activity vocabulary expose no replaced spelling. | Existing CLI response rendering and Gateway command activity | CLI instance tests and Gateway API activity assertions |
| Existing migration, Route, layout, path, identity, ownership, dirty-source, and unpublished-source decisions remain unchanged. | Existing removal action and remote source lifecycle | Gateway API and remote lifecycle focused suites |
| Maintained documentation describes the current command and request. | `docs/domains/applications.md`, generated context | `composer docs-build`; `composer docs-lint` |
| All component and repository checks pass. | Gateway, SDK, CLI, repository | component `composer check`; `PHPRC=/dev/null bin/test` |

## Implementation order

1. Audit and update the maintained AppInstance removal documentation.
2. Add focused regression expectations for the new vocabulary and preserved behavior.
3. Rename the coordinated request, SDK, CLI, action, lifecycle, and activity vocabulary without changing removal decisions or response shapes.
4. Run focused tests, documentation checks, component checks, and the full repository suite.
5. Integrate current `origin/main`, rerun any affected checks, commit `.loop`, push, and verify the exact remote head.

## Must preserve

- Active Route and migration refusals run before source mutation in normal and forced modes.
- Force waives only dirty-source and unpublished-commit checks.
- Path, ownership, origin, repository identity, symlink, canonical path, containment, layout, and starting-commit ancestry checks remain mandatory.
- Removal success and error response envelopes and CLI output shapes remain unchanged.
- SDK omission stays an empty body and explicit false stays representable.

## Open questions

- None.

## Deviations

- None.

## Review findings

- Pending independent review.
