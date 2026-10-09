---
title: "Instance transfer"
description: "How the Gateway moves one development Instance to another app-dev Node, keeps its ID, and recovers from a failed or interrupted move."
covers:
  - apps/gateway/app/Actions/Instances/TransferInstanceAction.php
  - apps/gateway/app/Domain/Instances/Transfer/**
  - apps/gateway/app/Infrastructure/Instances/{NativeInstanceTransferRuntime,RemoteInstanceTransferSource}.php
  - apps/gateway/app/Http/Controllers/Api/InstanceTransfersController.php
  - apps/gateway/app/Models/InstanceTransfer.php
  - apps/cli/app/Commands/Instances/TransferInstanceCommand.php
  - packages/php-sdk/src/Requests/Instances/TransferInstanceRequest.php
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

Both Nodes must be active Linux Nodes with an active `app-dev` role, and each must belong to an active Cluster. The two Clusters may differ. The Instance must be an active development Instance with one authoritative Route, and not in removal. An Instance with [Routes with a web root](/reference/routes#serve-several-web-roots) returns `instance.transfer_web_root_routes`; remove them, transfer, and create them again. The destination must be another Node that the Project does not [exclude](/reference/development-node-exclusions). No Schedule may target the Instance. Orbit checks before reserving and again under the Process admission lock immediately before cutover, so a Schedule created during a transfer also prevents cutover.

The Gateway reserves `<destination-apps-root>/<project-slug>/<name>`. It refuses an occupied or unsafe path with `instance.destination_exists`. Retry with another `--name`. A name that another Instance of the Project uses returns `instance.identity_conflict`.

## What moves

The destination gets an independent checkout, even when the source is a worktree. It holds the branch, commits, detached state, tracked changes, untracked files, and file modes of the source. Orbit does not fetch, reset, clean, or push the source. The common repository, sibling worktrees, and local branches of a source worktree stay unchanged.

Gateway Git commands that capture the source and prepare the destination pass `-c core.hooksPath=/dev/null` and `-c core.fsmonitor=false`. Checkout hooks and custom filesystem monitors are not transfer steps. The overrides apply to those commands without changing the stored Git configuration. When a worker is configured, checkout commands that can start clean, smudge, or process filters run as that worker without a credential environment; privileged file placement stays under the managed account.

Every transfer runs in this order:

1. Orbit stops the source Processes and timers, with or without SQLite.
2. It copies the source checkout to the destination.
3. When you select a SQLite file, it takes one consistent snapshot and installs it at the selected path on the destination.

The Gateway stages the transfer archive on disk, not in a size-limited `/tmp`. A checkout of any size that fits the Gateway's disk can move. If staging fails, the Gateway records and logs the failing step.

When the request selects a SQLite file, the checkout archive excludes that file and its `-wal` and `-shm` files. The SQLite step installs the consistent snapshot at the selected path, so the database contents come from the snapshot rather than an inconsistent archive copy.

Stopping Processes before the final checkout copy prevents their writes from being missed. The `annotator` preset also transfers its private store at `/var/lib/orbit/annotator/instance-{id}`. Capture adds the store to the checkout archive under `.orbit/annotator`; destination runtime installation restores it outside the checkout and removes that staging directory. Pausing the source unit does not delete its store. Source cleanup deletes the old store only after cutover, so rollback can restart the source with its annotations. Apart from that managed store and the selected SQLite file, Orbit copies no other database or data path.

Orbit imports the source `.env` into the [stored environment](/reference/environment-variables). For a Laravel root of `apps/site/public`, that file is `<source-checkout>/apps/site/.env`; transfer closes its permissions and synchronizes the destination's `apps/site/.env`, not an unrelated repository-root file. Transfer copies the entire checkout, keeps the effective web root, and relocates explicit Process working directories. A key that is already stored keeps its stored value. An unreadable source `.env` refuses the transfer. If a failure occurs before cutover, Orbit removes environment keys imported by that transfer along with the other prepared destination state.

At cutover, every Process that has an environment file gets the destination `.env` in the [application directory](/reference/projects#application-directory). This covers custom systemd Processes as well as presets such as `vp-dev` and `annotator`. Activation then rewrites each destination unit, so its `EnvironmentFile=` names the destination file, never the source Node's path.

Process records keep their IDs, definitions, and desired states. Orbit stops their source units, creates them on the destination, and leaves no duplicate. The destination gets its own [Vite port](/reference/assigned-vite-ports) and [SSR port](/reference/assigned-ssr-ports), and Orbit releases the source ports after cleanup.

An assigned annotator port is reassigned under the destination Node lock at cutover. The source assignment stays in `annotation_port_assignments` until source Caddy retirement succeeds, including after a failed destination activation or interrupted cleanup. Another Instance cannot claim that port while the old proxy may still exist. Allocation skips both annotator and Agentation assignments on that Node. The destination units use the new port and Route domain, and the stored `ANNOTATOR_URL` placeholder stays unchanged. See [Annotator Process](/reference/agentation#annotator-process).

## Route

An explicit domain keeps its Route. The Route moves to the destination Node and Cluster.

A generated domain uses the destination Cluster TLD: `<project-slug>.<tld>` for `default`, and `<name>.<project-slug>.<tld>` otherwise. When that domain is the same, the Route keeps its ID. When it changes, Orbit creates a replacement Route and releases the old domain after cleanup. A domain that another Route owns returns `route.domain_conflict`.

## Failure and retry

Cutover is the moment the destination becomes authoritative.

- A failure before cutover restarts the source Processes and keeps the source Route.
- It also deletes the destination checkout, the destination Vite port, and a replacement Route that is not active yet.
- Keys imported into the stored environment are removed; keys that were already stored before the transfer remain.
- After cutover, recovery only goes forward. Orbit never restarts the source. It finishes the Route, runtime, and cleanup without copying the source again.

Before rollback changes the Nodes, the Gateway records the failure and sets `rollback_pending` in recovery evidence. The marker keeps the transfer open if the Gateway stops during rollback. An identical retry finishes that rollback before pausing and capturing the source again; it never resumes the old forward checkpoint. The Gateway clears the marker and resets the checkpoint in one write only after rollback finishes. Recovery uses the same Instance and Node locks as transfer. It also clears the transfer's reference to a replacement Route if rollback already deleted that Route.

Rollback abandons the transfer's SQLite seed before it restarts the source. It removes the owned source snapshot, unfinished destination incoming and candidate files, and seed records. A retry takes a fresh snapshot after pausing the source again, so writes committed after restoration reach the destination. If seed cleanup fails or its response is lost, the Gateway records `sqlite-seed` as incomplete recovery evidence and keeps the transfer open. An identical retry must confirm that cleanup before it captures the source again. Cleanup checks the recorded operation and file identities; it never removes a file that belongs to another operation.

A transfer that fails before cutover is closed once rollback finishes. A new transfer request with any input, or `instance:destroy`, proceeds without a conflict from that closed transfer. Normal eligibility checks still apply.

An unfinished transfer, including a failed transfer whose rollback is incomplete, stays open. A transfer that fails after cutover also stays open. Only the identical request resumes an open transfer and closes it: a different transfer returns `instance.transfer_retry_conflict`, and removal returns `instance.transfer_incomplete`. For a pending transfer, the CLI offers the retry and names the original source Node.

If a Schedule targets the Instance before reservation or is added before cutover, transfer returns `schedule.target_in_use`. Remove the Schedule or retarget it away from the Instance, then send a new transfer request if rollback has finished, or retry the identical request if the transfer is still open.

Orbit records the source Cluster's Router on the transfer before cutover, and cleanup uses that record.

Cleanup deletes the old checkout or worktree and its runtime files, certificates, and firewall rules on the old workload and Router. The result reports the destination Node, path, domain, and whether cleanup finished. It does not depend on an HTTP response from the application.

## Failure codes

The Gateway returns these codes before or during a transfer.

| Code | Cause |
| --- | --- |
| `instance.confirmation_required` | The call has no consent. |
| `instance.lifecycle_conflict` | The Instance is not active, or its Route is not ready. |
| `schedule.target_in_use` | A Schedule targets the Instance. Remove it or retarget it away from the Instance before trying the transfer again. |
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
| `instance.transfer_retry_conflict` | A different request tried to resume an open transfer. |
| `instance.transfer_failed` | The transfer failed before cutover and the source is authoritative. |
| `instance.transfer_cleanup_incomplete` | The destination is authoritative, and cleanup needs the identical retry. |
| `instance.transfer_source_router_unknown` | The source Route has no Router, so Orbit cannot record one for the transfer. |
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
