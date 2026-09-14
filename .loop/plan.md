# Feature plan

Plan format: 1
Issue: ORB-330
Flow: discovery
Review verdict: PENDING

## Outcome

Gateway routes that serve `orbit metrics:disable` and `orbit doctor` carry those command names, so recorded activity names the command the operator ran.

## Code boundaries

In:
- `apps/gateway/routes/api.php` route names `metrics:remove` to `metrics:disable` and `doctor:run` to `doctor`
- `apps/gateway/app/Http/Middleware/RecordCommandActivity.php` and `apps/gateway/app/Infrastructure/Activity/CommandActivityTargetResolver.php` string matches on the doctor route name
- Literal uses of those two names under `apps/gateway/tests`, including `MetricsRoutesTest.php`, `DoctorTest.php`, `CommandActivityTest.php`, and `NodeAccessRouteScopeTest.php`

Out:
- CLI command signatures, SDK request classes, HTTP methods, HTTP paths, and metrics or doctor behavior stay unchanged
- Gateway Form Request class names, controller methods, and Metrics role-manager verbs stay unchanged
- Pages under `docs/` and the CLI command-surface test stay unchanged

## Documentation

none: route names are not documented on a maintained page. A grep of `docs/reference` for `metrics:remove` and `doctor:run` found no matches. `docs/reference/metrics.md` names the CLI command `orbit metrics:disable` and the HTTP path `DELETE /api/v1/metrics`, which this issue leaves unchanged.

## Acceptance map

| Criterion | Boundary | Focused proof |
| --- | --- | --- |
| A recorded activity for a metrics disable request names metrics:disable, and a recorded activity for a doctor run names doctor | `apps/gateway/routes/api.php` names, `RecordCommandActivity` doctor match, and the named tests | `apps/gateway/tests/Feature/Api/MetricsRoutesTest.php`, `apps/gateway/tests/Feature/Api/DoctorTest.php`, and `apps/gateway/tests/Feature/Api/CommandActivityTest.php` |
| Activity target resolution for a doctor run is unchanged under the new name | `CommandActivityTargetResolver` doctor match | `apps/gateway/tests/Feature/Api/CommandActivityTest.php` |

## Implementation order

1. Rename the two `->name(...)` calls in `apps/gateway/routes/api.php`.
2. Update the `'doctor:run'` matches in `RecordCommandActivity` and `CommandActivityTargetResolver`.
3. Replace remaining `metrics:remove` and `doctor:run` literals under `apps/gateway/tests`.
4. Run `composer test:affected` for `apps/gateway` on beast after the push.

## Must preserve

- ADR 0071: a Gateway route that serves a CLI command must carry that command's name.
- ADR 0071: the CLI uses enable and disable for toggles; `metrics:disable` remains the CLI command.
- ADR 0071: no aliases for a replaced name.
- HTTP method and path for `DELETE /api/v1/metrics` and `POST /api/v1/doctor` stay the same.
- Doctor activity still attributes a selected node, stays unattributed when `node_id` is omitted, stays unattributed on 404 for a missing node, and attributes an inaccessible node on 403.
- Metrics disable still calls the existing remove manager path with the same force and purge inputs.
- CLI commands, SDK request classes, and metrics or doctor behavior stay unchanged.

## Open questions

none

## Deviations

none

## Review findings

