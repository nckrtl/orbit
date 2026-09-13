# ORB-292 development record

Flow: discovery
Incus: not required

## Acceptance

1. Removing a failed install (`status=failed`, `failed_operation=install`, `error_code=tool.version_probe_failed`, no recorded version) deletes the Gateway row without probing.
   - `apps/gateway/tests/Feature/Domain/RemoveToolActionTest.php` — deletes a failed version-probe install without probing the package
   - `apps/gateway/tests/Feature/Api/ToolsTest.php` — removes a failed version-probe install without probing
   - Result: passed (84 gateway acceptance tests in those files)

2. CLI accepts the Gateway `applied` success for that snapshot.
   - `apps/cli/tests/Feature/Tools/ToolActionCommandsTest.php` — renders a successful remove of a failed version-probe install
   - Result: passed (12 CLI tool-action tests)

3. Installed or otherwise proven tools still fail closed when the installed-version probe throws.
   - Same RemoveToolActionTest file: retains a failed removal when the installed probe fails; still probes a failed install that recorded a version; retains a bounded failure when the initial installed probe fails
   - Result: passed

## Checks

- `apps/gateway` `composer check`: passed (guidance, rector, pint, phpstan)
- `apps/cli` `composer check`: passed
- `composer docs-lint`: passed
- Cold TIA full gateway suite failed on missing host tools (WireGuard, getfacl/setfattr, Caddy). Those failures are unrelated to tool removal.

## Documentation

- `docs/reference/tools.md`: removal now deletes a failed install that never recorded a version without probing
