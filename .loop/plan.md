# Feature plan

Issue: ORB-154
Review verdict: PENDING

## Outcome

Persist every valid Gateway profile name, including numeric names such as `0`, by encoding the `gateways` member as a JSON object.

## Code boundaries

In:
- `apps/cli/app/Repositories/GatewayConfigRepository.php`
- `apps/cli/tests/Unit/GatewayConfigRepositoryTest.php`
- `apps/cli/tests/Unit/GatewayConfigRepositorySecurityTest.php`
- `apps/cli/tests/Feature/Gateway/GatewayAddCommandTest.php`

Out:
- Gateway profile validation and architecture
- Recovery of existing corrupted list-shaped configuration
- Unrelated subsystem behavior and workflow frameworks
- Incus harness implementation

## Documentation

Audit scope: ORB-154, the `apps/cli` component, the Gateway concept, and existing Gateway configuration references.

Fixed:
- None.

Reported:
- None.

Documentation remains unchanged because no maintained page owns the private CLI gateway-profile JSON representation, and the issue has no `docs` label.

## Acceptance map

| Criterion | Boundary | Focused proof |
| --- | --- | --- |
| Empty, sole numeric, sequential numeric, and mixed-name maps serialize as objects and valid numeric names round-trip | Repository writer and public repository operations | `GatewayConfigRepositoryTest.php`; `GatewayAddCommandTest.php` |
| Malformed external lists remain rejected | Repository reader | `GatewayConfigRepositorySecurityTest.php`; `GatewayAddCommandTest.php` |
| Ownership, permission, and concurrent updates remain unchanged | Existing repository lock and file security paths | Existing repository and security regressions in the three named test files |
| Owning-project checks pass | CLI project | `cd apps/cli && composer check` |
| All repository suites pass | Monorepo | `PHPRC=/dev/null bin/test` without TIA |

## Implementation order

1. Add repository regressions for object encoding across empty, numeric, sequential numeric, and mixed map shapes.
2. Add command coverage for profile name `0` and malformed list refusal.
3. Encode only the `gateways` member as an object while retaining internal arrays.
4. Run focused tests, CLI `composer check`, and root `PHPRC=/dev/null bin/test` without TIA.
5. Integrate current `origin/main`, commit the complete workspace, push, and verify the remote head.

## Must preserve

- Strict rejection of an externally supplied list-shaped `gateways` value.
- Existing ownership, permission, locking, and concurrent update behavior.
- Internal array representation and existing valid configuration compatibility.

## Open questions

- None.

## Proof decision

Automated tests only. ORB-154 has no `proof:incus` label and changes only CLI persistence.

## Deviations

- None.

## Review findings

- None.
