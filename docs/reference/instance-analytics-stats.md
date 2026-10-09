---
title: "Instance analytics stats"
description: "How the Gateway reads live visitors, period visitor counts, and top pages for an Instance, and when the Orbit web page shows that panel."
covers:
  - apps/gateway/app/Actions/Analytics/ShowInstanceAnalyticsStatsAction.php
  - apps/gateway/app/Domain/Analytics/{AnalyticsStatsDriver,AnalyticsStatsRead,AnalyticsPageStat}.php
  - apps/gateway/app/Infrastructure/Analytics/PlausibleCommunityEditionStatsDriver.php
---

# Instance analytics stats

The Gateway reports visits for an Instance that publishes a tracking host. The report comes from the fleet analytics driver, which reads the Plausible Community Edition Stats API of the analytics role. The driver reads community-edition stats directly from the Stats API. [Analytics role](/reference/analytics) owns the role, the tracking host, and the Stats API key.

## Read the stats

`GET /api/v1/instances/{instance}/analytics/stats` returns one report. The Orbit web Instance page shows it. The CLI and the PHP SDK have no counterpart for this read. The read has no app selector. It uses the app of a single-app Project; on a Project with several apps it returns `app.required` once tracking is available. See [One app only](/reference/analytics#one-app-only).

| Field | Meaning |
| --- | --- |
| `available` | False when the analytics role is not active or the Instance publishes no tracking host. Every other field is then absent. |
| `readable` | False when the panel may show but the driver could not obtain stats. Visitor fields are then absent. |
| `driver` | `plausible_ce` for the fleet Plausible Community Edition driver. |
| `site_domain` | The Instance's authoritative public domain, which is the Plausible site. |
| `live_visitors` | People on the site now, as Plausible realtime visitors. |
| `visitors` | `past_24h`, `past_7d`, and `past_30d` visitor counts. `past_24h` is Plausible's `day` period: today in the site timezone. |
| `pages` | Up to ten paths for the last 30 days, each with `path` and `visitors`, most visitors first. |
| `error_code`, `error` | Present only when `readable` is false. They name why the driver could not read stats. |

## Know when the panel is available

The Gateway reports stats as available when both of these are true.

| Condition | How the Gateway decides |
| --- | --- |
| The fleet analytics role is up | A Node has an active `analytics` role, that Node is active, and it has a WireGuard address. |
| The Instance has tracking enabled | The Instance owns at least one `analytics_tracking` Route. |

The web page fetches this report on the Instance view. It draws the panel only when `available` is true. An Instance without tracking, or a fleet without an active analytics role, has no panel.

## Know how the Gateway reads it

The Plausible Community Edition driver calls the Stats API on the analytics Node over WireGuard, at the published Plausible port. It sends the stored Stats API key as a bearer token. It never uses a public URL and never returns the key.

The site is the Instance's authoritative public domain. That is the `data-domain` in the tracking snippet. The request cannot name another site.

The driver asks Plausible for realtime visitors, visitor totals for `day`, `7d`, and `30d`, and a 30-day page breakdown limited to ten rows. If any of those calls fails, the whole read is unreadable. The Gateway does not mix a successful count with a missing one.

## Handle a failed read

An unavailable report is `available: false`. That is not an error. A failed read of an available report is HTTP 200 with `readable: false`. The response never contains visitor numbers in that case, so a missing key cannot look like an empty site.

| `error_code` | Cause |
| --- | --- |
| `analytics.stats_key_missing` | No Stats API key is stored on the Gateway. Store one with `orbit analytics:credentials --set`. |
| `analytics.stats_unauthorized` | Plausible rejected the stored key. |
| `analytics.stats_site_missing` | Plausible has no site for the Instance's domain. Create that site in Plausible. |
| `analytics.stats_domain_missing` | The Instance has tracking but no authoritative domain to map to a site. |
| `analytics.stats_unreadable` | Plausible did not answer, or the answer was not a stats report. |

| Status | Error code | Cause |
| --- | --- | --- |
| 403 | `peer.identity_unknown` or `node_access.required` | The caller is not an active WireGuard peer or lacks access to the Instance's Node. |
| 404 | `resource.not_found` | No Instance matches the path. |
| 422 | `app.required` | The Project has several apps. |
