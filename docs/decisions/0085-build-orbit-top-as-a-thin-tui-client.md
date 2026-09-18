---
title: "ADR 0085: Build orbit top as a thin TUI client"
sidebarTitle: "0085 Build orbit top as a thin TUI client"
description: "Proposed. orbit top is a php-tui screen that stays current from the same SDK requests and realtime events every other command uses, with polling as a fallback and design sketches as its spec."
---

# ADR 0085: Build orbit top as a thin TUI client

`orbit top` is one live, sectioned screen built on php-tui. It loads the fleet with the same typed SDK requests every other command sends, keeps that state current from the realtime channel [ADR 0084](/decisions/0084-broadcast-record-changes-through-reverb) defines when it is available, and falls back to reloading on a fixed tick when it is not. Its actions run the same SDK request the matching command sends; panel prompts built on the CLI's existing prompt primitives collect the same inputs a command would ask for. Its interaction and rendering were agreed as a design sketch under `apps/cli/design` before the real command existed, and screen snapshot tests now hold that rendering to the agreed contract.

## Status

Proposed.

## Context

Operators watch the fleet by running individual list commands (`node:list`, `process:list`, and so on) repeatedly, or by tailing `realtime:tail` and translating raw events back into fleet state by hand. Neither gives one live picture across every family, and neither lets an operator act on what they see without leaving to another command.

[ADR 0084](/decisions/0084-broadcast-record-changes-through-reverb) gives the CLI a realtime channel; `GET /api/v1/realtime` plus `App\Support\Realtime\RealtimeSubscriber` already let `realtime:tail` and `realtime:show` connect to it. A live screen is the natural next client of that same subscriber, provided it degrades to the CLI's existing polling behavior when realtime is not configured, exactly as `realtime:tail` already documents falling back to the profile's `realtime_url`/`realtime_key` or `ORBIT_REALTIME_URL`/`ORBIT_REALTIME_KEY`.

The CLI's design-sketch discipline ([`apps/cli/design/README.md`](https://github.com/nckrtl/orbit/blob/main/apps/cli/design/README.md), used under `designing-cli-commands`) already requires new interaction to be agreed as a sketch, with a recorded flow, before the real command exists. `design:top` was that sketch: a scripted scenario exercising the sidebar, record pages, forms, and the actions menu with no Gateway request. Building the real command meant adding every request-driven behavior the sketch has no Gateway request to model (loading real lists, applying real events, sending real actions) while preserving the interaction the sketch had already fixed.

Part of the fleet data `orbit top` wants to show (deployment history, database connection users, live Node CPU/memory/disk metrics outside a `node.sample` event) came from a parallel Gateway effort landing alongside this one. The screen had to render correctly whether or not a given Gateway already exposes those endpoints.

## Decision

- `orbit top` must be implemented as `App\Commands\TopCommand`, delegating rendering to `App\Support\Tui\Screen` and interaction to `App\Support\Tui\Interaction`, both driven by fleet data held in `App\Support\Tui\State`. The command itself stays a thin loop: load, apply queued realtime events or poll, read terminal events, redraw.
- Startup must load every Node, App, AppInstance, Process, Schedule, firewall rule, and Database connection once, using the same typed SDK requests (`GatewayRequest` subclasses) every other command sends. `orbit top` must not invent a bulk or aggregate endpoint; it composes existing per-family list requests.
- `orbit top` must ask the Gateway for its realtime endpoint through `GET /api/v1/realtime` at startup, falling back to the profile's own `realtime_url`/`realtime_key` or `ORBIT_REALTIME_URL`/`ORBIT_REALTIME_KEY` exactly as `realtime:tail` does, through the shared `RealtimeSubscriber`. Any transport failure while asking must be treated as "not configured" rather than failing the whole command.
- When connected, `orbit top` must apply each realtime event to the row it names as it arrives. When realtime is not connected, or the socket drops, `orbit top` must reload every list on the `--tick` interval (default 10 seconds) instead. The header must name the current mode as `live`, `reconnecting`, or `polling every Ns`, so an operator can tell which guarantee they are getting.
- A record action available from the actions menu (`a` or a right click) must run the same SDK request its matching command sends: Process start/stop/restart, Schedule run/enable, Node doctor, Database connection destroy, firewall rule removal. An action with no request that can run synchronously inside a screen that must keep rendering (App instance deploy or rollback, which stream for minutes) or no request at all (`node:ssh`, `instance:profile`, `database:query`, which need their own interactive terminal) must print the equivalent command in the footer instead of attempting to run it.
- A form the screen needs (such as creating a Node) must reuse the CLI's existing prompt primitives through panel-scoped variants (`App\Support\Tui\Prompts\PanelTextPrompt`, `PanelMultiSelectPrompt`) rendered inside the screen's own layout, and must submit the same typed SDK request the matching command sends (for example, `node:add`'s `AddNodeRequest`).
- Deployment history, database connection users, and Node metrics must load through `App\Support\Tui\Sources\DeploymentsSource`, `DatabaseUsersSource`, and `NodeMetricsSource`, whose Gateway implementations send `ListAppInstanceDeploymentsRequest`, `ShowAppInstanceDeploymentRequest`, `ListDatabaseUsersRequest`, and `ShowNodeMetricsRequest`. A pane re-sends its request on a short poll interval while its page stays open, independent of the screen's own realtime-or-tick refresh. When a request fails, its source must return `null` instead of throwing, and the pane must render a short unavailable note rather than fail the screen.
- New interaction for this screen must be agreed as a design sketch under `apps/cli/design` before it ships as part of the real command, per the CLI's existing sketch-first discipline. Once a sketch is accepted, its scenario becomes the mock Gateway scenario for the real command's tests, and its recorded transcript becomes the expected output those tests hold.
- The screen's rendering for each represented state (dashboard, a list, a record page, a form, the actions menu) must be locked with screen snapshot tests under `apps/cli/tests/Expected/top/`, verified the same way the rest of the CLI's terminal output is verified.

## Rejected alternatives

- A web dashboard instead of a terminal screen: rejected because an operator already authenticates through the CLI's Gateway profile and trusted root certificate; a web dashboard would need its own hosting, session, and TLS story instead of reusing trust the CLI already has, and it would not compose with the CLI's other commands the way a screen sharing SDK requests and realtime state does.
- A read-only tail or log view instead of an interactive screen: rejected because `realtime:tail` already covers that use case; an operator watching the fleet also needs to act on what they see (restart a process, run a schedule) without leaving to another terminal.
- Ad hoc SDK polling per pane, with no shared state or event-application layer: rejected because every pane would reimplement reconciling a list against realtime events, and panes would drift out of sync with each other under the same screen.
- Always poll and ignore the realtime channel, since a fixed tick is simpler to reason about: rejected because [ADR 0084](/decisions/0084-broadcast-record-changes-through-reverb) exists for exactly this case, and polling every family fast enough to feel live does not scale with the number of open `orbit top` sessions.
- Skip the design-sketch step and build the real command's interaction directly: rejected because the CLI's existing discipline requires new interaction to be agreed and recorded as a flow before it exists as a real command, and `orbit top`'s interaction surface (sidebar, stacked pages, an actions menu, panel forms) was new enough to need that agreement first.

## Consequences

- Operators get one live, keyboard- and mouse-driven view of the whole fleet, current from realtime events where available and never more than one tick stale otherwise.
- Every action `orbit top` runs is exactly the request its matching command already sends, so its behavior (validation, confirmation semantics, error codes) never diverges from running that command directly.
- A deploy, rollback, or any command needing its own interactive terminal cannot run from inside the screen; an operator still leaves to run those, with the equivalent command printed for them.
- A deployment history, database users, or Node metrics pane can render its unavailable note on a Gateway that does expose the endpoint, whenever that one request fails (a timeout, a transient error), not only on a Gateway that lacks the route; the pane recovers on its own next poll rather than needing the operator to leave and reopen it. Node metrics shown this way are polled on the source's own interval, not streamed; only a `node.sample` realtime event updates the dashboard's compact metrics line live.
- New interaction on this screen carries the same design-sketch and recorded-flow overhead as any other new CLI interaction, which is slower than writing the interaction directly but keeps `apps/cli/design` as the enforceable spec for what the screen does.
- Screen snapshot tests under `apps/cli/tests/Expected/top/` must be updated deliberately (`ORBIT_EXPECTED=update`, reviewed) whenever the screen's rendering changes, the same discipline the rest of the CLI's expected-output tests already use.

## Affects

- Components: apps/cli, apps/docs
- ADRs: depends on [ADR 0084](/decisions/0084-broadcast-record-changes-through-reverb) for the realtime channel and events it consumes; none amended
- Detail: [`top`](/cli/top)
- Verify: `apps/cli/tests/Unit/Tui/ScreenTest.php`, `StateTest.php`, `InteractionTest.php`, `ActionRunnerTest.php`, `NodeFormStateTest.php`; screen snapshots under `apps/cli/tests/Expected/top/`; `apps/cli/design/README.md` and its recorded flows for the accepted `design:top` sketch
