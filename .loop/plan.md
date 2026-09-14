# Feature plan

Plan format: 1
Issue: ORB-333
Flow: discovery
Review verdict: PENDING

## Outcome

An operator manages App-owned process and schedule definitions with `--app` on the process and schedule families, using the same verbs and options as AppInstance and Node targets, and the app family carries no definition command.

## Code boundaries

In:
- `apps/cli/app/Commands/Processes/` and `apps/cli/app/Commands/Schedules/` gain an `--app` target on create, list, show, update, and destroy, plus `--for` for definition environments; `process:show`, `process:update`, and `schedule:update` are added for the App target.
- `apps/cli/app/Commands/Apps/{Process,Schedule,ListProcess,ListSchedule}*Definition*.php` and `AppRuntimeDefinitionCommand.php` are removed so the app family exposes no definition command.
- `apps/cli/tests/Feature/Processes/ProcessCommandsTest.php`, `apps/cli/tests/Feature/Schedules/ScheduleCommandsTest.php`, `apps/cli/tests/Feature/CommandSurfaceTest.php`, and `apps/cli/tests/Feature/Apps/RuntimeDefinitionCommandsTest.php` cover the new surface and the absent old names.
- `apps/gateway/routes/api.php` renames definition routes to `process:list|create|show|update|destroy` and `schedule:*` without changing HTTP paths; `ProcessDefinition` and `ScheduleDefinition` bind item operations by name within the App.
- `apps/gateway/app/Http/Middleware/RecordCommandActivity.php` and `apps/gateway/app/Infrastructure/Activity/CommandActivityTargetResolver.php` record those command names against the App rather than an AppInstance or Node lookup.
- `apps/gateway/tests/Feature/Api/AppRuntimeDefinitionsTest.php`, `apps/gateway/tests/Feature/Api/CommandActivityTest.php`, and `apps/gateway/tests/Feature/Configuration/NodeAccessRouteScopeTest.php` cover unique names, command names, App activity targets, and duplicate route names.
- `packages/php-sdk/src/Requests/Apps/` renames `Replace*` to `Update*` and `Remove*` to `Destroy*`; item requests encode the definition name; `packages/php-sdk/tests/Unit/Requests/Apps/RuntimeDefinitionRequestsTest.php`, `packages/php-sdk/tests/Unit/RepositoryGuidanceTest.php`, and `packages/php-sdk/.ai/rules/public-contract.md` drop the replaced class names.

Out:
- `apps/gateway/app/Actions/AppInstances/InstantiateAppRuntimeDefinitionsAction.php` and clone copy behavior stay unchanged under ADR 0048.
- Definition models keep `id`, `app_id`, `name`, `environments`, and `spec`; request bodies keep the same JSON field contract.
- AppInstance and Node `process:create` and `schedule:create` do not gain update; `process:start|stop|restart|logs` and `schedule:run|logs|enable` do not gain `--app`.
- HTTP paths stay `/api/v1/apps/{app}/process-definitions` and `/api/v1/apps/{app}/schedule-definitions` with the same item segment; methods stay GET, POST, PUT, and DELETE.

## Documentation

`docs/reference/app-processes-and-schedules.md` now describes the App target on process and schedule commands, `--for` for definition environments, selection by name, and structured create and update flags. It no longer documents `app:process-definition`, `app:process-definitions`, `app:schedule-definition`, `app:schedule-definitions`, or JSON-file input.

`docs/reference/schedules.md` now lists `--app` and `--for` on schedule create, list, show, update, and destroy, and it states that those flags record or select a definition rather than a Node or AppInstance Schedule.

### Audit

Scope: ORB-333 pages from `composer docs-context` for `apps/cli`, `apps/gateway`, `packages/php-sdk`, and the App, AppInstance, Process, Schedule, and Node concepts, plus the issue's definitions reference page.

Fixed:
- `docs/reference/app-processes-and-schedules.md`: the CLI still documented multiplexed `app:process-definition` and JSON-file input → it now states the App target, `--for`, and name selection on the process and schedule families.
- `docs/reference/schedules.md`: the CLI table omitted the App definition target → it now includes `--app` and `schedule:update` for definitions.

Reported:
- none

Verification: `composer docs-lint` after `composer docs-build`.

## Acceptance map

| Criterion | Boundary | Focused proof |
| --- | --- | --- |
| Create with `--app` and `--for` records a process or Schedule definition using the same spec flags as an AppInstance target | CLI process and schedule create; Gateway definition store | `apps/cli/tests/Feature/Processes/ProcessCommandsTest.php`, `apps/cli/tests/Feature/Schedules/ScheduleCommandsTest.php`, `apps/gateway/tests/Feature/Api/AppRuntimeDefinitionsTest.php` |
| `list`, `show`, `update`, and `destroy` with `--app` select by name, and create refuses a duplicate App name | CLI process and schedule commands; Gateway unique-name conflict | `apps/gateway/tests/Feature/Api/AppRuntimeDefinitionsTest.php`, `apps/cli/tests/Feature/Processes/ProcessCommandsTest.php`, `apps/cli/tests/Feature/Schedules/ScheduleCommandsTest.php` |
| Combined `--app` with `--instance` or `--node`, and `--for` on an AppInstance or Node target, fail before HTTP | CLI target parsing | `apps/cli/tests/Feature/Processes/ProcessCommandsTest.php`, `apps/cli/tests/Feature/Schedules/ScheduleCommandsTest.php` |
| The app family has no definition command; Gateway definition routes and recorded activity use process and schedule command names | CLI command surface; Gateway routes and activity | `apps/cli/tests/Feature/CommandSurfaceTest.php`, `apps/gateway/tests/Feature/Api/AppRuntimeDefinitionsTest.php`, `apps/gateway/tests/Feature/Api/CommandActivityTest.php` |
| SDK definition requests use create, list, show, update, and destroy, and replaced class names are absent | PHP SDK request classes | `packages/php-sdk/tests/Unit/Requests/Apps/RuntimeDefinitionRequestsTest.php` |
| The definitions reference describes the App target and options, and generated context is current | `docs/reference/app-processes-and-schedules.md` | `composer docs-lint` |

## Incus observations

Incus: not required. Discovery development uses focused local tests and the Builder candidate gate.

## Implementation order

1. Rewrite the maintained definition pages and regenerate documentation context.
2. Rename SDK `Replace*` and `Remove*` request classes to `Update*` and `Destroy*`, address items by name, and drop the old class names from tests and the public-contract rule.
3. Rename Gateway definition route names, bind item routes by name, keep the unique-name conflict, and resolve definition activity to the App.
4. Add `--app` and `--for` to the process and schedule families, add App-only `update` and process `show`, and delete the app-family definition commands.
5. Prove each acceptance item with the mapped tests and `composer docs-lint`.

## Must preserve

- ADR 0071: one noun family and one verb; create and destroy for Gateway-owned resources; update for a partial or replacement change; list and show for reads; the process and schedule families expose ADR 0048 definitions as the App target of those verbs; an App target selects a definition and does not create a Process or Schedule under ADR 0069; a Gateway route that serves a CLI command carries that command's name; an SDK request that serves a CLI command carries that command's verb; no alias for a renamed command.
- ADR 0048: an App owns reusable process and schedule definitions with development and production applicability; creating, editing, or removing a definition must not change existing instance copies or their runtime state; Orbit must not change an App definition when an instance copy is modified or removed.
- Definition HTTP paths, methods, and stored `name`, `environments`, and `spec` fields stay as they are; collection responses still omit command text.
- AppInstance and Node process and schedule create, list, show, and destroy keep their current selectors and request bodies.
- Docker `--environment` on `process:create` remains NAME=VALUE container variables; definition environments use `--for` only.

## Open questions

none

## Deviations

none

## Review findings
