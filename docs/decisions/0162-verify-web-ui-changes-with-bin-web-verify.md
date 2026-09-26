---
title: "ADR 0162: Verify web UI changes with bin/web-verify"
sidebarTitle: "0162 Verify web UI with bin/web-verify"
description: "Proposed. Agents verify web UI changes with bin/web-verify, which runs the web app in demo mode and drives it with Playwright. Reviewers open the phone and desktop screenshots."
---

# ADR 0162: Verify web UI changes with bin/web-verify

Agents verify an Orbit web UI change with `bin/web-verify`. The command runs `apps/web` in demo mode and drives the page with Playwright. A feature map names every route. The reviewer opens the phone and desktop screenshots and judges the phone layout.

## Status

Proposed.

## Context

An agent that changes the web app cannot see the page. Waiting for an operator to tap through a phone makes that person the bottleneck. The agent needs a way to open the page, click a control, and save a picture of the result.

The Vitest browser tests already render `apps/web` in Chromium at 1280 by 800 and check behavior. They do not show an iPhone-sized WebKit layout. They keep no PNG for a reviewer to open.

`apps/web` already supports demo mode through `VITE_ORBIT_DEMO=1`. That mode answers API calls from the fixture fleet and does not start the Gateway proxy. Playwright is already a dependency of `apps/web`. The shell already honors `--safe-area-inset-*` on the document element, which is how the browser tests simulate a device. [Web app](/reference/web-app) describes the installed iPhone layout, including a top inset of 0 and the home-indicator padding.

WebKit device emulation can show a phone viewport and that safe-area padding. It cannot show every home-screen quirk. [WebKit bug 301108](https://bugs.webkit.org/show_bug.cgi?id=301108) is one of those quirks.

## Decision

- `bin/web-verify` is the web verification tool. It runs the `apps/web` dev server with `VITE_ORBIT_DEMO=1` and drives that origin with Playwright from `apps/web`. It does not call a Gateway, and demo mode does not run dev adapters that call the CLI or another upstream service. The browser stays on the demo origin. It is shown the checked response, and a redirect is followed only when every hop stays on that origin.
- The subcommands are `routes`, `open`, `click`, `screenshot`, and `console-errors`. Stdout is one JSON object. A failure carries `next`, which tells the agent the command or edit that fixes it.
- `open`, `screenshot`, and `console-errors` take a concrete path. A path that still contains `$` fails. `click` uses the active page, which is the page from the latest `open`, or from the latest `screenshot` that loaded a route.
- `screenshot` writes a PNG under `.orbit-artifacts/web/`. Git ignores `/.orbit-artifacts/`.
- Phone devices are `iphone-15` and `pixel-8`. Desktop is `desktop`. Engines are `webkit` and `chromium`. A phone shot sets `--safe-area-inset-*` to the portrait insets in the reference. Desktop leaves those properties unset.
- `apps/web/feature-map.json` lists every router path, what the page is for, how a person reaches it from the main nav, and the `data-testid` values of its main controls. A unit test in `apps/web` fails when a router path is missing from the map, or the map names a path the router does not have.
- A UI change attaches one `iphone-15` screenshot taken with `webkit` and one `desktop` screenshot taken with `chromium` for each route it changes. The reviewer opens those files and judges layout and UX on the phone. Reading the diff is not that judgment.
- WebKit device emulation catches layout, viewport, and safe-area padding mistakes. It does not catch every iOS home-screen quirk. The reference states that limit.

The command syntax, JSON fields, insets, and error codes live in [Web verification](/reference/web-verification).

## Rejected alternatives

- Ask an operator to open the app on a phone: rejected because the operator is then the bottleneck for every UI change.
- Treat the Vitest browser tests as the only UI check: rejected because they use Chromium at 1280 by 800 and keep no PNG for a reviewer.
- Drive the live Gateway origin: rejected because that needs WireGuard trust and live fleet data. Demo mode is local, and the fixture fleet does not change between runs.
- Fail the check on a pixel diff of the PNGs: rejected because font and animation noise would reject a correct layout. A reviewer judges the pictures.
- Require a physical iPhone for every UI change: rejected because that cost is too high for each change. The emulation limit is stated instead of hidden.

## Consequences

- An agent can open a route, click a control, and save a phone picture without a Gateway.
- A new or renamed route fails the web test until the feature map lists it.
- Review of a UI change includes the phone and desktop PNGs, not the diff alone.
- This check leaves out home-screen quirks. [Web app](/reference/web-app) still describes the installed app.
- Screenshot files stay on the machine that ran the command. They are not committed.

## Affects

- Components: apps/docs, apps/web
- ADRs: none
- Detail: [Web verification](/reference/web-verification)
- Verify: `composer docs-lint`; the `apps/web` unit test that compares router paths with `apps/web/feature-map.json`; `bin/web-verify routes` prints that map
