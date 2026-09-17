---
title: "node:settings"
description: "Update the typed storage settings of one Node."
---

# node:settings

Update the typed storage settings of one Node. The only setting path is `apps.path`, the root under which the Gateway creates App instance checkouts.

```bash
orbit node:settings <node> --setting=apps.path:/mnt/apps [--json]
```

| Argument | Required | Meaning |
| --- | --- | --- |
| `node` | yes | Node ID or registered name. |

| Option | Meaning |
| --- | --- |
| `--setting=PATH:VALUE` | Repeatable setting. The CLI splits at the first colon, so a value may contain more colons. An empty value unsets the setting: `--setting=apps.path:`. |

The command requires at least one `--setting` option. Changing the apps root does not move, rewrite, or delete an existing checkout; the [Node settings reference](https://orbit.nckrtl.com/docs/reference/node-settings.md) lists the validation and failure codes.
