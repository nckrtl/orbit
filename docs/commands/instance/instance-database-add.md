---
title: "instance:database:add"
description: "Add a Database connection and write prefixed stored environment keys."
---

Add a registered Database connection on an App instance and write prefixed keys into its stored environment configuration.

```bash
orbit instance:database:add <slug> --instance=SELECTOR [--prefix=PREFIX] [--json]
```

| Argument | Required | Meaning |
| --- | --- | --- |
| `slug` | yes | Database connection slug from `database:list`. |

| Option | Default | Meaning |
| --- | --- | --- |
| `--instance=SELECTOR` | required | Positive App instance ID or exact Route domain. |
| `--prefix=PREFIX` | `DB` | Uppercase key prefix that starts with a letter, at most 32 characters. |

For mysql and pgsql the Gateway writes `DB_CONNECTION`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, and `DB_PASSWORD`. For sqlite it writes `DB_CONNECTION=sqlite` and `DB_DATABASE` as the absolute path, plus username and password only when stored. When the connection names the same Node as the App instance and aligns with a Node-owned Docker Process, the Gateway writes host `127.0.0.1` and the published host port.

```bash
orbit instance:database:add app --instance=12
orbit instance:database:add reporting --instance=shop.example.test --prefix=REPORTING
```

Add changes stored configuration only. Run `orbit env:sync --instance=SELECTOR` to install it in the workload `.env`.

## Check

Run `orbit env:sync --instance=SELECTOR` to write the stored values into the App instance `.env` file, then confirm that the application connects.
