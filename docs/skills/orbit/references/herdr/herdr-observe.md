---
title: "herdr:observe"
description: "Issue one observation grant that is short-lived and receive-only."
---

# herdr:observe

Issue one short-lived, receive-only observation grant for one recorded pane and its expected terminal.

```bash
orbit herdr:observe <session> --node=NODE --pane=PANE --terminal=TERMINAL --cols=COLS --rows=ROWS --origin=ORIGIN [--json]
```

| Argument | Required | Meaning |
| --- | --- | --- |
| `session` | yes | Named Herdr session. |

| Option | Meaning |
| --- | --- |
| `--node=NODE` | Node ID or registered name. |
| `--pane=PANE` | Recorded pane identity. At most 64 characters of letters, digits, `.`, `_`, `:`, and `-`. |
| `--terminal=TERMINAL` | Expected terminal identity, with the same form as the pane. |
| `--cols=COLS` | Viewport columns, from 20 through 400. |
| `--rows=ROWS` | Viewport rows, from 5 through 200. |
| `--origin=ORIGIN` | HTTPS browser origin allowed to use the grant, such as `https://tasks.example.test`. |

```bash
orbit herdr:observe commander-tasks \
  --node=beast \
  --pane=w1:p1 \
  --terminal=term-abc \
  --cols=120 \
  --rows=40 \
  --origin=https://tasks.commander.test
```

The Gateway returns a private `wss://` URL that carries the grant and its expiry. The grant is a signed token that expires after 60 seconds, permits only `terminal.observe` for the named pane and terminal within the bounded viewport, is tied to the one HTTPS origin, and can be used once. The Node adapter refuses input, control, arbitrary pane selection, an expired grant, a wrong Node or session, an origin mismatch, a pane-to-terminal mismatch, and a reused grant. Reconnecting means asking for a new grant.

A request that lacks a pane, terminal, viewport bound, or HTTPS origin fails with `herdr.grant_invalid` before the CLI sends it.
