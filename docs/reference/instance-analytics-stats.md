---
title: "App instance analytics stats"
description: "How the Gateway reads live visitors, period visitor counts, and top pages for an App instance, and when the Orbit web page shows that panel."
---

# App instance analytics stats

This page tells an operator how the Gateway reports visits for an App instance that publishes a tracking host. The report comes from the fleet analytics driver. The first driver reads the Plausible Community Edition Stats API of the analytics role. [Analytics role](/reference/analytics) owns the role, the tracking host, and the Stats API key. [ADR 0102](/decisions/0102-read-app-instance-analytics-through-a-fleet-driver) owns the driver and the panel.

## Read the stats

`GET /api/v1/instances/{instance}/analytics/stats` returns one report. The Orbit web App instance page shows it. The CLI and the PHP SDK have no counterpart for this read.

| Field | Meaning |
| --- | --- |
| `available` | False when the analytics role is not active or the App instance publishes no tracking host. Every other field is then absent. |
| `readable` | False when the panel may show but the driver could not obtain stats. Visitor fields are then absent. |
| `driver` | `plausible_ce` for the fleet Plausible Community Edition driver. |
| `site_domain` | The App instance's authoritative public domain, which is the Plausible site. |
| `live_visitors` | People on the site now, as Plausible realtime visitors. |
| `visitors` | `past_24h`, `past_7d`, and `past_30d` visitor counts. `past_24h` is Plausible's `day` period: today in the site timezone. |
| `pages` | Up to ten paths for the last 30 days, each with `path` and `visitors`, most visitors first. |
| `error_code`, `error` | Present only when `readable` is false. They name why the driver could not read stats. |

## Know when the panel is available

The Gateway reports stats as available when both of these are true.

| Condition | How the Gateway decides |
| --- | --- |
| The fleet analytics role is up | A Node has an active `analytics` role, that Node is active, and it has a WireGuard address. |
| The App instance has tracking enabled | The App instance owns at least one `analytics_tracking` Route. |

The web page fetches this report on the App instance view. It draws the panel only when `available` is true. An App instance without tracking, or a fleet without an active analytics role, has no panel.

## Know how the Gateway reads it

The Plausible Community Edition driver calls the Stats API on the analytics Node over WireGuard, at the published Plausible port. It sends the stored Stats API key as a bearer token. It never uses a public URL and never returns the key.

The site is the App instance's authoritative public domain. That is the `data-domain` in the tracking snippet. The request cannot name another site.

The driver asks Plausible for realtime visitors, visitor totals for `day`, `7d`, and `30d`, and a 30-day page breakdown limited to ten rows. If any of those calls fails, the whole read is unreadable. The Gateway does not mix a successful count with a missing one.

## Handle a failed read

An unavailable report is `available: false`. That is not an error. A failed read of an available report is HTTP 200 with `readable: false`. The response never contains visitor numbers in that case, so a missing key cannot look like an empty site.

| `error_code` | Cause |
| --- | --- |
| `analytics.stats_key_missing` | No Stats API key is stored on the Gateway. Store one with `orbit analytics:credentials --set`. |
| `analytics.stats_unauthorized` | Plausible rejected the stored key. |
| `analytics.stats_site_missing` | Plausible has no site for the App instance's domain. Create that site in Plausible. |
| `analytics.stats_domain_missing` | The App instance has tracking but no authoritative domain to map to a site. |
| `analytics.stats_unreadable` | Plausible did not answer, or the answer was not a stats report. |

| Status | Error code | Cause |
| --- | --- | --- |
| 403 | `peer.identity_unknown` or `node_access.required` | The caller is not an active WireGuard peer or lacks access to the App instance's Node. |
| 404 | `resource.not_found` | No App instance matches the path. |
