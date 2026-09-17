---
title: "profile"
description: "Profile one HTTP request from the operator machine and show timings, without using the Gateway."
commands:
  - profile
---

`profile` sends one GET from this machine to an absolute HTTP or HTTPS URL and reports timings. It does not use a Gateway profile, the Gateway API, or a registered App target. [Gateway profiles](/cli/gateway) remain a separate local configuration family.

The command is local-only. It never opens an SSH session and never asks the Gateway to issue the request.

## Commands

| Command | Result |
| --- | --- |
| [`profile`](#orbit-profile) | Profile one HTTP request from this machine. |

The command accepts `--json`.

{/* commands */}

## Related

- [`gateway`](/cli/gateway) registers and selects Gateway connection profiles. Those profiles are unrelated to this command.
- [`doctor`](/cli/doctor) compares Gateway intent with Node state and does not profile an HTTP request.
