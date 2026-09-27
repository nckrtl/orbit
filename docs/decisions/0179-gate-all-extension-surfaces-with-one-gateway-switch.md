---
title: "ADR 0179: Gate every extension surface with one Gateway switch"
sidebarTitle: "0179 Gate every extension surface with one Gateway switch"
description: "Proposed. Each extension has one Gateway-owned switch that controls its CLI commands, MCP tools, API routes, and web navigation, with no local extension state or per-extension enable commands."
---

# ADR 0179: Gate every extension surface with one Gateway switch

The Gateway owns exactly one enabled switch for each extension. A disabled extension is absent from every client surface, and enabling it reveals the same extension on every client.

## Status

Proposed.

This supersedes [ADR 0150](/decisions/0150-keep-extension-commands-local-and-confirm-the-proxycli-fleet-stop) and amends the extension boundary in [ADR 0103](/decisions/0103-absorb-commander-tasks-as-a-gateway-extension) and [ADR 0104](/decisions/0104-own-cliproxyapi-quota-through-the-proxycli-extension). It removes the local-only extension model and the `tasks:enable` and `tasks:disable` switch commands.

## Context

The whole idea of extensions is to keep the product minimal and extend it by what the operator needs. That requires one lean control plane: one way to enable an extension, one name for it, and no legacy path that can make one client disagree with another.

Today, tasks uses a Gateway setting and guards actions with `RequireTasksExtensionAction`, while the CLI's `extension:*` commands write `$ORBIT_HOME/extensions.json` through `LocalExtensionState`. The two controls are independent. The proxycli API routes and MCP catalogue are exposed regardless of the local file, and the web navigation can therefore disagree with the Gateway and with another client.

An extension must be a product boundary, not only a CLI convenience. Its commands, MCP tools, API routes, and web navigation must have the same enabled set. The Gateway also needs a durable source of truth that survives a new CLI, a new browser, or a second operator machine.

## Decision

- Each extension has one Gateway-owned switch. The initial extensions are `tasks` and `proxycli`. The switch is changed with `orbit extension:enable <name>`, `orbit extension:disable <name>`, and inspected with `orbit extension:list`. These commands are the only extension switch commands.
- Store one settings row for each extension in the Gateway scope under the key `extension.<name>.enabled`, with value `1` when enabled and no row, or an equivalent false value, when disabled. The row is not per operator, Node, browser, or machine. The Gateway is authoritative for the enabled set.
- The CLI asks the selected Gateway for the enabled set through one small extension-state response during command bootstrap. It uses that response for command registration, `orbit list`, help, and dispatch for the lifetime of the process, so listing does not make one request per command. Discovery uses an explicit 2-second total deadline shared by the request and its single connection retry, independent of the 900-second timeout for operational reads; it never loops or blocks core CLI recovery. If no profile is active, the CA is untrusted, the Gateway is unreachable, or the 2-second deadline expires, the CLI keeps the static core commands and core help available, reports extension state as `unknown`, and leaves extension commands hidden or refuses a direct extension invocation with `extension.state_unknown`, including `gateway.profile_missing`, `gateway.unreachable`, or the instruction to run `gateway:trust`, as the failed condition requires. It does not persist a client-owned enabled set. A command invocation that reaches the Gateway is checked again there, so a stale client cannot use a newly disabled extension.
- A confirmed disabled extension is invisible in CLI command listing and help, and a direct invocation fails with `extension.disabled`. `unknown` is never rendered as `disabled`. The core `gateway:add`, `gateway:use`, `gateway:remove`, and `gateway:trust` commands, along with core help, always remain available so an operator can establish, select, repair, or remove the profile needed for discovery. The CLI does not read or write `$ORBIT_HOME/extensions.json`; any existing file is ignored.
- API routes remain registered so the OpenAPI contract and SDK surface stay stable, and the mapping from routes to commands does not change. Extension routes are guarded before their action runs. A disabled extension returns HTTP 409 with the single error code `extension.disabled`. This gives clients a predictable refusal instead of a route-dependent 404 and keeps route generation static.
- Web navigation and extension panels read the same Gateway enabled set and omit disabled extensions. The web client must not show a disabled link and then discover the refusal only after navigation.
- The Gateway tags every generated MCP tool with its extension slug. The OpenAPI operation carries the tag and `bin/mcp-tools` writes it into `resources/mcp/tools.json`; untagged operations are core tools. Both the normal MCP server and `OrbitSearchServer` filter tagged tools before `tools/list`, `search_tools`, and `/mcp/search` responses. A direct `tools/call`, or a stale call inside `execute_tools`, is checked against that metadata before lookup and returns an MCP tool error with `isError: true` and content containing `status: 409` and `error.code: extension.disabled`; it does not become a generic not-found error or execute the route. HTTP 409 remains the API transport response only.
- The tasks family keeps `tasks:status` as a core assistance and status view with another purpose: it reports whether the extension is on and what needs operator attention, even when tasks is off. It is not tagged as a tasks extension tool. All other tasks commands and tools are gated. `tasks:enable` and `tasks:disable` are removed; an operator turns the extension on or off with `extension:enable tasks` or `extension:disable tasks`.
- The proxycli family keeps `proxycli:status`, reads, and account operations as ordinary extension commands. Its setup command is `proxycli:setup --node=… --cache-connection=… --cliproxy-url=… --cliproxy-management-key-file=…`; its teardown command is `proxycli:teardown [--yes]`. These commands configure or remove the fleet feature after the `proxycli` extension is on. They are not extension switches, and neither changes web visibility. While the switch is enabled but setup is absent, web navigation shows an unconfigured Quota state; disabling the switch hides that navigation. The names follow the existing setup and teardown vocabulary rather than overloading `enable` and `disable` with two meanings.
- The migration reads today's live state before removing the old controls. A Gateway with the tasks setting enabled creates `extension.tasks.enabled = 1`. A Gateway with a configured and enabled proxycli collector creates `extension.proxycli.enabled = 1`; an existing proxycli setup remains running. A configured but stopped proxycli feature remains disabled and keeps its setup data. Existing local `extensions.json` entries do not enable anything, so every client learns the Gateway state. The migration records the result before deleting the per-family switch paths. Any existing local `extensions.json` file remains ignored.

## Rejected alternatives

- Keep one local switch per operator: rejected because a local file cannot hide Gateway routes, MCP tools, or web navigation for every client, and two operators can see different products.
- Make `extension:enable` configure proxycli: rejected because setup needs a Node, cache connection, upstream URL, and management key, while the extension switch must have one small, uniform contract for tasks and proxycli alike.
- Keep `tasks:enable` and `tasks:disable` as aliases: rejected because one extension must have one switch name and the old paths preserve the split this decision removes.
- Unregister API routes while an extension is disabled: rejected because route removal makes OpenAPI, SDK, activity naming, and MCP generation vary with runtime state. Registered routes with a shared 409 refusal keep the contract stable and fail explicitly.
- Filter MCP tools only in `tools/list`: rejected because `/mcp/search` and direct execution would disclose or execute tools that the main catalogue hides. Filtering must happen before both catalogue shapes and execution.
- Cache the enabled set in `$ORBIT_HOME`: rejected because a cache becomes a second source of truth and can show a disabled extension after the Gateway changed state.

## Consequences

- One Gateway switch makes the extension set consistent across CLI clients, MCP clients, API callers, and the web application.
- The CLI needs one bounded lightweight Gateway request during bootstrap, but command registration remains fast because one response serves the whole process. The explicit 2-second total deadline prevents a silent Gateway from holding up command listing for the 900-second operational timeout. A missing or unhealthy Gateway does not strand profile repair: core commands and help stay local, and unknown state is distinct from a confirmed disabled extension.
- API and SDK schemas remain stable while disabled calls fail with one documented code, `extension.disabled`, at HTTP 409.
- MCP generation gains extension metadata and a runtime filter. A generated tool cannot be added without declaring whether it is core or belongs to an extension.
- Operators must enable `proxycli` before running `proxycli:setup`, and must use `proxycli:teardown` when removing its fleet resources. Disabling the extension hides and refuses the feature but does not silently tear down configured fleet resources.
- Existing tasks and proxycli state is preserved by migration, while local-only extension state and the legacy tasks switch commands disappear with no compatibility path.

## Affects

- Components: apps/cli, apps/docs, apps/gateway, apps/web, packages/php-sdk
- ADRs: supersedes [ADR 0150](/decisions/0150-keep-extension-commands-local-and-confirm-the-proxycli-fleet-stop); amends [ADR 0103](/decisions/0103-absorb-commander-tasks-as-a-gateway-extension) and [ADR 0104](/decisions/0104-own-cliproxyapi-quota-through-the-proxycli-extension)
- Detail: [extension CLI](/cli/extension), [tasks reference](/reference/tasks), [proxycli reference](/reference/proxycli), and [MCP server](/reference/mcp)
- Verify: `composer docs-lint`; the Gateway extension, API refusal, MCP filtering, migration, web navigation, and CLI command-surface checks
