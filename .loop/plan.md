# Feature plan

Issue: ORB-233
Review verdict: PASS

## Outcome

Remove the obsolete Tool-intent guard from Node application-role removal while preserving Tool Manager, Tool, and role-removal behavior.

## Code boundaries

In:
- `apps/gateway/app/Actions/Nodes/RemoveNodeRoleAction.php`: remove the guard dependency, safety calls, retirement call, and empty retirement-preview merge while retaining manager-scope locking and the ordinary role-removal lifecycle.
- `apps/gateway/app/Domain/Nodes/NodeRoleToolIntentGuard.php` and `apps/gateway/app/Infrastructure/Tools/EloquentNodeRoleToolIntentGuard.php`: delete the empty contract and its no-op implementation.
- `apps/gateway/app/Providers/AppServiceProvider.php`: remove the obsolete contract and implementation imports and container binding.
- `apps/gateway/tests/Feature/Domain/RemoveNodeRoleActionTest.php`: construct the production action without a Tool guard, remove the artificial guard double and guard-refusal race cases, and cover both application roles, previews, forced removal, failure, retry, and unchanged Tool state through the remaining production path.
- `apps/gateway/tests/Feature/Infrastructure/Tools/EloquentNodeRoleToolIntentGuardTest.php`: delete the test for the removed no-op implementation.

Out:
- Tool installation, Tool removal, and Tool Manager materialization, persistence, locking, and retry code remain unchanged.
- Role compatibility, assignment eligibility, dependency inspection, route guards, and mutable-role policy remain unchanged.
- Remote cleanup, baseline convergence, firewall, package-manager, and Secure Shell commands remain unchanged.
- HTTP controllers, request and response data, the CLI, and the PHP SDK remain unchanged, so public response shapes do not change.
- `apps/e2e/**` and `bin/e2e-*` remain unchanged; this automated-only discovery-flow issue uses no Incus topology or harness change.

## Documentation

none: ORB-233 removes internal no-op plumbing and does not change documented behavior; `docs/concepts.md` and `docs/reference/tools.md` already state that Tool Managers are independent of Node roles and that roles do not own managers or Tools.

Audit scope: ORB-233 with component `apps/gateway` and concepts `Node`, `Tool`, and `Tool Manager`; fixed findings: none; reported findings: none.

## Acceptance map

| Criterion | Boundary | Focused proof |
| --- | --- | --- |
| Removing either application role, including the final one, retains Tool Managers and Tool intent and never requests manager retirement | `RemoveNodeRoleAction.php`; `RemoveNodeRoleActionTest.php` | `cd apps/gateway && vendor/bin/pest --no-tia --compact tests/Feature/Domain/RemoveNodeRoleActionTest.php`, with app-dev and app-prod cases asserting unchanged manager and Tool rows and state after removal |
| Preview, normal removal, and forced removal keep existing outcomes and non-Tool refusals without guard calls or a container binding | `RemoveNodeRoleAction.php`; deleted guard files; `AppServiceProvider.php`; both affected test files | The focused Pest file exercises no-force preview, forced production removal, and existing non-Tool dependency and policy failures; `cd apps/gateway && ! rg -n 'NodeRoleToolIntentGuard|EloquentNodeRoleToolIntentGuard' app tests` confirms the obsolete contract, calls, implementation, binding, and doubles are absent |
| Success, injected lifecycle failure, and retry preserve Tool state and existing recovery through the production action without artificial guard refusal | `RemoveNodeRoleAction.php`; `RemoveNodeRoleActionTest.php` | `cd apps/gateway && vendor/bin/pest --no-tia --compact tests/Feature/Domain/RemoveNodeRoleActionTest.php`, including successful removal plus injected cleanup or baseline failure and retry with manager and Tool assertions |
| Gateway checks pass | All changed Gateway boundaries | `cd apps/gateway && composer check`; the independent code reviewer later runs root `composer check` as the local review gate |

Incus: not required. Incus observations: not applicable; discovery flow. ORB-233 is automated-only, and no topology or live discovery observation is planned.

## Implementation order

1. Refactor `RemoveNodeRoleActionTest.php` around the production action: cover app-dev and app-prod removal, retain Tool and manager assertions across preview, success, lifecycle failure, and retry, and remove only guard-specific artificial refusal and race coverage.
2. Remove `NodeRoleToolIntentGuard` from `RemoveNodeRoleAction`, including the constructor dependency, pre-claim and in-claim assertions, post-delete retirement call, and empty retirement-preview aggregation; keep the VP and Composer manager-scope locks and all non-Tool guards and recovery steps.
3. Delete the empty contract, no-op implementation, dedicated implementation test, and service-container imports and binding; update every constructor call and helper accordingly.
4. Run the focused role-removal test, confirm no obsolete guard references remain, run Gateway `composer check`, and leave the repository-wide local gate for independent code review.

## Must preserve

- ADR 0042: the Gateway treats each Tool Manager as a protected Node capability independent of Node roles.
- ADR 0042: a role may require a Tool Manager during convergence, but the role does not own that manager or its Tools.
- ADR 0042: the Gateway retains a materialized Tool Manager after its final Tool is removed, public Tool operations do not remove managers, and final application-role removal is not blocked solely by retained Tool intent.
- `ToolManagerScopeLock` continues to serialize app-role removal against VP and Composer manager-scope mutation; removing the obsolete guard does not change Tool Manager lifecycle or concurrency.
- No-force preview still returns the current dependency summaries without mutation; forced and ordinary removal still use the same policy, route, AppInstance, reachability, cleanup, baseline, and dependency-race guards.
- Cleanup or baseline failure still leaves the role and dependents retryable with the existing failure step and error code, and retry still completes the same removal outcome.
- Tool and Tool Manager rows, status, installed versions, protection, and failure fields remain unchanged during successful removal, failed removal, and retry.
- Public role-removal results, refusals, error codes, and response shapes remain unchanged, and no remote command or harness behavior changes.

## Open questions

- none

## Deviations

- none

## Review findings
