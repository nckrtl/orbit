---
title: "extension"
description: "Enable or disable optional command families on the operator machine."
commands:
  - extension:list
  - extension:enable
  - extension:disable
---

An extension is a command family that stays hidden until you enable it locally. Extension state lives on the operator machine and composes only that client's command surface. Enabling one changes nothing on the Gateway, and Gateway routes for an extension stay registered whether or not any client enables it.

The CLI stores the enabled set in `$ORBIT_HOME/extensions.json`, next to the Gateway profiles in `config.json`. The file is private to your user; the CLI refuses to read or write it when it is a symlink, is not a regular file, or is readable by other users.

## Commands

| Command | Result |
| --- | --- |
| [`extension:list`](#orbit-extensionlist) | List optional Orbit CLI extensions and their state. |
| [`extension:enable`](#orbit-extensionenable) | Enable an extension and reveal its commands. |
| [`extension:disable`](#orbit-extensiondisable) | Disable an extension and hide its commands. |

Every command accepts `--json`. Enable and disable require an explicit extension slug in every mode. Human output shows the operation and its completed or failed result, including waiting feedback when another local command holds the configuration lock. JSON has no prompt or progress output.

## Available extensions

| Extension | Commands it reveals |
| --- | --- |
| `herdr` | The [`herdr`](/cli/herdr) family: named Herdr sessions and observation grants. |

{/* commands */}

## Related

- [`herdr`](/cli/herdr) is the one extension family.
- [Using the CLI](/cli/overview#select-a-gateway) describes `ORBIT_HOME` and the local configuration directory.
