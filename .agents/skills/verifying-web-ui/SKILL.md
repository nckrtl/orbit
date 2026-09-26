---
name: verifying-web-ui
description: Use when an Orbit web UI change needs phone and desktop screenshots, or when a review must judge that layout.
---

# Verifying Web UI

Verify a web app change with `bin/web-verify`. The command runs `apps/web` in demo mode and drives it with Playwright. Stdout is one JSON object and nothing else. The contract is [Web verification](../../../docs/reference/web-verification.md). [ADR 0162](../../../docs/decisions/0162-verify-web-ui-changes-with-bin-web-verify.md) records the decision.

## Capture evidence

Run the commands from the repository root. Use `bin/web-verify routes` to read the map patterns in `apps/web/feature-map.json` before choosing a selector. A mapped control is `[data-testid=VALUE]`, using the `testid` from that map.

Pass a concrete path to `open`, `screenshot`, and `console-errors`. Do not pass a pattern that still contains `$`, such as `/tasks/$id` or `/$section`. Replace each parameter with one segment. The demo fleet includes task group 12, subtask 31, activity 150, node 1, and deployment 1 on instance 1, so `/tasks/12` and `/tasks/12/subtasks/31` open real records. A path that still has a `$` returns exit 1, `"error": "unresolved-parameter"`, and `"next": "Replace each $name with one segment, such as open /tasks/12 instead of open /tasks/$id."` Follow `next` on every failure.

For each route the change affects, save two screenshots. `screenshot` requires both flags. A missing flag is `"error": "usage"` and exit 2. Its `next` is `"Devices are iphone-15, pixel-8, or desktop. Engines are webkit or chromium."`

- `bin/web-verify screenshot <route> --device=iphone-15 --engine=webkit`
- `bin/web-verify screenshot <route> --device=desktop --engine=chromium`

A phone success for `/activity` prints `"file": ".orbit-artifacts/web/activity__iphone-15__webkit.png"` and `"viewport": { "width": 393, "height": 659 }`. Desktop is 1280 by 800, and its file ends in `activity__desktop__chromium.png`. Attach the `file` path. The files live under `.orbit-artifacts/web/` and are not committed. `"reused": true` means that engine's page was already on the route and device.

`click` takes no route and no engine. It clicks the active page. A success prints that page's `route`, `pattern`, `device`, `engine`, and `selector`. The active page is the latest `open`, or the latest `screenshot` that loaded a route. A recapture (`"reused": true`) leaves the active page unchanged, and the picture still includes a menu or sheet left by `click`. `open` makes its page active even when that page already shows the route. Open the phone page last when the click belongs on the phone, then screenshot again with `iphone-15` and `webkit`.

```bash
bin/web-verify open /activity --device=iphone-15 --engine=webkit
bin/web-verify click '[data-testid=activity-filters]'
bin/web-verify screenshot /activity --device=iphone-15 --engine=webkit
```

That sheet click keeps `route` as `/activity`, so the screenshot recaptures the open sheet. A filter control can add a query. From an unfiltered page, `click` on `[data-testid=activity-filter-status]` prints `"route": "/activity?status=running"`. Pass that `route` to the next `screenshot`. The bare path loads `/activity` again, `"reused"` is false, and the sheet is gone. The file name drops the query, so both writes are `.orbit-artifacts/web/activity__iphone-15__webkit.png`.

Use `console-errors` for the same concrete paths. It succeeds only when `errors` is `[]`, and it does not change the active page. `selector-missing` means nothing visible matched, or the selector matched more than one element. Its `next` is `"Use a testid from the feature map, or add the missing data-testid on the control and in the map."`

## Review the pictures

The implementer looks before handing the pictures over. The reviewer must open the phone PNG and the desktop PNG and judge layout and UX on the phone. Reading the diff is not that judgment.

On the phone, the reviewer must look for these:

- The log or the content starts near the top. In the 393 by 659 iPhone picture, a tall header or a stack of filters must not push it down.
- Controls are reachable, including the header menu, the main control, and the footer.
- There are no awkward stacked filters. A set of filters opens from one header control into a sheet. Activity does this with `[data-testid=activity-filters]`.
- The page uses native patterns, such as infinite scroll for a long list and a sheet for filters or a dialog. It does not use Older and Newer buttons.

Also look for overflow, clipped text, and controls that sit on top of each other.

WebKit device emulation catches layout, viewport, and safe-area padding mistakes. It does not catch every iOS home-screen quirk. Say that limit when you report the result. The reference lists what the PNG cannot show.

## Report

Return the routes checked, each `file` path, what you saw on the phone, and the emulation limit. Record the commit. After a fix, capture the affected routes again.
