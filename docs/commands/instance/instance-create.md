---
title: "instance:create"
description: "Create a development App instance on an app-dev Node."
---

Create a development App instance on a Node with the active `app-dev` role. New production placements require [`instance:clone`](instance-clone.md); `instance:create` refuses them with `instance.candidate_required`.

```bash
orbit instance:create <app> <node> <name> [options]
```

| Argument | Required | Meaning |
| --- | --- | --- |
| `app` | yes | Numeric App ID. |
| `node` | yes | Numeric Node ID. |
| `name` | yes | App instance name. `default` is reserved for the App's default development source. |

| Option | Default | Meaning |
| --- | --- | --- |
| `--root=PATH` | the App root | Relative web-root override for this App instance. |
| `--domain=HOST` | generated | Explicit Route domain for the development source. Without it, Orbit generates a domain from the active Cluster TLD, then the Node TLD. |
| `--branch=BRANCH` | see below | Explicit source branch. It must exist on the remote. |
| `--recover-source-profile` | off | Adopt complete source evidence for a checkpoint that has no recorded source profile. |

Development placement and branch selection follow the requested name.

| Creation input | Managed placement | Selected branch |
| --- | --- | --- |
| `default` without `--branch` | `<node-apps-root>/<app-slug>/default` | The App `default_branch`. |
| Another name without `--branch` | `<node-apps-root>/<app-slug>/<name>` | The matching remote branch, or a new branch from the fetched `default_branch` commit. |
| Any name with `--branch` | The placement for the requested name | The existing remote branch. |

```bash
orbit instance:create 4 7 default
orbit instance:create 4 7 feature-one --branch=release --domain=feature.example.test
```

The Gateway records each provisioning boundary, so an identical retry resumes an incomplete creation. A retry that adds, removes, or changes `--branch` returns `instance.placement_conflict`. A branch that does not exist returns `instance.branch_resolution_failed`.

<Note>
Active means Orbit prepared the source, selected any PHP runtime, aligned a Laravel `APP_URL`, and published the Route. It does not mean the application is healthy; missing dependencies or an application key can still return HTTP 500.
</Note>

## Use it when

Use this command for a new development copy or branch of an App on an app-dev Node. Use [`instance:clone`](instance-clone.md) for production, and [`instance:register`](instance-register.md) for a checkout that already exists on the Node.

## Check

1. Run `orbit instance:show <instance> --json` and confirm an active status and a Route domain.
2. Request the Route domain. Active does not mean healthy.

## After a refusal

| Error code | What to do |
| --- | --- |
| `instance.candidate_required` | The Node is a production target. Use [`instance:clone`](instance-clone.md). |
| `instance.placement_conflict` | A retry changed `--branch`. Repeat the original command exactly, or choose a new name. |
| `instance.branch_resolution_failed` | The branch is not on the remote. Ask the user which branch to use. |
