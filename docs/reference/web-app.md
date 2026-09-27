---
title: "Web app"
description: "How the Gateway serves the Orbit web app at https://gateway.orbit, how the app stays live, how bin/web-deploy releases it, and how to roll a release back."
covers:
  - apps/web/**
  - bin/web-deploy
  - apps/gateway/app/Infrastructure/Gateway/GatewayCaddyConfigRenderer.php
  - apps/gateway/app/Infrastructure/Gateway/GatewayWebDirectoryConverger.php
  - apps/gateway/app/Infrastructure/Gateway/NativeGatewayWebConverger.php
  - apps/gateway/app/Console/Commands/ConvergeGatewayWebCommand.php
---

# Web app

The web app is Orbit's live view of the fleet. It is a static single-page app that reads the Gateway API and follows [realtime events](/reference/events). The Gateway serves it from its own origin. Its TypeScript API schema is generated from `docs/openapi.json` with `bun run types` in `apps/web` and checked in with the app. The Route create request type distinguishes an app Route with `app_instance_id` from a custom proxy Route with `node_id` and an upstream or Process; it has no app Route creation form with `app_id` or a targetless scope.

## Open the app

Open `https://gateway.orbit` from a machine on the Orbit WireGuard network. The browser must trust the Orbit root certificate, which `orbit gateway:trust` installs. The Gateway identifies the browser by its WireGuard address, so there is no login. Pages on other origins cannot call the API, as [Browser access to the Gateway](/reference/browser-access) describes.

## How the Gateway site routes requests

The Gateway's Caddy site sends each request to one of three places.

| Path | Destination |
| --- | --- |
| `/api/*`, `/mcp`, `/mcp/*`, `/up`, `/.well-known/*` | Laravel. |
| `/grafana/*` | The `metrics.orbit` site on the same Caddy, after the Metrics access check. |
| Everything else | The current web release. |

A path without a file returns the release's `index.html`, and the app's router shows the page. Files under `/assets/` carry content hashes, so browsers cache them as immutable. Every other web response has `Cache-Control: no-cache`, so a new release shows on the next load.

`/grafana/*` checks the browser's address with `GET /api/v1/metrics/grafana/authorize`, removes the `/grafana` prefix, and forwards the request to `metrics.orbit`. The Metrics publication owns that site and its Grafana upstream. When Metrics is disabled, the app shows `—` for Node metrics.

The app connects to Reverb at the URL that `GET /api/v1/realtime` returns.

## Live Node and Process state

The app subscribes to `presence-node.{id}` for every active Node, next to the `orbit` channel. The [Node agent](/reference/node-agent) publishes there.

| Agent state for a Node | Node shows | Process `runtime_status` on the Node |
| --- | --- | --- |
| Online: `agent.{id}` is a member and sent an event in the last 15 seconds | online | The agent's latest state |
| Lost: the agent left, or sent nothing for 15 seconds | offline | The value from the Process list |
| Not seen since the page subscribed | Prometheus `up` | The value from the Process list |

A Node without an [agent](/reference/node-agent#where-it-runs) always uses the last row. An example is a [Node without roles](/reference/node-provisioning#nodes-without-roles) that has no pinned SSH host key.

CPU and memory come from [`process.usage`](/reference/events#process-usage) events. The app writes each sample into its cached Process list. While realtime is live, it reloads the Process list only when no sample arrived for 60 seconds.

## Live tasks

The app keeps the task board, each task group, its agent threads, its comments, and the extension status current from [task events](/reference/events#tasks).

| Event | The app refetches |
| --- | --- |
| `task_group.created`, `task_group.updated` | The task list and that group |
| `task_comment.created` | That Task's comments |
| `agent_thread.updated` | That group's agent threads and the group |
| `tasks.updated` | Nothing. It stores the new `enabled` value. |

The app waits 100 milliseconds after a task event and refetches each named query once. A refetch that an event starts replaces a request that is still running. An active group's duration counts forward on the page. Token and line counts change with the next event, and the agent thread stream shows live tokens.

## Live Activity

The Activity page is `/activity` in the main navigation, after Tasks. It is one log, newest first. Opening a row goes to `/activity/{id}`, which always loads the row with `activity:show`.

The log loads 50 rows at a time and loads the next page when you scroll near the end. The next page sets `before_id` to the oldest loaded id. A page with fewer than 50 rows is the end. The page filters by status, command, caller Node, and target Node. The filters live in the URL, and `before_id` never does. Changing a filter loads the newest matching rows. On a narrow screen, one Filters button opens a sheet with the same four filters.

While realtime is live, the page writes [Activity notices](/reference/events#activity) into the loaded log without a refetch:

- `activity.created` inserts a matching row at the top. A null caller or target does not match that filter.
- `activity.updated` patches a loaded row in place. A row that stops matching the filters stays until the next refresh.
- A row added above the screen leaves the visible rows in place. A `N new` control then scrolls to the top.
- `activity.updated` for the open row refetches it with `activity:show` after 100 milliseconds.

The periodic refresh rebuilds the loaded range from the newest page. Each next request uses the oldest id of the page just returned, until the range reaches the oldest id that was loaded before. When the socket subscribes or returns after a drop, the page fetches only the newest page and merges it by id. Opening a row and going back returns to the same filters and scroll position.

## Polling

Realtime events keep the fleet lists, the deployment history, and the annotation list current, so those views do not poll while the page is live. When the page goes live after a time without realtime, it reloads them once, because events sent in that time are lost.

While realtime is down, those views poll with a backoff. The first poll comes 30 seconds after the loss. The delay then doubles up to 5 minutes. A reconnect resets it. The footer shows `live updates paused` next to the Gateway name. Clicking it reloads every view and restarts the backoff. A hidden tab never polls. A tab that becomes visible reloads the views whose data is older than 30 seconds.

| View | While realtime is live | While it is down |
| --- | --- | --- |
| Task board, agents, comments, and extension status | Every 5 minutes | Every 30 seconds |
| Activity log and the open Activity | Every 5 minutes | Every 30 seconds |
| Process list, for CPU and memory | After 60 seconds without `process.usage` | Every 15 seconds |

These views have no event and poll while the tab is visible:

| View | Interval |
| --- | --- |
| Database users, on a database page | 15 seconds |
| Live UFW rules, on a Node page | 15 seconds |
| Process logs, Instance logs, queue, and analytics | 10 seconds |
| Node metrics from Grafana | 10 seconds |
| Quota (`proxycli`) status and provider pools | 60 seconds |

The firewall list reads every Node's rules with one request, `GET /api/v1/firewall-rules`. It returns only the Nodes that the browser's Node can reach.

## Live logs

The Instance and Process log panes follow their log through a [live log stream](/reference/live-logs) while realtime is live. A pane opens a stream with the lines it shows, 500 for an Instance and 100 for a Process. It renews the stream every 20 seconds and closes it when the pane closes. It shows `[orbit] N lines dropped` and `[orbit] N MiB skipped` where the stream reports them.

A pane polls the one-shot read every 10 seconds instead when the Gateway refuses the stream, when the stream ends, or when realtime is down. A production Instance pane always polls. For a reason that passes on its own, such as `agent_not_joined`, the pane tries a new stream every 30 seconds while it polls. [Live logs](/reference/live-logs#when-the-live-path-is-not-available) lists the reasons.

## Installed app on iPhone and iPad

Added to the home screen, the app opens full screen below an opaque black status bar. [index.html](https://github.com/nckrtl/orbit/blob/main/apps/web/index.html) sets `apple-mobile-web-app-status-bar-style` to `black` and the viewport to `viewport-fit=cover`. iOS reads these tags only when the icon is added, so add the app again after a release changes them.

In standalone display mode, the app shell pads each edge by its `env(safe-area-inset-*)` value. The top value is 0, because the web view starts below the status bar. The bottom value keeps the footer above the home indicator. A browser tab gets no safe-area padding, and pages never add their own. The Menu drawer shows one support line with the display mode, the window and screen sizes, and the four insets. [Web verification](/reference/web-verification) checks a phone-sized viewport.

## Web directory

The web directory is `/home/orbit/web`. `ORBIT_GATEWAY_WEB` can name another direct child of `/home/orbit`. The directory belongs to the `orbit` user and the `caddy` group.

| Path | Contents |
| --- | --- |
| `releases/<commit>` | One built release, named after the first 12 characters of its commit. |
| `current` | A link to the release that the Gateway serves. |

`orbit:bootstrap` creates the directory and publishes the site. After a Gateway deploy that changes the site, run `php artisan orbit:gateway-web` in the Gateway checkout. It runs these steps:

1. It installs Caddy from the [pinned source](/reference/node-provisioning#package-sources).
2. It grants Caddy access to the checkout's `public` directory and creates the web directory.
3. It publishes the Gateway certificate with the public root certificate.
4. It writes the Gateway PHP-FPM pool and runs `systemctl reload-or-restart php8.5-fpm`, which can restart the workers that serve the API.
5. It publishes the site.
6. It converges the runtime hibernator and the [agent view subscriber](/reference/node-agent#subscriber).

It changes no role, VPN setting, or Node. A Gateway deploy never changes the releases or `current`.

## Release a build

Run `bin/web-deploy` from a clean checkout of the commit to release.

```bash
bin/web-deploy
```

The command refuses uncommitted changes. It checks the commit out into a temporary worktree and builds it there with a minimal environment, so ignored files such as `apps/web/.env.local` and `VITE_*` variables never reach a release. It installs the locked dependencies of `packages/agent-annotation` and `apps/web`, builds `apps/web`, uploads the build to `releases/<commit>`, and switches `current` in one rename. It keeps the five newest releases and never removes the current one.

| Variable | Default | Meaning |
| --- | --- | --- |
| `ORBIT_WEB_DEPLOY_HOST` | `orbit@gateway` | SSH destination of the Gateway host. |
| `ORBIT_WEB_DEPLOY_SSH` | `ssh` | SSH command, including options such as `-i KEY`. |
| `ORBIT_WEB_DIR` | `/home/orbit/web` | Web directory on the Gateway host. |
| `ORBIT_WEB_GROUP` | `caddy` | Group that must read the release. |

## Roll back

Switch `current` to a retained release without a build.

```bash
bin/web-deploy --switch <release>
```

`<release>` is the first 12 characters of the commit, as in `releases/`. The command exits with status 2 for any other value and refuses a release that is not retained. The Gateway serves the older release on the next request.

## Browser tests

Browser tests assert on the page, the URL, and the requests that the demo Gateway received. They never write tracked files, so a passing `bun run test` in `apps/web` leaves the working tree unchanged.

## Why it works this way

These reasons explain the design. Check them before you propose a change.

### One live fleet view

The web app is the only live fleet view, and the CLI has none. A terminal screen is rejected: it would need its own copy of the metrics queries, and it would have to follow every change to shared requests and realtime events. Without a browser, use the list commands and `realtime:tail`.

### The Gateway origin

The Gateway identifies a caller by its WireGuard address. A proxy in front of the API would replace the browser's address with its own. A separate hostname such as `app.orbit` would make every API call cross-origin. On the Gateway origin, the browser already trusts the certificate, and the Gateway already knows the caller. The app adds no authority, because every request it makes is an API request from the browser's own address.

### Releases outside the checkout

Files copied into the Gateway checkout's `public` directory would follow Gateway deploys, and a deploy that cleans the checkout would remove them. A separate web directory lets a web release and a Gateway deploy happen independently. A rollback only moves `current`.

### A build on the operator's machine

Building on the Gateway host would need Node or Bun there only for this step.

### Grafana through the Metrics site

The Metrics publication rewrites the `metrics.orbit` site when Metrics moves. A direct proxy to the Metrics Node would copy its address into the Gateway site, which that publication does not rewrite.

### Notices patched into the Activity log

Each notice carries every list column. A refetch per notice would reload the pages that the operator reads, and a burst would still cost a request. The page keeps the visible rows in place, so the operator never loses their place. A reconnect merges one page by id instead of refetching every loaded page.

### An opaque status bar on iOS 26

With a translucent status bar and `viewport-fit=cover`, iOS 26 starts the web view under the status bar and makes it shorter by the top inset. Every CSS height and `innerHeight` then leaves a dead band at the bottom, and the edge blur covers the header. [WebKit bug 301108](https://bugs.webkit.org/show_bug.cgi?id=301108) records the fault. The opaque `black` style starts the web view below the bar and lets it reach the bottom edge.

Instance records omit an environment column; clients derive placement from the owning Node role.
