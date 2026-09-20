---
title: "Cut over proxy-quota collectors"
description: "Move CLIProxyAPI quota collection from hand-rolled Processes to the Orbit proxycli extension without a second poller."
---

# Cut over proxy-quota collectors

This page tells an operator how to replace hand-rolled `proxy-quota-*` Processes and a `proxy-cli-usage.test` hostname with the Orbit `proxycli` extension so exactly one collector remains. [proxycli](/reference/proxycli) owns the extension. [ADR 0104](/decisions/0104-own-cliproxyapi-quota-through-the-proxycli-extension) records why Orbit does not keep both pollers.

## Before you start

Complete these four checks so enable has a cache, a Management API, and a place to copy history.

1. Place Valkey as a Node-targeted Docker Process on a Node with the `database` role.
2. Register that server as a Redis Database connection.
3. Confirm CLIProxyAPI still answers its Management API on the Node that will run the collector.
4. Copy any current snapshot you want to keep. The new collector writes the same Valkey key space `orbit:proxycli:*`.

## Cut over

Stop the old poller first, then enable the extension so only one collector remains.

1. Copy any existing snapshot keys you want to keep into `orbit:proxycli:raw` and `orbit:proxycli:snapshot` on the shared Valkey.
2. Stop the old scheduler and every `proxy-quota-*` Process. Do not start them again.
3. Remove or stop the unmanaged `proxy-cli-usage.test` Caddy site so it cannot poll.
4. Enable the extension and the fleet feature.

```bash
orbit extension:enable proxycli
orbit proxycli:enable --node=<cliproxy-node> --cache-connection=valkey --cliproxy-url=http://127.0.0.1:8317 --cliproxy-management-key-file=./management.key
```

5. Confirm `orbit proxycli:status` shows one enabled collector and a `collected_at` timestamp.
6. Point CodexBar at `https://proxycli.orbit` with the read token from Gateway settings. Do not give CodexBar the management key.
7. Refresh the Orbit Quota page twice. The collector lock must prevent a second upstream fetch.

## Prove one collector

Use these checks after enable. Each one must show a single collector and no extra upstream quota fetches.

| Check | Expected result |
| --- | --- |
| `orbit process:list --node=<cliproxy-node>` | One Process named `proxycli` is running. No `proxy-quota-*` Process is running. |
| Valkey `GET orbit:proxycli:lock` during a poll | One lock holder. |
| CLIProxyAPI Management API access log | Quota `api-call` traffic only from the collector interval, not from UI refresh or account toggle. |
| `https://proxycli.orbit/v1/quota-stats` | CodexBar-compatible JSON from the snapshot. |
| Account toggle in the web UI | CLIProxyAPI `PATCH /auth-files/status` then a cache recompile. No quota `api-call`. |

If two pollers appear, disable `proxycli`, stop the leftover Process, and enable again only after the old unit is gone.

## Related

- [proxycli](/reference/proxycli)
- [Database role](/reference/database-role)
- [Migrate an unmanaged Executor hostname](/solutions/migrate-unmanaged-executor-hostname)
