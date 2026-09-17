---
title: "app"
description: "Create, inspect, and remove App records. An App owns one repository and the source defaults that App instances inherit."
commands:
  - app:create
  - app:list
  - app:show
  - app:update
  - app:destroy
---

An App is Orbit's stable record for one application. It owns one repository identity, one access URL, a default branch, and a relative web root. App instances, Routes, and process and Schedule definitions hang off the App.

The [Apps reference](/reference/apps) owns repository identity, default-branch resolution, and idempotent creation. The [Applications guide](/domains/applications) shows how an App becomes a running App instance.

## Commands

| Command | Result |
| --- | --- |
| [`app:create`](#orbit-appcreate) | Create an App from a slug and a Git repository URL. |
| [`app:list`](#orbit-applist) | List Apps. |
| [`app:show`](#orbit-appshow) | Show one App with its stored repository, default branch, and root. |
| [`app:update`](#orbit-appupdate) | Change source defaults and reconcile affected instances. |
| [`app:destroy`](#orbit-appdestroy) | Remove an App. |

Every command accepts `--json`.

Human lists use an uppercase-header table. App details use a detail tree. Requests show progress while waiting, and results retain the Gateway request ID. Inputs remain explicit in every mode.

{/* commands */}

## Related

- [`instance`](/cli/instance) creates a checkout or production release of an App on a Node.
- [`process`](/cli/process) and [`schedule`](/cli/schedule) record App-owned definitions with `--app`.
- [`route`](/cli/route) creates explicit domains owned by an App.
