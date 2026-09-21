---
title: "ADR 0108: Persist managed environment on systemd Processes"
sidebarTitle: "0108 Persist managed environment on systemd Processes"
description: "Proposed. A systemd Process may persist a managed environment map. The renderer projects it as Environment= directives so Gateway-owned Processes such as proxycli receive their secrets on the unit."
---

# ADR 0108: Persist managed environment on systemd Processes

Orbit persists a non-empty environment map on a systemd Process specification and projects those values as `Environment=` directives. Derived development-server, certificate, and Agentation keys still win. Values never enter `ExecStart` argv. HTTP `process:create` still accepts environment only for Docker.

## Status

Proposed.

This decision extends [ADR 0069](/decisions/0069-allow-node-process-targets) and [ADR 0104](/decisions/0104-own-cliproxyapi-quota-through-the-proxycli-extension). It does not change Docker environment handling.

## Context

`AddProcessData` already carries an environment map. Docker stores that map and streams it through a mode-0600 env file at start. systemd stored only `command`, `environment_file`, and an optional preset. The renderer then projected Instance development-server and certificate values, not the map the caller supplied.

[ADR 0104](/decisions/0104-own-cliproxyapi-quota-through-the-proxycli-extension) deploys the `proxycli` collector as a Node systemd Process. Enable supplies `PROXYCLI_*` values, including the CLIProxyAPI management key, CodexBar tokens, and Valkey coordinates. Dropping that map meant the collector started without credentials. A Node Process also has no App instance `.env` file, so an environment file path cannot carry those values.

systemd units require an absolute executable. They do not search an operator `PATH`. The collector command must therefore be `/usr/bin/python3`, not `python3`.

Secrets must not enter local or remote argv. Projecting the map through `/usr/bin/env` on `ExecStart` would violate that rule. `Environment=` directives keep values out of argv. API responses already redact environment values.

## Decision

- `ProcessSpecification` stores a non-empty `environment` string map on systemd `runtime_config` and canonicalizes key order the same way Docker does.
- `SystemdProcessRenderer` writes each stored pair as an `Environment=` directive after the optional `EnvironmentFile` and before derived Instance projection.
- Derived keys still win: `PATH`, `NODE_USE_SYSTEM_CA`, `VITE_DEV_SERVER_*`, `ORBIT_DEV_SERVER_*`, `AGENTATION_URL`, and `ORBIT_AGENTATION_PORT`. Stored values for those names are omitted.
- Stored values never appear on `ExecStart`.
- HTTP Process create still rejects `environment` unless `runtime` is `docker`. Gateway-owned enable paths such as `proxycli:enable` persist the map through `AddProcessData`.
- The `proxycli` collector command is `/usr/bin/python3` plus the installed `server.py`.

## Rejected alternatives

- Keep systemd environment only on an App instance `.env` file: rejected because a Node Process has no App instance environment file, and `proxycli` would still drop `PROXYCLI_*`.
- Prefix `ExecStart` with `/usr/bin/env KEY=value`: rejected because secret bytes must not enter argv.
- Open HTTP `process:create` environment for systemd: rejected for this change because operators do not need a new create flag for `proxycli`, and Docker remains the public environment map runtime.
- Write a separate mode-0600 EnvironmentFile during converge: rejected as a larger runtime-manager change. `Environment=` on the Orbit-owned unit is enough for the collector to start. A later change may move secrets into a protected file without changing the specification contract.

## Consequences

- Enable can persist `PROXYCLI_*` on the collector Process and the unit receives them after converge.
- Changing the stored map is a specification change. An identical add with different values returns `process.name_taken`. `proxycli` enable already replaces that Process.
- Unit files under `/etc/systemd/system` may contain those values. API list and show responses continue to redact them.
- Operators still cannot pass `--environment` to a systemd `process:create`.

## Affects

- Components: apps/gateway, apps/docs
- ADRs: extends [ADR 0069](/decisions/0069-allow-node-process-targets) and [ADR 0104](/decisions/0104-own-cliproxyapi-quota-through-the-proxycli-extension)
- Detail: [App processes and schedules](/reference/app-processes-and-schedules), [proxycli](/reference/proxycli)
- Verify: Gateway Process specification, systemd renderer, and proxycli enable tests; collector Valkey read test; `composer docs-lint`
