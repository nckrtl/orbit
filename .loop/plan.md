# Feature plan

Plan format: 1
Issue: ORB-328
Flow: discovery
Review verdict: PENDING

## Outcome

Operators provision or converge a Node with `orbit node:add`, and the Gateway route name and SDK request class that serve it carry the verb add under ADR 0071.

## Code boundaries

In:
- CLI command `apps/cli/app/Commands/Nodes/AddNodeCommand.php` (from `ProvisionNodeCommand.php`), its tests, `apps/cli/tests/Feature/CommandSurfaceTest.php`, clone help text, and CLI error-rendering cases that name the command
- Gateway route name in `apps/gateway/routes/api.php`, `CommandActivityTargetResolver` match, activity and route-scope tests; `apps/gateway/tests/Feature/Api/ProvisionNodeTest.php` keeps its filename
- SDK `packages/php-sdk/src/Requests/Nodes/AddNodeRequest.php` (from `ProvisionNodeRequest.php`) and its unit test

Out:
- Gateway console command `orbit:node-provision` (`apps/gateway/app/Console/Commands/ProvisionNodeCommand.php`)
- Gateway form request `apps/gateway/app/Http/Requests/Nodes/ProvisionNodeRequest.php` and action `ProvisionNodeAction`
- Node removal semantics under ADR 0072, `node:create`, and `node:destroy`
- Arguments, options, payload, HTTP method, path, and behavior of the command
- Every file under `apps/e2e/`
- Accepted ADRs under `docs/decisions/`

## Documentation

- `docs/reference/node-provisioning.md`: the Detail page still titled Node provisioning; operator command is `orbit node:add <name> [host]`; Gateway console command `orbit:node-provision` is unchanged
- `docs/reference/node-settings.md`: setting examples and option text name `node:add`
- `docs/reference/appinstance-cloning.md`: production Node example uses `orbit node:add`

`docs/reference/node-retarget.md`, `docs/reference/wireguard-endpoints.md`, and `docs/domains/*.md` do not name `node:provision`.

### Audit

Fixed:
- `docs/reference/node-provisioning.md`: operator command `node:provision` → `node:add`; page title and file name unchanged
- `docs/reference/node-settings.md`: operator command `node:provision` → `node:add`
- `docs/reference/appinstance-cloning.md`: example `orbit node:provision` → `orbit node:add`

Reported:
- ADR 0071 names Detail `docs/reference/cli-command-vocabulary.md`, which is absent from the corpus. This issue's `docs` label covers the Node provisioning reference page, not a new vocabulary page. Owner: a follow-up `docs` issue through `creating-issues` after merge.

## Acceptance map

| Criterion | Boundary | Focused proof |
| --- | --- | --- |
| `orbit node:add` performs the operation of `orbit node:provision` with unchanged arguments and options, and `node:provision` is an unknown command | CLI `AddNodeCommand` and command surface | `apps/cli/tests/Feature/CommandSurfaceTest.php` and `apps/cli/tests/Feature/Nodes/AddNodeCommandTest.php` |
| The Gateway route that serves node:add carries that name, and a recorded activity for it names node:add | Gateway `routes/api.php` and `CommandActivityTargetResolver` | `apps/gateway/tests/Feature/Api/ProvisionNodeTest.php` and `apps/gateway/tests/Feature/Api/CommandActivityTest.php` |
| The SDK request class that serves node:add carries the verb add, and the replaced class name is absent | SDK `AddNodeRequest` | `packages/php-sdk/tests/Unit/Requests/Nodes/AddNodeRequestTest.php` |
| The Node provisioning reference page names node:add, and generated context is current | `docs/reference/node-provisioning.md` | `composer docs-lint` |

## Incus observations

Incus: not required. Discovery development only; isolated acceptance proof not run.

## Implementation order

1. Update the maintained pages that name `node:provision`, rebuild generated context, and lint docs.
2. Rename the SDK request class and test to `AddNodeRequest`, and assert the old class name is absent.
3. Rename the CLI command, description, tests, and command-surface entries to `node:add` with no alias.
4. Rename the Gateway route and activity matcher to `node:add`; keep HTTP method, path, and `ProvisionNodeTest.php` filename.
5. Run affected tests and project checks on the Mac for CLI, SDK, and docs; run Gateway affected tests on beast.

## Must preserve

- ADR 0071: add and remove for a Node in the fleet; reserve `node:create` and `node:destroy`; a Gateway route that serves a CLI command carries that name; an SDK request class that serves a CLI command carries that verb; no alias for a renamed command
- Arguments, options, payload, HTTP method `/api/v1/nodes` POST, and provisioning behavior stay unchanged
- Gateway console command `orbit:node-provision`, form request, and `ProvisionNodeAction` keep their names
- Existing CLI option and JSON contracts in `CommandSurfaceTest` for every other command
- Node removal command `node:remove` and its semantics

## Open questions

none

## Deviations

none

## Review findings
