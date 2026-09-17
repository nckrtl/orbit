---
title: "extension:list"
description: "List optional Orbit CLI extensions and their state."
---

# extension:list

List every known extension with its local state.

```bash
orbit extension:list [--json]
```

```bash
orbit extension:list
```

Human output is one table with the columns EXTENSION and STATE. Narrow terminals wrap cells or use labeled records when a table cannot fit. JSON output returns an `extensions` array with `extension` and `enabled` for each entry.

Recorded output from commit local on 2026-09-17, exit status 0:

```text
$ orbit extension:list
 ┌───────────┬──────────┐
 │ EXTENSION │ STATE    │
 ├───────────┼──────────┤
 │ herdr     │ disabled │
 └───────────┴──────────┘
```
