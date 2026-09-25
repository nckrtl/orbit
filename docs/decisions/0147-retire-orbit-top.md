---
title: "ADR 0147: Retire orbit top"
sidebarTitle: "0147 Retire orbit top"
description: "Proposed. The CLI drops the orbit top terminal screen, its php-tui dependency, and its direct Grafana metrics reader. The web app is the live fleet view. Supersedes ADR 0085 and amends ADR 0088 and ADR 0123."
---

# ADR 0147: Retire orbit top

The CLI no longer has an `orbit top` command. The [web app](/reference/web-app) is Orbit's live view of the fleet. The Gateway API, the realtime channel, and the SDK stay as they are, because the web app and other commands use them.

## Status

Proposed.

## Context

[ADR 0085](/decisions/0085-build-orbit-top-as-a-thin-tui-client) built `orbit top` as a php-tui screen. [ADR 0123](/decisions/0123-serve-the-web-app-from-the-gateway-origin) then served the web app from the Gateway origin, as a second client of the same API and realtime channel. The web app now shows the same sections, record pages, metrics, and actions in a browser, and it adds live Node presence from [ADR 0129](/decisions/0129-publish-node-presence-and-process-state-on-per-node-presence-channels).

The maintainer uses only the web app. The terminal screen still costs maintenance: 22 support classes, a php-tui dependency in every CLI build, screen snapshot tests, and a CLI copy of the Prometheus query set and mapper that [ADR 0088](/decisions/0088-cli-reads-display-metrics-from-grafana) required to match the Gateway's copy.

Every Gateway request `orbit top` sent also belongs to another CLI command or to the web app. No Gateway endpoint exists only for `orbit top`.

## Decision

- The CLI removes the `top` command, `App\Support\Tui`, its tests and screen snapshots, and the `php-tui/php-tui` dependency.
- The CLI removes `App\Support\Metrics`, its copy of the Prometheus queries and mapper. The CLI reads Node metrics only through `node:metrics`.
- The CLI removes `GatewayCommand::poolSend()`, which only `orbit top` used.
- The Gateway, the SDK, the MCP tools, and the realtime channel do not change. `realtime:tail` and `realtime:show` keep the shared realtime subscriber.

This decision supersedes ADR 0085 and amends two others:

- [ADR 0088](/decisions/0088-cli-reads-display-metrics-from-grafana): the CLI no longer reads display metrics from Grafana. The web app reads them through the Gateway's `/grafana` path. The Gateway's own `node:metrics` reader does not change.
- [ADR 0123](/decisions/0123-serve-the-web-app-from-the-gateway-origin): the web app replaces `orbit top` as the live fleet view.

## Rejected alternatives

- Keep `orbit top` for terminal-only use: rejected because the web app works from every operator machine on the WireGuard network, and nobody uses the terminal screen.
- Hide the command behind a local extension: rejected because the code, tests, and php-tui dependency would stay, and every change to a shared request or realtime event would still have to keep the screen working.

## Consequences

- The CLI binary is smaller and has one dependency fewer.
- A new fleet view feature lands once, in the web app.
- An operator without a browser on the WireGuard network watches the fleet with the list commands and `realtime:tail`.
- Node metrics for display have one query set and mapper on each side of the API: the Gateway's in PHP and the web app's in TypeScript.

## Affects

- Components: apps/cli, apps/docs
- ADRs: supersedes [ADR 0085](/decisions/0085-build-orbit-top-as-a-thin-tui-client); amends [ADR 0088](/decisions/0088-cli-reads-display-metrics-from-grafana) and [ADR 0123](/decisions/0123-serve-the-web-app-from-the-gateway-origin)
- Detail: [CLI overview](/cli/overview), [web app](/reference/web-app), [metrics](/reference/metrics)
- Verify: `apps/cli` `tests/Feature/CommandSurfaceTest.php`; `php orbit list` shows no `top` command
