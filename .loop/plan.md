# Feature plan

Issue: ORB-156
Review verdict: implementation authorized by prepared dispatch

## Outcome

Give every protected-file write an operation-owned sibling candidate, publish the complete candidate atomically, and clean up only that invocation's candidate after success or failure.

## Code boundaries

In:
- `apps/gateway/app/Infrastructure/Files/ProtectedFileWriter.php`
- Focused coverage in `apps/gateway/tests/Feature/Infrastructure/Gateway/NativeGatewayWebConvergerTest.php`
- Existing ownership and failure coverage in `apps/gateway/tests/Unit/Infrastructure/Ssh/KnownHostsRepositoryTest.php`

Out:
- Whole-bootstrap serialization or transaction changes
- Caller interface changes
- Harness implementation changes
- Unrelated infrastructure behavior

## Documentation

Audit scope: ORB-156, `apps/gateway`, and the `Gateway` concept.

Fixed:
- None.

Reported:
- None.

Verification: no maintained page describes internal protected-file candidate naming or cleanup. The issue has no `docs` label, so maintained documentation stays unchanged.

## Acceptance map

| Criterion | Boundary | Focused proof |
| --- | --- | --- |
| Concurrent writes use distinct sibling candidates and publish one complete input | `ProtectedFileWriter::put` | Real concurrent processes in `NativeGatewayWebConvergerTest.php`; existing locked candidate coverage in `KnownHostsRepositoryTest.php`; Incus `protected-writer-contention` |
| Failed publication preserves the destination and removes only its own candidate | `ProtectedFileWriter::put` cleanup | Refused rename with an unrelated candidate in `NativeGatewayWebConvergerTest.php`; existing refusal cleanup in `KnownHostsRepositoryTest.php`; Incus `protected-writer-contention` |
| Creation and final permissions remain restrictive | protected directory, candidate, and published file modes | Gateway custom-mode assertions in `NativeGatewayWebConvergerTest.php`; default-mode assertions in `KnownHostsRepositoryTest.php`; Incus `protected-writer-contention` |
| Isolated operating-system boundary passes | real filesystem and concurrent PHP writers | Incus `protected-writer-contention` |
| Owning project passes | Gateway | `PHPRC=/dev/null composer check` |
| Repository suites pass | monorepo | `PHPRC=/dev/null bin/test` |

## Implementation order

1. Add focused contention, refusal cleanup, complete-content, and permission regression coverage.
2. Replace the shared fixed `.tmp` path with a unique sibling created at restrictive mode.
3. Require a complete write, apply the requested final mode, atomically rename, and clean the owned candidate in `finally`.
4. Exercise the proof scenario on discovery, integrate current `origin/main`, and prove the exact commit.

## Must preserve

- `put(string $path, string $contents, int $permissions = 0o600): void`
- Protected directory mode `0700`, default file mode `0600`, and caller-selected final mode
- Atomic final `rename`
- Original destination when publication fails
- Last-writer-wins behavior for concurrent writes

## Open questions

- None.

## Deviations

- None.

## Review findings

- None; independent review belongs to the external orchestrator.
