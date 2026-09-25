---
title: "ADR 0150: Keep extension commands local and confirm the proxycli fleet stop"
sidebarTitle: "0150 Keep extension commands local and confirm the proxycli fleet stop"
description: "Proposed. extension:enable and extension:disable change only the local CLI gate for every extension. proxycli:disable is the only CLI command that stops the proxycli fleet feature, and it requires a default-No confirmation or --yes."
---

# ADR 0150: Keep extension commands local and confirm the proxycli fleet stop

`extension:enable` and `extension:disable` change only the extension gate on the operator machine. They never call the Gateway, for any extension. `proxycli:disable` is the only CLI command that stops the proxycli fleet feature. It asks a default-No question that names the fleet effect, and noninteractive and JSON callers must pass `--yes`.

## Status

Proposed.

This amends the disable path in [ADR 0104](/decisions/0104-own-cliproxyapi-quota-through-the-proxycli-extension). The Gateway ownership of the fleet feature, the collector, the publication, and the token model stay.

## Context

ADR 0104 made `extension:disable proxycli` call the Gateway disable as well as hide the local commands. `extension:enable proxycli` only reveals the local commands. The two commands therefore had different reach, and the command description said only that it disables a CLI extension.

The Gateway disable stops the collector Process, withdraws the collector site, certificate, and DNS record, and deletes the stored CLIProxyAPI management key and the read and control tokens. Re-enabling needs the management key file again and gives CodexBar new tokens. An operator who ran `orbit extension:disable proxycli -n` to hide commands on one machine took ProxyCli down for every client. Nothing warned about the fleet effect, and `-n` did not stop it.

The [CLI standard](/reference/cli-ux#consent) requires a destructive command to state the target and the effect, default to No, and require explicit consent in noninteractive and machine modes.

## Decision

- `extension:enable` and `extension:disable` only write `$ORBIT_HOME/extensions.json`. They make no Gateway request for any extension, so enable and disable have the same reach.
- `proxycli:disable` is the CLI path that stops the fleet feature. It first asks a default-No question that names the fleet effect: the collector stops, `collector.cli-proxy-api.orbit` and its certificate are withdrawn, and the stored management key and tokens are deleted. `--yes` supplies consent without a prompt. Noninteractive and JSON calls without `--yes` fail with `input.confirmation_required` before any Gateway request. Declining or cancelling fails with `input.cancelled` and changes nothing.
- The Gateway `DELETE /api/v1/proxycli` contract does not change.

## Rejected alternatives

- Keep the coupling and add a confirmation to `extension:disable proxycli`: rejected because a command that manages the local gate would still stop the fleet. Operators toggle local gates without expecting fleet changes.
- Make `extension:enable proxycli` also enable the fleet feature: rejected because the fleet enable needs a Node, a cache connection, a CLIProxyAPI URL, and a management key file, which a local gate command does not take.
- Leave `proxycli:disable` without confirmation: rejected because it deletes stored credentials that the Gateway cannot recreate without the operator.

## Consequences

- Hiding the proxycli commands on one machine no longer affects the fleet or other clients.
- An operator who wants the fleet feature off runs `proxycli:disable` while the extension is enabled, then may disable the extension.
- Automation that relied on `extension:disable proxycli` to stop the collector must call `proxycli:disable --yes` instead.
- `proxycli:disable` scripts must add `--yes`.

## Affects

- Components: apps/cli, apps/docs
- ADRs: amends [ADR 0104](/decisions/0104-own-cliproxyapi-quota-through-the-proxycli-extension)
- Detail: [extension](/cli/extension), [proxycli](/cli/proxycli), [proxycli reference](/reference/proxycli)
- Verify: `apps/cli` tests `ProxycliExtensionTest` and `ProxyCliCommandsTest`; `composer docs-lint`
