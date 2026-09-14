# Feature plan

Plan format: 1
Issue: ORB-325
Flow: discovery
Review verdict: PENDING

## Outcome

Operators create and destroy Apps, Clusters, and Routes with create and destroy, add and remove a Node in a Cluster, and set and unset a Cluster Router or Route target, and the Gateway route names and SDK request classes that serve those commands carry the same verbs under ADR 0071. Discovery flow; Incus is not required.

## Code boundaries

In:
- CLI signatures and command classes under `apps/cli/app/Commands/Apps`, `apps/cli/app/Commands/Clusters`, and `apps/cli/app/Commands/Routes`, plus `apps/cli/tests/Feature/CommandSurfaceTest.php`, `apps/cli/tests/Feature/Apps/AppCommandsTest.php`, `apps/cli/tests/Feature/Clusters/ClusterCommandsTest.php`, `apps/cli/tests/Feature/Routes/RouteCommandsTest.php`, and CLI tests that invoke the renamed signatures.
- Gateway route names in `apps/gateway/routes/api.php`, activity matching in `apps/gateway/app/Infrastructure/Activity/CommandActivityTargetResolver.php`, and the family API, activity, and node-access tests that name those routes.
- SDK request classes under `packages/php-sdk/src/Requests/Apps`, `packages/php-sdk/src/Requests/Clusters`, and `packages/php-sdk/src/Requests/Routes`, plus the matching SDK request tests and class-list checks.
- Prepared-state paths `apps/cli/app/Commands/Clusters/AttachClusterNodeCommand.php` and `packages/php-sdk/src/Requests/Clusters/AttachClusterNodeRequest.php` in `apps/e2e/resources/prepared-state.json`, with the matching assertions in `apps/e2e/tests/Unit/E2E/PreparedStateFingerprintTest.php`.

Out:
- Workspace family commands, routes, and SDK classes remain `workspace:new` and `workspace:remove`.
- Command arguments, options, defaults, request and response payloads, HTTP methods, and HTTP paths stay unchanged.
- Gateway domain actions, Form Requests, and controller method names stay unchanged.
- `cluster:router:set`, `route:target:set`, `CreateAppRequest`, `CreateClusterRequest`, `CreateRouteRequest`, `SetClusterRouterRequest`, and `SetRouteTargetRequest` keep their names.
- Behavior, confirmation prompts, and human output of each renamed command stay unchanged.
- Accepted ADRs under `docs/decisions/` stay unchanged.
- Harness code under `apps/e2e` other than `apps/e2e/resources/prepared-state.json` and the Unit/Feature tests that name the moved prepared-state paths stays unchanged.

## Documentation

- `docs/reference/apps.md` now names `app:create` for App creation, including examples, the `--default-branch` option, and the idempotent retry contract.
- `docs/domains/applications.md` now names `app:create` for the same creation command.
- `docs/reference/routes.md` now names `route:create`, `route:target:unset`, and `route:destroy` as the CLI names of those Route operations.
- `docs/generated/context.json` is rebuilt after those page edits.
- Reported: ADR 0071 names `docs/reference/cli-command-vocabulary.md` as its Detail page, and that page does not exist. Owner: a separate docs-labeled issue; this issue only renames the App, Cluster, and Route lifecycle commands already named on maintained pages.
- Reported: no maintained page names the Cluster create, destroy, node add, node remove, or router unset commands. Owner: a separate Cluster reference page if operators need that contract in prose.
- Reported: `docs/reference/apps.md` names App creation and does not name `app:destroy`. Owner: a separate docs issue if App destruction needs a reference section.

## Acceptance map

| Criterion | Boundary | Focused proof |
| --- | --- | --- |
| The ten renamed CLI commands perform the replaced operations with unchanged arguments and options, and each replaced name is an unknown command | CLI command signatures and classes under Apps, Clusters, and Routes | `composer test:affected` in `apps/cli` covering `apps/cli/tests/Feature/CommandSurfaceTest.php`, `apps/cli/tests/Feature/Apps/AppCommandsTest.php`, `apps/cli/tests/Feature/Clusters/ClusterCommandsTest.php`, and `apps/cli/tests/Feature/Routes/RouteCommandsTest.php` |
| Each Gateway route that serves those commands carries the command name, and recorded activity names the new command | `apps/gateway/routes/api.php` and `CommandActivityTargetResolver` | `composer test:affected` in `apps/gateway` covering `apps/gateway/tests/Feature/Api/AppsTest.php`, `ClustersTest.php`, `ClusterNodesTest.php`, `ClusterRouterTest.php`, `RoutesTest.php`, and `CommandActivityTest.php` |
| SDK request classes that serve those commands carry create, destroy, add, remove, set, and unset, and each replaced class name is absent | SDK request classes under Apps, Clusters, and Routes | `composer test:affected` in `packages/php-sdk` covering `packages/php-sdk/tests/Unit/Requests/Apps/AppRequestsTest.php`, `packages/php-sdk/tests/Unit/Requests/Clusters/ClusterRequestsTest.php`, and `packages/php-sdk/tests/Unit/Requests/Routes/RouteRequestsTest.php` |
| The prepared-state manifest names the moved cluster membership command and request files at their new paths | `apps/e2e/resources/prepared-state.json` | `composer test:affected` in `apps/e2e` covering `apps/e2e/tests/Unit/E2E/PreparedStateFingerprintTest.php` |
| Maintained documentation names only the new commands, and generated context is current | `docs/reference/apps.md`, `docs/reference/routes.md`, `docs/domains/applications.md`, `docs/generated/context.json` | `composer docs-lint` from the repository root |

## Implementation order

1. Write the documentation pages and rebuild generated context, then record this plan.
2. Rename the SDK request classes and files, then update SDK tests so the old class names are absent.
3. Rename CLI command signatures and the command classes whose names carry the old verbs, then update CLI tests including the command-surface list, option sets, JSON contracts, and an unknown-command check for each replaced name.
4. Rename the Gateway route names and the activity resolver matches, then assert the new command on recorded activity in the listed API tests.
5. Update the two prepared-state path entries and the fingerprint test, keeping the manifest sorted.
6. Run focused TIA checks in `apps/cli`, `packages/php-sdk`, and `apps/docs` on this Mac, then `apps/gateway` and `apps/e2e` on beast.

## Must preserve

- ADR 0071: the CLI names every command as one noun family followed by one verb.
- ADR 0071: create and destroy for a resource the Gateway brings into existence and tears down.
- ADR 0071: add and remove for an association between things that exist independently, including a Node in a Cluster.
- ADR 0071: set and unset for single-valued slots.
- ADR 0071: a Gateway route that serves a CLI command carries that command's name.
- ADR 0071: an SDK request class that serves a CLI command carries that command's verb.
- ADR 0071: the CLI must not keep an alias for a renamed command.
- HTTP methods and paths, command arguments and options, and request and response payloads stay unchanged.
- Workspace family names stay unchanged.
- `cluster:router:set` and `route:target:set` keep their names.
- CommandSurfaceTest continues to enumerate every visible command, its arguments and options, and its JSON failure envelope.
- Destructive Cluster and Route commands keep interactive confirmation or `--force`.
- Activity continues to record the Gateway route name as the command.

## Open questions

none

## Deviations

none

## Review findings

