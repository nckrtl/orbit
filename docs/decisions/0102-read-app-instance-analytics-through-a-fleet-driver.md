---
title: "ADR 0102: Read App instance analytics through a fleet driver"
sidebarTitle: "0102 Read App instance analytics through a driver"
description: "Proposed. The Gateway reads App instance visits through a fleet analytics driver. The first driver calls the fleet Plausible Community Edition Stats API with a Gateway-stored key."
---

# ADR 0102: Read App instance analytics through a fleet driver

The Gateway reads visitor counts for an App instance through a fleet analytics driver. The first driver is Plausible Community Edition, the same service the analytics role already runs. The Orbit web App instance page shows those counts only when that role is active and the instance publishes a tracking host.

## Status

Proposed.

## Context

[ADR 0096](/decisions/0096-run-plausible-through-an-analytics-role) runs Plausible Community Edition as the fleet analytics role and publishes a private dashboard at `analytics.orbit`. [ADR 0097](/decisions/0097-publish-analytics-tracking-hosts-for-app-instances) lets an App instance publish a first-party tracking host. Orbit still does not create Plausible sites, accounts, or API tokens. The operator creates the site and adds the script tag. The snippet names the App instance's own domain as `data-domain`.

Operators then need those visits on the App instance page, next to the queue and the application log. The browser must not hold a Plausible management secret, and it must not be able to ask for another instance's site. The Gateway already reaches fleet services over WireGuard, as it does when it reads Prometheus through Grafana ([ADR 0088](/decisions/0088-cli-reads-display-metrics-from-grafana)). A second analytics product does not invent a second Plausible or a second event store.

## Decision

- The Gateway owns a fleet analytics driver interface. The first implementation talks only to the fleet's Plausible Community Edition Stats API. Another product binds another implementation of the same interface; this decision does not add Plausible Cloud or a second Community Edition.
- The operator creates a Stats API key in Plausible and stores it on the Gateway with `analytics:credentials`. The Gateway keeps the key as a protected Gateway-scoped setting, so a relocated analytics role still has it. API responses, activity, CLI output, and the browser never contain the key.
- The driver maps one App instance to one Plausible site by the instance's authoritative public domain, the same domain the tracking snippet already sends as `data-domain`. The caller cannot name a site. A tracking host such as `analytics.example.com` is not the site.
- The Gateway fetches stats from the analytics Node's WireGuard address and Plausible port, not from a public URL and not through the browser. The web app calls only `GET /api/v1/instances/{instance}/analytics/stats`.
- That read is available only while an active analytics role exists and the App instance publishes at least one tracking host. Otherwise the answer is `available: false` and the web page shows no panel.
- An available read that cannot obtain stats is `readable: false`, with an error code and no visitor fields. Zero is a real count from Plausible, never a stand-in for a failed read. A missing key, a missing site, an unauthorized key, or an unanswered Stats API are failed reads.

## Rejected alternatives

- Let the browser call Plausible or receive the Stats API key: rejected because a management secret would leave the Gateway, and a client then queries any site the key can see.
- Let Orbit create Plausible sites or tokens: rejected because [ADR 0096](/decisions/0096-run-plausible-through-an-analytics-role) and [ADR 0097](/decisions/0097-publish-analytics-tracking-hosts-for-app-instances) leave Plausible accounts with the operator.
- Query ClickHouse or Plausible's PostgreSQL for events: rejected because it invents a second read path beside the Stats API of the fleet Plausible.
- Call Plausible Cloud or run a second Community Edition: rejected because the fleet already has one CE at the analytics role.
- Treat a failed read as zeros: rejected because a missing key or an unreachable Stats API would look like an empty site.
- Store one API key per App instance: rejected because one fleet key already scoped by site domain is enough, and per-instance keys would multiply secrets the Gateway must hide.
- Use the tracking host as the Plausible site id: rejected because the script tag counts the App's own domain, which is the site the operator already created.

## Consequences

- After tracking is enabled, the App instance page can show live visitors, visitors for Plausible's day, 7-day, and 30-day periods, and the top ten pages, without opening `analytics.orbit`.
- The operator still creates the Plausible site and a Stats API key, then stores that key once on the Gateway.
- The key survives analytics-role relocation because it is Gateway-scoped, not stored on the role's Node.
- Another analytics product implements the same driver interface; it does not add a second Plausible.

## Affects

- Components: apps/cli, apps/docs, apps/gateway, packages/php-sdk
- ADRs: extends [ADR 0096](/decisions/0096-run-plausible-through-an-analytics-role) and [ADR 0097](/decisions/0097-publish-analytics-tracking-hosts-for-app-instances); follows [ADR 0088](/decisions/0088-cli-reads-display-metrics-from-grafana)
- Detail: [Analytics role](/reference/analytics), [App instance analytics stats](/reference/instance-analytics-stats), [`analytics`](/cli/analytics)
- Verify: Gateway feature tests for panel gating and unreadable reads, driver tests against a faked Stats API, and Orbit web browser tests that hide the panel, show counts, and show the error state
