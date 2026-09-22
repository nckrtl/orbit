# Instance transfer

This page tells an operating agent how the Gateway moves one active development Instance from its current Node to another app-dev Node while keeping the same Instance ID. [ADR 0066](/decisions/0066-transfer-development-appinstances-between-nodes) owns the transfer decision. [ADR 0065](/decisions/0065-replace-routes-when-domains-change) owns generated domain replacement. [ADR 0044](/decisions/0044-own-appinstance-environment-configuration-in-orbit) owns stored environment configuration.

## Request a transfer

An authorized client sends one Instance selector and the destination Node to the Gateway.

| Input | Requirement |
| --- | --- |
| Endpoint | `POST /api/v1/instances/{instance}/transfer` |
| Command | `orbit instance:transfer INSTANCE NODE` |
| `node_id` | Required positive ID of a distinct destination Node |
| `name` | Optional normalized destination Instance name |
| `sqlite_source_path` | Optional absolute path to one SQLite database on the source |

The request accepts no destination path, Cluster, Route, Process, or extra persistent-data selector. The Gateway refuses malformed JSON, duplicate members, unknown members, and invalid values before it changes stored or remote state.

The caller needs directed access to both the source Node and the destination Node. Interactive CLI calls require a default-No confirmation naming the source, destination, downtime and old-placement deletion, unless `--force` supplies explicit consent. JSON and noninteractive calls require `--force`; `--json` only selects machine output and disables prompts. Missing consent returns `instance.confirmation_required` before mutation.

## Check eligibility

The Gateway accepts one active development Instance on an active Linux Node that has the active `app-dev` role and belongs to an active Cluster. The destination must be a distinct active Linux Node that also has the active `app-dev` role and belongs to an active Cluster. The destination may be in the same Cluster or another Cluster.

The Gateway refuses the request before it mutates source state when the Instance is production, reserved, migrating, or being removed, when either Node is inactive, missing `app-dev`, or standalone, or when the destination is the current Node.

## Reserve the destination

The Gateway reserves exactly `<destination-apps-root>/<app-slug>/<instance-name>`. The destination name is the current Instance name unless the operator supplies `name`. An explicit name recalculates that path and, for a generated Route, the destination domain.

The Gateway refuses an occupied, overlapping, linked, or otherwise unsafe destination with `instance.destination_exists`. The error message contains `destination already exists` and tells the operator to retry with a different `name`. The original name stays unchanged until cutover.

The Gateway also refuses an existing Instance identity on the same Project and a Route domain that another Route already owns.

## Preserve application state

Transfer keeps the Instance ID and Project ownership. The Gateway copies source content into an independent destination checkout and does not fetch, reset, clean, or push the source.

### Source checkout

The destination checkout includes branch and commit evidence, tracked changes, untracked files, executable modes, unpublished commits, and detached Git state. A source worktree becomes an independent destination checkout. Transfer leaves the common repository, sibling worktrees, local branches, and unrelated Git administration unchanged.

### Selected SQLite

When the operator selects one SQLite database, the Gateway pauses source execution, captures one consistent snapshot, and installs those bytes at the destination. It never discovers or copies another database or persistent path.

### Environment and runtime

Transfer imports the source `.env` into the encrypted Gateway store without returning or logging values. Stored application keys win over imported keys. The Gateway resolves destination references and writes the destination environment before it activates destination runtime.

The source environment file must exist and be readable. A valid empty file is allowed. A failed or incomplete read, unsafe file, or invalid dotenv stops transfer before destination environment rebuild and cutover. The source remains authoritative. Correct the file or observation failure, then retry the identical request.

Existing Process and Schedule records keep their IDs, definitions, and desired states. The Gateway stops source processes and timers for the downtime window, recreates destination runtime artifacts under the destination identity, and leaves no source or destination duplicate.

## Move the Route

An explicit Route domain and a generated Route whose destination domain stays the same keep their Route ID. Transfer moves the Route target and Cluster or Node scope.

A generated Route whose destination domain changes uses the destination Cluster TLD and the replacement Route lifecycle from [ADR 0065](/decisions/0065-replace-routes-when-domains-change). The new generated domain is `{app-slug}.{tld}` when the destination name is `default`, and `{name}.{app-slug}.{tld}` otherwise.

## Recover from failure

A failure before cutover restores the source Route, environment, processes, and schedules as authoritative. The Gateway removes successfully reversible destination state and retains bounded recovery evidence when that cleanup is incomplete. Only the identical request can resume.

Once cutover makes the destination authoritative, retry proceeds only forward. The Gateway never restarts source execution, completes Route publication and runtime activation, and resumes exact old-placement cleanup without copying source again.

For a pending transfer to the same destination, the CLI asks to retry the transfer and names its original source Node, including after cutover. The Gateway still checks that the request matches the pending transfer.

The transfer records the original Router before cutover. If an older incomplete transfer passed cutover without this evidence, cleanup stops with `instance.transfer_source_router_unknown`. Recover the original Router identity from retained operation evidence before retrying; the current Cluster membership alone does not establish that identity.

## Finish cleanup

Successful transfer deletes the old managed checkout or owned worktree and its runtime artifacts. It releases an obsolete generated Route. It preserves unowned worktree resources such as the common repository and sibling worktrees.

After cutover, an obsolete generated Route stops contributing serving and DNS projections. Its record reserves the old domain until cleanup succeeds. The Gateway reconciles the old workload and Router before removing their unused certificates, firewall rules and source placement. A cleanup failure keeps the destination authoritative and retains the transfer identity for an identical retry.

Successful cleanup clears the destination Route's replacement state and completes the transfer together. The resulting Route can be used by environment operations and a later transfer, including a transfer back to the original Node.

The result reports the destination Node, destination path, authoritative domain, and whether cleanup completed. Completion depends on verified source, environment, runtime, Route, and placement state. It does not depend on a successful application HTTP response.

## Run the same-Cluster transfer

Move a development Instance to another app-dev Node in the same Cluster:

```text
orbit instance:transfer 11 8 --force
```

The destination path uses the destination Node apps root, the Project slug, and the current Instance name. A generated Route that keeps the same Cluster TLD keeps its domain and Route ID.

## Run the cross-Cluster transfer

Move the same Instance to an app-dev Node in another Cluster:

```text
orbit instance:transfer 11 9 --name=preview --force
```

The optional name recalculates the destination path and generated domain. A generated domain that changes uses the destination Cluster TLD and replaces the source Route.

## Failure codes

The Gateway returns these transfer conflicts before or during the operation.

| Code | When the Gateway returns it |
| --- | --- |
| `instance.lifecycle_conflict` | The Instance is not active, has no authoritative Route, or has an incomplete Route replacement. |
| `instance.production_refused` | The Instance is not development. |
| `instance.migration_required` | The Instance still requires source migration. |
| `instance.removal_conflict` | The Instance is being removed. |
| `instance.same_node` | The destination is the current Node. |
| `instance.node_inactive` | The source or destination Node is not an active Linux Node. |
| `instance.node_not_app_dev` | The source or destination Node has no active app-dev role. |
| `instance.standalone_unsupported` | The source or destination Node is not in an active Cluster. |
| `instance.identity_conflict` | The destination name is already owned on the Project. |
| `instance.destination_exists` | The destination path is occupied, overlapping, or unsafe. |
| `route.domain_conflict` | The destination domain is already owned. |
| `instance.transfer_retry_conflict` | A different request tried to resume an incomplete transfer. |
| `instance.transfer_failed` | Transfer failed and the Gateway restored or retained the current authority. |
| `instance.transfer_cleanup_incomplete` | The destination is authoritative and old-placement cleanup still needs the identical retry. |
| `instance.transfer_source_router_unknown` | The original Router identity is unavailable, so source projection cleanup cannot proceed. |
| `instance.transfer_cleanup_conflict` | Recorded placement or Route ownership changed, so cleanup stops without finalizing the transfer. |
