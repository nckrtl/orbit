# Feature plan

Issue: ORB-176
Review verdict: implementation authorized by dispatch

## Outcome

Local PHP-FPM and Caddy inventory queries hydrate only the requested Node's
eligible legacy, workload, and Router records. Global DNS inventory remains
complete.

## Code boundaries

In:
- `apps/gateway/app/Infrastructure/AppDev/AppDevSiteRepository.php`
- Mixed-fleet regression coverage in the two proof files named by the issue

Out:
- Architecture changes and unrelated subsystem behavior
- Incus harness implementation
- Public behavior, schema, cache, and dependency changes

## Documentation

Audit scope: ORB-176; `apps/gateway`, Node, Route, Workspace, AppInstance, and
Router context returned by `composer docs-context`.

Fixed:
- None.

Reported:
- None.

Maintained pages already state the preserved Route, Router, workload, DNS, and
legacy Workspace behavior. The query boundary is an internal optimization, and
the issue has no `docs` label.

## Acceptance map

| Criterion | Boundary | Focused proof |
| --- | --- | --- |
| Node output equals the global subset for active, failed, caller-pending, and legacy records | `AppDevSiteRepository::forNode()` | `AppDevInfrastructureTest.php`; `NativeDevelopmentRouteProjectorTest.php` |
| Eligibility, colocated deduplication, address exclusions, and Workspace inheritance stay unchanged | Existing site builders and filters | Both named proof files, including existing colocated coverage |
| Unrelated fleet records are not hydrated locally; global DNS stays complete | Node-constrained Instance and Route queries; unchanged `all()` | Mixed-fleet retrieval and DNS assertions in both named proof files |
| Gateway checks pass | apps/gateway | `composer check` |
| Repository suites pass | repository root | `PHPRC=/dev/null bin/test` in the serialized final window |

## Implementation order

1. Add mixed-fleet regressions that observe local hydration and global output.
2. Constrain legacy Instance hydration by `node_id`.
3. Constrain Route hydration with the existing status and projection eligibility groups.
4. Run focused tests and the Gateway project check.
5. Stop at the clean product checkpoint for final current-main integration and root suites.

## Must preserve

- `(active OR caller-pending) AND (workload OR Router)` grouping.
- Workload address requirements and Router pool addresses.
- Colocated workload and Router deduplication.
- Legacy Workspace lifecycle filtering and inherited PHP version.
- `all()` as the complete eligible inventory for global DNS.

## Open questions

- None.

## Deviations

- None.

## Review findings

- None; independent review remains owned by the root orchestrator.

## Verification

- `AppDevInfrastructureTest.php`: 61 passed, 676 assertions.
- `NativeDevelopmentRouteProjectorTest.php`: 10 passed, 67 assertions.
- `composer analyse`: passed.
- `composer check`: 2,658 passed, 15,005 assertions; Rector, formatting, analysis, and lint passed.
- `git diff --check`: passed.
- Root `bin/test`: deferred to the serialized final current-main window.
