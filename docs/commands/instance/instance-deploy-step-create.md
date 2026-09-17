---
title: "instance:deploy-step:create"
description: "Record one named deploy step."
---

Record one named deploy step on a production App instance. The Gateway stores it at the end of its phase unless you place it.

```bash
orbit instance:deploy-step:create <instance> <name> --command=COMMAND [options]
```

| Argument | Required | Meaning |
| --- | --- | --- |
| `instance` | yes | Numeric App instance ID. |
| `name` | yes | Step name: 1 through 63 lowercase letters, digits, or hyphens, unique within the App instance. |

| Option | Default | Meaning |
| --- | --- | --- |
| `--command=COMMAND` | required | Command the Gateway runs as the production user from the fresh release. |
| `--phase=PHASE` | `before_activation` | `before_activation` or `after_activation`. |
| `--timeout=SECONDS` | `300` | Timeout from 1 through 900 seconds. |
| `--before=NAME` | none | Place before this step in the same phase. Exclusive with `--after`. |
| `--after=NAME` | none | Place after this step in the same phase. Exclusive with `--before`. |

```bash
orbit instance:deploy-step:create 15 install --command="composer install --no-dev --optimize-autoloader"
orbit instance:deploy-step:create 15 migrate --command="php artisan migrate --force" --after=install
orbit instance:deploy-step:create 15 restart-queue --command="php artisan queue:restart" --phase=after_activation
```

The Gateway refuses a duplicate name, a placement that names an unknown step or a step in another phase, a thirty-third step, a timeout over 900 seconds, or a step-timeout sum over 3,600 seconds.

## Use it when

Use this command when a production App instance needs a build, migration, or restart command during deployment. Put steps that must succeed before traffic moves, such as dependency installs and migrations, in `before_activation`; a failure there keeps the previous release live.

## Check

Run [`instance:deploy-step:list`](instance-deploy-step-list.md) and confirm the order.
