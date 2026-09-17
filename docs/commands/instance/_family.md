---
title: "instance"
description: "Create, register, clone, deploy, roll back, and remove App instances, and attach Database connections to them."
commands:
  - instance:create
  - instance:register
  - instance:list
  - instance:show
  - instance:destroy
  - instance:clone
  - instance:update
  - instance:deploy-step:create
  - instance:deploy-step:list
  - instance:deploy-step:update
  - instance:deploy-step:destroy
  - instance:deploy
  - instance:release:list
  - instance:rollback
  - instance:database:add
  - instance:database:remove
  - instance:transfer
---

An App instance is one placement of an App on one Node. A development App instance owns a checkout or worktree on an app-dev Node. A production App instance owns a dedicated Unix user, a production home with retained releases, and a `current` link that selects the release Caddy serves. Every active App instance has exactly one Route.

The [Applications guide](/domains/applications) walks through creation and registration. The [production release layout](/reference/deployments), [App instance cloning](/reference/appinstance-cloning), [App instance removal](/reference/appinstance-removal), and [Database connections](/reference/database-connections) references own the contracts behind the commands on this page.

## Commands

Every command accepts `--json`. Machine output never supplies consent. Human list commands use tables, with labeled records when the terminal is too narrow; detail commands show a tree and slow requests show progress. `instance:deploy` and `instance:rollback` return newline-delimited JSON (NDJSON) instead of one document.

Placement commands create, adopt, inspect, and remove an App instance on one Node.

| Command | Result |
| --- | --- |
| [`instance:create`](#orbit-instancecreate) | Create a development App instance on an app-dev Node. |
| [`instance:register`](#orbit-instanceregister) | Adopt the current Git checkout or worktree as a managed App instance. |
| [`instance:list`](#orbit-instancelist) | List App instances. |
| [`instance:show`](#orbit-instanceshow) | Show one App instance with its Route, source, and deploy steps. |
| [`instance:transfer`](#orbit-instancetransfer) | Move a development App instance to another Node. |
| [`instance:destroy`](#orbit-instancedestroy) | Remove an App instance and its owned Processes, Schedules, and Route. |

Release commands prepare and operate a production App instance.

| Command | Result |
| --- | --- |
| [`instance:clone`](#orbit-instanceclone) | Clone a candidate into a prepared production App instance. |
| [`instance:update`](#orbit-instanceupdate) | Change the deployment branch of a production App instance. |
| [`instance:deploy-step:create`](#orbit-instancedeploy-stepcreate) | Record one named deploy step. |
| [`instance:deploy-step:list`](#orbit-instancedeploy-steplist) | List deploy steps in phase and placement order. |
| [`instance:deploy-step:update`](#orbit-instancedeploy-stepupdate) | Change one named deploy step. |
| [`instance:deploy-step:destroy`](#orbit-instancedeploy-stepdestroy) | Remove one named deploy step. |
| [`instance:deploy`](#orbit-instancedeploy) | Deploy the configured branch as a fresh release. |
| [`instance:release:list`](#orbit-instancereleaselist) | List retained releases and the current selection. |
| [`instance:rollback`](#orbit-instancerollback) | Select one retained release. |

Attachment commands write a registered Database connection into stored App instance configuration.

| Command | Result |
| --- | --- |
| [`instance:database:add`](#orbit-instancedatabaseadd) | Add a Database connection and write prefixed stored environment keys. |
| [`instance:database:remove`](#orbit-instancedatabaseremove) | Remove a Database connection and clear those keys. |

{/* commands */}

## Related

- [`app`](/cli/app) owns the repository and source defaults that an App instance inherits.
- [`route`](/cli/route) shows and changes the domain of an App instance.
- [`env`](/cli/env) imports, updates, and synchronizes the stored environment that deployments render.
- [`process`](/cli/process) and [`schedule`](/cli/schedule) run services and timers on an App instance.
- [`database`](/cli/database) registers the connections that `instance:database:add` attaches.
