---
title: "ADR 0145: Publish the proxycli collector on collector.cli-proxy-api.orbit"
sidebarTitle: "0145 Publish the proxycli collector on collector.cli-proxy-api.orbit"
description: "Proposed. The proxycli extension publishes its collector on collector.cli-proxy-api.orbit, under the CLIProxyAPI management name. Enable takes over a custom proxy Route with that name when it points at the collector on the collector Node, in one Caddy reload, and refuses any other Route on that name."
---

# ADR 0145: Publish the proxycli collector on collector.cli-proxy-api.orbit

The Gateway publishes the proxycli collector on `collector.cli-proxy-api.orbit` instead of `collector.proxycli.orbit`. The name sits under `cli-proxy-api.orbit`, where operators publish CLIProxyAPI management. When enable finds a custom proxy Route on the new name that already points at the collector on the collector Node, it takes that Route over in one Caddy reload and then removes it. It refuses every other Route on that name.

## Status

Proposed.

This amends the collector hostname in [ADR 0109](/decisions/0109-publish-the-proxycli-collector-on-a-subdomain). The subdomain split, the reserved name, the extension boundary from [ADR 0104](/decisions/0104-own-cliproxyapi-quota-through-the-proxycli-extension), and the token model stay.

## Context

ADR 0109 put the collector on `collector.proxycli.orbit` and left the apex free for management. Operators then named the management server `cli-proxy-api` and published it as a custom proxy Route on `cli-proxy-api.orbit`. The collector name no longer shares a parent with the service it reports on.

On the production collector Node, the extension's Caddy site and the Node's Route sites chose their listeners separately and conflicted. The operator retired `collector.proxycli.orbit` by hand and published the collector as a custom proxy Route on `collector.cli-proxy-api.orbit` to the loopback collector. CodexBar now reads that name. [ADR 0141](/decisions/0141-build-each-node-caddyfile-on-the-gateway) fixes the bind conflict: a Node without `ingress` binds Route sites to its WireGuard and LAN addresses.

The hand-made Route must go before the Node Caddy build takes over, because a build refuses two sites on one address. It must go without a gap: CodexBar and `proxycli:status` users read the collector all the time. Until the build cutover, the Route's site and the extension's site live in different fragments with different publishers, and Caddy refuses two sites for one name on one listener. Deleting the Route first and then publishing the extension's site leaves the name unserved between two reloads.

## Decision

- `ProxyCliHostname` is `collector.cli-proxy-api.orbit`. The Gateway reserves it, issues the Orbit CA leaf for it, renders the collector Caddy site for it, publishes its private DNS `host-record`, and returns it as `hostname`. Account control sends it as the `Host` header.
- `collector.proxycli.orbit` stops being reserved or published. Apex `cli-proxy-api.orbit` stays free for the management Route.
- Enable checks for a Route on the collector name before it changes anything. A custom proxy Route whose Node is the collector Node, whose upstream is `http://127.0.0.1:8787` (the collector port), and which has no Process target is a takeover candidate. Any other Route on that name fails enable with `proxycli.hostname_taken` (409) and changes nothing.
- A takeover holds the development projection lock. It stores the Route as `retiring` with no published sites, renders the Node's route fragment without it, and publishes that fragment and the collector fragment in one Caddy validation and reload. When that publication fails, it restores the Route and Caddy keeps the previous version. After the reload it runs the normal Route removal, which removes the Route's certificate and record.
- Enable publishes the collector site before it converges the collector Process, which restarts it. The collector site retries its loopback upstream for up to 5 seconds, so a request that arrives during that restart waits instead of failing. The Route's site has no such retry, so it must be gone before the restart.
- Private DNS for the name never disappears. The Route and the extension publish the same `host-record` for the collector Node's WireGuard address, and the renderer deduplicates it.
- The rollout for an existing Route is one command: `orbit proxycli:enable` with the current settings.

## Rejected alternatives

- Refuse every Route on the name and ask the operator to destroy it first: rejected because `route:destroy` and `proxycli:enable` reload Caddy separately, which leaves the name unserved in between.
- Delete any Route on the name: rejected because a Route that points elsewhere is operator intent that enable cannot judge. Removing it breaks the service it serves.
- Wait for the Node Caddy build cutover, where one build swaps the sites: rejected because the build refuses the duplicate address, so the Route must be gone before the cutover.
- Keep `collector.proxycli.orbit` as a second name: rejected because it keeps a name nothing reads and a second certificate and DNS record to own.

## Consequences

- The collector name matches the management name. CodexBar and account control use `collector.cli-proxy-api.orbit`.
- A client still configured for `collector.proxycli.orbit` stops resolving it after the next private DNS publication. Production retired that name on 2026-09-22.
- Enable can remove one Route. The match is strict, so it only removes a Route that serves the same name, on the same Node, to the same port.
- The takeover writes the route fragment from the ProxyCli publisher. The Node Caddy build cutover replaces both publishers with one build and keeps the same checks on stored state.

## Affects

- Components: apps/cli, apps/gateway, packages/php-sdk, apps/docs
- ADRs: amends [ADR 0109](/decisions/0109-publish-the-proxycli-collector-on-a-subdomain)
- Detail: [proxycli](/reference/proxycli#collector-hostname)
- Verify: Gateway reserved hostname, DNS, Caddy site, publication manager, and enable tests; CLI and SDK status tests; Incus proof of the takeover while sampling the collector by name
