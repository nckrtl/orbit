---
title: "Instance cloning"
description: "How the Gateway creates a prepared production Instance from a development or production candidate, with an optional SQLite seed."
covers:
  - apps/gateway/app/Actions/Instances/{CloneInstanceAction,CloneInstanceEnvironmentAction,InstantiateProjectRuntimeDefinitionsAction}.php
  - apps/gateway/app/Domain/Instances/{InstanceCloneCandidateInspector,CloneCandidateSource,ProductionCloneRouteProjector,ProductionInstanceProvisioner,ProductionInstanceSourceLifecycle}.php
  - apps/gateway/app/Domain/Instances/Sqlite/**
  - apps/gateway/app/Infrastructure/*/NativeProduction*Provisioner.php
  - apps/gateway/app/Infrastructure/Instances/{RemoteInstanceCloneCandidateInspector,RemoteInstanceSqliteSeeder,ProtectedSqliteSnapshotTransfer,RemoteProductionInstanceSourceLifecycle}.php
  - apps/gateway/app/Http/{Controllers/Api/InstanceClonesController.php,Requests/Instances/CloneInstanceRequest.php}
  - apps/cli/app/Commands/Instances/CloneInstanceCommand.php
  - packages/php-sdk/src/Requests/Instances/CloneInstanceRequest.php
---

# Instance cloning

Every production Instance starts as a clone. The Gateway copies a candidate Instance's committed source, stored environment, and optionally one SQLite database to a new Instance on an `app-prod` Node. The candidate keeps running. The clone ends with a prepared production home and no release. Its first [deployment](/reference/deployments) selects code. Provisioning creates the production PHP runtime through the native provisioner and projects its Route through the shared native Route projector.

`instance:create` on an `app-prod` Node returns `instance.candidate_required`. The `app-prod` role decides this, not `APP_ENV`.

## Prepare the Node

The destination Node needs its own TLD for the preview domain of a serving app. Set it when you add the Node:

```bash
orbit node:add production production.example --role=app-prod --tld=prod.orbit
```

## Clone

Name the candidate, the destination Node, the new Instance name, and a preview name:

```bash
orbit instance:clone <candidate> <node> <name> --preview-name=shop.com [--branch=BRANCH] [--sqlite-source-path=PATH]
```

The CLI calls `POST /api/v1/instances/{candidate}/clone`.

| Input | Meaning |
| --- | --- |
| `node_id` | Destination Node with an active `app-prod` role. Required. |
| `name` | Name of the new Instance. Required. |
| `preview_name` | Name that the preview domain starts with. Required. |
| `branch` | Branch to deploy. It must exist in the repository. It defaults to the candidate's branch, or its deployment branch for a production candidate. |
| `sqlite_source_path` | Absolute path to one SQLite database on the candidate. Optional. |

The request accepts no Project, commit, Unix user, or path. The candidate decides the Project. Laravel-package apps use their declared app type for source classification; they do not need an `artisan` application to clone. The caller needs an [access grant](/cli/node) to both Nodes.

The result names the new Instance, its branch, its preview domain, and its selected release, which is empty.

## Candidate rules

The candidate is an active development Instance with its checkout, or an active production Instance with a selected release. A development candidate must have its recorded branch checked out, not another branch or a detached `HEAD`. Its source must have no staged, unstaged, untracked, or submodule change. Its current commit must be in the Project repository. The Gateway checks this on the candidate's Node using its recorded source identity.

Every Git invocation in the candidate inspection, including calls through `sudo` and checks inside submodules, passes `-c core.hooksPath=/dev/null` and `-c core.fsmonitor=false`. When a worker is configured, development candidate checks that inspect file contents run as that worker without a credential environment.

A checkout's hooks and custom filesystem monitor do not run during that check. These overrides leave the candidate's stored Git configuration unchanged; they do not relax the clean-source rules.

### Destination checks

The destination must be an active Linux Node with an active `app-prod` role. The Project can have one production Instance per Node. In an active Cluster, the Cluster needs an active Router.

Orbit inspects the destination checkout as its production user from a directory that user can access. A private SSH account home does not prevent source classification. For a nested Laravel app, classification uses `composer.json` and `artisan` in the [application directory](/reference/projects#application-directory) of the app's effective path; Git identity checks still use the complete repository. Foreign file ownership, unsafe source metadata, and failed directory scans still stop the clone.

## What the clone gets

The new Instance gets its own copy of each part below.

| Part | Result |
| --- | --- |
| Source | A new checkout of the branch from the Project repository, inside the production home. Orbit copies no files from the candidate's working directory. |
| Environment | Every stored candidate value, encrypted again for the new Instance. Then Orbit sets `APP_ENV=production` and `APP_DEBUG=false`, or `APP_ENV=prod` and `APP_DEBUG=0` for a `symfony-app`. You can change them later. |
| Processes and Schedules | Copies of the Project's production [definitions](/reference/processes-and-schedules#production-copies), installed stopped. Candidate-specific Processes and Schedules do not copy. |
| PHP | A [dedicated PHP-FPM service](/reference/php-runtime#production-runtime) with Orbit defaults, when the source uses PHP. |
| Route | One private preview Route for a serving app. A non-serving package gets no Route. |
| Apps | The Project's app and the candidate's app overrides. |

Before the clone completes, Orbit renders the new Instance's stored values and writes its `.env` in the production home. The first deployment links that file as the [production release layout](/reference/deployments#the-production-home) describes, including a nested path such as `apps/site/.env`. It copies no `.env` file from the candidate, and no cached configuration, dependencies, logs, caches, or PHP-FPM tuning. Stored values such as `APP_KEY` copy as they are. References such as `{{instance.domain}}` resolve against the new Instance.

## Preview domain

Cloning has no app selector. Production supports a Project with one app. The preview domain is `<preview-name>.<node-tld>`. It does not start with the app name. For example, `shop.com` on a Node with TLD `prod.orbit` gives `shop.com.prod.orbit`. Orbit never uses the Cluster TLD here.

Orbit stores the preview as an explicit private Route, so a later TLD change does not rename it. On a standalone Node the Route has Node scope. In an active Cluster it has Cluster scope, and private DNS points to the Router. Cloning publishes no public listener. Replace the preview with the production domain through a separate [Route](/reference/routes) change.

A missing Node TLD returns `route.tld_required`. A domain that another Route owns returns `route.domain_conflict`.

## SQLite seed

`sqlite_source_path` must be inside the candidate's checkout or selected release, and it must be a readable SQLite database. Orbit takes a consistent snapshot while the candidate keeps writing, checks its integrity, and installs it as `<home>/database.sqlite`, owned by the new Instance's user. The Gateway checks the path and disk space on both Nodes before it replaces anything. Without the option, the clone gets no database.

Cloning seeds a different production Instance at the fixed `<home>/database.sqlite` path. [Instance transfer](/reference/instance-transfer) instead keeps the Instance ID between development Nodes and installs the snapshot at the selected file's relative path in the destination checkout. Each transfer attempt has its own snapshot record. Transfer rollback abandons that seed and removes its owned temporary files before closure. An identical retry after restoration takes a fresh snapshot rather than reusing data captured before the source restarted. These transfer rules do not change the clone's destination or its identical-request retry.

The snapshot holds whatever the candidate committed at that moment, queued jobs included. Remove copied queue rows or other data on the new Instance before you start its workers. Other databases and external storage need their own preparation.

## Candidate stays live

Cloning never stops the candidate's Processes or Schedules, pauses queues, or changes its source, environment, or database.

## Retry

The Gateway records the clone request and each finished step. An identical request resumes an interrupted clone, even when the candidate has moved to a newer commit. A request that changes the candidate, Node, name, preview, branch, or SQLite path returns `instance.clone_retry_conflict`. After completion, an identical request returns the same Instance and changes nothing. `instance:create` does not create or resume a production Instance.

While a clone is incomplete, removal of its candidate returns `instance.clone_in_progress`.

## Errors

The Gateway returns these codes for a clone. A failure after reservation records its code on the new Instance, and the identical request resumes it.

| Code | Cause |
| --- | --- |
| `instance.node_inactive`, `instance.node_not_app_prod` | The destination is not an active Linux Node with an active `app-prod` role. |
| `instance.production_placement_conflict` | The Project already has a production Instance on the Node. |
| `instance.placement_conflict` | Another Instance of the Project has the name. |
| `instance.clone_reservation_conflict` | Another request took the name or preview domain at the same moment. |
| `route.tld_required`, `route.domain_conflict` | The preview domain cannot be built or is taken. |
| `instance.clone_retry_conflict` | A different request tries to resume the clone. |
| `instance.clone_candidate_inactive`, `instance.clone_candidate_unavailable` | The candidate is not active, or Orbit cannot inspect it. |
| `instance.clone_candidate_branch_invalid` | The candidate has no branch, or a development candidate is on another branch. |
| `instance.clone_candidate_dirty` | The candidate source has changes. |
| `instance.clone_candidate_commit_unavailable`, `instance.clone_candidate_repository_unavailable` | The repository does not have the candidate's commit, or Orbit cannot read it. |
| `instance.clone_candidate_release_missing`, `instance.clone_candidate_release_changed` | A production candidate has no selected release, or it changed. |
| `instance.clone_candidate_source_invalid`, `instance.clone_candidate_environment_invalid` | The candidate's source or environment does not match its record. |
| `instance.clone_target_branch_missing` | The branch is not in the repository. |
| `instance.clone_candidate_changed` | The candidate's commit, branch, or placement changed during the request. |
| `sqlite.seed_preflight_failed`, `sqlite.seed_transfer_failed`, `sqlite.seed_failed` | The SQLite seed failed its checks, its copy, or its install. |
| `instance.clone_sqlite_unconfirmed` | Orbit cannot confirm the SQLite result. Retry. |
| `instance.clone_failed` | Another step failed. |

## Next steps

1. Update environment values that must differ from the candidate, then [synchronize](/cli/env) to rewrite `.env`.
2. Clean copied application data, such as queue rows.
3. Record [deploy steps](/reference/deployments#deploy-steps).
4. Run `instance:deploy` to create and select the first release.
5. Start the copied Processes and Schedules.

## Why it works this way

These reasons explain the design. Check them before you propose a change.

### Clone from a candidate

A repository does not hold an Instance's environment or data. A candidate does. So Orbit creates production from an existing Instance. Direct production creation from a repository was rejected. Any eligible development or production Instance can be the candidate.

### Rebuild source from the repository

Copying the candidate directory would carry dependencies, logs, caches, and Node-specific configuration. So the clone checks out committed source from the repository, and a dirty candidate is refused.

### Keep the candidate running

Stopping workers or clearing queues on the candidate would disturb a live application. So Orbit takes a live SQLite snapshot and leaves target cleanup to you.

### No automatic deployment

You need to check the environment and deploy steps before the first release. So cloning and deployment are separate requests.
