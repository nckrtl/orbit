# Feature plan

Plan format: 1
Issue: ORB-326
Flow: discovery
Review verdict: PENDING

## Outcome

Operators create and destroy AppInstances with create and destroy and list retained releases with release:list, and every Gateway route that serves an instance or env command carries that command's name.

## Code boundaries

In:
- CLI signatures and the destroy command class under `apps/cli/app/Commands/Instances/`
- CLI surface and family tests under `apps/cli/tests/Feature/CommandSurfaceTest.php`, `apps/cli/tests/Feature/Instances/InstanceCommandsTest.php`, `apps/cli/tests/Feature/Instances/DeploymentCommandsTest.php`, and `apps/cli/tests/Feature/Gateway/GatewayErrorRenderingTest.php`
- Gateway route names in `apps/gateway/routes/api.php` and literal route-name matches in `apps/gateway/app/Http/Middleware/RecordCommandActivity.php` and `apps/gateway/app/Infrastructure/Activity/CommandActivityTargetResolver.php`
- Gateway activity and route-scope tests under `apps/gateway/tests/Feature/Api/AppInstancesTest.php`, `apps/gateway/tests/Feature/Api/AppInstanceDeploymentsTest.php`, `apps/gateway/tests/Feature/Api/AppInstanceEnvironmentTest.php`, `apps/gateway/tests/Feature/Api/CommandActivityTest.php`, `apps/gateway/tests/Feature/Api/ActivitiesTest.php`, and `apps/gateway/tests/Feature/Configuration/NodeAccessRouteScopeTest.php`
- SDK `DestroyAppInstanceRequest` under `packages/php-sdk/src/Requests/AppInstances/` and tests under `packages/php-sdk/tests/Unit/Requests/AppInstances/AppInstanceRequestsTest.php`, `packages/php-sdk/tests/Unit/Requests/Deployments/DeploymentRequestsTest.php`, `packages/php-sdk/tests/Unit/Requests/Instances/InstanceRequestsTest.php`, and `packages/php-sdk/tests/Unit/RepositoryGuidanceTest.php`

Out:
- Keep `instance:deployment-config` and `instance:prepare-deployment` and their routes `instance:deployment-config:*` and `instance:deployment-layout:prepare` unchanged; ADR 0073 issues own those names.
- Keep `packages/php-sdk/src/Requests/Instances/*` unchanged; ORB-202 owns the legacy Instance request surface.
- Keep the workspace family and `instance:transfer` unchanged; ORB-245 owns transfer.
- Keep command arguments, options, payloads, HTTP methods, and HTTP paths unchanged.
- Keep the Gateway FormRequest `App\Http\Requests\AppInstances\RemoveAppInstanceRequest` unchanged; it is not an SDK request class.
- Keep `apps/e2e` harness paths, including `apps/e2e/resources/guest/converge-sample-app.sh`, unchanged.

## Documentation

Updated maintained pages to name only the new commands. `composer docs-build` regenerates `docs/generated/context.json`.

- `docs/domains/applications.md`: operators create with `instance:create` and destroy with `instance:destroy`.
- `docs/reference/appinstance-removal.md`: operators destroy an AppInstance with `instance:destroy`.
- `docs/reference/deployments.md`: operators list retained releases with `instance:release:list`.
- `docs/reference/routes.md`: the generated-hostname example uses `instance:create`.
- `docs/reference/environment-variables.md`: already names `env:import`, `env:update`, and `env:sync`.
- `docs/reference/appinstance-cloning.md`: already names `instance:clone` and `instance:deploy`.
- `docs/reference/apps.md`: names App commands only; it does not name these instance lifecycle commands.

### Audit

Fixed:
- `docs/domains/applications.md`: named `instance:new` and `instance:remove` → names `instance:create` and `instance:destroy`.
- `docs/reference/appinstance-removal.md`: named `instance:remove` → names `instance:destroy`.
- `docs/reference/deployments.md`: named `instance:releases` → names `instance:release:list`.
- `docs/reference/routes.md`: named `instance:new` in the branch example → names `instance:create`.

Reported:
- `docs/decisions/0018-register-caller-local-development-worktrees.md` and `docs/decisions/0027-adopt-local-git-sources-into-appinstance-ownership.md`: Decision bullets still name `instance:new` and `instance:remove`; accepted ADRs are immutable; owner: those ADRs.
- `apps/e2e/resources/guest/converge-sample-app.sh`: still invokes `instance:new`; harness code is outside this issue; owner: a dedicated `apps/e2e` harness issue.

## Acceptance map

| Criterion | Boundary | Focused proof |
| --- | --- | --- |
| `orbit instance:create`, `orbit instance:destroy`, and `orbit instance:release:list` perform the replaced operations with unchanged arguments and options, and each replaced name is an unknown command | CLI signatures and destroy command class under `apps/cli/app/Commands/Instances/` | `apps/cli/tests/Feature/CommandSurfaceTest.php`, `apps/cli/tests/Feature/Instances/InstanceCommandsTest.php`, and `apps/cli/tests/Feature/Instances/DeploymentCommandsTest.php` through `composer test:affected` |
| The Gateway route that serves each instance and env command carries the command name, and a recorded activity for each names that command | `apps/gateway/routes/api.php`, `RecordCommandActivity.php`, and `CommandActivityTargetResolver.php` | `apps/gateway/tests/Feature/Api/AppInstancesTest.php`, `apps/gateway/tests/Feature/Api/AppInstanceDeploymentsTest.php`, `apps/gateway/tests/Feature/Api/AppInstanceEnvironmentTest.php`, and `apps/gateway/tests/Feature/Api/CommandActivityTest.php` through `composer test:affected` |
| The SDK request classes that serve instance:create, instance:destroy, and instance:release:list carry the verbs create, destroy, and list, and each replaced class name is absent | `packages/php-sdk/src/Requests/AppInstances/DestroyAppInstanceRequest.php` and `ListAppInstanceReleasesRequest` | `packages/php-sdk/tests/Unit/Requests/AppInstances/AppInstanceRequestsTest.php` and `packages/php-sdk/tests/Unit/Requests/Deployments/DeploymentRequestsTest.php` through `composer test:affected` |
| Maintained documentation names only the new commands, and generated context is current | pages listed in Documentation | `composer docs-lint` |

## Incus observations

Incus: not required.

## Implementation order

1. Write the maintained pages and regenerate documentation context.
2. Rename the three CLI command names, rename the destroy command class, and update CLI tests so replaced names are unknown.
3. Rename the in-scope Gateway routes and the activity matchers, then update activity and route-scope tests.
4. Rename `RemoveAppInstanceRequest` to `DestroyAppInstanceRequest` in the SDK, add `AppInstanceRequestsTest.php`, and update remaining SDK callers.
5. Run affected tests and changed-project checks, then the Builder gate.

## Must preserve

- ADR 0071: the CLI names every command as one noun family followed by one verb.
- ADR 0071: the CLI uses create and destroy for a resource that the Gateway brings into existence and tears down.
- ADR 0071: a Gateway route that serves a CLI command carries that command's name.
- ADR 0071: an SDK request class that serves a CLI command carries that command's verb.
- ADR 0071: the CLI does not keep an alias for a renamed command.
- ADR 0071: the PHP SDK and the Gateway do not carry a request surface for the legacy Instance model retired by ADR 0036.
- HTTP methods, HTTP paths, command arguments, options, and payloads stay unchanged.
- `instance:register` and `instance:clone` already match their route names and stay unchanged.
- `CreateAppInstanceRequest` and `ListAppInstanceReleasesRequest` already carry create and list and stay named.

## Open questions

none

## Deviations

none

## Review findings

