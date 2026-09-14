# Feature plan

Plan format: 1
Issue: ORB-329
Flow: discovery
Review verdict: PENDING

## Outcome

An operator removes a gateway profile from the CLI configuration with `orbit gateway:remove NAME`.

## Code boundaries

In:
- `apps/cli/app/Commands/Gateway/GatewayRemoveCommand.php` for the command contract.
- `apps/cli/app/Repositories/GatewayConfigRepository.php` for locked profile removal and pinned CA file deletion.
- `apps/cli/tests/Feature/Gateway/GatewayRemoveCommandTest.php` for acceptance behavior.
- `apps/cli/tests/Feature/CommandSurfaceTest.php` for the command name, options, and JSON failure envelope.
- `apps/cli/tests/Unit/GatewayConfigRepositoryTest.php` for locked removal and CA file side effects.

Out:
- Leave `gateway:add`, `gateway:use`, `gateway:trust`, and `gateway:status` command contracts unchanged.
- Leave the gateway profile file schema unchanged except deleting one named entry and clearing `active_gateway` when `--force` removes the active profile.
- Do not add a Gateway HTTP request, route, SDK request class, or operating-system trust-store uninstall.

## Documentation

- `docs/reference/gateway-trust.md`: states that `gateway:use` selects an existing profile, and that `gateway:remove` deletes an inactive named profile and its pinned certificate file, refuses the active profile unless `--force` clears the active selection, reports an unknown name as not found, and returns the removed profile name in JSON.
- `docs/generated/context.json`: regenerated after the page change.

### Audit

Fixed:
- `docs/reference/gateway-trust.md`: `gateway:use` was missing beside `gateway:add`; the page now states selection and removal.

Reported:
- `docs/reference/gateway-trust.md`: `gateway:status` is not described on a maintained page. Status is out of this issue's Scope, so a later docs-labeled issue owns that coverage.

## Acceptance map

| Criterion | Boundary | Focused proof |
| --- | --- | --- |
| Remove inactive profile and pinned CA; unknown name is not found | GatewayRemoveCommand and GatewayConfigRepository | `apps/cli/tests/Feature/Gateway/GatewayRemoveCommandTest.php` |
| Refuse active profile; `--force` removes it and clears the active selection | GatewayRemoveCommand and GatewayConfigRepository | `apps/cli/tests/Feature/Gateway/GatewayRemoveCommandTest.php` |
| `--json` returns the removed profile name; command is on the surface | CommandSurfaceTest and GatewayRemoveCommand | `apps/cli/tests/Feature/CommandSurfaceTest.php` |
| Gateway reference describes `gateway:remove`; generated context is current | `docs/reference/gateway-trust.md` | `composer docs-lint` |

## Implementation order

1. Update the gateway trust page and regenerate docs context.
2. Add `GatewayConfigRepository::remove` under the existing config lock, including pinned CA deletion.
3. Add `gateway:remove` with `--force` and `--json`, matching sibling error rendering.
4. Cover acceptance in `GatewayRemoveCommandTest` and register the command in `CommandSurfaceTest`.
5. Run `apps/cli` `composer test:affected` and `composer check`, then `composer docs-lint`.

## Must preserve

- ADR 0071: the CLI must name every command as one noun family followed by one verb.
- ADR 0071: the CLI must use add and remove for an association between things that exist independently, including a gateway profile in the CLI configuration.
- ADR 0071: the CLI must not keep an alias for a renamed command.
- Profile file format stays `{active_gateway, gateways}`; removal only deletes one entry and may clear the active selection.
- Removal uses `GatewayConfigLock` like `add` and `use`.
- JSON errors use the shared envelope with `code`, `message`, and `request_id`.
- Existing `gateway:add`, `gateway:use`, `gateway:trust`, and `gateway:status` tests keep passing.

## Open questions

none

## Deviations

none

## Review findings
