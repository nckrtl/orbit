---
title: "tool"
description: "Install, update, inspect, and remove packages on a Node through the apt, Vite+, Composer, or Homebrew Tool Managers."
commands:
  - tool:manager:list
  - tool:install
  - tool:list
  - tool:show
  - tool:update
  - tool:remove
---

A Tool is one manager-native package that Orbit manages on one Node. Its identity is the Node, the Tool Manager, and the package. The `tool` family lists the managers a Node supports, installs a package, updates or removes it, and shows the recorded state.

The [Tools reference](/reference/tools) owns manager lifecycle, first-use provisioning, Homebrew limits, removal outcomes, and Doctor findings.

## Tool Managers

Orbit exposes four code-owned managers on active managed Ubuntu Nodes. A manager is independent of Node roles, and the Gateway provisions it on first use.

| Manager | Package scope | Availability |
| --- | --- | --- |
| `apt` | The Node's Advanced Package Tool database | Materialized with the managed Node baseline |
| `vp` | Orbit's shared Vite+ global package scope | Materialized on first use or when a role requires it |
| `composer` | Orbit's shared Composer global package scope | Materialized on first use or when a role requires it |
| `brew` | Orbit's shared Homebrew prefix at `/home/linuxbrew/.linuxbrew` | Materialized or recognized on first use |

Use `vp` for npm-compatible global tools such as Codex and Claude Code, `composer` for `vendor/package` tools, `apt` for Ubuntu packages, and `brew` for one unqualified Homebrew Core formula with a Linux bottle.

## Commands

| Command | Result |
| --- | --- |
| [`tool:manager:list`](#orbit-toolmanagerlist) | List the managers a Node supports and their status. |
| [`tool:install`](#orbit-toolinstall) | Install one package, provisioning its manager first when needed. |
| [`tool:list`](#orbit-toollist) | List the Tools recorded on a Node. |
| [`tool:show`](#orbit-toolshow) | Show one Tool. |
| [`tool:update`](#orbit-toolupdate) | Update one Tool within its constraint. |
| [`tool:remove`](#orbit-toolremove) | Remove one Tool and delete its record. |

Every command accepts `--json`. `--node` is a positive numeric Node ID; the CLI rejects a name with `tool.node_id_invalid`.

{/* commands */}

## Related

- [`node`](/cli/node) provisions the managed Node that hosts the managers.
- [`herdr`](/cli/herdr) requires the `herdr` formula installed through `brew` on the target Node.
- [`doctor`](/cli/doctor) reports Tool drift without changing packages.
