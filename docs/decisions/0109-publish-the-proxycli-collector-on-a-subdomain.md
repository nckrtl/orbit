---
title: "ADR 0109: Publish the proxycli collector on a subdomain"
sidebarTitle: "0109 Publish the proxycli collector on a subdomain"
description: "Proposed. The proxycli extension publishes the quota collector on collector.proxycli.orbit and leaves apex proxycli.orbit free for a CLIProxyAPI management Route."
---

# ADR 0109: Publish the proxycli collector on a subdomain

The Gateway publishes the Orbit quota collector on `collector.proxycli.orbit`. Apex `proxycli.orbit` stays free so an operator can create a custom proxy Route to the CLIProxyAPI management UI and API on loopback port 8317. Enable and converge keep that split.

## Status

Proposed.

This amends the reserved hostname in [ADR 0104](/decisions/0104-own-cliproxyapi-quota-through-the-proxycli-extension). The extension boundary, Valkey cache, collector Process, and token model stay.

## Context

[ADR 0104](/decisions/0104-own-cliproxyapi-quota-through-the-proxycli-extension) reserved `proxycli.orbit` for the collector. Enable issued an Orbit CA leaf, rendered a Caddy site that reverse-proxied that name to the loopback collector, and published a private DNS `host-record`. A Route cannot own a reserved name.

Operators already run CLIProxyAPI management on the same Node at `:8317`. They need the apex name for that UI and API. Live Caddy already splits the names: apex to management, `collector.proxycli.orbit` to the Orbit collector. The next `proxycli:enable` rewrote the Orbit-managed fragment back to apex and broke management.

The collector still needs a reserved name. An operator Route must not take or destroy the CodexBar endpoint independently of the extension lifecycle. Apex is a different service.

## Decision

- `ProxyCliHostname` and enable publish `collector.proxycli.orbit`. The Gateway reserves that name, issues the Orbit CA leaf for it, renders the Caddy site for it, and publishes the private DNS `host-record` for it.
- A Route cannot own `collector.proxycli.orbit`.
- Enable and converge do not publish, reserve, or reclaim `proxycli.orbit`. A second enable keeps the collector on `collector.proxycli.orbit`.
- Operators publish CLIProxyAPI management as a custom proxy Route on `proxycli.orbit` to a loopback upstream such as `http://127.0.0.1:8317`.
- CodexBar reads `https://collector.proxycli.orbit/v1/quota-stats`. Account control uses the same collector hostname and the control token.

## Rejected alternatives

- Keep reserving apex and add a second collector hostname: rejected because enable then owns apex and a Route cannot publish management there.
- Publish both names from the extension: rejected because the Orbit-managed fragment would still claim apex and overwrite a management Route or live Caddy override.
- Publish the collector as a custom proxy Route: rejected in ADR 0104; an operator who creates or destroys that Route takes or removes the collector independently of the extension lifecycle.

## Consequences

- Re-enable matches the live hostname split and leaves apex Caddy to the management Route.
- CodexBar and cutover docs use `collector.proxycli.orbit`.
- Operators create the apex Route themselves. Orbit does not create it during enable.

## Affects

- Components: apps/cli, apps/gateway, packages/php-sdk, apps/docs
- ADRs: amends [ADR 0104](/decisions/0104-own-cliproxyapi-quota-through-the-proxycli-extension)
- Detail: [proxycli](/reference/proxycli)
- Verify: Gateway reserved hostname, custom proxy Route, collector publication, and enable tests; `composer docs-lint`
