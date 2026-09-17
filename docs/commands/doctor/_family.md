---
title: "doctor"
description: "Compare what the Gateway expects with what is on each Node and report every difference without repairing anything."
commands:
  - doctor
---

Doctor is one command. It asks the Gateway to compare stored intent with a bounded, read-only observation of each registered Node and returns one report. Doctor never converges, installs, removes, starts, stops, restarts, adopts, or otherwise changes managed state. [ADR 0004](/decisions/0004-verify-only-doctor-boundary) records that boundary.

## Commands

| Command | Result |
| --- | --- |
| [`doctor`](#orbit-doctor) | Verify registered Node state without making repairs. |

The command accepts `--json`.

{/* commands */}

## Related

- [`activity`](/cli/activity) finds the Activity row that a Doctor request created.
- [`node`](/cli/node) converges a Node or role again after Doctor reports drift.
- [`process`](/cli/process) and [`schedule`](/cli/schedule) start, stop, or replace the resources that Doctor compares.
- [`route`](/cli/route) creates and removes the custom proxy Routes the `route` family inspects.
