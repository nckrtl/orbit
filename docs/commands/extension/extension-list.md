---
title: "extension:list"
description: "List optional Orbit CLI extensions and their state."
---

List every known extension with its local state.

```bash
orbit extension:list [--json]
```

```bash
orbit extension:list
```

Human output is one table with the columns EXTENSION and STATE. Narrow terminals wrap cells or use labeled records when a table cannot fit. JSON output returns an `extensions` array with `extension` and `enabled` for each entry.
