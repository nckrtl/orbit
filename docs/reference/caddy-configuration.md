---
title: "Caddy configuration"
description: "How Orbit owns a Node's Caddyfile, adopts an existing one, and refuses carried global options."
---

# Caddy configuration

Orbit owns `/etc/caddy/Caddyfile` on a Node that serves sites through Caddy. This page tells an operator how Orbit publishes that file, what happens to a Caddyfile that existed before Orbit, and how to fix a Node whose publication fails on global options. [ADR 0137](/decisions/0137-refuse-carried-caddy-global-options) records the global options rule.

## Published layout

Each publication writes a new version under `/etc/caddy/orbit-versions/<version>` and then points `/etc/caddy/Caddyfile` at that version's `Caddyfile`. The version's `Caddyfile` has two parts:

```caddy
{
    auto_https disable_certs
}
import /etc/caddy/orbit-versions/<version>/fragments/*.caddy
```

The global options block is Orbit's. `auto_https disable_certs` keeps Caddy's HTTP-to-HTTPS redirects but stops Caddy from obtaining certificates on its own; each site serves the certificate Orbit publishes for it.

Every role keeps its sites in its own fragment, such as `app-dev.caddy` or `metrics.caddy`. A publisher replaces only its own fragment and copies every other fragment into the new version. It runs `caddy validate` on the candidate before it switches the symlink, and it restores the previous file or symlink when Caddy fails to reload.

## Adopted Caddyfile

On the first publication, a Node's `/etc/caddy/Caddyfile` can be a regular file. When that file is the unmodified package default, Orbit replaces it. Otherwise Orbit keeps it as `fragments/00-unmanaged.caddy` and carries it into every later version. A legacy `fragments/unmanaged.caddy` becomes `00-unmanaged.caddy` on the next publication.

## Carried global options

Caddy accepts one global options block, and only as the first block. Orbit writes that block, so a carried fragment must not start with its own. Before validation, each publisher checks every carried fragment. When one starts with a block that has no site address, the publisher stops, leaves the live Caddyfile and Caddy unchanged, and fails with its usual error code:

| Publisher | Error code |
| --- | --- |
| `app-dev` sites, including public Ingress sites | `app-dev.caddy_config_failed` |
| `app-prod` sites | `app-prod.caddy_config_failed` |
| `websocket` site | `websocket.caddy_publication_failed` |
| `analytics` site | `analytics.caddy_publication_failed` |
| Herdr observer site | `herdr.observer_failed` |
| `proxycli` site | `proxycli.caddy_publication_failed` |
| Metrics site on the Gateway | `metrics.caddy_publication_failed` |

For every publisher except Metrics and `proxycli`, the activity record keeps the command output. It names the fragment, the options in the block, and the file to edit:

```text
Caddy fragment 00-unmanaged.caddy opens its own global options block (local_certs, email). Orbit writes the only global options block. Remove that block from /etc/caddy/Caddyfile, then publish again.
```

On first adoption the file is the Node's own `/etc/caddy/Caddyfile`. When Orbit already carries the fragment, the file is that fragment in the live version, under `/etc/caddy/orbit-versions/<version>/fragments/`. Remove the whole block, keep the site blocks, and repeat the command that failed. Orbit does not support operator global options; it never merges, strips, or rewrites them.

`orbit proxycli:disable` does not check its Caddy removal. When the removal is refused, the command still succeeds and the `proxycli` site stays in the live version until a later removal succeeds.

A site block, a snippet such as `(common) {`, and an address that starts with an environment placeholder such as `{$SITE} {` are not global blocks.
