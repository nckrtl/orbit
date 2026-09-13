# AppInstance cloning

This page tells an operating agent how the Gateway creates a prepared production AppInstance from an eligible development or production candidate. [ADR 0047](../decisions/0047-create-production-appinstances-from-candidates.md) owns candidate cloning, [ADR 0023](../decisions/0023-separate-hostname-selection-from-cluster-routing.md) owns private Route scope and Router projection, [ADR 0044](../decisions/0044-own-appinstance-environment-configuration-in-orbit.md) owns stored environment configuration, and [ADR 0048](../decisions/0048-copy-app-process-and-schedule-definitions-into-appinstances.md) owns runtime-definition copies.

## Request a clone

An authorized client sends one candidate AppInstance selector and the target identity to the Gateway.

| Input | Requirement |
| --- | --- |
| Endpoint | `POST /api/v1/instances/{candidate}/clone` |
| `node_id` | Required positive ID of the destination Node |
| `name` | Required normalized target AppInstance name |
| `preview_name` | Required name that Orbit normalizes before it derives the preview hostname |
| `branch` | Optional existing target repository branch; omission inherits the candidate's configured branch |
| `sqlite_source_path` | Optional absolute path to one SQLite database on the candidate |

The request accepts no target App, commit identifier, Unix user, destination path, or other source or runtime override. The Gateway refuses malformed JSON, duplicate members, unknown members, and invalid values before it changes stored or remote state.

The caller needs directed access to both the candidate Node and the destination Node. The candidate selects the App. The destination must be an active Node with the active `app-prod` role, and the App must not already have a production AppInstance there. The destination can be standalone or a member of an active Cluster. A Cluster destination needs its active Router before cloning can reserve the target. Existing active-role, production-placement, and legacy production conflicts still apply.

## Clone from the CLI

Provision the production Node with its own TLD before it receives a clone. The `--tld` value supplies the suffix for private production preview hostnames:

```text
orbit node:provision production production.example \
  --role=app-prod \
  --tld=prod.orbit
```

Run the clone command with a candidate selector, destination Node selector, target name, and required preview name:

```text
orbit instance:clone CANDIDATE NODE NAME \
  --preview-name=shop.com \
  [--branch=BRANCH] \
  [--sqlite-source-path=PATH]
```

Use unambiguous candidate and Node identifiers in noninteractive and `--json` calls. The command sends typed requests through the PHP software development kit (SDK) and does not open a local or remote shell. Its result identifies the target AppInstance ID, configured branch, actual preview hostname, and current selected release. A new target has no selected release.

The candidate supplies committed source evidence, stored environment values, and an optional SQLite snapshot. The candidate's App supplies the production Process and Schedule definitions. Cloning copies no candidate-specific Process or Schedule override and starts no copied runtime.

After cloning, update target environment values that must differ from the candidate and synchronize them. Remove copied queue entries or perform other application-specific cleanup on the target only. Configure deployment steps, then run the separate `instance:deploy` command to create and select the first release. Cloning does not deploy the target.

## Derive the private preview

The Gateway normalizes `preview_name`, appends the destination Node's own TLD, and validates the complete hostname. For example, `shop.com` on a Node whose TLD is `prod.orbit` produces `shop.com.prod.orbit`.

The destination Node must have its own TLD. Orbit does not replace or supplement that value with the Cluster TLD, including when the destination belongs to a Cluster with a different TLD. A missing Node TLD, missing required Router, invalid full hostname, or hostname already owned by another Route stops cloning before target reservation.

Orbit stores the resolved hostname as an explicit private Route. A standalone destination gives the Route Node scope. A destination in an active Cluster gives the Route Cluster scope while retaining the hostname derived from the production Node TLD. Orbit does not store the Route as generated from the Node, so a later TLD change does not rename the preview. Replacing the preview with an intended production hostname remains a separate explicit Route operation.

## Check candidate eligibility

The Gateway accepts an active development candidate with its recorded usable checkout or an active production candidate with a selected release. It inspects the selected source through the candidate's recorded Node, placement, and runtime identity.

The candidate must have no staged, unstaged, nonignored untracked, or submodule change. Its current source commit must be available from the App repository, and the selected target branch must exist there. The caller supplies no commit identifier. A failed source, branch, or repository check stops cloning before target preparation.

## Prepare independent target state

The Gateway reconstructs the selected App repository branch beneath the target's owned production release directory. It does not copy the candidate working directory and does not select the target's `current` release link.

Orbit copies every stored candidate environment entry into an independently encrypted target value. Literal values such as `APP_KEY` and reference expressions remain unchanged in storage. Target synchronization resolves `{{app_instance.hostname}}` and `{{app_instance.environment}}` against the new AppInstance after the reusable remote preflight succeeds. The clone copies no source `.env` bytes, cached Laravel configuration, or local environment-file edits.

Orbit copies the App's production Process and Schedule definitions into independent AppInstance-owned records. It installs those copies in stopped state and does not use candidate-specific overrides. A PHP source receives a dedicated target runtime from Orbit defaults; a non-PHP source receives no PHP runtime. Candidate dependencies, ignored logs, caches, and local PHP-FPM tuning do not transfer.

## Select an optional SQLite database

Database seeding is optional. When a clone includes a seed, the operating agent supplies one explicit absolute path on the candidate AppInstance.

The Gateway validates the source and target before it can replace the target database.

| Boundary | Requirement |
| --- | --- |
| Source placement | The resolved path stays within the candidate's recorded development checkout or selected production release. |
| Source identity | The Gateway checks and reads the path as the candidate's recorded runtime user. |
| Source file | The path identifies one readable regular SQLite database with valid structure. |
| Capacity | The candidate has enough temporary space for the bounded snapshot, and the target has enough space for its installation. |
| Target path | The destination is the production home's `database.sqlite`, owned by the target runtime user with protected permissions. |

An unsafe path, unreadable or non-regular file, invalid database, unavailable execution identity, or insufficient source or target capacity stops the seed before target replacement. Omitting `sqlite_source_path` creates no database.

## Keep the candidate live

Orbit creates a transactionally consistent SQLite snapshot while the candidate can continue writing in write-ahead logging mode. It checks the snapshot's integrity before transfer.

Cloning does not stop source processes or schedules, pause queue processing, clear a queue, force a checkpoint, or modify source code, stored environment configuration, database bytes, or runtime desired state. The installed target seed contains the committed state represented by the snapshot, including queued rows stored there.

## Complete without deployment

A successful request returns the ordinary active production AppInstance and its sole private preview Route. The prepared target has no selected deployment release. Cloning does not require an application response, run a framework command, deploy code, select `current`, or start a Process or Schedule.

The first deployment is a separate explicit request. It fetches the target's configured branch, synchronizes its stored environment, runs only configured deployment steps, and selects the new release as described in [Production release layout](deployments.md).

For a standalone destination, private Domain Name System (DNS) resolves the preview directly to the workload Node. For a Cluster destination, private DNS resolves it to the Router. The Router and workload receive separate Orbit certificate authority identities. Router Caddy preserves the preview hostname as both the HTTP `Host` value and Transport Layer Security server name when it forwards to the workload. The workload firewall permits only the required private path, and Caddy uses the target's dedicated PHP socket when the source needs PHP. [Routes](routes.md#initial-private-projection) describes the shared private projection, and [PHP runtimes](php-runtime.md#production-runtime) describes the dedicated production service.

Clone preparation publishes no public listener or Ingress certificate. Orbit can prepare and activate the private Route before a deployment selects application code, so projection checks verify private routing and Transport Layer Security without requiring an HTTP success response.

## Retry the owned operation

The Gateway records the immutable clone request and bounded provisioning checkpoints before each owned effect. An interrupted identical request resumes the unfinished target even if the candidate has moved to a later commit after reservation. The target keeps its selected App repository branch, private Route scope, and prepared source and projection state; it does not become a snapshot of the candidate working tree.

A source, certificate, firewall, Caddy, Router, or private DNS failure records its bounded clone boundary for the retry. A request that changes the candidate, destination, name, preview, branch override, or SQLite selection refuses without adopting or replacing that target.

After completion, an identical request returns the same AppInstance and Route. It does not revalidate changing candidate state or replace target source, database, stored environment edits, definition copies, runtime desired state, or final hostname. Temporary SQLite work belongs to the clone operation and is cleaned without removing unrelated files.

Orbit does not clean application data in either database. Before target workers start, the operating agent removes copied target queue entries or performs other target-only application cleanup when the application requires it. External storage and databases other than SQLite need separate preparation.

The owning implementation and tests live in `apps/gateway`.
