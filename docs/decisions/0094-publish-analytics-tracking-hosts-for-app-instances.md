---
title: "ADR 0094: Publish analytics tracking hosts for App instances"
sidebarTitle: "0094 Publish analytics tracking hosts"
description: "Proposed. An App instance publishes analytics.<its domain> as a dedicated public Route kind that proxies only Plausible's script and event paths."
---

# ADR 0094: Publish analytics tracking hosts for App instances

An App instance can publish a public tracking host, by default `analytics.<instance domain>`, that proxies only Plausible's script and event paths to the analytics role. It is a dedicated Route kind, not a general way to proxy chosen paths of a Route.

## Status

Proposed.

## Context

Plausible counts a visit when the visitor's browser loads a script and posts an event. Both requests must reach Plausible from the public internet, while the Plausible dashboard stays private at `analytics.orbit` ([ADR 0093](/decisions/0093-run-plausible-through-an-analytics-role)). A tracking host on the App's own domain also keeps the requests first-party, so content blockers that list the Plausible domains do not drop them.

A Route today serves one upstream for every path. The only path-scoped handling is two reserved development prefixes that the app-dev site renders itself ([ADR 0067](/decisions/0067-serve-development-servers-on-the-route-origin) and [ADR 0082](/decisions/0082-wire-agentation-watch-mode-through-appinstance-processes)). A node-owned custom proxy Route accepts only a loopback upstream on its serving Node and no path ([ADR 0080](/decisions/0080-add-node-owned-custom-proxy-routes)). Public Routes reach an App through one Ingress and one Router in an active cluster ([ADR 0011](/decisions/0011-clustered-production-ingress-and-app-prod-placement) and [ADR 0023](/decisions/0023-separate-hostname-selection-from-cluster-routing)).

## Decision

- Add `RouteKind::AnalyticsTracking`. A Route of this kind belongs to one App instance, is always public, and has no operator-chosen upstream: the Gateway derives it from the active analytics role.
- `instance:analytics enable` creates one Route per host. The default host is `analytics.<instance domain>`, so the App instance must have a public domain first; `--host` names other hosts, up to ten. `instance:analytics disable` removes the Routes, and `instance:analytics show` returns the hosts with the script URL, the event URL, and the DNS records the operator must create.
- The Router site for the host proxies exactly `/js/*` and `/api/event` to the analytics container over WireGuard and answers every other path with 404, so the dashboard and the Plausible API never become public through it.
- The host follows the public Route rules unchanged: the same eligibility, the same Ingress site and certificate handling, and the same firewall rules.
- Enabling refuses while no analytics role is active. Removing the analytics role refuses while a tracking host exists, unless the removal also removes them.
- `instance:analytics verify` runs on the operator's machine, as `profile` does: it resolves the host in public DNS and probes `https://<host>/js/script.js` for 200 and `https://<host>/` for 404. The Gateway calls no DNS provider.

## Rejected alternatives

- General path-scoped proxying on any Route: rejected because nothing else needs it today, it would reopen the custom proxy upstream rules of ADR 0080, and a fixed pair of paths is a much smaller surface to verify and to keep private.
- Serve the script and event paths from the App's own domain under a reserved prefix: rejected because it would put fleet infrastructure inside every App site, on a path that collides with any App that defines the same path, and it would tie tracking to the App instance's runtime being awake.
- Publish `analytics.orbit` publicly: rejected because it would expose the dashboard and the login page of the whole fleet's analytics.
- Let Orbit create the Plausible site and inject the script: rejected because Orbit does not manage Plausible accounts, and editing an App's markup is the App's own business.

## Consequences

- An operator enables first-party tracking for an App instance with one command and one DNS record.
- Routes gain a third kind, and every place that matches on `RouteKind` must handle it.
- The operator still creates the site in Plausible and adds the script tag to the App.
- The tracking host depends on a cluster with an active Router and Ingress, as every public Route does.

## Affects

- Components: apps/cli, apps/gateway, packages/php-sdk, apps/e2e
- ADRs: extends [ADR 0011](/decisions/0011-clustered-production-ingress-and-app-prod-placement), [ADR 0023](/decisions/0023-separate-hostname-selection-from-cluster-routing), and [ADR 0093](/decisions/0093-run-plausible-through-an-analytics-role)
- Detail: [Analytics role](/reference/analytics)
- Verify: Gateway feature tests for the Route kind and its rendered Router site, and an Incus proof that loads the script path and gets 404 for the root path
