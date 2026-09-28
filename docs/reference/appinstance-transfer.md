---
title: "Instance transfer"
description: "How the Gateway moves one development Instance to another app-dev Node, keeps its ID, and recovers from a failed or interrupted move."
covers:
  - apps/gateway/app/Actions/AppInstances/TransferAppInstanceAction.php
  - apps/gateway/app/Domain/AppInstances/Transfer/**
  - apps/gateway/app/Infrastructure/AppInstances/{NativeAppInstanceTransferRuntime,RemoteAppInstanceTransferSource}.php
  - apps/gateway/app/Http/Controllers/Api/AppInstanceTransfersController.php
  - apps/gateway/app/Models/AppInstanceTransfer.php
  - apps/cli/app/Commands/Instances/TransferInstanceCommand.php
  - packages/php-sdk/src/Requests/AppInstances/TransferAppInstanceRequest.php
---

# Instance transfer

A transfer moves one active development Instance to another `app-dev` Node. The Instance keeps its ID, Project, and Processes. A Schedule cannot target the Instance during transfer. The source gets a short downtime, and Orbit deletes the old placement at the end.

```bash
orbit instance:transfer <instance> <node> [--name=NAME] [--sqlite-source-path=PATH] [--force] [--json]
```

## Request

The CLI calls `POST /api/v1/instances/{instance}/transfer`.

| Input | Meaning |
| --- | --- |
| `node_id` | Destination Node. Required. |
| `name` | Destination Instance name. It defaults to the current name. |
| `sqlite_source_path` | Absolute path to one SQLite database on the source to copy. Optional. |

The request accepts no path, Cluster, Route, or Process input. The caller needs an [access grant](/cli/node) to both Nodes. Interactive calls ask for default-No consent that names the source, the destination, the downtime, and the deletion. `--force` gives that consent. JSON and noninteractive calls without `--force` return `instance.confirmation_required`.

## Eligibility

Both Nodes must be active Linux Nodes with an active `app-dev` role, and each must belong to an active Cluster. The two Clusters may differ. The Instance must be an active development Instance with one authoritative Route, and not in removal. The destination must be another Node that the Project does not [exclude](/reference/development-node-exclusions). No Schedule may target the Instance. Orbit checks before reserving and again under the Process admission lock immediately before cutover, so a Schedule created during a transfer also prevents cutover.

The Gateway reserves `<destination-apps-root>/<project-slug>/<name>`. It refuses an occupied or unsafe path with `instance.destination_exists`. Retry with another `--name`. A name that another Instance of the Project uses returns `instance.identity_conflict`.

## What moves

The destination gets an independent checkout, even when the source is a worktree. It holds the branch, commits, detached state, tracked changes, untracked files, and file modes of the source. Orbit does not fetch, reset, clean, or push the source. The common repository, sibling worktrees, and local branches of a source worktree stay unchanged.

Every transfer runs in this order:

1. Orbit stops the source Processes and timers, with or without SQLite.
2. It copies the source checkout to the destination.
3. When you select a SQLite file, it takes one consistent snapshot and installs it at the destination.

Stopping Processes before the final checkout copy prevents their writes from being missed. Orbit copies no other database or data path.

Orbit imports the source `.env` into the [stored environment](/reference/environment-variables). A key that is already stored keeps its stored value. An unreadable source `.env` refuses the transfer. If a failure occurs before cutover, Orbit removes environment keys imported by that transfer along with the other prepared destination state.

Process records keep their IDs, definitions, and desired states. Orbit stops their source units, creates them on the destination, and leaves no duplicate. The destination gets its own [Vite port](/reference/assigned-vite-ports), and Orbit releases the source port after cleanup.

## Route

An explicit domain keeps its Route. The Route moves to the destination Node and Cluster.

A generated domain uses the destination Cluster TLD: `<project-slug>.<tld>` for `default`, and `<name>.<project-slug>.<tld>` otherwise. When that domain is the same, the Route keeps its ID. When it changes, Orbit creates a replacement Route and releases the old domain after cleanup. A domain that another Route owns returns `route.domain_conflict`.

## Failure and retry

Cutover is the moment the destination becomes authoritative.

- A failure before cutover restarts the source Processes and keeps the source Route.
- It also deletes the destination checkout, the destination Vite port, and a replacement Route that is not active yet.
- Keys imported into the stored environment are removed; keys that were already stored before the transfer remain.
- After cutover, recovery only goes forward. Orbit never restarts the source. It finishes the Route, runtime, and cleanup without copying the source again.

A failed or unfinished transfer stays open. Only the identical request resumes it, and it is the only way to close it: a different transfer returns `instance.transfer_retry_conflict`, and removal returns `instance.transfer_incomplete`. For a pending transfer, the CLI offers the retry and names the original source Node. If a Schedule targets the Instance before reservation or is added before cutover, transfer returns `schedule.target_in_use`. Remove the Schedule or retarget it away from the Instance, then retry the identical transfer request.

Cleanup deletes the old checkout or worktree and its runtime files, certificates, and firewall rules on the old workload and Router. The result reports the destination Node, path, domain, and whether cleanup finished. It does not depend on an HTTP response from the application.

## Failure codes

The Gateway returns these codes before or during a transfer.

| Code | Cause |
| --- | --- |
| `instance.confirmation_required` | The call has no consent. |
| `instance.lifecycle_conflict` | The Instance is not active, or its Route is not ready. |
| `schedule.target_in_use` | A Schedule targets the Instance. Remove it or retarget it away from the Instance before retrying the identical transfer request. |
| `instance.production_refused` | The Instance is a production Instance. |
| `instance.removal_conflict` | The Instance is being removed. |
| `instance.same_node` | The destination is the current Node. |
| `instance.node_inactive` | A Node is not an active Linux Node. |
| `instance.node_not_app_dev` | A Node has no active `app-dev` role. |
| `instance.node_excluded` | The Project excludes the destination. |
| `instance.standalone_unsupported` | A Node is not in an active Cluster. |
| `instance.identity_conflict` | Another Instance of the Project has the name. |
| `instance.destination_exists` | The destination path is occupied or unsafe. |
| `route.domain_conflict` | Another Route owns the destination domain. |
| `instance.transfer_retry_conflict` | A different request tried to resume a transfer. |
| `instance.transfer_failed` | The transfer failed before cutover and the source is authoritative. |
| `instance.transfer_cleanup_incomplete` | The destination is authoritative, and cleanup needs the identical retry. |
| `instance.transfer_source_router_unknown` | The source Router is unknown, so Orbit cannot clean up the source projection. |
| `instance.transfer_cleanup_conflict` | The recorded placement or Route changed, so cleanup stops. |
| `instance.clone_sqlite_unconfirmed` | Orbit cannot confirm the SQLite copy. Retry. |
| `sqlite.seed_preflight_failed`, `sqlite.seed_transfer_failed`, `sqlite.seed_failed` | The SQLite snapshot failed its checks, its copy, or its install. |

## Why it works this way

These reasons explain the design. Check them before you propose a change.

### Keep the Instance, not the layout

Removing the Instance and registering a new one would lose its ID and rebuild its Route, environment, and Processes. So transfer keeps the record. A worktree cannot keep its link to a common repository on another Node, so the destination is always an independent checkout.

### Downtime, not live migration

A consistent SQLite snapshot needs a moment with no writes. So transfer stops the source for a short window. Zero-downtime transfer was rejected.

### Only the selected SQLite file

Orbit cannot know the owner, credentials, or consistency rules of other databases. So it copies only the SQLite file you name. Discovering and copying external databases was rejected.

### Clusters on both sides

A move between Clusters can change the generated domain and the Router path. Transfer uses the destination Cluster's naming and the replacement Route lifecycle for that change. Standalone Nodes are refused.

Transfers preserve the Instance identity and resolve its development or production placement from the destination Node role.
