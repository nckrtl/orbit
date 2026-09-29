---
title: "ADR 0097: Publish analytics tracking hosts for Instances"
sidebarTitle: "0097 Publish analytics tracking hosts"
description: "Proposed. An Instance publishes analytics.<its domain> as a dedicated public Route kind that proxies only Plausible's script and event paths."
---

# ADR 0097: Publish analytics tracking hosts for Instances

An Instance can publish a tracking host, by default `analytics.<instance domain>`, that proxies only Plausible's script and event paths to the analytics role. It is a dedicated Route kind, not a general way to proxy chosen paths of a Route, and it is served wherever the Instance's own domain is served.

## Status

Proposed.

## Context

Plausible counts a visit when the visitor's browser loads a script and posts an event. Both requests must reach Plausible from the public internet, while the Plausible dashboard stays private at `analytics.orbit` ([ADR 0096](/reference/analytics)). A tracking host on the Instance's own domain also keeps the requests first-party, so content blockers that list the Plausible domains do not drop them.

A Route today serves one upstream for every path. The only path-scoped handling is two reserved development prefixes that the app-dev site renders itself ([ADR 0067](/reference/routes#development-server-endpoint) and [Agentation](/reference/agentation)). A node-owned custom proxy Route accepts only a loopback upstream on its serving Node and no path ([ADR 0080](/reference/routes#custom-proxy-routes)). Public Routes reach an Instance through one Ingress and one Router in an active cluster ([ADR 0011](/reference/routes#publish-a-public-route) and [ADR 0023](/reference/routes#routes-own-domains-and-cluster-membership-owns-scope)).

## Decision

- Add `RouteKind::AnalyticsTracking`. A Route of this kind belongs to one Instance and has no operator-chosen upstream: the Gateway derives it from the active analytics role.
- A tracking Route mirrors the scope and the publication of the Instance's own authoritative Route. A cluster-scoped public Route reaches the internet through the Ingress and the Router; a node-scoped private Route is served by the Instance's own Node, behind whatever edge already fronts it. The Instance must already serve a domain, and `analytics.domain_required` refuses one that does not.
- `instance:analytics:enable` creates one Route per host. The default host is `analytics.<instance domain>`, so the Instance must have a public domain first; `--host` names other hosts, up to ten. `instance:analytics:disable` removes the Routes, and `instance:analytics:show` returns the hosts with the script URL, the event URL, and the DNS records the operator must create.
- The Router site for the host proxies exactly `/js/*` and `/api/event` to the analytics container over WireGuard and answers every other path with 404, so the dashboard and the Plausible API never become public through it.
- A public tracking host follows the public Route rules unchanged: the same eligibility, the same Ingress site and certificate handling, and the same firewall rules. A private one is converged like the Instance's own private Route and never touches the public edge.
- Enabling refuses while no analytics role is active. Removing the analytics role refuses while a tracking host exists, unless the removal also removes them.
- `instance:analytics:verify` runs on the operator's machine, as `profile` does: it resolves the host in public DNS and probes `https://<host>/js/script.js` for 200 and `https://<host>/` for 404. The Gateway calls no DNS provider.

## Rejected alternatives

- Make every tracking host a public, cluster-scoped Route: rejected because an Orbit fleet does not have to own its public edge. Where a CDN or another proxy fronts the Instance, its own Route is node-scoped and private, and a public tracking Route would demand an Ingress and a cluster that the fleet does not otherwise need, and would change how the Instance itself is served.

- General path-scoped proxying on any Route: rejected because nothing else needs it today, it would reopen the custom proxy upstream rules of ADR 0080, and a fixed pair of paths is a much smaller surface to verify and to keep private.
- Serve the script and event paths from the Instance's own domain under a reserved prefix: rejected because it would put fleet infrastructure inside every Instance site, on a path that collides with any Project that defines the same path, and it would tie tracking to the Instance's runtime being awake.
- Publish `analytics.orbit` publicly: rejected because it would expose the dashboard and the login page of the whole fleet's analytics.
- Let Orbit create the Plausible site and inject the script: rejected because Orbit does not manage Plausible accounts, and editing a Project's markup is that Project's own business.

## Consequences

- An operator enables first-party tracking for an Instance with one command and one DNS record.
- Routes gain a third kind, and every place that matches on `RouteKind` must handle it.
- The operator still creates the site in Plausible and adds the script tag to the Project.
- The tracking host depends on a cluster with an active Router and Ingress, as every public Route does.

## Affects

- Components: apps/cli, apps/gateway, packages/php-sdk, apps/e2e
- ADRs: extends [ADR 0011](/reference/routes#publish-a-public-route), [ADR 0023](/reference/routes#routes-own-domains-and-cluster-membership-owns-scope), and [ADR 0096](/reference/analytics)
- Detail: [Analytics role](/reference/analytics)
- Verify: Gateway feature tests for the Route kind and its rendered Router site, and an Incus proof that loads the script path and gets 404 for the root path
