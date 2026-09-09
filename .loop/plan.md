# Feature plan

Issue: ORB-162
Review verdict: IMPLEMENTING

## Outcome

Resolve the canonical Git worktree root once inside each dirty-overlay inventory
or archive operation, while keeping every existing source safety and fidelity
check.

## Code boundaries

In:
- `apps/e2e/app/E2E/Git/GitRepository.php`
- `apps/e2e/app/E2E/WorktreeSynchronizer.php` for the adjacent deletion scan
- Git repository unit tests and source synchronization feature tests

Out:
- Architecture redesign
- Unrelated subsystem behavior
- Generic workflow frameworks

## Documentation

Audit scope returned the E2E topology and proof documentation. Those pages do
not describe the private overlay root-resolution implementation, and the issue
has no `docs` label. No maintained documentation changes are required.

Fixed:
- None.

Reported:
- None.

## Acceptance map

| Criterion | Boundary | Focused proof |
| --- | --- | --- |
| Constant root-resolution subprocesses | `GitRepository::dirtyOverlay()` and `GitRepository::createOverlayArchive()` | `GitRepositoryTest.php` records real process commands for nested multi-file operations; `WorktreeSynchronizationTest.php` exercises the complete nested overlay flow |
| Preserve paths, content, modes, and source hash | Git overlay inventory/archive and guest receiver | `GitRepositoryTest.php` checks the inventory/archive; `WorktreeSynchronizationTest.php` checks transferred content, executable mode, deletions, and effective tree hash |
| Preserve refusal guards and fresh operations | Git path/entry validation and repeated source preparation | Existing Git/synchronization traversal, symlink, secret, submodule, and deletion regressions plus repeated-operation coverage |
| Owning project checks | `apps/e2e` | `cd apps/e2e && composer check` |
| Repository suites | Root test coordinator | `PHPRC=/dev/null bin/test` in the root-granted final merge window |

## Implementation order

1. Add regression coverage for nested multi-file overlay costs and fidelity.
2. Resolve and pass the canonical root through each private validation walk.
3. Reuse one canonical root in the adjacent synchronization deletion scan.
4. Run focused tests and the E2E project check.
5. Integrate current `origin/main`, then run the root suite in the coordinated window.

## Must preserve

- Canonical `realpath` root validation.
- Traversal, symlink-parent, dirty-symlink, secret-path, submodule, and deletion guards.
- Archive path order, file content, modes, empty overlays, and source tree hashes.
- Fresh repository state between separate operations; no persistent root or source-state cache.

## Open questions

- None.

## Deviations

- None.

## Review findings

- None yet.
