---
title: "Web app"
description: "How the Gateway serves the Orbit web app at https://gateway.orbit, how bin/web-deploy releases it, and how to roll a release back."
---

# Web app

This page tells an operator how the Gateway serves the Orbit web app, how to release a new build, and how to roll a release back. [ADR 0123](/decisions/0123-serve-the-web-app-from-the-gateway-origin) records why the app shares the Gateway origin.

## Open the app

Open `https://gateway.orbit` from a machine on the Orbit WireGuard network. The browser must trust the Orbit root certificate, which `orbit gateway:trust` installs. The Gateway identifies the browser by its WireGuard address, so there is no login. Pages on other origins cannot call the API, as [Browser access to the Gateway](/reference/browser-access) describes.

## How the Gateway site routes requests

The Gateway's Caddy site sends each request to one of three places.

| Path | Destination |
| --- | --- |
| `/api/*`, `/mcp`, `/mcp/*`, `/up`, `/.well-known/*` | Laravel, as before. |
| `/grafana/*` | The `metrics.orbit` site, after the same WireGuard authorization. |
| Everything else | The current web release. |

A web path without a file returns the release's `index.html`, and the app's router shows the page. Files under `/assets/` carry content hashes, so browsers cache them as immutable. Every other web response must be revalidated, so a new release shows on the next load.

`/grafana/*` checks the browser's address through the Metrics authorization endpoint, removes the `/grafana` prefix, and forwards the request to `metrics.orbit` on the same Caddy. The Metrics publication owns that site and its Grafana upstream. When Metrics is disabled, `/grafana` returns an error and the app shows `—` for Node metrics.

The web app connects to Reverb at the URL that `GET /api/v1/realtime` returns. It needs no Gateway path for realtime.

## Live Node and Process state

The web app subscribes to `presence-node.{id}` for every active Node, next to the `orbit` channel. [Realtime events](/reference/events#node-agent-channels) defines those channels, and the [Node agent](/reference/node-agent) publishes on them.

| Agent state for a Node | Node shows | Process `runtime_status` for the Node's Processes |
| --- | --- | --- |
| Online: `agent.{id}` is a member and sent an event in the last 15 seconds | online | The agent's latest state. A polled value does not replace it. |
| Lost: the agent left, or sent nothing for 15 seconds | offline | The polled value from the Gateway. |
| Not seen since the page subscribed | Prometheus `up`, as before | The polled value from the Gateway. |

A Node without an agent, such as an operator client, always uses the last row. When realtime is not configured, the web app polls as before and shows every Node from Prometheus.

CPU and memory come from [`process.usage`](/reference/events#process-usage) events, which the Gateway sends every 15 seconds while a browser is subscribed. The web app writes each sample into its cached Process list. While realtime is live, it reloads the Process list only when no `process.usage` event arrived for 60 seconds. While realtime is down or not configured, it polls the list every 15 seconds. The Gateway answers that list from its [view of the agents](/reference/node-agent#gateway-view) when the view is fresh, so a reload causes no SSH even when Prometheus is down.

## Live tasks

The web app keeps the task board, each task group, its agent threads, its comments, and the extension status current from [task events](/reference/events#tasks) on the `orbit` channel. [ADR 0151](/decisions/0151-push-task-and-process-usage-changes-over-realtime) records the design.

| Event | The web app refetches |
| --- | --- |
| `task_group.created`, `task_group.updated` | The task list and that group |
| `task_comment.created` | That subtask's comments |
| `agent_thread.updated` | That group's agent threads and the group |
| `tasks.updated` | Nothing; it stores the new `enabled` value |

The web app waits 100 milliseconds after a task event and then refetches each named query once, so the notices of one Gateway change show a group once.

While realtime is live, these queries refetch every 5 minutes as a safety net for a lost notice, and a refetch that an event starts replaces a request already in flight. When the socket first subscribes, the web app refetches the task and Process queries, because a change between their first load and the subscription sent no event to the page. When the socket comes back after a drop, it refetches every query, because events sent during the drop are lost.

While realtime is down or not configured, the task queries poll every 30 seconds. An active group's duration counts forward on the page between refetches. Token and line counts change with the next event for the group, and the agent thread stream shows live tokens for each thread.

## Polling

Realtime events keep the fleet lists, the deployment history, and the annotation list current. While the page is subscribed to `orbit`, those views do not poll. When the page goes live after a period without realtime, it reloads everything once, because events sent while the socket was down are lost. That holds after a reconnect, and after a first connect that comes more than 5 seconds after page load.

When realtime is down, or the Gateway offers none, those views poll with a backoff. The first poll comes 30 seconds after the loss. After that, the delay grows with the time realtime has been down, so it doubles each time until it reaches 5 minutes. A reconnect resets it. The footer shows `live updates paused` next to the Gateway name while the page polls. Clicking it reloads every view at once and starts the backoff again.

A hidden tab never polls. When the tab is visible again, it reloads the views whose data is older than 30 seconds.

The task views and the Process list's CPU and memory have events of their own, as [Live tasks](#live-tasks) and [Live Node and Process state](#live-node-and-process-state) describe:

| View | While realtime is live | While it is down |
| --- | --- | --- |
| Task board, agents, comments, and task status | Every 5 minutes | Every 30 seconds |
| Process list, for CPU and memory | Only when no `process.usage` event arrived for 60 seconds | Every 15 seconds |

Some views have no event and poll on their own clock while the tab is visible:

| View | Interval |
| --- | --- |
| Database users, on a database page | 15 seconds |
| Live UFW rules, on a Node page | 15 seconds |
| Process logs, on a Process page | 10 seconds |
| Instance logs, queue, and analytics, on an Instance page | 10 seconds |
| Node metrics from Grafana, not the Gateway | 10 seconds |
| Quota (`proxycli`) status and provider pools | 60 seconds |

The firewall list reads every Node's rules with one request, `GET /api/v1/firewall-rules`. It returns only the Nodes that the browser's Node can reach. The CLI keeps the per-Node list.

## Live logs

The Instance log pane and the Process log pane follow their log through a [live log stream](/reference/live-logs) while realtime is live. The pane opens a stream with the lines it shows, 500 for an Instance and 100 for a Process, renews it every 20 seconds, and closes it when the pane closes. It shows `[orbit] N lines dropped` and `[orbit] N MiB skipped` where the stream reports them.

A pane does not poll while its stream runs. It polls the one-shot read every 10 seconds, as before, when the Gateway refuses the stream, when the stream ends with `agent_left` or `source_unavailable`, or when realtime is down. When the socket comes back, the pane opens a new stream.

## Web directory

The web directory is `/home/orbit/web` unless `ORBIT_GATEWAY_WEB` sets another path under `/home/orbit/`. It belongs to the `orbit` user and the `caddy` group.

| Path | Contents |
| --- | --- |
| `releases/<commit>` | One built release, named after the 12-character commit it was built from. |
| `current` | A link to the release that the Gateway serves. |

`orbit:bootstrap` creates the directory and publishes the site. After a Gateway deploy that changes the site, run `php artisan orbit:gateway-web` in the Gateway checkout. It installs Caddy from the [pinned source](/reference/node-provisioning#package-sources), creates the directory, publishes the Gateway certificate with the public root certificate, and publishes the site, without changing roles, VPN settings, or the Gateway Node. Gateway deploys do not change the releases or `current`.

## Release a build

Run `bin/web-deploy` from a clean checkout of the commit to release.

```bash
bin/web-deploy
```

The command refuses a working tree with uncommitted changes. It checks the commit out into a temporary worktree and builds it there with a minimal environment, so ignored files such as `apps/web/.env.local` and `VITE_*` shell variables never reach a release. It installs the locked dependencies of `packages/agent-annotation` and `apps/web`, then builds `apps/web`. It uploads the build to `releases/<commit>` and switches `current` to it in one rename. It keeps the five newest releases and never removes the current one. Releasing a commit that already exists replaces that release.

These environment variables change the target.

| Variable | Default | Meaning |
| --- | --- | --- |
| `ORBIT_WEB_DEPLOY_HOST` | `orbit@gateway` | SSH destination of the Gateway host. |
| `ORBIT_WEB_DEPLOY_SSH` | `ssh` | SSH command, including options such as `-i KEY`. |
| `ORBIT_WEB_DIR` | `/home/orbit/web` | Web directory on the Gateway host. |
| `ORBIT_WEB_GROUP` | `caddy` | Group that must read the release. |

## Roll back

Switch `current` to a retained release without building.

```bash
bin/web-deploy --switch <commit>
```

The command refuses a commit that has no retained release. The Gateway serves the older release on the next request.
