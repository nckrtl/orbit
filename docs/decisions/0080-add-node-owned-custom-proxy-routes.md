---
title: "ADR 0080: Add node-owned custom proxy Routes"
sidebarTitle: "0080 Add node-owned custom proxy Routes"
description: "Proposed. Add a Node-owned custom proxy Route kind for arbitrary unique hostnames that reverse-proxy to a local service."
---

# ADR 0080: Add node-owned custom proxy Routes

Orbit stores a second Route kind for Node-local services. A custom proxy Route owns an exact private hostname, answers Gateway DNS with the serving Node, and reverse-proxies from that Node's Caddy to a loopback or Node Process listener. App instance Routes keep their current ownership, targets, and Cluster membership rules.

## Status

Proposed.

This proposal extends [ADR 0009](/decisions/0009-clustered-app-instance-routing) and [ADR 0064](/decisions/0064-name-application-endpoints-as-domains) with a Node-owned Route kind. It extends [ADR 0069](/decisions/0069-allow-node-process-targets) so a Node Process can receive a hostname without a synthetic App instance. It does not change App instance Route generation, Cluster TLD precedence, or platform names.

## Context

App instance Routes exist to give an App instance a domain and a web root. Their owner is an App. Their targets are App instances. Active Cluster membership selects Cluster scope and Router hopping. Generated names follow the Cluster or Node TLD. The Gateway rejects a backend URL and operator Caddy.

That model is too narrow for a fleet service that is not an application. A self-hosted Executor on a Node needs one private name such as `executor.orbit`. Gateway DNS answers that exact name with the serving Node. That Node's Caddy reverse-proxies to the local Docker Process. The hostname is not forced under `.test` or any other reserved TLD.

A dummy App instance would attach the App instance lifecycle, PHP handle, and removal cascade. An unmanaged Caddy fragment plus an Orbit CA leaf issued by hand can serve the name, but it is not Gateway intent, it does not appear in `route:list`, and Doctor cannot own it.

## Decision

- A Route has exactly one kind: `app` or `custom_proxy`.
- An `app` Route keeps the current App owner, App instance targets, exclusive Node or Cluster scope, publication intent, and Cluster membership rules.
- A `custom_proxy` Route has no App owner and no App instance target. Its owner is the serving Node. Its scope stays Node-direct even when that Node belongs to an active Cluster.
- A custom proxy Route accepts any unique DNS domain. Orbit does not require a Cluster TLD, a Node TLD, or `.orbit`. There is no TLD allowlist.
- Domain uniqueness stays fleet-global across every Route kind. A custom proxy Route cannot take an App Route domain, another custom proxy domain, `gateway.orbit`, or `metrics.orbit`.
- The caller names either a loopback HTTP upstream on the serving Node or a Node-owned Process on that Node. The Gateway resolves a Process listener to a loopback or Node-local bind. It refuses a remote URL on another machine.
- Callers must not supply Caddyfile fragments, certificate material, DNS records, or firewall rules. The Gateway owns the site, Orbit CA leaf, exact private DNS record, and cleanup.
- Gateway private DNS publishes an exact `host-record` for the domain. The answer is the serving Node address under the same Node-scoped private Route rules: WireGuard by default. Cluster Router hopping is not used.
- The serving Node Caddy site terminates Orbit-CA TLS for the domain and reverse-proxies HTTP to the local upstream. It preserves the request `Host` value and admits streaming and WebSocket upgrades. The site is not an HTTPS hop and is not a PHP document root.
- Create persists the Route and converges certificates, Caddy, and DNS before it returns an active Route. Destroy runs the untargeted private cleanup path and does not touch App instance Routes.
- Node removal refuses while a custom proxy Route still references the Node. Process removal refuses while a custom proxy Route still targets that Process. Both refusals are retry-safe and leave the Route in place.
- Doctor inspects each custom proxy Route on its serving Node for DNS, certificate, Caddy, and upstream reachability. App instance Route inspection stays on the instance family.

## Rejected alternatives

- Synthetic App instance for Executor: rejected because App instance lifecycle, PHP/Caddy handles, and removal cascade are the wrong owner for a Node service. [ADR 0069](/decisions/0069-allow-node-process-targets) already rejected synthetic App instances for Node Processes.
- A separate proxy resource outside Routes: rejected because hostname uniqueness, `route:list` / `route:show`, private DNS, and Doctor would duplicate the Route identity.
- Reusing `isProxy()` HTTPS hop sites for loopback: rejected because those sites force `https://` plus Route SNI and would not reach an HTTP Docker publish.
- Operator-supplied Caddyfile or certificate files: rejected because Gateway intent would leave the unmanaged sidecar path in place.
- Forcing the hostname under the Cluster or Node TLD: rejected because fleet names such as `executor.orbit` and `grafana.internal` are valid unique domains.
- Applying Cluster membership to custom proxy scope: rejected because the serving Node is the DNS target and Router hopping is not required.

## Consequences

- Operators can publish a Node-local service on any unique hostname without creating an App.
- `route:create` accepts a custom-proxy form (`domain`, Node, and upstream or Process) beside the existing App form.
- Route API responses include `kind`. `app_id` is null on a custom proxy Route.
- Cluster TLD wildcards still catch names that have no exact record. An exact custom proxy record wins for that name.
- Unmanaged Beast `executor.test` fragments stay in place until an operator migrates them. Orbit does not delete live unmanaged Caddy or hand-placed certificates.

## Affects

- Components: apps/cli, apps/gateway, packages/php-sdk, apps/docs
- ADRs: extends [ADR 0009](/decisions/0009-clustered-app-instance-routing), [ADR 0064](/decisions/0064-name-application-endpoints-as-domains), and [ADR 0069](/decisions/0069-allow-node-process-targets)
- Detail: [Routes](/reference/routes#custom-proxy-routes)
- Verify: Gateway create, uniqueness, projection, destroy, Node and Process refusal tests; CLI and SDK contract tests; `composer docs-lint`
