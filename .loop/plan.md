# Feature plan

Plan format: 1
Issue: ORB-331
Flow: discovery
Review verdict: PENDING

## Outcome

Operators create and destroy Database connection records with create and destroy and add and remove a connection on an AppInstance with add and remove, and the Gateway route names and SDK request classes that serve those commands carry the same verbs.

## Code boundaries

In:
- CLI signatures, class names, and tests for `database:create`, `database:destroy`, `instance:database:add`, and `instance:database:remove` in `apps/cli/app/Commands/Database`, `apps/cli/app/Commands/Instances`, `apps/cli/tests/Feature/CommandSurfaceTest.php`, and `apps/cli/tests/Feature/Database/DatabaseConnectionCommandsTest.php`
- Gateway route names in `apps/gateway/routes/api.php` for those commands plus `database:list`, `database:show`, and `database:update`, with activity command strings and Node-access route-scope keys in `apps/gateway/tests/Feature/Api/DatabaseConnectionsTest.php`, `apps/gateway/tests/Feature/Api/DatabaseConnectionAttachmentsTest.php`, `apps/gateway/tests/Feature/Api/CommandActivityTest.php`, and `apps/gateway/tests/Feature/Configuration/NodeAccessRouteScopeTest.php`
- SDK request class and file names in `packages/php-sdk/src/Requests/DatabaseConnections` and tests in `packages/php-sdk/tests/Unit/Requests/DatabaseConnections/DatabaseConnectionRequestsTest.php` and `packages/php-sdk/tests/Unit/RepositoryGuidanceTest.php`

Out:
- `database:list`, `database:show`, and `database:update` CLI signatures, arguments, and options stay as they are; only their Gateway route names change to match
- Stored connection fields, attachment `operation` values, encrypted passwords, and the prefixed environment keys an attachment writes stay unchanged
- HTTP methods and paths stay `/api/v1/database-connections` and `/api/v1/instances/{instance}/database-connections/{slug}`
- Gateway FormRequest, Action, and Data class names stay internal
- The Database Node role and `docs/reference/database-role.md` stay unchanged
- No aliases for replaced command, route, or SDK class names

## Documentation

- `docs/reference/database-connections.md`: names `orbit database:create`, `orbit database:destroy`, `orbit instance:database:add`, and `orbit instance:database:remove`; keeps list, show, and update; HTTP paths unchanged.
- `docs/reference/environment-variables.md`: names `instance:database:add` and `instance:database:remove` for the stored-key write and clear that this page does not own.
- `docs/README.md`: the Database connections index line names AppInstance add instead of attach.
- `docs/generated/context.json`: regenerated after the page edits.

### Audit

Scope: ORB-331 pages from `composer docs-context` for `apps/cli`, `apps/gateway`, `packages/php-sdk`, Database, Database connection, and AppInstance, plus the Database connections reference page.

Fixed:
- `docs/reference/database-connections.md`: named `database:add`, `database:remove`, `database:attach`, and `database:detach` → names create, destroy, instance add, and instance remove.
- `docs/reference/environment-variables.md`: named attach and detach as the stored-key writers → names `instance:database:add` and `instance:database:remove`.
- `docs/README.md`: "AppInstance attach" → AppInstance add.

Reported:
- `docs/architecture.md` and `docs/concepts.md`: describe adding a connection as attach in English and do not name CLI commands. Leave the association wording; `docs/reference/database-connections.md` owns the command names. No extra issue.
- `docs/reference/database-role.md`: does not name these commands; the Database role is out of scope.
- ADR 0071 Detail names `docs/reference/cli-command-vocabulary.md`, which is absent. Owner: the remaining ADR 0071 family issues, not this rename.

Verification: `composer docs-lint` after `composer docs-build`.

## Acceptance map

| Criterion | Boundary | Focused proof |
| --- | --- | --- |
| `orbit database:create`, `orbit database:destroy`, `orbit instance:database:add`, and `orbit instance:database:remove` perform the former add, remove, attach, and detach operations with the same arguments and options, and each replaced name is unknown | CLI command classes and `CommandSurfaceTest.php` | `apps/cli` `composer test:affected` covering `CommandSurfaceTest.php` and `DatabaseConnectionCommandsTest.php` |
| Each serving Gateway route carries the command name, and recorded activity names the new command | `apps/gateway/routes/api.php` and activity tests | `apps/gateway` `composer test:affected` covering `DatabaseConnectionsTest.php`, `DatabaseConnectionAttachmentsTest.php`, and `CommandActivityTest.php` |
| SDK request classes carry create, destroy, add, and remove, and each replaced class name is absent | `packages/php-sdk/src/Requests/DatabaseConnections` | `packages/php-sdk` `composer test:affected` covering `DatabaseConnectionRequestsTest.php` |
| The Database connections reference page names only the new commands, and generated context is current | `docs/reference/database-connections.md` | `composer docs-lint` |

## Incus observations

Incus: not required. Discovery maps the issue Proof venues to TIA affected tests in the changed projects and the Builder candidate gate.

## Implementation order

1. Write the documentation pages and regenerate context.
2. Rename the SDK request classes, then the CLI commands that send them, then the Gateway route names and activity assertions.
3. Run affected tests in `apps/cli`, `packages/php-sdk`, and `apps/docs` on this Mac, then `apps/gateway` on beast.
4. Run the Builder candidate gate on the exact pushed head.

## Must preserve

- ADR 0071: one noun family followed by one verb; create and destroy for Gateway-owned records; add and remove for an association; a serving route carries the CLI command name; an SDK request class carries that verb; no alias for a renamed command.
- HTTP methods and paths stay unchanged.
- Attachment arguments stay `{slug}` plus `--instance`, `--prefix`, `--force` on remove, and `--json`.
- `instance:database:*` activity resolves the AppInstance the way other `instance:` commands do.
- Passwords stay encrypted at rest and redacted from responses, activity, and debug output.
- `database:list`, `database:show`, and `database:update` keep their CLI contracts.

## Open questions

none

## Deviations

none

## Review findings
