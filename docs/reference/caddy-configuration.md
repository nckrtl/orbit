---
title: "Caddy configuration"
description: "How Orbit owns a Node's Caddyfile, adopts an existing one, refuses carried global options, and runs publishers one at a time under one lock."
---

# Caddy configuration

Orbit owns `/etc/caddy/Caddyfile` on a Node that serves sites through Caddy. This page tells an operator how Orbit publishes that file, how publishers avoid dropping each other's sites, what happens to a Caddyfile that existed before Orbit, and how to fix a Node whose publication fails on global options. [ADR 0137](/decisions/0137-refuse-carried-caddy-global-options) records the global options rule.

## Published layout

Each publication writes a new version under `/etc/caddy/orbit-versions/<version>` and then points `/etc/caddy/Caddyfile` at that version's `Caddyfile`. The version's `Caddyfile` has two parts:

```caddy
{
    auto_https disable_certs
    metrics {
        per_host
    }
}
import /etc/caddy/orbit-versions/<version>/fragments/*.caddy
```

The global options block is Orbit's, and it is the same on every Node.

`auto_https disable_certs` keeps Caddy's HTTP-to-HTTPS redirects but stops Caddy from obtaining certificates on its own. A private site serves the Orbit CA certificate Orbit publishes for it. A public Ingress site opts back in, as [public Ingress certificates](#public-ingress-certificates) describes.

`metrics { per_host }` makes Caddy count requests, errors, and durations per hostname. On the Node, `curl http://localhost:2019/metrics` shows them. Prometheus scrapes them only on Ingress, as [service metrics](/reference/service-metrics#caddy-traffic) describes. [ADR 0139](/decisions/0139-collect-caddy-http-metrics-on-every-node) records this choice and its cost.

Every role keeps its sites in its own fragment, such as `app-dev.caddy` or `metrics.caddy`. A publisher replaces only its own fragment and copies every other fragment into the new version. It runs `caddy validate` on the candidate before it switches the symlink, and it restores the previous file or symlink when Caddy fails to reload.

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
| `00-unmanaged.caddy` | An [adopted Caddyfile](#adopted-caddyfile) |

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

The Gateway's own `gateway.caddy` fragment is published during Gateway convergence in several separate steps. That publication does not take the lock.

## Public Ingress certificates

A public Ingress site gets its certificate from Let's Encrypt, not from Orbit. Its site block carries `tls force_automate`:

```caddy
shop.example.com {
    bind 0.0.0.0
    tls force_automate
    reverse_proxy https://10.0.0.20 {
        # Router forwarding settings
    }
}
```

`force_automate` makes Caddy manage the certificate for that hostname even though the global block disables certificate management. Caddy uses its default issuers, Let's Encrypt first, and renews the certificate on its own. Let's Encrypt validates the hostname on port 80 or 443. The hostname's public DNS must point at the Ingress Node. Orbit opens both ports on the Ingress firewall while the Cluster has a live public Route.

Private sites never carry `force_automate`, so Caddy never asks a public CA for a private hostname, even when a pinned certificate does not match its site. `force_automate` needs Caddy 2.9.0 or newer, which is the [release floor](/reference/node-provisioning#package-sources). [ADR 0138](/decisions/0138-opt-public-ingress-sites-into-caddy-certificate-automation) records this rule.

[`orbit doctor`](/cli/doctor) reports `instance.public_tls_mismatch` when the live public site pins an Orbit CA leaf, or when its block lacks `tls force_automate` while the live Caddyfile disables certificate management. Converge the public Route to publish the site again.

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
| ProxyCli collector site | `proxycli.caddy_publication_failed` |
| Herdr observer site | `herdr.observer_failed` |
| Metrics site on the Gateway | `metrics.caddy_publication_failed` |

Role convergence, such as `orbit node:role:add NODE app-dev --converge`, fails with `node_role.convergence_failed`; `orbit node:role:list` shows the publisher's code as the underlying error.

The refusal message names the fragment, the options in the block, and the file to edit:

```text
Caddy fragment 00-unmanaged.caddy opens its own global options block (local_certs, email). Orbit writes the only global options block. Remove that block from /etc/caddy/Caddyfile, then publish again.
```

The activity record keeps that message when a Route or Instance command fails on an `app-dev` or `app-prod` site publication. Role convergence and the other publishers record only the error code, because they do not keep command output. After one of those codes, check the start of the adopted `/etc/caddy/Caddyfile` and of every fragment that Orbit did not write in the live version.

On first adoption the file to edit is the Node's own `/etc/caddy/Caddyfile`. When Orbit already carries the fragment, it is that fragment in the live version, under `/etc/caddy/orbit-versions/<version>/fragments/`. Remove the whole block, keep the site blocks, and repeat the command that failed. Orbit does not support operator global options; it never merges, strips, or rewrites them.

A site block, a snippet such as `(common) {`, and an address that starts with an environment placeholder such as `{$SITE} {` are not global blocks.
