---
title: "instance:register"
description: "Adopt the current Git checkout or worktree as a managed App instance."
---

# instance:register

Adopt the current Git checkout or worktree as a managed App instance. Run it on the app-dev Node that holds the source.

```bash
orbit instance:register [options]
```

| Option | Default | Meaning |
| --- | --- | --- |
| `--path=PATH` | the current directory | Existing Git checkout or worktree. |
| `--include-worktrees` | off | Adopt the checkout and every linked worktree as one preflighted set. |
| `--app=ID` | resolved from the origin | Existing numeric App ID. |
| `--app-name=NAME` | inferred | Confirmed App display name for a new App. |
| `--app-slug=SLUG` | the repository name | Confirmed App slug for a new App. |
| `--default-branch=BRANCH` | the remote default | Confirmed App default branch for a new App. |
| `--name=NAME` | inferred | Non-default App instance name. |
| `--root=PATH` | `public` for an unambiguous Laravel checkout | Confirmed App root, or a root override for an existing App. |
| `--domain=HOST` | generated | Explicit Route domain for the primary source. |
| `--yes` | off | Confirm transfer of the source into Orbit ownership without prompting. |

The CLI refuses a directory outside a Git checkout and refuses a credential-bearing origin before it sends a request. The Gateway resolves the App by canonical repository identity, verifies the source on the caller Node, moves it into managed placement, and provisions the same Route and runtime that `instance:create` provisions.

In an interactive terminal the CLI shows the inferred values, asks only for unresolved ones, and asks for ownership consent that names the source and defaults to No. JSON and noninteractive calls require `--yes` and refuse unresolved required values before mutation. Invalid prompted values can be corrected; cancellation or end of input leaves the source unchanged.

```bash
cd ~/apps/acme
orbit instance:register
orbit instance:register --path=/srv/orbit/apps/acme/main --include-worktrees --yes --json
```

Registration also completes the manual migration of an App instance whose list and show output reports `migration_required: true`. The [Applications guide](https://orbit.nckrtl.com/docs/domains/applications.md#register-an-existing-development-source) describes inferred identity and placement.

## Use it when

Use this command when a checkout already exists on the Node, or when `instance:list` reports `migration_required: true`. Run it on the Node that holds the source.

## Check

Run `orbit instance:show <instance> --json` and confirm an active status and a Route domain.

## After a refusal

A JSON call without `--yes` or with an unresolved required value is refused before anything changes. Ask the user to confirm the ownership transfer, supply the missing value, and run the command again. A directory outside a Git checkout or an origin URL with credentials needs a corrected path or origin.
