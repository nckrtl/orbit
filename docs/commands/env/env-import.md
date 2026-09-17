---
title: "env:import"
description: "Import the workload `.env` into stored configuration."
---

Import the workload `.env` into stored configuration. For development the Gateway reads the recorded checkout; for production it reads the application-user home. It never reads `.env.example`.

```bash
orbit env:import --instance=SELECTOR [--replace] [--json]
```

| Option | Meaning |
| --- | --- |
| `--instance=SELECTOR` | Positive App instance ID or exact Route domain. |
| `--replace` | Replace stored keys that the file also defines. Stored keys that the file omits stay in place. |

Without `--replace`, a file key that is already stored returns `env.import_conflict` and the Gateway stores nothing. The importer accepts comments, quoted and escaped values, multiline quoted values, and expansion of keys in the same file. Duplicate keys, unresolved expansion, invalid syntax, or a file over 1 MiB reject the complete import.

```bash
orbit env:import --instance=12
orbit env:import --instance=shop.example.test --replace
```

For a Laravel App instance, import stores `APP_URL` as `https://{{app_instance.domain}}` and keeps `APP_KEY` and every other literal unchanged.
