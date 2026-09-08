# Feature plan

Issue: ORB-185
Review verdict: PENDING

## Outcome

Operators can discover and use registered Tool Managers on any active SSH-managed Linux Node while Orbit provisions missing managers on first use and retains their lifecycle independently of roles.

## Code boundaries

In:
- `apps/gateway/app/Actions/Tools`, `app/Data/Tools`, `app/Http/Controllers/Api/ToolManagersController.php`, and `app/Domain/Tools`: synthesize supported uninstalled manager states, enforce the managed-Node boundary, materialize under the existing operation locks, translate provisioning failures, and keep unknown persisted identifiers readable.
- `apps/gateway/app/Infrastructure/Tools`, `app/Infrastructure/Nodes/Roles/NodeRolePrerequisiteCommandFactory.php`, and `app/Actions/Nodes`: give APT, VP, and Composer idempotent manager-owned materialization, retain role-triggered convergence without role ownership, and remove final-app-role Tool gates and retirement.
- `apps/cli/app/Commands/Tools` and Tool command tests: transport nullable manager IDs, show every lifecycle state, and offer active or uninstalled managers during interactive installation.
- `packages/php-sdk/src/Responses/Tools` and response/request tests: transport nullable IDs and the `uninstalled` state without adding manager policy.
- Existing Gateway Tool, Node-role, HTTP, and model tests: cover first-use success, failed retry, eligibility, retained state, route absence, and the rollback bridge.

Out:
- Homebrew registration, package operations, and bootstrap remain for ORB-186; this branch adds no Homebrew adapter or public Homebrew support.
- Roleless operator clients remain unmanaged; this branch adds no client-local inspection, SSH requirement, or convergence.
- Public manager removal and automatic manager cleanup remain absent from routes, actions, SDK requests, and CLI commands.
- Doctor remains verify-only and receives no mutating or manager-materialization path.

## Documentation

- `docs/concepts.md`: defines Tool and Tool Manager with their Node identity, protected capability, on-demand availability, and role independence.
- `docs/reference/tools.md`: describes supported managers, nullable IDs, lifecycle states, managed-Node eligibility, first-use provisioning, retry, and retention.
- Documentation audit fixed the prior reference page's removal-only coverage; no findings remain reported for another owner.

## Acceptance map

| Criterion | Boundary | Focused proof |
| --- | --- | --- |
| List every registered manager with the four lifecycle states and nullable uninstalled ID | Gateway list action/data/controller; SDK manager DTO; CLI list command | `apps/gateway/tests/Feature/Domain/ToolReadActionsTest.php`; `packages/php-sdk/tests/Unit/Responses/Tools/ToolResponsesTest.php`; `apps/cli/tests/Feature/Tools/ToolCommandsTest.php` |
| Offer uninstalled managers interactively | CLI install command | `apps/cli/tests/Feature/Tools/ToolCommandsTest.php` |
| Provision or retry VP and Composer before first Tool install | Gateway install action, materializer, VP and Composer adapters | `apps/gateway/tests/Feature/Domain/ToolActionsTest.php`; Incus `first-use-manager-materialization` |
| Allow role-independent managed Nodes and reject roleless clients | Gateway Tool actions and managed-Node eligibility helper | `apps/gateway/tests/Feature/Domain/ToolActionsTest.php`; Incus `managed-node-boundary` |
| Avoid eager provisioning while preserving role-required convergence and role removal | Node provision/add/remove actions, prerequisite renderer, and manager materializer | `apps/gateway/tests/Feature/Domain/ProvisionNodeActionTest.php`; `apps/gateway/tests/Feature/Domain/AddNodeRoleActionTest.php`; `apps/gateway/tests/Feature/Domain/RemoveNodeRoleActionTest.php` |
| Retain a manager after final Tool removal and expose no removal route | Existing Tool removal action and HTTP route surface | `apps/gateway/tests/Feature/Domain/RemoveToolActionTest.php`; `apps/gateway/tests/Feature/Configuration/HttpRouteSurfaceTest.php` |
| Keep an unknown persisted manager readable but unavailable for new installs | Tool Manager model/read state and registry boundary | `apps/gateway/tests/Feature/Domain/ToolReadActionsTest.php`; `apps/gateway/tests/Feature/Domain/CentralStoreModelsTest.php` |
| Publish the maintained Tool contract | `docs/concepts.md`; `docs/reference/tools.md` | `composer docs-lint` |

Incus observations: `observed_inputs: false`. The decisive acceptance includes remote SSH scripts, package-manager state, and Node ownership outside PHP file coverage, so PCOV cannot completely observe the required surfaces; the proof uses the broad static input policy.

## Implementation order

1. Introduce a stable string-backed persisted manager identifier and a nullable manager-state transport object so an absent registry adapter cannot break reads after rollback.
2. Make the manager registry synthesize ordered `uninstalled` states and update Gateway, SDK, and CLI list and prompt contracts.
3. Move managed-Node eligibility into the Tool action boundary and remove app-role requirements from install and update.
4. Add idempotent `materialize` behavior to each manager adapter, with VP and Composer prerequisites extracted from role setup and retained role-triggered calls.
5. Invoke materialization within the existing exact Tool and manager-scope locks, translate retryable failures, and retain the manager record without creating Tool intent on failure.
6. Remove last-app-role blocking and manager retirement while preserving Tool and package state.
7. Add focused regression tests, create the Incus proof plan and fixtures, run all component and repository gates, and prove the exact commit.

## Must preserve

- ADR 0001: a Tool remains Node + manager + package managed intent; Orbit never adopts host inventory, and private manager prerequisites receive no Tool rows.
- ADR 0001: callers still provide only Node, manager, package, and optional constraint; adapters validate package grammar and construct fixed commands in the shared Orbit-owned scope.
- ADR 0001: raw versions, conservative SemVer constraints, no alternative release search, and no automatic downgrade remain unchanged.
- ADR 0001: install rejects an already-present unowned package; Tool removal targets only the recorded root and never removes manager rows or protected prerequisites.
- ADR 0001: exact Tool identity and shared manager mutations remain serialized; live-state retry, bounded failure state, redacted diagnostics, stable outcomes, and request IDs remain intact.
- ADR 0001: Gateway owns manager policy and mutation, SDK owns typed transport, CLI owns rendering and prompts, and Tool operations never own processes.
- ADR 0012: roleless Ubuntu 24.04 operator clients receive no Gateway-to-client SSH or managed convergence and remain outside managed-role platform support.
- ADR 0042: managers are protected Node capabilities independent of roles and Tool mutations require an active Linux Node under Gateway-owned SSH management.
- ADR 0042: missing managers materialize on first use, failed materialization remains retryable, and Node provisioning does not install every manager.
- ADR 0042: roles may require but never own managers or Tools; managers remain after their final Tool; no public manager removal exists; final app-role removal is not blocked by Tool intent.
- Existing APT exact-removal handling, verify-only Doctor behavior, authorization middleware, and HTTP body validation remain covered by their current tests.

## Open questions

- None.

## Deviations

- VP and Composer now own complete first-use materialization. The existing app-role prerequisite script keeps its idempotent copies because it also converges Bun and publishes the combined runtime atomically. Extracting that established role script would broaden this issue without changing the accepted manager lifecycle.

## Review findings

- None.
