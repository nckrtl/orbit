---
title: "ADR 0159: Set a stale Caddyfile aside when Caddy is absent"
sidebarTitle: "0159 Set a stale Caddyfile aside when Caddy is absent"
description: "Proposed. A Node Caddy build that skips because Caddy is absent moves a live Caddyfile that is not the render into the backup directory, so a start of Caddy after that move cannot load a removed certificate. Amends ADR 0141."
---

# ADR 0159: Set a stale Caddyfile aside when Caddy is absent

When a Node Caddy build skips because `/usr/bin/caddy` is absent and the `caddy` service is not running, and the live Caddyfile is not the file the build rendered, the build moves that live path into `/etc/caddy/orbit-backups/`. `systemctl start caddy` after that move has no live file that names a removed certificate. The next build that can validate writes a fresh file.

## Status

Proposed. Amends [ADR 0141](/decisions/0141-build-each-node-caddyfile-on-the-gateway).

## Context

[ADR 0141](/decisions/0141-build-each-node-caddyfile-on-the-gateway) skips a build on a Node that has no `/usr/bin/caddy` and no running `caddy` service when the render has no site, or when no Caddy role on the Node is active or converging. The build reports `unchanged` and leaves every file where it is. A running service is not skipped: it keeps the configuration it already loaded, so a missing binary alone does not skip the build.

Removal commits the withdrawal, requests that build, and then deletes the certificate the withdrawn site used. On the skip, the live `/etc/caddy/Caddyfile` can name the withdrawn site. `systemctl start caddy` after the skip, including a start from installing the Caddy package, loads that file and fails before it serves:

```text
open /etc/caddy/orbit-websocket-cert-current/reverb.pem: no such file or directory
```

The websocket role's removal on a Node whose Caddy package was already gone produced that failure. The certificate step had deleted `reverb.pem` after the skipped build left the site in the live file.

An unchanged render on a Node where Caddy is installed still writes nothing and does not reload. This decision does not change that path. [Caddy configuration](/reference/caddy-configuration#when-caddy-is-absent) states the operator-facing result.

## Decision

- The skip stays a success and reports `unchanged`. It does not validate, reload, or start Caddy. It runs only when `/usr/bin/caddy` is absent, the `caddy` service is not active, and either the render has no site or no Caddy role (`gateway`, `router`, `ingress`, `app-dev`, `app-prod`, `websocket`, `analytics`) is active or converging.
- When `/etc/caddy/Caddyfile` exists on that skip and the bytes it loads are not the rendered file, the build moves the live path into `/etc/caddy/orbit-backups/<UTC timestamp>/`. A symlink moves as a symlink. A regular file moves as a file. The timestamp names are the ones a replaced configuration already uses. The live path is then absent. The build does not delete the backup or the version directory the symlink names.
- A live file whose bytes are the render stays in place. A missing live path stays missing.
- When the move cannot be completed, the build fails at stage `release`, leaves the live path in place, and does not report `unchanged`.
- The next build that does not take the skip writes a new version and a new live path, and validates that file before the path points at it.

## Rejected alternatives

- Leave the live file in place: rejected because `systemctl start caddy` then fails on the missing certificate, and the Caddy package keeps an existing Caddyfile when it is installed.
- Write the rendered file as the live file during the skip: rejected because the build cannot run `caddy validate` without `/usr/bin/caddy`, and the start that follows loads an unvalidated file.
- Delete the live file with no backup: rejected because the file can be the only copy of a hand-placed configuration or an earlier Orbit version.
- Fail the removal until Caddy is installed: rejected because the skip exists so a removal can finish on a Node that has no Caddy, and the next convergence of a Caddy role installs Caddy.

## Consequences

- A skipped build can change the Node: the live path moves into the backup directory. Callers treat `unchanged` as success, so certificate removal after the build proceeds.
- A failed move blocks that caller at the build. The certificate stays until a retry moves the file.
- The backup is kept until an operator removes it, as with every other file in `orbit-backups`.
- An operator who starts Caddy before the next publishing build has no Orbit live file to load. Doctor is unchanged: a missing live file reads as empty, so a check that compares the Node reports `role.caddy_build_drift` with `observed` `not_built` when the render is buildable.

## Affects

- Components: apps/gateway, apps/docs
- ADRs: amends [ADR 0141](/decisions/0141-build-each-node-caddyfile-on-the-gateway)
- Detail: [Caddy configuration](/reference/caddy-configuration#when-caddy-is-absent)
- Verify: `apps/gateway` push-script tests for the absent-Caddy skip, covering a live file that names a removed certificate, a live file that already matches the render, a missing live file, and a move that cannot be completed
