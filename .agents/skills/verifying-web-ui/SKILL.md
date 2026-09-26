---
name: verifying-web-ui
description: Use when an Orbit web UI change needs phone and desktop screenshots, or when a review must judge that layout.
---

# Verifying Web UI

Verify a web app change with `bin/web-verify`. The command runs `apps/web` in demo mode and drives it with Playwright. The contract is [Web verification](../../../docs/reference/web-verification.md). [ADR 0162](../../../docs/decisions/0162-verify-web-ui-changes-with-bin-web-verify.md) records the decision.

## Capture evidence

Run the commands from the repository root. Use `bin/web-verify routes` to read the map patterns in `apps/web/feature-map.json` before inventing a selector.

Pass a concrete path to `open`, `screenshot`, and `console-errors`. Do not pass a pattern that still contains `$`, such as `/tasks/$id` or `/$section`. Replace each parameter with one segment. The demo fleet includes task group 12, subtask 31, activity 150, node 1, and deployment 1 on instance 1, so `/tasks/12` and `/tasks/12/subtasks/31` open real records. `unresolved-parameter` means the path still has a `$`. Follow `next`.

For each route the change affects, save two screenshots:

- `bin/web-verify screenshot <route> --device=iphone-15 --engine=webkit`
- `bin/web-verify screenshot <route> --device=desktop --engine=chromium`

`click` takes no route and no engine. It clicks the active page. That page is the latest `open`, or the latest `screenshot` that loaded a route. A screenshot that only recaptures the page already on that route and device does not change the active page. The JSON `route` from `click` is the active page's concrete path. Open the phone page last when the click belongs on the phone, then screenshot with `iphone-15` and `webkit` so the picture includes the click.

Attach the PNG paths the JSON `file` field prints. The files live under `.orbit-artifacts/web/` and are not committed. Use `console-errors` for the same concrete paths. A failure's `next` field is the recovery. Fix the page or the feature map, then run the command again.

## Review the pictures

The reviewer opens the phone PNG and the desktop PNG and judges layout and UX on the phone. Reading the diff is not that judgment. Check the header, the main nav or the phone menu, the main control, and the footer. Look for overflow, clipped text, and controls that sit on top of each other.

WebKit device emulation catches layout, viewport, and safe-area mistakes. It does not catch every iOS home-screen quirk. Say that limit when you report the result. The reference lists what the PNG cannot show.

## Report

Return the routes checked, the screenshot paths, what you saw, and the emulation limit. Record the commit. After a fix, capture the affected routes again.
