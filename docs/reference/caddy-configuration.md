---
title: "Caddy configuration"
description: "How Orbit publishes Caddy fragments and certificates on a Node, and the one lock that keeps concurrent publishers from dropping each other's sites."
---

# Caddy configuration

Several Orbit features serve through the same Caddy process on a Node. Each feature owns one fragment. Orbit combines the fragments into a new version and switches Caddy to it atomically. One Node-wide lock makes these publications run one at a time.

## Fragments and versions

`/etc/caddy/Caddyfile` is a symlink into a version directory under `/etc/caddy/orbit-versions`. A version holds a `Caddyfile` with Orbit's global options and an `import` of its `fragments/*.caddy`.

| Fragment | Publisher |
| --- | --- |
| `app-dev.caddy` | App development sites and [Route](/reference/routes) ingress |
| `app-prod.caddy` | App production sites |
| `00-metrics-service.caddy` | [Service metrics](/reference/service-metrics) monitoring site |
| `herdr-<session>.caddy` | [Herdr session](/reference/herdr-sessions) observer |
| `metrics.caddy` | [Metrics](/reference/metrics) route on the Gateway |
| `websocket.caddy` | `websocket` role |
| `proxycli.caddy` | [ProxyCLI](/reference/proxycli) collector |
| `analytics.caddy` | [Analytics](/reference/analytics) role |
| `00-unmanaged.caddy` | A Caddyfile that Orbit found in place and preserved |

To publish, a publisher copies every other fragment from the live version into a candidate, writes its own fragment, and validates the candidate with `caddy validate`. It then switches the `Caddyfile` symlink and reloads Caddy. If the reload fails, it restores the previous `Caddyfile` and reloads again. Removal follows the same steps without the owned fragment.

The `websocket`, `proxycli`, `analytics`, and Metrics publishers also switch a certificate directory, such as `/etc/caddy/orbit-websocket-cert-current`, and reload Caddy.

## Publication lock

Every publisher holds `/run/lock/orbit/caddy.lock` from before it reads the live version until Caddy has reloaded. A second publisher waits up to 30 seconds for the lock and then fails without changing the Node. The fragment and certificate publishers above all use this lock.

Without one shared lock, two publishers could each copy the fragments they saw. The later switch would then drop the fragment that the earlier one had just published.

Before it takes the lock, a publisher checks the lock path:

| Path | Requirement |
| --- | --- |
| `/run/lock/orbit` | A real directory, not a symlink, owned by `root:root` with mode `0700`. Orbit creates it with that mode when it is missing. |
| `/run/lock/orbit/caddy.lock` | A regular file, not a symlink, owned by `root:root`. Orbit sets mode `0600` after it opens the file. |

A failed check stops the publication before any change. `/run/lock` is a tmpfs, so the lock and its directory are recreated after a reboot.

## Limits

The Gateway's own `gateway.caddy` fragment is published during Gateway convergence in several separate steps. That publication does not take the lock.
