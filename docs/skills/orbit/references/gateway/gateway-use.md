---
title: "gateway:use"
description: "Select the active profile."
---

# gateway:use

Select an existing profile as the active Gateway. The command changes no certificate pin and no trust store.

```bash
orbit gateway:use <name>
```

| Argument | Required | Meaning |
| --- | --- | --- |
| `name` | yes | Local profile name. |

```bash
orbit gateway:use production
```

Supply the profile name explicitly in every mode. An unknown name returns `gateway.profile_not_found`. Human output shows the selection operation and its result. JSON returns `active_gateway` with the selected name.
