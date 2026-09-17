---
title: "tool:install"
description: "Install one package, provisioning its manager first when needed."
---

# tool:install

Install one manager-native package through the Gateway.

```bash
orbit tool:install [package] --node=ID --manager=MANAGER [--constraint=CONSTRAINT] [--json]
```

| Argument | Required | Meaning |
| --- | --- | --- |
| `package` | in non-interactive calls | Manager-native package coordinate of at most 255 characters. An interactive terminal prompts for it when omitted. |

| Option | Meaning |
| --- | --- |
| `--node=ID` | Numeric target Node ID. |
| `--manager=MANAGER` | Tool Manager name. An interactive terminal offers the Node's `active` and `uninstalled` managers when omitted; a non-interactive or `--json` call fails with `tool.manager_required`. |
| `--constraint=CONSTRAINT` | Optional SemVer safety constraint. It only blocks an unsafe candidate; the Gateway owns every package-manager and version decision. |

```bash
orbit tool:install @openai/codex --node=12 --manager=vp --constraint='^0.150'
orbit tool:install laravel/installer --node=12 --manager=composer
orbit tool:install herdr --node=12 --manager=brew
```

When the manager is `uninstalled` or `failed`, the Gateway provisions or retries it first and records it `active` before it changes Tool intent. A failed provisioning returns `tool.manager_provision_failed`, keeps the bounded failure on the manager, and creates no Tool row; repeating the command retries from live Node state. The result reports `applied` for a new install and `unchanged` when the package is already installed.

> **Note:** The Gateway refuses every Tool mutation with `tool.node_unmanaged` on a roleless operator client or any Node outside Gateway-owned SSH management. When an install creates a Tool row and then fails, for example with `tool.version_probe_failed`, the error names the Tool ID so you can retry or remove it.

The `brew` manager accepts one unqualified lowercase Homebrew Core formula with a stable version and a Linux bottle for the Node architecture. It rejects a tap-qualified formula, cask, URL, local definition, Git reference, or caller option before package mutation, and it never builds from source.
