---
title: "instance:clone"
description: "Clone a candidate into a prepared production App instance."
---

# instance:clone

Clone a candidate into a prepared production App instance. The candidate supplies source, stored environment values, and an optional SQLite snapshot. The App supplies production Process and Schedule definitions.

```bash
orbit instance:clone <candidate> <node> <name> --preview-name=NAME [options]
```

| Argument | Required | Meaning |
| --- | --- | --- |
| `candidate` | yes | Numeric candidate App instance ID. |
| `node` | yes | Numeric destination Node ID with the active `app-prod` role. |
| `name` | yes | Target production App instance name. |

| Option | Default | Meaning |
| --- | --- | --- |
| `--preview-name=NAME` | required | Preview name that the Gateway combines with the destination Node TLD. |
| `--branch=BRANCH` | the candidate branch | Target branch that must exist in the App repository. |
| `--sqlite-source-path=PATH` | none | Absolute path to one SQLite database on the candidate to seed `database.sqlite`. |

Provision the production Node with `node:add --tld` before cloning; `shop.com` on a Node whose TLD is `prod.orbit` becomes the private preview `shop.com.prod.orbit`. The candidate must have no staged, unstaged, untracked, or submodule change, and its commit must be reachable from the App repository.

```bash
orbit node:add production production.example --role=app-prod --tld=prod.orbit
orbit instance:clone 12 9 primary --preview-name=shop.com --sqlite-source-path=/srv/orbit/apps/acme/default/database/database.sqlite
```

Cloning ends with an active App instance that has no selected release. Update and synchronize target environment values, configure deploy steps, then run `instance:deploy` to create and select the first release. An identical retry resumes an interrupted clone; a request that changes the candidate, destination, name, preview, branch, or SQLite selection is refused.

## Use it when

Use this command to put an App into production for the first time on a Node. The result has no release until [`instance:deploy`](instance-deploy.md) runs.

## Check

1. Run `orbit instance:show <instance> --json` and confirm an active status with no selected release.
2. Update and synchronize environment values with the `env` family.
3. Add deploy steps with [`instance:deploy-step:create`](instance-deploy-step-create.md).
4. Run [`instance:deploy`](instance-deploy.md) to create and select the first release.

## After a refusal

A dirty candidate or a commit outside the App repository needs a commit and push before a retry. A retry that changes any input is refused; repeat the original command exactly to resume an interrupted clone.
