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

The web app is Orbit's live view of the fleet. It is a static single-page app that reads the Gateway API and follows [realtime events](/reference/events). Its TypeScript API schema is generated from the Gateway OpenAPI document (`docs/openapi.json`) with `bun run types` in `apps/web`. Operation descriptions and request-field comments in that schema follow the OpenAPI document, including argument and option text that [API reference generation](/reference/api-reference) reads from the CLI command classes. Regenerate the schema after an OpenAPI change and commit it with the app.

The generated API schema also includes the Project development deploy step operations and their `required` boolean. These types describe the [API contract](/reference/deployments#development-deploy-steps); they do not add web controls or start deployments.

The Project schema includes `task_workspace_routed`, which describes routing for new task workspaces; an existing workspace keeps its recorded mode. The Gateway serves it from its own origin.

The generated Process schema includes nullable `user`, the explicitly selected account or null for the derived account. Process create accepts `user` only for a Node systemd Process without a preset. [Processes and schedules](/reference/processes-and-schedules#node-account) owns the account validation and working-directory defaults.

Extension navigation follows the Gateway's enabled set: each page and its route depend only on their own extension. Tasks pages need only the `tasks` extension. The Quota page needs only `proxycli`, and it shows provider quota whenever `proxycli` is enabled and its collector is configured, whether or not Tasks is enabled. Tasks and ProxyCLI links and routes are absent while their extension is disabled. The Gateway API remains authoritative, so a stale direct request still receives `extension.disabled` rather than granting access.

The Route create request type distinguishes an app Route with `instance_id` from a custom proxy Route with `node_id` and an upstream or Process; it has no app Route creation form with `project_id` or a targetless scope.

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

## Node detail pages

A Node detail page has the same section menu pattern as an Instance: Overview, Tools, and Firewall where supported. Overview holds identity, roles, metrics when supported, Instances, and Node Processes. Firewall has its own page section on Linux, with the existing operator rules, Orbit rules, live rules, and missing-state comparisons. macOS tool support does not enable firewall management.

The menu chooses the URL. Overview is `/nodes/<id>`. Tools is `/nodes/<id>/tools`. Firewall is `/nodes/<id>/firewall` on Linux. On a narrow screen the menu is one row, so the list keeps the width of the page. A macOS Firewall URL returns to Overview and does not request Linux firewall rules.

### Tools

Tools always shows every registered Tool for that Node, including failed tools and records on an unreachable machine. Managed rows show manager, package, recorded version, observed version, version constraint, status, and failures. On a narrow screen, the observed version, the constraint, and the failure wrap onto a second line so a phone still shows them, and Update and Remove sit on that line.

The recorded version is the last operation's result. A live scan supplies the observed version beside it: a newer install, `absent` when a completed scan did not see the package, `not scanned` when that manager is outside the inventory, and `unavailable` when no observation is loaded.

A separate detected-unmanaged group shows installed Homebrew formulae, casks, and Vite+ globals from `GET /api/v1/tool-inventory` (`tool:scan`). It labels package kind, observed version, dependency status, and the support reason. A null scan version, including a non-SemVer formula revision or cask version, is shown as `unreadable`. A formula and a cask that share a name stay on separate rows. Discoveries are informational and do not affect Node health.

The page shows inspection time and status and has an explicit Refresh action. Refresh sends only another inventory read. An active Node is read when the section opens. An unreachable Node does not start that read, and Refresh stays disabled so it cannot start one later. The inspection says no scan was started, and the registered rows stay.

A failed refresh keeps the last observation and marks it stale, or says the inspection is unavailable when there was none. Neither case is shown as an empty healthy inventory. A manager that is `incomplete`, `absent`, `unsupported`, or `conflicting` is named, and its empty package array is not an inventory.

Each supported unregistered package has an Adopt action. Unsupported packages, including dependencies and unsupported casks, show the block reason and have no enabled Adopt action. Adopt opens an ownership form that names the Node, manager, and package, and asks before it sends `POST /api/v1/tools/adopt`. The optional constraint is omitted when the field is empty. The Gateway rechecks the live installation before creating intent. A revalidation error stays on that form.

Success refreshes registered Tools and drops only that package from the unmanaged group. It does not install or update the package. Update sends `POST /api/v1/tools/<id>/update` with an empty object and shows whether the Tool changed, stayed current, or was blocked by its constraint. Remove asks before it sends `DELETE /api/v1/tools/<id>`. The question names the package, the manager, the Node, and that the package is uninstalled and the record deleted. Success reports status `removed` and drops the row, so the list never shows `removed`. A failed removal stays on that question and leaves the row. No scan, refresh, or page load adopts or updates a package.

Phone navigation and actions remain reachable without compressing the package list beside a full desktop sidebar. The [web verification](/reference/web-verification#node-tools-review) covers phone and desktop layouts, successful adoption, and offline or failed scan states.

## Live Node and Process state

The app subscribes to `presence-node.{id}` for every active Node, next to the `orbit` channel. The [Node agent](/reference/node-agent) publishes there.

| Agent state for a Node | Node shows | Process `runtime_status` on the Node |
| --- | --- | --- |
| Online: `agent.{id}` is a member and sent an event in the last 15 seconds | online | The agent's latest state |
| Lost: the agent left, or sent nothing for 15 seconds | offline | The value from the Process list |
| Not seen since the page subscribed | Prometheus `up` | The value from the Process list |

A Node without an [agent](/reference/node-agent#where-it-runs) always uses the last row. A macOS tool-only Node has no agent or Metrics exporter in this slice; the page shows unavailable live telemetry rather than treating that absence as a failed Linux service. An example is a [Node without roles](/reference/node-provisioning#nodes-without-roles) that has no pinned SSH host key.

CPU and memory come from [`process.usage`](/reference/events#process-usage) events. The app writes each sample into its cached Process list. While realtime is live, it reloads the Process list only when no sample arrived for 60 seconds.

## Live tasks

The generated task schema keeps `watched_pr_url`, `watched_pr_number`, and `watched_pr_state` apart from `pr_url`. The watched fields describe the pull request found on the task branch while subtasks are open; `pr_url` still identifies the reviewed pull request Orbit opened. The [branch watch](/reference/tasks#watch-the-branch-while-subtasks-are-open) owns that distinction. Regenerate the web schema after these response fields change, and keep typed test fixtures current. A task with no watched pull request has null watched fields; do not copy `pr_url` into them.

When the Gateway reports Tasks enabled, the app keeps the task board, each task, its agent threads, its comments, and the extension status current from [task events](/reference/events#tasks). When disabled, it hides task navigation and task routes; enabling the extension makes those views available again without removing stored task records.

A card for a task that asks for direction says `Needs your direction`, and the task page shows that question first. A card for a failure says `Needs attention`. The board does not answer either request. The operator answers through the CLI, MCP, or the API. [Direction requests](/reference/tasks#direction-requests) define the two kinds.

| Event | The app refetches |
| --- | --- |
| `task_group.created`, `task_group.updated` | The task list and that task |
| `task_comment.created` | That subtask's comments |
| `agent_thread.updated` | That task's agent threads and the task |
| `tasks.updated` | Nothing. It stores the new `enabled` value. |

The app waits 100 milliseconds after a task event and refetches each named query once. A refetch that an event starts replaces a request that is still running. An active task's duration counts forward on the page. Token and line counts change with the next event, and the agent thread stream shows live tokens.

## Task definitions

The Tasks page at `/tasks` lists task definitions with `tasks:definition:list`. Each Project page at `/projects/{project}` lists the definitions for that Project. The definition view is `/projects/{project}/task-definitions/{name}`. The lists and the view are shown only while the tasks extension is enabled. While it is off, they are absent, as the other Tasks routes are. A stale direct request still receives `extension.disabled`. The lists are read-only. Creating or changing a definition is an API call, as [Task definitions](/reference/tasks#task-definitions) describes. Opening a definition does not start a task.

The lists and the view load when the page opens. They have no realtime event and do not poll. A change another client makes with `tasks:definition:update` appears the next time the page opens.

### Drawing

The definition view draws that task definition on a canvas from the live `tasks:definition:show` response.

The main path runs down the middle. A detour or a failure path sits in a side column, and each of those paths ends in its own `complete` or `fail` node. The drawing opens at full size, so the card text stays readable. On a narrow screen, pan to reach a side path.

Each subtask shows its kind, its models, and its routes with their outcome labels. A default route to an end stays hidden. [Routes](/reference/tasks#routes) defines the defaults.

Each phase is one card. Opening the card shows a frame around that phase's subtasks. The canvas label says to open a phase, and after a phase is open it says to collapse a phase.

The drawing also shows the stages the engine always runs around the definition. Before the first subtask it shows the workspace start. Inside each `agent` subtask it shows the implementer, the handoff check, and the reviewer. After the last subtask it shows the pull request, the merge, and the cleanup. A person merges unless the definition has a `merge` subtask.

A schedule is shown in words, such as "Weekly on Monday at 03:00 UTC".

The view reports a finding when every option of a `decide` subtask leads to the same subtask. The Gateway refuses a definition that contains a subtask no path reaches, so the view does not report that finding for a stored definition.

The view reports a model that no driver can run when the [ProxyCli model list](/reference/proxycli#models) is available. Task agents run on Pi only. A model is known when ProxyCli offers it through a provider Pi runs. Claude models and models listed under the `claude` or `anthropic` provider are unavailable for task agents. Annotations still use the operator's T3 threads, as [Agent annotation](/reference/agent-annotation) describes. When that list is missing, empty, or refused, the view says that the model list is unavailable and reports no driver findings.

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

These views have no event. A view with an interval polls while the tab is visible. A view loaded when opened does not poll.

| View | Interval |
| --- | --- |
| Database users, on a database page | 15 seconds |
| Live UFW rules, on a Linux Node's Firewall section | 15 seconds |
| Process logs, Instance logs, queue, and analytics | 10 seconds |
| Node metrics from Grafana | 10 seconds |
| Quota (`proxycli`) status and provider pools | 60 seconds |
| Task definition lists and the definition view | Loaded when opened |

The firewall list reads every Node's rules with one request, `GET /api/v1/firewall-rules`. It returns only the Nodes that the browser's Node can reach.

## Live logs

The Instance and Process log panes follow their log through a [live log stream](/reference/live-logs) while realtime is live. A pane opens a stream with the lines it shows, 500 for an Instance and 100 for a Process. It renews the stream every 20 seconds and closes it when the pane closes. It shows `[orbit] N lines dropped` and `[orbit] N MiB skipped` where the stream reports them.

A pane polls the one-shot read every 10 seconds instead when the Gateway refuses the stream, when the stream ends, or when realtime is down. A production Instance pane always polls. For a reason that passes on its own, such as `agent_not_joined`, the pane tries a new stream every 30 seconds while it polls. [Live logs](/reference/live-logs#when-the-live-path-is-not-available) lists the reasons.

## Installed app on iPhone and iPad

Added to the home screen, the app opens full screen below an opaque black status bar. [index.html](https://github.com/nckrtl/orbit/blob/main/apps/web/index.html) sets `apple-mobile-web-app-status-bar-style` to `black` and the viewport to `viewport-fit=cover`. iOS reads these tags only when the icon is added, so add the app again after a release changes them.

The app shell is a `position: fixed` box with an opaque page background that fills the web view. iOS 26 blurs the top edge of an installed web app with its glass edge effect unless a fixed, opaque box covers that edge. The shell keeps the header sharp.

In standalone display mode, the shell pads the top, left, and right edges by their `env(safe-area-inset-*)` values. The top value is 0, because the web view starts below the status bar. Bottom padding depends on the window width. Pages never add their own safe-area padding.

| Window | Top, left, and right | Bottom |
| --- | --- | --- |
| Installed, phone (below `md`) | The shell pads by the inset | The page scrolls to the screen edge; its content ends one inset higher, above the home indicator |
| Installed, `md` and wider | The shell pads by the inset | The shell pads by the inset, keeping the footer above the home indicator |
| Browser tab, any width | No safe-area padding | No safe-area padding |

On a phone, the footer is hidden because the header already shows the Gateway's live status and the footer's keyboard hints are not useful there. It appears only for a message or paused live updates, and then sits above the home indicator. From the `md` breakpoint up, the footer is always shown.

The Menu drawer shows one support line with the display mode, the window and screen sizes, and the four insets. [Web verification](/reference/web-verification) checks a phone-sized viewport.

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

The opaque status bar alone does not stop the glass edge effect. iOS 26 still blurs about 38 points below the top of the web view unless WebKit can take a flat colour from a fixed, opaque box covering that edge. No CSS property or meta tag turns the effect off. Padding the header down would leave an empty band, so the shell itself is that fixed box.
