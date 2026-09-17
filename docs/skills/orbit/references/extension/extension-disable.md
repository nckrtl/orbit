---
title: "extension:disable"
description: "Disable an extension and hide its commands."
---

# extension:disable

Disable one extension. Its commands disappear from `orbit list`, and running one directly fails with `extension.disabled`.

```bash
orbit extension:disable <extension> [--json]
```

| Argument | Required | Meaning |
| --- | --- | --- |
| `extension` | yes | Extension slug. |

```bash
orbit extension:disable herdr
```

Disabling an already disabled extension succeeds. JSON returns `extension` and `enabled: false`. Unknown slugs and invalid configuration use the same errors as enable.

> **Note:** Disabling an extension does not stop, remove, or change anything the extension created on a Node. A Herdr session keeps running after you disable the `herdr` extension; use `herdr:session:destroy` first if you want it gone.
