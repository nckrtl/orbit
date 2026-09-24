---
title: "ADR 0142: Apply Plausible's ClickHouse configuration from the analytics role"
sidebarTitle: "0142 Apply Plausible's ClickHouse configuration"
description: "Proposed. The analytics role publishes Plausible's four ClickHouse configuration files on the ClickHouse Process's Node, mounts them read-only into that Process, and restarts ClickHouse when they change. Amends ADR 0096."
---

# ADR 0142: Apply Plausible's ClickHouse configuration from the analytics role

Each analytics role converge applies Plausible's own ClickHouse configuration to the ClickHouse Process that the role names. The role publishes Plausible's four files unchanged on that Process's Node, adds their read-only mounts to the Process, and restarts ClickHouse only when a file or mount changed. The operator still creates and owns the ClickHouse server.

## Status

Proposed.

## Context

[ADR 0096](/decisions/0096-run-plausible-through-an-analytics-role) keeps Plausible's events in an operator-created, Node-owned Docker ClickHouse Process on a `database` Node. It only documents a low-resource ClickHouse configuration. Nothing applies it.

In production, the ClickHouse Process ran with ClickHouse's defaults:

| Field | Value |
| --- | --- |
| Process | 82 |
| Image | `clickhouse/clickhouse-server:24.12-alpine` |
| Command | `-- --logger.level=warning` |
| Port | `10.44.0.3:8123:8123/tcp` |
| Data volume | `analytics-clickhouse` at `/var/lib/clickhouse` |

ClickHouse's own system log tables grew to about 950 MB. Background merges then failed in a loop with "memory limit exceeded" on a Node with 3.8 GB of memory. The loop used 120 to 150 percent CPU.

Plausible Community Edition ships four ClickHouse files for this case. Its `compose.yml` mounts them read-only into the ClickHouse container:

| File | Effect |
| --- | --- |
| `logs.xml` | Logs warnings to the console. Keeps `query_log` with a 30-day TTL and removes every other system log table. |
| `ipv4-only.xml` | Listens on `0.0.0.0`. |
| `low-resources.xml` | Limits the mark cache to 500 MiB. |
| `default-profile-low-resources-overrides.xml` | Runs the default profile with one thread, smaller blocks, and no parallel parsing or formatting. |

The operator decided to follow Plausible's setup exactly.

## Decision

- The Gateway ships Plausible's four files byte for byte from [plausible/community-edition](https://github.com/plausible/community-edition) at commit `ec6c4da77654`. To follow a newer Plausible release, a maintainer copies the files again.
- Each analytics role converge, on assignment and on `node:role:add NODE analytics --converge` with the same two storage Processes, applies the configuration before it runs Plausible. The step is `clickhouse-config`.
- The role writes the files on the Node that runs the ClickHouse Process, under `/etc/orbit/analytics/clickhouse/config.d` and `/etc/orbit/analytics/clickhouse/users.d`. Each file is root-owned with mode `0644`. The role writes a file only when its content differs, through a candidate file that it moves into place.
- The role adds each missing read-only mount to the Process's Docker volumes, at the container path that Plausible's `compose.yml` uses. It never removes or changes the Process's other volumes, ports, environment, command, or image. Another volume on one of those container paths stops the converge with `analytics.clickhouse_mount_conflict`.
- A new mount changes the Process specification. The existing Process runtime then replaces the container, which reads the files as it starts. Changed files alone restart the container through the same runtime. When nothing changed, ClickHouse keeps running. A stopped Process stays stopped and loads the files at its next start.
- A Process that a previous converge left `failed` is converged and restarted again on the next converge, so a failed restart does not leave ClickHouse on the old configuration.
- The Process keeps its ID, name, data volume, and credentials. Orbit still creates no ClickHouse database, user, or server. It owns only these four files and their mounts on the Process that the role names.
- Role removal leaves the files and mounts in place, because the ClickHouse Process and its data outlive the role.

This decision amends the consequence in ADR 0096 that the role only documents a low-resource configuration.

## Rejected alternatives

- Keep documenting the configuration: rejected because production ran without it, and the result was a merge loop that used the Node's CPU.
- Write a smaller Orbit configuration between ClickHouse's defaults and Plausible's: rejected because the operator chose Plausible's tested files, and an Orbit variant needs its own tuning and review on every Plausible release.
- Add a generic `process:update` command and let the operator add the mounts: rejected because a general Process edit is a larger decision, and the operator still has to write the files on the Node by hand.
- Mount the whole `config.d` directory: rejected because the ClickHouse image keeps its own files in `config.d` and `users.d`, and its entrypoint writes the user from the Process environment there. Single-file mounts keep those files.

## Consequences

- ClickHouse stops writing most of its own diagnostic tables. Only `query_log` remains, and it keeps 30 days. The metric, asynchronous metric, query thread, text, trace, session, and part logs are gone. Diagnosing ClickHouse itself then relies on its console log and `query_log`.
- Tables that ClickHouse wrote before the change keep their data. Orbit does not drop them.
- ClickHouse restarts once when an existing install first converges with this change, and again when a Plausible update changes a file. Plausible loses its ClickHouse connection during each restart.
- An operator who mounted their own file at one of the four container paths removes that mount before the role converges.
- Removing the role or the ClickHouse Process leaves the four files on the Node.

## Affects

- Components: apps/gateway, apps/docs
- ADRs: amends [ADR 0096](/decisions/0096-run-plausible-through-an-analytics-role)
- Detail: [Analytics role](/reference/analytics#clickhouse-configuration)
- Verify: `apps/gateway` Pest tests for the published file bytes, write-only-when-changed, mount merging, restart only on change, a stopped Process, and the `clickhouse-config` failure step; an Incus proof that converges the role on an existing ClickHouse Process and reads the four files inside the container
