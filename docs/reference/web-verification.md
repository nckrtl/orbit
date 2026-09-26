---
title: "Web verification"
description: "How bin/web-verify runs the web app in demo mode so an agent can open a route, click a control, and save phone and desktop screenshots."
---

# Web verification

This page is for an agent that changes the Orbit web app, and for the reviewer who judges that change. `bin/web-verify` runs `apps/web` in demo mode and drives it with Playwright. [ADR 0162](/decisions/0162-verify-web-ui-changes-with-bin-web-verify) records why this tool exists. The installed iPhone layout stays on the [web app](/reference/web-app) page.

Run every command from the repository root. Stdout is one JSON object and nothing else. Logs stay on stderr, and the demo server log is `.orbit-artifacts/web/server.log`.

## Commands

`routes` reads the feature map and does not start the app. The other commands share one demo server for the checkout. A dead server is started again. [Active page](#active-page) states which page `click` uses.

| Subcommand | What it does |
| --- | --- |
| `routes` | Prints the feature map. |
| `open <route>` | Opens that concrete path and makes its page active. |
| `click <selector>` | Clicks that selector on the active page. |
| `screenshot <route>` | Writes a PNG for that route, device, and engine. |
| `console-errors <route>` | Reports console errors for that route. |

`open`, `screenshot`, and `console-errors` take a concrete path. A concrete path contains no `$` segment. `/tasks/12` is concrete. `/tasks/$id` and `/$section` are only map patterns. Navigation rejects those strings with `unresolved-parameter`. Quote a route that contains `?`. `<selector>` is a Playwright selector. A mapped control is `[data-testid=VALUE]`, using the `testid` from the map.

The demo server is the `apps/web` dev server with `VITE_ORBIT_DEMO=1`, bound to `127.0.0.1` on a free port. Demo mode answers the API from the fixture fleet. It does not proxy to a Gateway. The command waits up to 60 seconds for the server to answer.

## Active page

`open` loads the concrete path and makes that page the active page. Each engine keeps the page last used for it. The default device is `desktop` and the default engine is `chromium`.

`click` takes no route and no engine. It clicks the active page and waits up to 5 seconds for the selector to be visible. With no active page, it fails.

The active page comes from the latest `open`. A `screenshot` that loads a route also makes its page the active page. A `screenshot` that recaptures a page already on that route and device leaves the active page unchanged. `console-errors` loads desktop Chromium in a second page, then closes it, and does not change the active page.

When both engines have a page, `click` still uses only the active one. `route` in its JSON is that page's concrete path, not a command argument. `pattern` is the map path that matched. `url`, `device`, and `engine` describe that page. After `open` on Chromium and a later `open` on WebKit, `click` targets the WebKit page. The Chromium page stays loaded. Run `open` on Chromium again before clicking it.

`screenshot` captures the page for its engine when that page already shows the same route and device. The picture includes a menu or dialog left by `click` on that page, and the active page stays as it was. Any other `screenshot` loads the route, makes that page active, and then captures.

## Options

`screenshot` requires both flags. `open` may omit them.

| Flag | Commands | Allowed values |
| --- | --- | --- |
| `--device` | `open`, `screenshot` | `iphone-15`, `pixel-8`, `desktop` |
| `--engine` | `open`, `screenshot` | `webkit`, `chromium` |

`iphone-15` uses the Playwright iPhone 15 descriptor: a 393 by 659 viewport, scale 3, touch, and the iPhone user agent. `pixel-8` uses the Pixel 8 descriptor: a 412 by 839 viewport, scale 2.625, touch, and the Pixel user agent. `desktop` is 1280 by 800, scale 1, no touch, and the engine's own user agent. These names are portrait only.

The tool uses the Playwright dependency of `apps/web`. A missing browser binary fails with `browser-missing`. Install both engines from `apps/web` with `bunx playwright install webkit chromium`.

## Safe-area insets

Playwright device emulation does not set `env(safe-area-inset-*)`. For the two phone devices, the tool sets `--safe-area-inset-*` on the document element before the first paint. The shell already applies those properties. [Web app](/reference/web-app) describes that hook. Desktop removes the properties, so a browser tab keeps zero shell padding.

| Device | Top | Right | Bottom | Left |
| --- | --- | --- | --- | --- |
| `iphone-15` | `0px` | `0px` | `34px` | `0px` |
| `pixel-8` | `0px` | `0px` | `24px` | `0px` |
| `desktop` | unset | unset | unset | unset |

The iPhone top value is 0 because the installed web view starts below the status bar. The 34px bottom value is the home indicator. The Pixel bottom value stands in for the gesture bar so the padding is visible.

## Screenshots

The PNG is `.orbit-artifacts/web/<slug>__<device>__<engine>.png`, relative to the repository root. The slug drops the leading slash and turns each `/` into `-`. The dashboard slug is `home`. A query string is part of the navigation and is not part of the file name. A new capture of the same path, device, and engine replaces the file. Git ignores `/.orbit-artifacts/`.

A UI change attaches two files for each route it changes. One uses `iphone-15` and `webkit`. The other uses `desktop` and `chromium`. The reviewer opens those files and judges layout and UX on the phone. Reading the diff is not that judgment. The [limits](#limits) below are part of the judgment.

## Feature map

`apps/web/feature-map.json` is the committed map. `routes` prints it. Each entry has these fields.

| Field | Rule |
| --- | --- |
| `path` | The TanStack path, such as `/` or `/tasks/$id`. |
| `purpose` | What the page is for, in one sentence. |
| `reach` | How a person gets there from the main nav. |
| `controls` | At least one control on that page. |

Each control is an object with `testid` and `purpose`. `testid` is the `data-testid` value, matching `[a-z0-9]+(-[a-z0-9]+)*`. It is not a CSS selector.

A pattern such as `/$section` is one entry. It is not one entry per section name. The `purpose` names the sections that pattern serves. A static path such as `/tasks` is its own entry, even though a dynamic pattern also fits that URL.

The map stores patterns. `routes` prints them. `open`, `screenshot`, and `console-errors` do not accept a pattern. Replace each `$param` with one segment that is not empty and contains no slash. `/tasks/12` matches `/tasks/$id`, not `/$section/$id`, because `tasks` is static. An exact static path such as `/tasks` matches that map entry. Two still-tied patterns are `ambiguous-route`.

The demo fleet includes task group 12 and its subtask 31, activity 150, node 1, and deployment 1 on instance 1. `/tasks/12`, `/tasks/12/subtasks/31`, `/activity/150`, `/nodes/1`, and `/instances/1/deployments/1` open those records. An id the fleet does not contain still loads the route, and the page shows its missing-record state.

```json
{
  "path": "/tasks/$id",
  "purpose": "One task group.",
  "reach": "Open Tasks in the main nav, then choose a group.",
  "controls": [
    { "testid": "task-group-title", "purpose": "The group title." }
  ]
}
```

That object shows the map shape. Pass `/tasks/12` to `open`. Do not pass `/tasks/$id`. The file in the repository lists the real routes and the real test ids.

A unit test in `apps/web` builds the app router and reads every route `fullPath` except the root layout route. That set must equal the `path` values in the map. A router path missing from the map fails the test. A map path the router does not have fails the test.

## Output

Exit 0 means `"ok": true`. Exit 1 means the command ran and failed. Exit 2 means the command line is wrong. The failure object is still on stdout.

| Field | When it is present |
| --- | --- |
| `ok` | Always. |
| `command` | Always. The subcommand name. |
| `route` | On `open`, `screenshot`, and `console-errors`, the concrete path argument. On `click`, the concrete path of the active page. |
| `pattern` | The map path that matched. |
| `url` | The absolute page URL. |
| `device` | The device used. |
| `engine` | The engine used. |
| `routes` | On `routes`, the map's route array. |
| `selector` | On `click`, the selector argument. |
| `file` | On `screenshot`, the PNG path from the repository root. |
| `viewport` | On `screenshot`, `width` and `height` in CSS pixels. |
| `reused` | On `screenshot`, true when that engine's page already showed the route and device. |
| `errors` | On `console-errors`, each `{source, message}`. |
| `error` | On failure, the code in the table below. |
| `message` | On failure, one sentence. |
| `next` | On failure, the command or edit that fixes it. |

`console-errors` succeeds only when `errors` is empty. `source` is `console` for `console.error` and `pageerror` for an uncaught exception. A warning does not fail the command.

| `error` | Exit | What failed |
| --- | --- | --- |
| `usage` | 2 | The flags or arguments are wrong or missing. |
| `unknown-route` | 1 | The path matches no map entry. |
| `ambiguous-route` | 1 | The path matches two map entries. |
| `no-page` | 1 | `click` ran with no active page. |
| `unresolved-parameter` | 1 | The path still contains a `$` segment. |
| `selector-missing` | 1 | The selector matched nothing visible. |
| `console-errors` | 1 | The page reported a console or page error. |
| `navigation-failed` | 1 | The route did not load. |
| `server-failed` | 1 | The demo server did not answer. |
| `browser-missing` | 1 | The Playwright browser is not installed. |
| `map-invalid` | 1 | The feature map breaks the schema above. |

`next` names the recovery. `usage` includes the allowed devices and engines. `unknown-route` and `ambiguous-route` tell the agent to run `bin/web-verify routes`. `no-page` tells the agent to run `open` first. `unresolved-parameter` tells the agent to replace each `$name` with one segment, such as `open /tasks/12` instead of `open /tasks/$id`. `selector-missing` tells the agent to use a `testid` from the map, or to add the missing `data-testid` on the control and in the map. `browser-missing` includes the install command above. `map-invalid` names the first broken field.

## Limits

The phone PNG uses the Playwright layout viewport, not the device screen. iPhone 15 is 393 by 659 CSS pixels. Pixel 8 is 412 by 839. An installed app's web view is taller than that viewport.

WebKit device emulation catches layout, viewport, and safe-area padding mistakes. It does not catch every iOS home-screen quirk.

| Visible in the PNG | Not visible in the PNG |
| --- | --- |
| Layout at the viewport above | The installed home-screen web view |
| Shell padding from the inset table | Standalone display mode |
| The narrow menu in place of the full nav | The iOS 26 status-bar dead band |
| Console errors from `console-errors` | A physical phone |

The status-bar failure is [WebKit bug 301108](https://bugs.webkit.org/show_bug.cgi?id=301108). The tool also does not apply `apple-mobile-web-app-status-bar-style`, and it does not rotate the phone. Those checks stay on a real install, as [Web app](/reference/web-app) describes.
