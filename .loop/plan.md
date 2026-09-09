# Feature plan

Issue: ORB-163
Review verdict: no separate plan review requested; implement from the live contract

## Outcome

Load the App, Route, and Route target relationships when the Gateway retrieves an AppInstance list so response serialization does not add relationship queries per returned row.

## Code boundaries

In:
- `apps/gateway/app/Actions/AppInstances/ListAppInstancesAction.php`
- `apps/gateway/tests/Feature/Api/AppInstancesTest.php`

Out:
- AppInstance or Route architecture changes
- API and SDK schema changes
- unrelated subsystem behavior and generic workflow frameworks
- Incus harness implementation and proof resources

## Documentation

Documentation audit:
- Scope: ORB-163; the AppInstance, App, Route, Gateway, Applications, Apps, Routes, and removal response documentation returned by filtered `composer docs-context`.
- Fixed: none. Eager loading changes an internal query strategy without changing documented behavior.
- Reported: none. The maintained pages already state the public list/show response, visibility, ordering, shared App, nullable Route, and removal-progress contracts.
- Verification: no maintained documentation changed, so no docs build or lint is required.

## Acceptance map

| Criterion | Boundary | Focused proof |
| --- | --- | --- |
| One and several returned rows use bounded App, Route, and target queries | Eager-load `app` and `routes.targets` in `ListAppInstancesAction` | AppInstances API regression compares relationship query counts for one row and several rows; run `AppInstancesTest.php` and `AppsTest.php` |
| Visibility and newest-first order remain unchanged across shared and distinct Apps, inaccessible Nodes, and no-Route records | Keep the existing access constraint and `latest('id')`; exercise all listed record shapes | AppInstances API response assertions plus existing App list access regressions in `AppsTest.php` |
| Single-object DTO callers retain defensive missing-relation loading | Keep `AppInstanceData::fromModel()` `loadMissing(['app', 'routes.targets'])` | DTO regression serializes an unloaded model with lazy loading prohibited |
| Owning-project checks pass | Gateway project | `cd apps/gateway && composer check` |
| Repository suites pass | Root repository | `PHPRC=/dev/null bin/test`, deferred until the orchestrator grants the current-main merge window |

## Implementation order

1. Add the one-versus-many relationship query regression and the defensive DTO regression.
2. Eager-load the existing response graph in the list action.
3. Run the focused API files and Gateway `composer check`.
4. Commit and report the clean product checkpoint; hold current-main integration and root `bin/test` for the orchestrator's merge window.

## Must preserve

- Binary Node visibility filtering.
- Newest AppInstance ID first.
- Separate rows that share one App and rows owned by distinct Apps.
- AppInstances without a Route.
- Defensive relation loading for DTO callers outside the list action.
- Existing API shape and removal progress behavior.

## Open questions

- None.

## Deviations

- None.

## Review findings

- None.
