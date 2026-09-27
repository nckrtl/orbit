---
title: "ADR 0166: Raise PHPStan one level at a time"
sidebarTitle: "0166 Raise PHPStan by level"
description: "Proposed. Raise PHPStan from level 6 to level 9 in all five PHP projects, one level at a time. A level is complete only when every project passes it, and the next level starts only after that group merges."
---

# ADR 0166: Raise PHPStan one level at a time

Orbit raises PHPStan from level 6 to level 9 in `apps/gateway`, `apps/cli`, `packages/php-sdk`, `apps/e2e`, and `apps/docs`. One level is one Task group. That level is complete only when every project passes it. The next level starts only after that group's pull request merges. A fix does not add an ignore, a baseline, `assert()`, an inline `@var`, a silencing cast, or a wider type.

## Status

Proposed.

## Context

Every PHP project runs static analysis at level 6. Laravel projects use Larastan. The SDK uses PHPStan directly. `composer analyse` reads `level` from each project's `phpstan.neon`. [Feature delivery](/reference/implementation-loop) records the paths and the level.

Stricter levels report type errors that level 6 accepts. Those errors are escapes: a value that reaches review with the wrong type. Catching them in CI is part of tightening checks before auto-merge. A reviewer then spends the turn on behavior, not on a type the analyzer already knows.

On 2026-09-26, PHPStan reported these error totals at each level. The total is every error that level reports, including errors from the levels below it.

| Level | Gateway | CLI | SDK | E2E | Docs |
| --- | --- | --- | --- | --- | --- |
| 7 | 74 | 21 | 3 | 14 | 0 |
| 8 | 106 | 27 | 3 | 17 | 0 |
| 9 | 438 | 81 | 25 | 98 | 2 |

Level 7 is the smaller step. Level 9 reported 438 errors in the Gateway, against 74 at level 7. One pull request for all three levels would mix that Gateway total with the CLI, SDK, and E2E totals. Docs reported 0 errors at levels 7 and 8, and 2 at level 9.

CLI and E2E already record counted `ignoreErrors` for Larastan findings on inherited command helpers. Each entry names the command, the input, the file, and the count. Unmatched entries fail analysis. That list is not a way to climb a level.

## Decision

- The shared level moves from 6 to 9 in this order: 7, then 8, then 9. All five `phpstan.neon` files change in the same pull request. No project moves ahead of the others.
- A level is complete only when `composer analyse` passes in `apps/gateway`, `apps/cli`, `packages/php-sdk`, `apps/e2e`, and `apps/docs` at that level.
- Each level is one Task group and one pull request. The next level starts only after that group merges. Until that merge, `level` on main stays at the last merged level. That level is 6.
- The ramp exists so stricter types catch escapes before review. That is part of tightening CI before auto-merge.
- A finding is fixed in the code that produces the value PHPStan reports. The fix makes the declared type and the runtime value agree.
- The fix does not add or widen `ignoreErrors`. It does not add `@phpstan-ignore`. It does not add a PHPStan baseline.
- The fix does not add `assert()` to narrow a type for the analyzer. It does not add an inline `@var` that replaces the inferred type.
- The fix does not add a cast whose only effect is to silence the finding. It does not widen a declared type so the finding no longer applies.
- The counted `ignoreErrors` entries already in CLI and E2E stay at their recorded commands, inputs, files, and counts. A level finding does not become a new entry, and an existing count does not grow to hide one.

## Rejected alternatives

- Raise all three levels in one pull request: level 9 alone reported 438 Gateway errors on 2026-09-26. One review cannot settle that set, and the open pull request would hold every other PHP change.
- Raise one project before the others: the five projects share one CI gate. A Gateway at level 9 beside a CLI at level 6 is not one level. The level is complete only when every project passes it.
- Keep level 6 and store levels 7 to 9 in a baseline: a baseline keeps the errors. New code can repeat them. The ramp exists to remove the errors before review.
- Clear a finding with an ignore, `assert()`, an inline `@var`, a silencing cast, or a wider type: the report stops, and the value is unchanged. Review then misses the escape the level was raised to catch.
- Stay at level 6: level 6 does not report the errors measured above. Those errors are the escapes CI has to catch before auto-merge.
- Continue past level 9: this ramp stops at level 9. That is the level measured here, and it is already the large step.

## Consequences

- Main stays at level 6 until the level 7 pull request merges. After that merge, main is level 7. Level 8 starts only then. Level 9 starts only after level 8 merges.
- The level 7 group fixes the 2026-09-26 totals: 74 Gateway, 21 CLI, 3 SDK, 14 E2E, and 0 Docs. Docs still sets `level: 7` in that same merge so the five files stay aligned.
- Level 8 and level 9 are their own Task groups. Their counts on 2026-09-26 were the totals in the table above. The counts can fall as earlier levels land, because a higher level includes the errors below it.
- A pull request that only raises one project's level does not complete a level.
- A fix that silences a finding does not complete a level, even when `composer analyse` exits 0.
- The counted command-helper exceptions in CLI and E2E remain. They are not a pattern for new findings.
- `composer check` keeps running `composer analyse`. CI fails a project that does not pass the shared level.

## Affects

- Components: apps/cli, apps/docs, apps/e2e, apps/gateway, packages/php-sdk
- ADRs: none
- Detail: [Contributor guide](/contributor-guide#static-analysis)
- Verify: `composer docs-lint`; each level is complete when `composer analyse` passes in all five projects at that level
