# Feature plan

Plan format: 1
Issue: ORB-327
Flow: discovery
Review verdict: PENDING

## Outcome

Operators create and destroy Processes, Schedules, and Herdr sessions with create and destroy and enable a Schedule timer with enable, and the Gateway route names and SDK request classes that serve those commands carry the same verbs.

## Code boundaries

In:
- CLI command signatures and command classes whose names carry the old verbs: `apps/cli/app/Commands/Processes/{Add,Remove}ProcessCommand.php`, `apps/cli/app/Commands/Schedules/{Add,Remove,Activate}ScheduleCommand.php`, `apps/cli/app/Commands/Herdr/{Add,Remove}HerdrSessionCommand.php`; command-surface, family, README, unknown-command, and Boost guidance tests under `apps/cli/tests/Feature/` plus `apps/cli/README.md`, `apps/cli/.ai/rules/commands.md`, and `apps/cli/.ai/skills/orbit-cli-development/SKILL.md`
- Gateway route names in `apps/gateway/routes/api.php`; literal route-name matches in `apps/gateway/app/Http/Middleware/RecordCommandActivity.php` and `apps/gateway/app/Infrastructure/Activity/CommandActivityTargetResolver.php`; activity and family API tests under `apps/gateway/tests/Feature/Api/` plus route-scope and admission tests that name those routes
- SDK request classes and files under `packages/php-sdk/src/Requests/{Processes,Schedules,Herdr}/` and the SDK tests that construct those classes

Out:
- App process and schedule definitions (`app:process-definition`, `app:schedule-definition`, `process-definition:*`, `schedule-definition:*`) stay on their current verbs; ORB-333 moves them later
- `process:start`, `process:stop`, `process:restart`, and `process:logs` stay unchanged
- `schedule:run`, `schedule:logs`, and the Node-side `schedule:complete` hook stay unchanged; no schedule disable command is added
- Command arguments, options, payloads, HTTP methods, and HTTP paths stay unchanged, including `/api/v1/schedules/{schedule}/activate`
- Gateway actions, data objects, and HTTP form requests keep their current class names, including `AddProcessAction`, `RemoveProcessAction`, `AddScheduleAction`, `ActivateScheduleAction`, and `App\Http\Requests\Herdr\RemoveHerdrSessionRequest`
- `docs/decisions/` stays untouched
- E2E harness code stays untouched; Feature and Unit tests under `apps/e2e/tests` name none of these commands today

## Documentation

Fixed:
- `docs/reference/schedules.md`: CLI commands are `schedule:create`, `schedule:destroy`, and `schedule:enable`; the matching API operations use those verbs while HTTP methods and paths stay the same
- `docs/reference/herdr-sessions.md`: CLI commands are `herdr:session:create` and `herdr:session:destroy`
- `docs/reference/app-processes-and-schedules.md`: Process lifecycle commands are `process:create` and `process:destroy`; App definition sections and `--remove` on definition commands stay unchanged

Reported:
- none

Verification: `composer docs-lint` after `composer docs-build`. Generated `docs/generated/context.json` is committed when the build changes it.

Audit scope was the `docs-context` pages for `apps/cli`, `apps/gateway`, `packages/php-sdk`, Process, and Schedule, plus the three reference pages this issue requires. Other pages in that set do not name these commands.

## Acceptance map

| Criterion | Boundary | Focused proof |
| --- | --- | --- |
| `orbit process:create`, `orbit process:destroy`, `orbit schedule:create`, `orbit schedule:destroy`, `orbit schedule:enable`, `orbit herdr:session:create`, and `orbit herdr:session:destroy` perform the replaced operations with unchanged arguments and options, and each replaced name is an unknown command | CLI command classes, signatures, README, and Boost schedule surface | `apps/cli/tests/Feature/CommandSurfaceTest.php`, `apps/cli/tests/Feature/Processes/ProcessCommandsTest.php`, `apps/cli/tests/Feature/Schedules/ScheduleCommandsTest.php`, `apps/cli/tests/Feature/Herdr/HerdrSessionCommandsTest.php`; TIA via `apps/cli` `composer test:affected` |
| The Gateway route that serves each of those commands carries the command name, and a recorded activity for each names the new command | `apps/gateway/routes/api.php`, activity middleware, activity target resolver | `apps/gateway/tests/Feature/Api/ProcessesTest.php`, `apps/gateway/tests/Feature/Api/SchedulesTest.php`, `apps/gateway/tests/Feature/Api/HerdrSessionsTest.php`, `apps/gateway/tests/Feature/Api/CommandActivityTest.php`; TIA via `apps/gateway` `composer test:affected` on beast |
| The SDK request classes that serve those commands carry create, destroy, and enable, and each replaced class name is absent | `packages/php-sdk/src/Requests/{Processes,Schedules,Herdr}/` | `packages/php-sdk/tests/Unit/Requests/Processes/ProcessRequestsTest.php`, `packages/php-sdk/tests/Unit/Requests/Schedules/ScheduleRequestsTest.php`, `packages/php-sdk/tests/Unit/Requests/Herdr/HerdrSessionRequestsTest.php`; TIA via `packages/php-sdk` `composer test:affected` |
| Maintained documentation names only the new commands, and generated context is current | `docs/reference/schedules.md`, `docs/reference/herdr-sessions.md`, `docs/reference/app-processes-and-schedules.md`, `docs/generated/context.json` | `composer docs-lint` |

## Implementation order

1. Write the three reference pages to the new command names and regenerate docs context.
2. Rename the seven SDK request classes and files; update SDK tests and CLI imports.
3. Rename CLI command signatures and command classes whose names carry the old verbs; update command-surface, family, README, unknown-command, and Boost guidance tests.
4. Rename the seven Gateway route names and the literal activity matches; update family API, activity, and route-scope tests. HTTP methods and paths stay the same.
5. Run `composer test:affected` and `composer check` in `apps/cli`, `packages/php-sdk`, and `apps/docs` on this Mac; run gateway (and e2e if touched) affected tests on beast; then the Builder gate on beast.

## Must preserve

- ADR 0071: the CLI names every command as one noun family followed by one verb
- ADR 0071: create and destroy name a resource the Gateway brings into existence and tears down
- ADR 0071: enable names a toggle; this issue enables a Schedule timer and does not add disable
- ADR 0071: a Gateway route that serves a CLI command carries that command's name
- ADR 0071: an SDK request class that serves a CLI command carries that command's verb
- ADR 0071: the CLI must not keep an alias for a renamed command
- HTTP methods and paths, including `POST /api/v1/schedules/{schedule}/activate`
- Process start, stop, restart, and logs; Schedule run, logs, and complete
- App definition commands and `process-definition:*` / `schedule-definition:*` route names
- Existing arguments, options, and payloads on the renamed commands
- Incus: not required; local TIA checks and the Builder candidate gate

## Open questions

none

## Deviations

none

## Review findings
