---
title: "extension:enable"
description: "Enable an extension and reveal its commands."
---

# extension:enable

Enable one extension. Its commands appear in `orbit list` and run on the next invocation.

```bash
orbit extension:enable <extension> [--json]
```

| Argument | Required | Meaning |
| --- | --- | --- |
| `extension` | yes | Extension slug, for example `herdr`. |

```bash
orbit extension:enable herdr
```

An unknown slug fails with `extension.unknown`. A configuration file that is malformed or not private fails with `extension.config_invalid` and leaves the file unchanged. Enabling an already enabled extension succeeds. JSON returns `extension` and `enabled: true`.
