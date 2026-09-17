---
title: "env:update"
description: "Add or replace one stored value."
---

# env:update

Add or replace one stored value without changing the workload file.

```bash
orbit env:update --instance=SELECTOR --key=KEY --value=VALUE [--json]
```

| Option | Meaning |
| --- | --- |
| `--instance=SELECTOR` | Positive App instance ID or exact Route domain. |
| `--key=KEY` | Key to add or replace: 1 to 255 ASCII characters that start with a letter or underscore. |
| `--value=VALUE` | Exact string value of at most 65,536 bytes. |

Quote the value for your shell so Orbit receives the intended string.

| Value | Write it as |
| --- | --- |
| Empty | `--value=''` |
| Text with spaces or shell characters | `--value='hello world $1'` |
| A reference expression | `--value='https://{{app_instance.domain}}'` |
| `false` or `0` | `--value=false` stays the string `false`. |

```bash
orbit env:update --instance=12 --key=MAIL_MAILER --value=log
orbit env:update --instance=12 --key=APP_URL --value='https://{{app_instance.domain}}'
```

Two placeholders are supported, alone or as part of a longer value: `{{app_instance.domain}}` resolves to the Route domain and `{{app_instance.environment}}` to `development` or `production` when the Gateway renders the file. Any other placeholder returns `env.configuration_invalid` with the redacted key and rule. An identical stored value succeeds with `changed: false`. For Laravel, `APP_URL` accepts only `https://{{app_instance.domain}}`.
