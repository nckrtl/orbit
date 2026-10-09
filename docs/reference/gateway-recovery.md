---
title: "Update and recover a Gateway"
description: "Preserve source, state, and keys through a Gateway update, recover from a failed update, find the request logs, receive release alerts, and follow the fleet rollout."
covers:
  - apps/gateway/app/{Domain,Infrastructure}/Releases/**
  - apps/gateway/config/{app,logging}.php
  - apps/gateway/app/Infrastructure/Logging/**
  - apps/gateway/app/Domain/Gateway/GatewayCacheStore.php
  - apps/gateway/app/Infrastructure/Gateway/GatewayCheckoutAccessConverger.php
  - apps/gateway/app/**/GatewayReleases/**
  - apps/gateway/app/**/*GatewayRelease*.php
  - apps/gateway/{app/Domain/Nodes/NodeUpdat*.php,app/Data/Nodes/NodeUpdatingData.php,app/Domain/Fleet/**,app/Infrastructure/Fleet/**,app/Actions/Fleet/**,app/Data/Fleet/**,app/Models/FleetRollout*.php,app/Console/Commands/FleetConvergeCommand.php,app/Http/Controllers/Api/FleetRolloutsController.php,config/fleet.php}
---

# Update and recover a Gateway

This guide helps an operator keep Gateway state safe during a source update and recover when an update fails. It uses the installation layout from the [Quickstart](/quickstart). Test the procedure on a disposable copy before you rely on it for important data.

## Gateway request logs

The Gateway logs an exception at `ERROR` when its response status is 500 or higher. A lower status is a client refusal. The Gateway does not log it, because [Activity](/cli/activity) already records the failed request. An invalid task definition is HTTP 422, so it is one of those refusals.

Every log entry written during an HTTP request carries that request's `request_id`. When the request creates an Activity row, its `activity_log.request_id` has the same value. Search the Gateway logs for the Activity row's request ID to find all related request log entries.

Gateway log files rotate daily and keep 14 days. `LOG_DAILY_DAYS` changes the count.

Gateway web setup lets Caddy read the regular files and directories under the checkout's `public` directory, also files restored with restrictive permissions. It does not follow symlinks there or change the permissions of other source files. The Gateway `.env` stays at mode `0600`. In the [release layout](#release-layout), each release gets this access when it is prepared.

## Preserve a complete state set

The database alone is not a recoverable Gateway backup. Keep these inputs together and store the backup outside the machine, with access limited to administrators.

| Input | Why it matters |
| --- | --- |
| Exact monorepo commit and lock files | Reinstalls the matching CLI, Gateway, and SDK code. |
| Gateway `.env` | Holds configuration. It can hold the encryption key or a custom database path. |
| Complete `ORBIT_HOME` | Holds `gateway.sqlite` and its WAL files, the SSH identity and known hosts, WireGuard keys, the root CA, generated state, and `gateway.app-key` when the `.env` has no key. |
| Machine configuration or VM snapshot | Keeps service, firewall, DNS, network, ownership, and package state when an update changes them. |
| Application backups | Application databases, uploads, and source are separate from Gateway state. |

A nonempty `APP_KEY` in the environment takes precedence over `ORBIT_HOME/gateway.app-key`. Preserve the effective key with its encrypted data. Do not generate a replacement key during an update or restore. A retained public CA certificate cannot replace its private key.

## Back up before an update

Schedule a maintenance window and stop CLI users and automation from changing the fleet. Record the current commit, Gateway status, and the first application's successful HTTPS response. Keep provider-console access available.

On the Gateway, record the source revision as `orbit`:

```bash
cd /home/orbit/orbit
git status --short
git rev-parse HEAD
apps/cli/orbit gateway:status
```

Resolve or preserve local edits before checkout changes. Stop incoming requests and any running Gateway console operations before copying state. For a complete machine recovery point, take a provider or hypervisor snapshot while the machine is shut down. Store the recorded commit and snapshot identifier with the backup.

For a state archive on a quiescent machine, run these commands from a root console. Use a new backup directory for each attempt:

```bash
sudo systemctl stop orbit-runtime-hibernator.timer
sudo systemctl stop orbit-runtime-hibernator.service caddy php8.5-fpm
sudo install -d -m 0700 /root/orbit-backups
test ! -e /root/orbit-backups/gateway-before-update.tar.gz || exit 1
sudo tar --acls --xattrs -czpf /root/orbit-backups/gateway-before-update.tar.gz \
  -C / home/orbit/.orbit home/orbit/orbit/apps/gateway/.env
sudo chmod 0600 /root/orbit-backups/gateway-before-update.tar.gz
sudo tar -tzf /root/orbit-backups/gateway-before-update.tar.gz
```

In the release layout, archive `home/orbit/shared/gateway.env` instead of `home/orbit/orbit/apps/gateway/.env`, which is a link there.

Adjust the paths when `ORBIT_HOME` or `DB_DATABASE` differs. Include an external database path and its `-wal` and `-shm` files, as [SQLite WAL default](/solutions/sqlite-wal-default) explains. Pause Gateway timers and external automation too; stopping the web services alone does not stop console writers. Do not copy a live SQLite file without its journal state. Transfer the archive to protected storage and verify that it can be read before changing source.

The archive contains secrets. Do not attach it to a bug report or commit it to Git. A successful archive listing verifies readability, not restore behavior.

## Release layout

A Gateway in the release layout runs from immutable releases instead of an in-place checkout. Each release is one exact commit, built beside the live one, so preparing a release never changes what the Gateway serves.

| Path | Content |
| --- | --- |
| `/home/orbit/releases/<id>/` | One release: a linked Git worktree of one commit, with its own `vendor/` for `apps/cli` and `apps/gateway`, and a `REVISION` file. `<id>` is the first 12 hex digits of the commit. |
| `/home/orbit/orbit` | A link to the current release. `PATH`, systemd units, the PHP-FPM `chdir`, and the Caddy root keep using this path. |
| `/home/orbit/shared/orbit.git` | The bare repository every release is a worktree of. Its `origin` is the Orbit repository. |
| `/home/orbit/shared/gateway.env` | The one Gateway env file. `apps/gateway/.env` in every release links to it. |
| `/home/orbit/shared/gateway-storage/` | The Gateway storage directory. `apps/gateway/storage` in every release links to it, so the file cache, its locks, and the logs stay the same across releases. |
| `ORBIT_HOME` | Gateway state, outside every release, as before. |

The paths come from `ORBIT_GATEWAY_CHECKOUT`, which names `apps/gateway` below the current link. Run release commands through that link, as `php /home/orbit/orbit/apps/gateway/artisan`. A shell that changed into the directory before a switch still runs the release it entered. A release directory is never changed after it is prepared. Its source directories are read-only, so an in-place `git checkout` or `composer install` inside a release fails instead of changing it. Only `apps/gateway/bootstrap/cache` and `apps/cli/storage` stay writable.

The Gateway reports its version in `gateway:status`. When `APP_VERSION` is unset or empty, the version is the full commit in the release's `REVISION` file, so `bin/deploy-verify --sha` works without an env edit. Without either, the version is `dev`. Leave `APP_VERSION` out of the shared env file in the release layout; a set value always wins.

### Prepare a release

As `orbit`, build a release for one commit:

```bash
php /home/orbit/orbit/apps/gateway/artisan gateway:release:prepare <SHA>
```

Name the commit by its hex SHA, 7 to 40 characters. Branch names, tags, and other revision syntax are refused. Prepare fetches the repository's branches through the [GitHub App](/reference/github-app) when the commit is not present yet, and fetches a full SHA directly when no branch holds it any more. Then it:

1. creates the worktree `releases/<id>` for the exact commit;
2. links the shared env file and the shared storage directory into it;
3. runs `composer install --no-dev` and `composer check-platform-reqs --no-dev` for `apps/cli` and `apps/gateway`, with the committed locks. A release holds only the packages it runs, not Pest, PHPStan, Pint, Rector, or Boost;
4. installs the commit's [web build](#web-build) from CI into the web directory, without serving it yet;
5. gives Caddy the same access to the release's `public` directory that [Gateway web setup](#gateway-request-logs) gives a checkout, and makes the source directories and files read-only;
6. writes `REVISION`, then runs `php artisan config:cache` in the release, so the cached configuration holds the release's version. The cache holds `APP_KEY`, so it is mode `0600`.

Every artisan command of a release runs with a clean environment that has only `HOME`, `PATH`, and `LANG`, so the release reads its configuration from the shared env file alone. These commands, and `composer install`, hold `ORBIT_HOME/gateway-release-step.lock` while they run. When the process that started one dies, for example with its SSH session, the command still finishes. Until it has, the next release step is refused with `gateway.release_in_progress`.

Only a release with a `REVISION` file is prepared. When the configuration cannot be cached, prepare removes `REVISION` again. The Gateway runs with a cached configuration, so a change to the shared env file takes effect only after [`gateway:release:configure`](#apply-an-env-change). A release that already has it is reused without another build step. Only its web build is installed again when it is missing. A partial release from a failed or interrupted prepare is removed and built again on the next run of the same commit, or [pruned](#deploy-a-release) by a later verified release once it is 1 hour old.

Prepare never touches the current release link, the database, or a running service.

Prepare refuses before it fetches or writes when the releases directory has less free space than `ORBIT_GATEWAY_RELEASE_MIN_FREE_MB`, 1024 MiB by default. Each release has its own `vendor/` directories. Releases share the Git objects in `shared/orbit.git`, so a release costs about the size of its source and its two `vendor/` directories, about 115 MB without development packages.

The command prints one JSON object. Success exits 0 with `release`, `sha`, `path`, `reused`, and `duration_ms`. A refused commit exits 2. Every other failure exits 1 with `error_code`, `step`, and `message`.

| Error code | Meaning |
| --- | --- |
| `gateway.release_commit_invalid` | The commit is not a hex SHA of 7 to 40 characters. |
| `gateway.release_commit_unknown` | The repository does not have the commit, or the prefix names more than one commit. |
| `gateway.release_layout_missing` | The shared repository or env file is missing. |
| `gateway.release_in_progress` | Another release step holds the single-flight lock in `ORBIT_HOME/gateway-release.lock`, or a command of an earlier step still runs. |
| `gateway.release_disk_low` | The releases directory has less free space than the floor. |
| `gateway.release_conflict` | The release directory holds another commit with the same 12-digit id. |
| `gateway.release_current_incomplete` | The current release has no `REVISION`. Repair it before preparing it again. |
| `gateway.release_layout_invalid` | `ORBIT_GATEWAY_CHECKOUT` is not an absolute `<base>/<checkout>/apps/gateway` path. |
| `gateway.release_lock_unavailable` | The release lock file cannot be opened. |
| `gateway.release_fetch_failed`, `gateway.release_worktree_failed`, `gateway.release_link_failed`, `gateway.release_dependencies_failed`, `gateway.release_access_failed`, `gateway.release_revision_failed`, `gateway.release_configuration_failed` | The named build step failed. The live release is unchanged, and the commit is marked failed unless the failure is the machine (disk, lock, or a missing layout). |
| `gateway.release_web_build_missing` | CI published no unexpired `web-dist-<sha>` artifact from an [accepted CI run](#accepted-ci-runs) of the commit. The commit is marked failed. |
| `gateway.release_web_build_invalid` | The artifact is larger than the limits, has no digest or does not match it, has an unsafe entry, or has no `index.html`. The commit is marked failed. |
| `gateway.release_web_build_unavailable` | GitHub Actions cannot be read: no GitHub App, no `Actions: read` permission, or no answer. The commit is tried again later. |
| `gateway.release_web_directory_missing`, `gateway.release_web_install_failed` | The web directory is missing, or the build cannot be written or given to the `caddy` group. The commit is tried again later. |

#### Web build

The web app ships with the Gateway release of the same commit. On every CI run on `main`, a push or a manual dispatch, CI uploads the build of `apps/web` as the artifact `web-dist-<sha>` and keeps it for 14 days ([Feature delivery: CI](/reference/implementation-loop#ci)). Prepare installs it into `web/releases/<id>` of the [web directory](/reference/web-app#web-directory), in the layout `bin/web-deploy` uses:

1. It asks the [GitHub App](/reference/github-app#read-ci-artifacts) for a token with only `Actions: read`, for the repository that `shared/orbit.git` names as `origin`.
2. It finds the newest unexpired artifact with that name from an [accepted CI run](#accepted-ci-runs) of exactly this commit.
3. It downloads the archive, at most 128 MiB, from GitHub's artifact storage without the token. The download must match the artifact's SHA-256 digest.
4. It extracts the archive into a hidden staging directory and refuses unsafe entries, listed below.
5. It requires `index.html` at the top. It gives directories mode `0750` and files `0640`, with group `caddy`.
6. It moves the staging directory to `web/releases/<id>` in one rename.

Extraction accepts only regular files and directories with plain relative names. It refuses links, special files, absolute names, `..`, duplicate and encrypted entries, and entries whose size or checksum differs from their header. It also refuses more than 20,000 entries or more than 512 MiB of files.

A complete build is reused. Nothing serves it until the release verified: deploy then switches `web/current` to it in one rename. Open pages read the new build's `version.json` and move to it on their next navigation or resume ([Updates to open pages](/reference/web-app#updates-to-open-pages)). A failure leaves no partial build behind. After a verified release, the Gateway removes every web build that belongs to no retained release, such as the build of a commit whose prepare failed later, or an older `bin/web-deploy` build. It never removes the build `current` serves. When the releases directory cannot be read, it skips this cleanup and logs a warning.

CI's `Required checks` job needs the Web job, and the Web job uploads the artifact before it succeeds. So a commit with passing checks has its artifact, and a missing or expired one fails the commit at once instead of waiting for it. A newer commit is released instead. The artifact expires after 14 days, so deploying or adopting an older commit fails at this step.

##### Accepted CI runs

The run must be a `push` or a `workflow_dispatch` of `.github/workflows/ci.yml` on `main`, in this repository. A run from a fork, a pull request, another workflow, or another branch does not count. An artifact without a SHA-256 digest is refused.

### Deploy a release

Deploy is the manual release. Before it changes anything, it:

1. prepares the commit;
2. refuses a commit that does not descend from the current release, with `gateway.release_downgrade`;
3. refuses a commit that lacks a migration the `migrations` table has applied, with `gateway.release_migration_crossed`;
4. caches the release's configuration again from the shared env file, so a broken env file stops the release before any migration.

An unreadable `migrations` table refuses with `gateway.release_migrations_unreadable`. `--force` skips these refusals. It never migrates backwards.

Then it migrates when needed and switches `/home/orbit/orbit` to the release with one `mv -T`. The switch runs only when the current path is already a release link. An in-place checkout, or a link to something else, is refused until `gateway:release:adopt`.

```bash
php /home/orbit/orbit/apps/gateway/artisan gateway:release:deploy <SHA>
php /home/orbit/orbit/apps/gateway/artisan gateway:release:deploy <SHA> --force
```

After the switch, deploy:

1. runs the serving phase of the runtime handoff: Caddy, PHP-FPM, and the units;
2. verifies the release, as described below;
3. runs the scheduler phase of the handoff: the scheduler drain and restart, document cleanup, the Gateway Node's agent update, and the OPcache reset;
4. switches `web/current` to the release's [web build](#web-build) with one rename;
5. runs [smoke](#smoke) against the switched release.

Verify runs before the scheduler phase, so a broken release is found and switched back without waiting for a long scheduled command or an idle pool.

Verify checks `GET /up` and Gateway status at `ORBIT_GATEWAY_VERIFY_ORIGIN` (default `https://gateway.orbit`). Status must be `ok`, and the version must be the full commit or its 12-digit id. A busy pool can queue `/up` behind long requests, so a failed check runs again after 2, 4, 8, 16, and 30 seconds.

Smoke does not run before the web switch. Any failure after the switch counts, also an error the release code did not expect (`gateway.release_unexpected_failure`):

- Without migrations, deploy switches back to the previous release and repeats the handoff phases that started. `web/current` returns to its earlier build. The outcome is `switched_back`.
- A previous release whose code has no handoff command, or no phases, gets the handoff from the deploying process's code.
- A previous release that lacks a migration the database applied gets no switch-back. Deploy pauses instead. This can follow an earlier pause.
- When the `migrations` table cannot be read, deploy pauses too, because it cannot rule that out.
- After migrations, deploy pauses. It writes `ORBIT_HOME/gateway-release.paused` and records outcome `paused`. It never switches back onto a schema the previous code has not run.
- A switch that fails after migrations pauses too. The previous code then serves the new schema.

To decide, deploy compares the migrations the database has applied with the previous release's files, never with what is pending now. A killed attempt of the same commit can leave such a migration.

After a verified release, deploy removes old releases. It keeps the newest `ORBIT_GATEWAY_RELEASES_KEEP` releases (default 5), and always the current and the previous one.

It also removes each release directory without `REVISION` that is more than 1 hour old, with its web build. Such a directory is left by a prepare that stopped, for example an adoption that lacked a GitHub App permission, and no later prepare of another commit removes it. Every prepare holds the release lock, and so does deploy while it prunes, so no prepare is writing to the directory. The age is a margin on top.

Deploy keeps an incomplete directory that is the current or the previous release, because that release needs a repair. It also keeps a directory whose `REVISION` names another commit. Remove that one by hand. A directory that cannot be removed stays, and each verified release logs a warning with its id.

#### Migrations and the snapshot

Before the switch, deploy compares the migration files the release ships with the `migrations` table. When none are pending, it neither snapshots nor migrates.

When some are pending, deploy first writes a consistent copy of the Gateway database to `ORBIT_HOME/backups/pre-<id>-<time>-<random>.sqlite`, with mode `0600`. Each attempt writes its own file, so a retry after a failed migration never replaces the clean copy. A retry of a paused commit keeps naming the copy from before its first attempt, in its record and in the pause marker. It uses SQLite `VACUUM INTO`, so it needs no `sqlite3` binary. Then the release migrates with its own code, `php releases/<id>/apps/gateway/artisan migrate --force`, while the previous release still serves.

- The Gateway keeps the newest `ORBIT_GATEWAY_RELEASE_SNAPSHOTS_KEEP` snapshots (default 5), and always the one the pause marker names.
- The release migrates with `migrate --isolated`, so a second migration waits for a running one.
- A snapshot needs room for the database and its WAL plus the free-space floor. Prepare keeps that room too, so a release refuses with `gateway.release_disk_low` before it builds anything.
- A failed snapshot changes nothing, and the commit is tried again later.
- A failed migration may have applied part of its changes. Deploy then pauses with `gateway.release_migrate_failed` and names the snapshot. To go back to it, follow [Recover a failed update](#recover-a-failed-update) with the snapshot as the database file.

#### Runtime handoff

The handoff runs `php releases/<id>/apps/gateway/artisan gateway:release:handoff --phase=serve`, then, after verify, `--phase=schedule`, with the code of the release that just became current. A release that changes what the Gateway renders applies that change at once, although the deployer itself runs from the previous release. In order, the handoff:

The serving phase:

1. publishes the Gateway Node's Caddyfile when the render changed, with a graceful Caddy reload;
2. compares the rendered pool with `/etc/php/8.5/fpm/pool.d/orbit-gateway.conf` and reloads PHP-FPM only for a difference, as a reload ends requests in flight;
3. installs the hibernator, agent-view, and [release units](#release-units) again and restarts agent-view. The units name the stable `/home/orbit/orbit/apps/gateway` path.

The scheduler phase:

1. moves the scheduler to the new release without cutting off a scheduled command;
2. restarts every other Gateway Node Process whose directory is in the Gateway application;
3. resumes [document cleanup](/reference/project-documents#restore-time-cleanup-gate);
4. updates the Gateway Node's own `orbit-agent` to the release's pin, described below;
5. resets OPcache, described below.

PHP-FPM is not restarted for a release. Caddy resolves the `/home/orbit/orbit` link for each request (`resolve_root_symlink`) and passes PHP-FPM the release's real script path. A request that started before the switch finishes on its release, and the next one runs the new release. The `/grafana` authorization resolves the link the same way. A fixed script path through the link would let each PHP-FPM worker keep the old release in its realpath cache for up to two minutes.

Release files never change in place, so OPcache never marks the scripts of an old release as wasted, and the cache fills up. The scheduler phase therefore ends with an OPcache reset through the pool's socket, with a script outside `public/`.

All pools of the PHP-FPM master share one OPcache. OPcache restarts once no request uses the cache. A restart that stays pending for `opcache.force_restart_timeout` (180 seconds) kills the workers that still serve a request, and a Gateway request may run 600 seconds. So the handoff resets only while no enabled pool has a connection on its `listen` socket, and the restart happens with the next request. It waits up to 60 seconds for that moment. Then it asks the pool again until the restart has happened.

| `opcache.outcome` | Meaning |
| --- | --- |
| `reset` | The cache restarted. `cache_full` and the memory figures from before and after are in the record. |
| `deferred` | A pool stayed busy for 60 seconds. A later release resets it. |
| `pending` | The reset was accepted, but the restart was still pending after 5 seconds. |
| `failed` | The pool could not be asked. The release continues. |

A request that starts in the milliseconds between the idle check and the reset, and runs longer than 180 seconds, could still be killed. Setting `opcache.force_restart_timeout` above 600 seconds closes that gap, but it needs a PHP-FPM restart, and a restart ends requests in flight while `process_control_timeout` is 0. Leave it for a planned PHP-FPM restart. The handoff never reloads PHP-FPM for OPcache.

The scheduler is the Gateway Node's systemd Process that runs `schedule:work` in the Gateway application directory. Every Gateway runs one. A missing one fails the handoff with `gateway.release_scheduler_missing`, and one in another directory with `gateway.release_scheduler_mismatch`, because it would keep the old code. The handoff never cuts off a scheduled command in the normal path. It moves the scheduler in these steps:

The drain needs the scheduler's PHP to have the `pcntl` extension, so `schedule:work` can handle SIGTERM. Without it, the handoff takes the forced path at once and records `reason: no_pcntl`.

1. It runs `schedule:interrupt`, so repeating events such as `tasks:tick` stop after their current run.
2. It sends SIGTERM to the `schedule:work` main process only. That process starts no new `schedule:run` and waits for the running ones, so a long command such as a development deploy finishes on the release it started on.
3. When the main process has exited, it starts the unit, which now runs the new release, and waits until it is active.

Each command ran to its end and released its own overlap lock, so the handoff clears none. While the old scheduler drains, it starts nothing, so `tasks:tick` pauses for the drain at most.

The drain waits at most `ORBIT_GATEWAY_RELEASE_SCHEDULER_DRAIN_SECONDS` (default 600). After that limit, the handoff takes the `tasks:tick` lock and stops the unit, which ends what still runs. It then clears the schedule's overlap locks with `schedule:clear-cache`, because the stopped commands cannot release them, and starts the unit. The release record's `handoff.scheduler_drain` shows `outcome` (`drained`, `forced`, or `not_running`), `waited_ms`, the commands that were `running` when the drain began, and, after a forced stop, the commands it `stopped`.

The new scheduler pauses document cleanup when it starts. The handoff waits for the new cleanup generation, runs `project-documents:cleanup:reconcile`, and resumes with that report. Without configured document storage it reports `skipped`. When the report has differences or resume is refused, cleanup stays paused. The release record then has `cleanup_paused: true` and the handoff's `cleanup_error_code`, and the release continues.

The handoff also installs the [fleet rollout units](#units). A failure there only logs a warning.

When any step fails, the handoff still starts the scheduler unit, so the scheduler never stays down. The handoff prints one JSON object with `caddy`, `fpm`, `opcache`, `scheduler`, `scheduler_unit`, `scheduler_drain`, `processes_restarted`, `cleanup`, `cleanup_error_code`, `agent_view`, `cleanup_paused`, and `gateway_agent`. Run it again by hand after fixing a handoff failure. It takes no release lock, so run it only when no release step is running.

##### Gateway Node agent

The [fleet rollout](#rollout-set-and-order) never visits the Gateway's own machine, so the scheduler phase updates that Node's `orbit-agent` itself. It runs with the release's own code, so it uses that release's [agent pin](/reference/node-agent#install-and-upgrade). It runs after verify, so an agent restart never delays verify. When smoke later fails and the release switches back, the scheduler phase runs again with the previous release's code, which moves the agent to that release's pin. It leaves the Gateway's CLI alone: the Gateway runs the release's own `apps/cli`.

The update runs one script through local `sudo`, never over SSH to itself. The script holds `/run/lock/orbit-self-update.lock`, the lock that `orbit self-update` and the agent converge hold, and waits up to 120 seconds for it. It does nothing when the unit at `/etc/systemd/system/orbit-agent.service` lacks the `# Managed by Orbit: agent` marker, or when the binary already matches the pinned SHA-256.

Otherwise it takes the converge's steps: it downloads a candidate, checks the SHA-256, sets `root:root` and mode `0755`, and renames the candidate into place. Only then does it restart `orbit-agent.service`. The agent must stay active without a restart for 5 seconds, the health window of `orbit self-update`. When the restart fails or the agent does not stay up, the script restores the previous binary from `/usr/local/bin/orbit-agent.orbit-previous` and restarts it. Each restart has a 60-second limit.

The handoff result records the update as `gateway_agent`. It has an `outcome` and the pinned `version`:

| Outcome | When |
| --- | --- |
| `unchanged` | The binary already matched the pin. The agent did not restart |
| `updated` | The pinned binary replaced the old one and stayed up. `previous_sha256` names the old binary, or is null when there was none |
| `skipped` | `reason` is `not_installed` when the Node has no Orbit agent unit, or `platform` when the Node does not run Linux |
| `failed` | `error_code` and `message` say why: `agent.binary_download_failed`, `agent.checksum_mismatch`, `agent.install_failed`, `agent.restart_failed`, `agent.unhealthy`, `agent.architecture_unsupported`, or `node.update_busy` |

A failed update never fails or switches back the release. The release stays `verified`, and the record raises one [`release_gateway_agent_failed` alert](#release-alerts), stored as `gateway_agent.alert`. Doctor keeps reporting `node.agent_binary_mismatch` for the Gateway Node until a later release updates the agent. To retry sooner, run the scheduler phase of the handoff again by hand, as described above. Do not run `orbit self-update` on the Gateway machine, because it also replaces the Gateway's CLI.

#### Smoke

Smoke runs `bin/gateway-smoke` of the new release, `releases/<id>/bin/gateway-smoke`, as the Gateway account. So it is the exact code under test, and it uses that release's `apps/cli/orbit` with the account's Gateway profile. [Delivery-line proofs: bin/gateway-smoke](/reference/delivery-line#bingateway-smoke) lists its checks. The release passes:

| Option | Value |
| --- | --- |
| `--sha` | The release's commit. |
| `--since` | The time the runtime handoff started, in whole seconds. The scheduler and agent view must have restarted after it. |
| `--timeout` | `ORBIT_GATEWAY_RELEASE_SMOKE_TIMEOUT`, default `90` seconds. |
| `--checkout`, `--web-dir` | `/home/orbit/orbit` and the web directory, so the checks read the live paths. |
| `--web-url`, `--up-url`, `--status-url` | Built from `ORBIT_GATEWAY_VERIFY_ORIGIN`. |
| `--write-check --smoke-project` | Only when `ORBIT_GATEWAY_RELEASE_SMOKE_PROJECT` names a Project. |

The Python checks trust Orbit's root CA through `SSL_CERT_FILE`, set to Caddy's `root-ca.pem` unless the environment already sets it. The document write check is off by default, because it writes a Project Document on every release. To turn it on, create a dedicated Project and set `ORBIT_GATEWAY_RELEASE_SMOKE_PROJECT` to its ID or slug in the shared env file, then [apply the env change](#apply-an-env-change).

The release record stores the smoke JSON as `phases.smoke.report`, also when smoke fails. Smoke fails when it exits nonzero, reports `passed: false`, prints no JSON, or the release has no `bin/gateway-smoke`. When it runs 15 seconds past its own limit, the step fails with `gateway.release_smoke_timeout`. `timeout` then sends `SIGTERM` to smoke, which kills every check command it started, each in a session of its own, and prints a `terminated` result that the record keeps as the report. After 5 more seconds, `timeout` kills what is left. No check outlives the step. A smoke failure is handled like a failed verification: switch back without migrations, pause after them.

Smoke does not wait for the first `tasks:tick`, because a restarted scheduler starts it only at the next full minute. It checks that the scheduler's process runs from the new release and that the release's `artisan schedule:list` lists `tasks:tick`. The first tick is [confirmed after the release](#post-release-tick-confirmation). Pass `--wait-for-tick` to `bin/gateway-smoke` by hand to wait for it instead.

#### Post-release tick confirmation

A verified release starts with the step `tick` set to `pending`. The step records `since`, the time the runtime handoff started. It also records `deadline`, which comes `ORBIT_GATEWAY_RELEASE_TICK_CONFIRMATION_SECONDS` after the release was verified. The default is 180 seconds, and the minimum is 60. Every [tick of the release runner](#what-a-tick-does) decides the pending confirmations it can, also while automatic releases are disabled or paused, so a manual deploy and a rollback are confirmed too.

`tasks:tick` records when it started and the version of the code that ran it, the commit in the release's `REVISION`. The step ends as one of these:

| Outcome | When |
| --- | --- |
| `confirmed` | A tick started at or after `since` and ran the release's own commit. It records `last_tick_at`, `last_tick_version`, and `decided_at`. A tick that ran during smoke confirms the release at once |
| `missed` | The first runner tick after `deadline` saw no such tick. It records the last tick it saw and raises at most one [`release_scheduler_silent` alert](#release-alerts), stored as `tick.alert` |
| `skipped` | The tasks extension is disabled, so the scheduler runs no `tasks:tick` |
| `superseded` | A newer verified release went live. That release has a confirmation of its own. While another release is only being tried, the step stays `pending`, because a failed attempt switches back |

A missed confirmation does not switch back or pause. The release passed verify and smoke, and its scheduler runs the new code, so a forward fix still ships automatically. The record stays `verified`, and the fleet rollout follows it as usual. Check the scheduler unit with `systemctl status` and its journal. `gateway:release:show` lists the step with the others, and `gateway:release:auto:status` shows it for the current release as `tick_confirmation`.

Run the same smoke by hand against the live Gateway. It runs `bin/gateway-smoke` of the current release for its commit, or for the commit you name. It changes nothing and writes no release record.

```bash
orbit gateway:release:smoke [<SHA>] [--since=<TIME>]
```

From any Node with access to the Gateway, [`orbit gateway:release:smoke`](/cli/gateway#orbit-gatewayreleasesmoke) asks the Gateway to run it. Smoke restarts nothing and writes no record, so it runs inside the API request, unlike a deploy. An API request gets 570 seconds, 20 of them reserved for cleanup, and PHP-FPM ends it after 600. So the API lowers the smoke limit to what the request has left, about 520 seconds at most. With the 15-second grace period and the stop and kill delays, the run ends within the request. The default limit of 90 seconds stays as it is.

Checks that did not pass answer with the `failed` outcome and the report, and the CLI exits 1. One API smoke runs at a time, because a run and its checks hold several PHP-FPM workers. A second one fails with `gateway.release_smoke_in_progress`.

On the Gateway host, the Artisan command runs the same smoke without the lower limit:

```bash
php /home/orbit/orbit/apps/gateway/artisan gateway:release:smoke [<SHA>] [--since=<TIME>]
```

It prints one JSON object with `release`, `sha`, `outcome`, and the smoke `report`. A failed smoke exits 1 with `error_code`, `step`, `message`, and the report under `detail.report`. A commit or `--since` value it cannot read exits 2.

#### Release records

Every attempt writes one release record. The record exists from the start of the attempt and stores each step as it ends, so a caller can follow it. The same attempt writes an Activity entry with the command `gateway:release:<trigger>`. A refusal that changes nothing, such as an unknown commit or a downgrade, ends its record `failed` and retryable, so the commit is not marked failed. A step refused by the release lock writes nothing, unless it ran for a queued record, which then ends `failed`.

| Field | Content |
| --- | --- |
| `id` | The record id |
| `release`, `sha` | The release id and the full commit. Both are null while a queued deploy names a short SHA that prepare has not resolved |
| `requested` | The commit or release id the caller named |
| `trigger` | `deploy` for a manual release, `auto` for the [automatic runner](#automatic-releases), or `rollback` |
| `outcome` | `queued` or `running` while the attempt runs, then `verified`, `switched_back`, `paused`, `failed`, or `interrupted` |
| `finished` | False while the outcome is `queued` or `running` |
| `retryable` | True when the commit may be tried again. A retryable failure does not mark the commit failed |
| `phases` | Each step that ended, in order, with its outcome |
| `alert` | The receipt of the [release alert](#release-alerts) the record raised, or null |

A failure the commit itself causes, such as a downgrade, a failed migration, or a missing or invalid web build, is final at once. Any other failure, such as low disk, a fetch error, a slow verify, or an unexpected error, is recorded with `retryable: true` for the first two attempts of a commit; the third is final. Only earlier finished attempts that failed, switched back, or were interrupted count, from a deploy, an automatic release, or an adoption. A rollback, a verified release, and the attempt that runs do not count.

`gateway:release:list` reads the 50 newest records. `gateway:release:show` takes a record id of one to six digits, or a hex SHA of 7 to 40 characters for the newest record of that commit.

A record is `interrupted` when its process ended before it wrote an outcome, for example after a kill or a unit timeout. Three places end such a record with `gateway.release_interrupted`:

- the release unit's `ExecStopPost`, which runs `gateway:release:settle` as soon as its main process exits;
- every tick of the automatic runner, under the release lock;
- every new release attempt and every API request, under the release lock.

Under the lock no release process runs, so a `running` record is dead. `ExecStopPost` also ends a `running` record only while the lock is free. A `queued` record is dead when its unit does not run two minutes after the request. When an interrupted record's steps show a snapshot, a migration, or a switch, it pauses automatic releases and alerts, because a retry could run old code on a migrated schema.

Otherwise it spends one attempt of the commit's retry budget, and it alerts once that budget is spent. An attempt that died before prepare named the commit counts by the full SHA it requested.

While a record is `running`, the Gateway Node reads as [updating](#nodes-being-updated).

One release step holds `ORBIT_HOME/gateway-release.lock`. A second step is refused with `gateway.release_in_progress`.

#### Deploy through the API

`orbit gateway:release:deploy <sha>` and `orbit gateway:release:rollback <id>` ask the Gateway API for the release. The release never runs in the PHP-FPM worker that answers, because it can restart the scheduler and reload PHP-FPM. Instead the Gateway:

1. ends the records of dead releases, then checks that no release step holds the lock and no requested release waits to start;
2. writes a `queued` record;
3. starts `orbit-gateway-release-run@<record>.service` with `sudo systemctl start --no-block`;
4. answers 202 with the queued record.

The unit runs `gateway:release:run <record>`, which claims the record and runs the same deploy or rollback as the commands above. The CLI follows the record until it finishes. A queued start is no proof, so the Gateway reads the unit state for up to five seconds after it. When systemd refuses the unit, the unit fails, or it neither runs nor claims the record in time, the request fails with `gateway.release_unit_failed` and the record ends as failed. Install the units with `orbit:gateway-web` on the Gateway.

| Error code | Meaning |
| --- | --- |
| `gateway.release_not_adopted` | `/home/orbit/orbit` is still an in-place checkout. |
| `gateway.release_not_prepared` | The release id is not a finished release. |
| `gateway.release_switch_failed` | The current link could not be replaced. It stays on the previous release. |
| `gateway.release_migrations_unreadable` | The `migrations` table could not be read. Nothing changed. |
| `gateway.release_snapshot_failed`, `gateway.release_snapshot_unavailable` | The pre-migration snapshot failed, or the database is not SQLite. Nothing changed. |
| `gateway.release_migrate_failed` | The release's migrations failed. The release pauses. |
| `gateway.release_caddy_failed`, `gateway.release_fpm_failed`, `gateway.release_units_failed` | The handoff could not publish Caddy, reload PHP-FPM, or install the Gateway units. |
| `gateway.release_scheduler_busy` | After the drain limit, a tasks tick held its lock for more than 330 seconds, so the scheduler was not stopped. See below. |
| `gateway.release_scheduler_failed` | The scheduler unit did not stop, start, or become active. |
| `gateway.release_handoff_failed` | The release printed no handoff result. |
| `gateway.release_verify_failed` | `/up` or Gateway status did not match the commit. |
| `gateway.release_web_build_missing` | The release's web build is not installed, so `web/current` was not switched. |
| `gateway.release_web_publish_failed` | `web/current` could not be switched. It stays on the build it served. |
| `gateway.release_smoke_failed` | Smoke failed after the web switch, or printed no result. |
| `gateway.release_smoke_timeout` | Smoke ran past its limit and was stopped. |
| `gateway.release_smoke_killed` | Smoke was killed before its limit by something else, such as the kernel's out-of-memory killer. |
| `gateway.release_smoke_missing` | The release has no `bin/gateway-smoke`. |
| `gateway.release_smoke_in_progress` | Another smoke run through the API is in progress. |
| `gateway.release_switch_back_failed` | The failure was real, and returning to the previous release also failed. |
| `gateway.release_configuration_failed` | The release's configuration could not be cached again. It runs before migrations, so nothing changed. |
| `gateway.release_unexpected_failure` | A step failed with an error the release code did not expect. The message names it. |
| `gateway.release_migration_crossed` | The database has applied a migration the target release does not ship. |
| `gateway.release_downgrade` | The commit does not descend from the current release. |
| `gateway.release_scheduler_missing`, `gateway.release_scheduler_mismatch` | The Gateway Node has no scheduler Process, or it runs outside the Gateway application path. |
| `gateway.release_unit_failed` | systemd did not start the release unit for a requested release. |
| `gateway.release_not_queued` | `gateway:release:run` named a record that is not queued. |
| `gateway.release_not_found` | No release record matches the id or commit. |
| `gateway.release_interrupted` | The process of a running record ended before it wrote an outcome. |

When the handoff reports `gateway.release_scheduler_busy`, the old scheduler still finishes its commands and exits. With `Restart=always`, systemd then starts it on the current release. Otherwise, start its unit by hand.

### Roll back

Roll back to return the Gateway to a release it still keeps, for example after a pause or a bad release that verify and smoke did not catch.

```bash
php /home/orbit/orbit/apps/gateway/artisan gateway:release:rollback <id>
php /home/orbit/orbit/apps/gateway/artisan gateway:release:rollback <id> --force
```

`<id>` is the first 12 hex digits of a retained release. Rollback switches to it and runs the same handoff, verify, web switch, and smoke as a deploy. It refuses when the database has applied a migration the target does not ship. `--force` switches the code anyway and names the newest pre-migration snapshot. It does not migrate backwards. A failed verification switches back to the release that was current, because rollback itself does not migrate.

Rollback switches the web app to the target's build. Pruning a Gateway release removes its web build too, so every retained release keeps its own, and a manual `bin/web-deploy` never prunes it. When the build is gone anyway, rollback installs it from the commit's CI artifact, which exists for 14 days. When the artifact has expired too, a rollback does not block the code on assets: it leaves `web/current` as it is, records the web step as `kept` with a warning, and smoke skips its `web` check. A deploy always fails without its web build.

### Apply an env change

Every release caches its configuration, so an edit to `/home/orbit/shared/gateway.env` changes nothing until you cache it again. As `orbit`, after the edit:

```bash
php /home/orbit/orbit/apps/gateway/artisan gateway:release:configure
```

It caches the current release's configuration beside the live cache and renames it into place, so a request never reads half of it. Do not run `php artisan config:cache` in a release: it rewrites the live cache in place. A deploy or rollback caches its target again, so a retained release picks up the edit when it goes current.

## Automatic releases

The Gateway can release itself from its own branch. A timer looks for a new commit that passed CI's `Required checks` every minute, and the release goes live without a maintenance window. Automatic releases are disabled by default. An operator turns them on after [adoption](#release-layout) and a first release by hand. The operator also decides what happens after a [pause](#pause).

```bash
orbit gateway:release:auto:enable
orbit gateway:release:auto:status
orbit gateway:release:auto:disable
```

### Release units

The Gateway runs releases in systemd units, never in PHP-FPM or the scheduler. `orbit:gateway-web` and every [runtime handoff](#runtime-handoff) install them. The handoff writes the units and enables the timer, but it never starts or restarts a release unit, so the release that runs the handoff keeps running.

| Unit | Runs |
| --- | --- |
| `orbit-gateway-release.timer` | Starts `orbit-gateway-release.service` every minute, with up to 10 seconds of random delay. It does not catch up missed runs |
| `orbit-gateway-release.service` | One `gateway:release:auto` tick |
| `orbit-gateway-release-run@.service` | One [requested release](#deploy-through-the-api), with the record id as the instance |

Each unit runs as `orbit` from `/home/orbit/orbit/apps/gateway`, so a run starts from the release that is current. It sets `PATH`, `HOME`, and `LANG=C.UTF-8`, so `php`, `git`, and `composer` resolve as they do for the account. A unit may run for one hour, because prepare runs `composer install`. A timer tick that comes while the service still runs starts nothing. After the main process exits, `ExecStopPost` runs `gateway:release:settle`: the run unit with its record id, the service without one.

### What a tick does

Each tick of `gateway:release:auto` takes these steps and stops at the first that ends it:

1. It decides the pending [tick confirmations](#post-release-tick-confirmation).
2. It stops when automatic releases are disabled or paused. A stall ends then.
3. A requested release that waits for its unit goes first. The tick stops.
4. It takes the release lock and ends the records of dead releases. A busy lock stops the tick.
5. It stops without a deployed commit: the Gateway runs from no release, or `REVISION` is unreadable.
6. It asks GitHub for the newest [green commit](/reference/github-app#find-the-newest-green-commit) of the branch that descends from the deployed commit. Commits with a release that failed for the commit itself are left out.
7. A manual deploy that pinned an older commit after the last resume is never undone. The tick pauses with the reason `manual_deploy`.
8. It deploys that commit with the trigger `auto`, under the release lock.

The repository is the `origin` of the shared release repository. `ORBIT_GATEWAY_RELEASE_BRANCH` and `ORBIT_GATEWAY_RELEASE_CHECK` name the branch and the check, by default `main` and `Required checks`. A commit whose release failed or switched back with a retry left is tried again after 10 minutes, up to three attempts.

Every tick stores its result as the last tick. A tick prints it as one JSON object. It exits 1 only when it ran a release that failed.

| Result | Meaning |
| --- | --- |
| `disabled`, `paused`, `busy` | Automatic releases are off or paused, or another release step runs or waits for its unit. A `busy` result counts toward a stall |
| `not_adopted`, `no_deployed_release` | The Gateway does not run from a release, or its `REVISION` is unreadable. Deploy the first release by hand |
| `up_to_date` | No newer commit qualifies |
| `backing_off` | The newest commit failed for a retryable reason less than 10 minutes ago |
| `source_unavailable` | GitHub, the App, or the shared repository's origin did not answer. The tick is skipped |
| `released` | The tick released a commit, and it went live |
| `failed` | The tick released a commit, and the release failed or switched back |

### Pause

A pause is durable state with a reason. It holds until `resume` or until a deploy ends `verified`, whatever other release records come after it. The start of every pause raises one `release_paused` alert that names the reason.

| Reason | Cause |
| --- | --- |
| `migration_failure` | A release failed after its migrations ran. It stays current |
| `rollback` | A rollback went live. The runner does not release a newer commit over it |
| `manual_deploy` | A manual deploy went live while a newer green commit already existed: a deliberate pin of an older commit. The runner does not undo it |
| `interrupted` | A release died after a snapshot, a migration, or a switch |
| `marker` | Only `ORBIT_HOME/gateway-release.paused` exists, as an older Gateway wrote it |

A manual deploy or rollback still runs during a pause. A manual deploy, an automatic release, or an adoption that ends `verified` ends a pause, so a forward fix hands back to automation. An adoption that ends `resumed` serves a release that was never verified, so it keeps the pause. A rollback that goes live starts a `rollback` pause instead.

When a manual deploy goes live, the Gateway asks GitHub whether a newer green commit exists. It stores the answer on the record as the `newest_green` step: `superseded` with that commit, `newest`, or `unknown` when GitHub did not answer. `superseded` and `unknown` make the next tick pause with `manual_deploy`, so a pin is never undone on a guess. After a GitHub outage, a manual deploy may need one `resume`. A manual deploy of the newest green commit hands back to automation, and a newer commit that turns green later is released as usual.

After a failure, decide between a forward fix, a [rollback](#roll-back) with `--force`, and a restore from the snapshot. Then clear a pause that is left:

```bash
orbit gateway:release:auto:resume
```

Resume removes the pause and the marker. The next tick releases the newest green commit, also over a manual release from before the resume. Resume refuses with `gateway.release_not_paused` when nothing is paused. A commit that failed is not released again automatically.

### Failure and alerts

A release record that ends `paused`, or that ends `switched_back`, `failed`, or `interrupted` with no retry left, raises one [release alert](#release-alerts). An `interrupted` record that touched live state alerts at once, because it pauses. A pause that a rollback or a manual deploy starts alerts too. So does a release that goes live but leaves document cleanup paused. The receipt is stored on the record, so the record never alerts twice. A retryable failure does not alert by itself.

Two stalls alert once with the kind `release_stalled`. The subject is the deployed commit.

- A GitHub error, a retryable failure, or a busy release lock lasted 30 minutes. The next tick that reaches GitHub ends the stall.
- The branch head stayed unreleased for 6 hours. This check also runs during a pause. A deployed head ends it.

While it is up to date, the runner reads the branch head at most every 15 minutes. The second stall covers a deployed commit that left the branch after a force push, because then no newer commit can qualify.

### Read the state

`orbit gateway:release:auto:status` shows the switch, a pause with its release, error code, and snapshot, the current release, the last tick, the stalls, and the current release's [tick confirmation](#post-release-tick-confirmation). `orbit gateway:status` shows the current release and a short summary. A last check older than two minutes means the timer does not run. Check it with `systemctl status orbit-gateway-release.timer` on the Gateway.

## Adopt the release layout

A Gateway installed with the [Quickstart](/quickstart) runs from an in-place checkout at `/home/orbit/orbit`. `gateway:release:adopt` converts it into the [release layout](#release-layout) once, and the Gateway keeps serving throughout. Run it from a temporary checkout of the commit to adopt into, so the in-place checkout needs no update first. After adoption, deploy, roll back, and configure with the release commands. An update in place then fails, because each release is read-only.

### Before you adopt

Check these conditions on the Gateway before you run the command.

- Back up as in [Back up before an update](#back-up-before-an-update).
- `git status --short --untracked-files=no` in `/home/orbit/orbit` prints nothing. Untracked files, such as `.env` backups, are fine.
- The disk has room for one release, about the size of the checkout without `.git`, plus the free-space floor and a database snapshot.
- `python3` is installed. Ubuntu installs it by default. Without it, the swap uses two renames, and the path is missing for the microseconds between them.
- The Gateway GitHub App installation has accepted `Actions: read`. See [Read CI artifacts](/reference/github-app#read-ci-artifacts). Phase 2 needs it for the web build.
- `orbit node:list` succeeds as `orbit` on the Gateway. The [Quickstart](/quickstart) step "Connect the CLI" sets this up. Smoke needs it.

Without the permission, phase 2 stops in prepare with `gateway.release_web_build_unavailable`. Nothing changes live. Without the CLI profile, smoke fails with `gateway.profile_missing`, and phase 2 pauses after its migrations.

### Run adoption

As `orbit`, make a temporary checkout of the commit to adopt into, `<SHA>`, and install its dependencies. Its env file is a link to the Gateway's, so the command reads the Gateway's configuration and state. Keep its logs, which record the run:

```bash
git clone --quiet https://github.com/nckrtl/orbit.git /home/orbit/adopt-tmp
git -C /home/orbit/adopt-tmp checkout --quiet --detach <SHA>
composer --working-dir=/home/orbit/adopt-tmp/apps/gateway install --prefer-dist --no-interaction
ln -s /home/orbit/orbit/apps/gateway/.env /home/orbit/adopt-tmp/apps/gateway/.env
php /home/orbit/adopt-tmp/apps/gateway/artisan gateway:release:adopt --commit=<SHA> | tee /home/orbit/.orbit/logs-adopt.json
mv /home/orbit/adopt-tmp/apps/gateway/storage/logs /home/orbit/.orbit/logs-adopt-tmp
rm -rf /home/orbit/adopt-tmp
```

Adoption holds the release lock and runs in two phases. The first changes the layout but not the code. The second changes the code through a normal deploy. So no process ever runs code from two commits.

Phase 1 runs these steps for the commit the in-place checkout has:

| Step | What happens |
| --- | --- |
| Check | Tracked changes in the checkout refuse adoption with `gateway.release_adopt_local_changes`. |
| Repository | `shared/orbit.git` is a bare clone of the checkout's repository, with the same `origin` and remote branches. `<SHA>` comes from the temporary checkout, or through the [GitHub App](/reference/github-app). |
| Env file | `shared/gateway.env` is a copy of `apps/gateway/.env` with any `APP_VERSION` line commented out, so each release reports its own commit. |
| Env link | The original stays as `apps/gateway/.env.pre-adopt`, and one rename replaces `.env` with a link to the shared file. The running code uses its cached configuration, so it serves the same. |
| Env backups | Each `apps/gateway/.env.bak*` file is copied to `shared/env-backups/`. |
| Storage | `apps/gateway/storage` moves to `shared/gateway-storage` through two exchanges with prepared links, so its path always reaches the same directory and is never missing. |
| Prepare | `releases/<id>` is built for the checkout's own commit. With `--commit`, it gets no web build, because that commit may predate the CI artifact. |
| Swap | `renameat2(RENAME_EXCHANGE)` swaps the checkout directory with a link to that release. The directory stays as `/home/orbit/orbit.pre-adopt-<time>`. |
| Handoff | The temporary checkout's code hands the runtime over, because the checkout's commit may predate the handoff command. The app code that serves is the same as before. |
| Serving | `/up` is up and Gateway status is `ok`. The version may be `dev`, because `APP_VERSION` is commented out. |

Phase 2 deploys `<SHA>` exactly as [Deploy a release](#deploy-a-release) describes, with its snapshot, migrations, handoff, verify, web build, and smoke. Without `--commit`, it deploys the checkout's commit itself, which then must have the command; that adds the web build, the exact version check, and smoke. The web build comes from the commit's CI artifact, which CI keeps for 14 days, so phase 2 needs a commit built within that time.

The command prints one JSON object with `release`, `sha`, `from`, `pre_adopt_path`, `shared`, `switch`, `phase1`, and `deploy`, the phase-2 release record. `switch.method` and `shared.storage_method` are `exchange`, or `rename` with the gap in `switch.gap_us` and `shared.storage_gap_us`. Each phase writes a release record with trigger `adopt` and an Activity entry.

Running it again on a Gateway that finished adoption prints `"already": true` and changes nothing. When an earlier run swapped and then stopped before it verified, for example because its session dropped, the next run hands the runtime over and checks serving again. It records outcome `resumed`, prints `"resumed": true`, and then runs phase 2, which verifies the exact version. Only the phase-1 release can resume this way, and a `resumed` record keeps any pause marker; only a verified release clears it.

The kept checkout is a complete way back. Its `.env.pre-adopt` holds the original env file, so keep its permissions, and remove it once a few releases have verified:

```bash
rm -rf /home/orbit/orbit.pre-adopt-<time>
```

The first release runs the checkout's own commit. That commit may predate the release version, so the release reports `dev`, and a rollback to it fails verify and switches back. With `--commit`, it also has no web build of its own. Roll back to a later release instead.

### When adoption fails

Each step checks whether it already ran, so after fixing the cause, run the command again.

| Failure | Result |
| --- | --- |
| A refusal, or a failure before the swap | The checkout keeps serving, with its original `.env` back. The storage link stays, as it reaches the same files. |
| Phase-1 handoff or serving check | Adoption swaps the checkout back, restores the original `.env`, and hands the runtime back. The record says `switched_back`. |
| Phase 2 | The Gateway stays adopted. The deploy switches back to the phase-1 release or pauses, as any deploy does. |

The phase-1 release may have no handoff command of its own. A switch-back to it then gets the handoff from the deploying process's code.

When even the swap back fails, the outcome is `gateway.release_switch_back_failed`. Swap back by hand as `orbit`, then hand the runtime over with the temporary checkout's code:

```bash
cd /home/orbit
mv -T orbit orbit.adopt-link && mv -T orbit.pre-adopt-<time> orbit && rm orbit.adopt-link
mv orbit/apps/gateway/.env.pre-adopt orbit/apps/gateway/.env
php /home/orbit/adopt-tmp/apps/gateway/artisan gateway:release:handoff
```

| Error code | Meaning |
| --- | --- |
| `gateway.release_adopt_local_changes` | The checkout has tracked changes. |
| `gateway.release_adopt_not_checkout`, `gateway.release_adopt_unsupported` | `/home/orbit/orbit` is not an in-place checkout, or is a link to something other than a release. |
| `gateway.release_adopt_env_missing`, `gateway.release_adopt_env_conflict` | The checkout has no `.env` file, or `shared/gateway.env` exists with other contents. |
| `gateway.release_adopt_storage_missing`, `gateway.release_adopt_storage_conflict` | The storage directory is missing, or both it and `shared/gateway-storage` exist. |
| `gateway.release_adopt_repository_conflict` | `shared/orbit.git` exists without the checkout's commit. |
| `gateway.release_adopt_conflict` | A link or kept checkout from an earlier attempt is in the way. |

### After adoption

The release directories are read-only, and `bin/bootstrap` refuses to run in a release. `orbit:gateway-web` converges the release link: it keeps `/home/orbit` and `/home/orbit/releases` traversable for Caddy and the shared env file at mode `0600`, and leaves the releases themselves unchanged.

## Recover a failed update

Keep requests and automation paused. Save redacted failure output and preserve the failed state separately for diagnosis. Do not use a generic migration rollback to guess compatibility with older code.

If a command failed before any state or machine change, restore the previous source commit and its locked dependencies, then verify status and HTTPS before reopening access. Once migrations or machine changes ran, recover the complete pre-update state set. The safest recovery for this walkthrough is the matching stopped-machine snapshot. Do not connect a restored clone to the original fleet at the same time: it contains the same WireGuard, SSH, and CA identities.

For an archive-based restore, stop all Gateway writers and use a root console. Move the failed `ORBIT_HOME` aside rather than extracting over it, restore the archive's original ownership and permissions, restore the matching source commit, and reinstall its locked CLI and Gateway dependencies. Restore any external database and service configuration from the same recovery point. Clear stale Laravel configuration, then start services and repeat status, Node, Doctor, DNS, and HTTPS checks before reopening access.

Do not migrate the restored database forward while attempting to run the older code. If the effective encryption key, CA private key, database, or required machine state is missing, stop: an apparently healthy process cannot prove recovery. This procedure does not roll back changes already made to other Nodes or restore application data; recover those from their own backups and review their consistency with the Gateway.

## Release alerts

A release command raises an alert when a release fails, when it pauses automatic releases, or when a fleet rollout halts. The alert leaves an Activity entry and a problem for the [outer loop](/reference/tasks#outer-loop), and it posts a signed webhook when one is configured. Each part is recorded on its own. A failed part never stops the others, and the alert never fails the release command that raised it. A release command raises the alert outside a database transaction, so a rollback cannot discard the records after the webhook went out.

| Kind | When |
| --- | --- |
| `release_failed` | A release failed. Nothing went live, or the previous release serves again |
| `release_paused` | A release failed after its migrations ran, or died after it touched the schema or the current link, and automatic releases are paused |
| `rollout_halted` | A fleet rollout stopped at a Node that failed |
| `release_stalled` | [Automatic releases](#failure-and-alerts) made no progress for 30 minutes, or the branch head stayed unreleased for 6 hours |
| `release_cleanup_paused` | A release went live, but [document cleanup](/reference/project-documents#restore-time-cleanup-gate) stayed paused after the handoff |
| `release_scheduler_silent` | A release went live, but its own scheduler ran no `tasks:tick` by the [confirmation deadline](#post-release-tick-confirmation). At most once per release; nothing switches back or pauses |
| `release_gateway_agent_failed` | A release went live, but the handoff could not bring the [Gateway Node's agent](#gateway-node-agent) to the pin. At most once per release; nothing switches back or pauses |
| `rollout_stalled` | `orbit self-update` on one Node stayed `incomplete` for 6 visits in a row, or a rollout waited more than 2 hours for its CLI release. The rollout does not halt |
| `rollout_caddy_skipped` | A fleet rollout kept a Node's live Caddyfile because the new one was refused. Once per rollout; the rollout does not halt |

Every alert names its subject, a summary, and an optional evidence link. The summary passes through the Gateway log redactor and is cut at 1,000 characters. A field outside these limits is a bug in the calling command, which refuses it before it records anything.

| Field | Content |
| --- | --- |
| `target` | What was released: `gateway`, `fleet`, or another lowercase name of at most 64 characters, such as `instance:12` |
| `repository` | The GitHub repository as `owner/name` |
| `sha` | The full commit SHA: 40 or 64 lowercase hexadecimal characters |
| `release_id` | The release record's id, or null. At most 64 characters |
| `evidence_url` | An `http` or `https` link to the evidence, such as a CI run, or null. At most 2,048 characters |

### Activity entry

Each alert writes one Activity entry with the command `release:alert`. `orbit activity:list --command=release:alert` lists them. The entry starts as `running` and keeps a request ID that the webhook body repeats. Its `properties` hold the kind, the subject, the summary, the evidence link, and the outcome of the problem and the webhook parts. An outcome is `done`, `skipped`, or `failed`, with a reason.

| Status | When | Error code |
| --- | --- | --- |
| `succeeded` | No part failed. A skipped part does not fail the entry | none |
| `failed` | Recording the problem failed | `release.alert_problem_failed` |
| `failed` | The problem part did not fail, but the webhook failed | `release.alert_webhook_failed` |

A failed entry is a server-class Activity row. When the webhook keeps failing, the outer loop counts those rows and files that as its own problem.

### Problem

The alert records the fingerprint `release|{kind}|{target}|{sha}`, with the source `release`. Unlike a recurring signal, it is ready on its first occurrence. The next hourly `problems:file` run files it as a Backlog task in the `orbit` Project, ahead of every other ready fingerprint. [Outer loop](/reference/tasks#outer-loop) describes the sample, the brief, the daily cap, and the mute.

| Outcome | Reason | When |
| --- | --- | --- |
| `done` | none | The fingerprint was recorded |
| `skipped` | `tasks_disabled` | The Tasks extension is disabled, so the outer loop does not run |
| `skipped` | `suppressed` | The fingerprint is on the [suppression list](/reference/tasks#suppression) |
| `failed` | `error` | The database write failed. The Gateway log has the exception |

### Webhook

Set both values in the Gateway's own `.env` to receive alerts:

| Variable | Content |
| --- | --- |
| `ORBIT_RELEASE_ALERT_WEBHOOK_URL` | The receiver's URL |
| `ORBIT_RELEASE_ALERT_WEBHOOK_SECRET` | The HMAC secret |

When either value is unset or empty, the webhook part is `skipped` with the reason `not_configured`, and the Activity entry and the problem are still recorded. The Gateway never returns the secret. It never stores the URL, the secret, or the response body, because a chat webhook URL is itself a credential. Unset both values on a disposable Gateway clone, so its alerts do not reach the operator.

The Gateway posts one JSON object. It signs `{unix timestamp}.{raw body}` with HMAC-SHA256 and sends `X-Orbit-Timestamp`, `X-Orbit-Signature: sha256={hex}`, and `Content-Type: application/json`. This is the same signature as the [Coder webhook](/reference/tasks#coder-settle-webhook). The connection timeout is 3 seconds and the whole request has 10 seconds. The Gateway follows no redirect and does not retry.

| Body field | Content |
| --- | --- |
| `event` | `release.alert` |
| `kind` | The kind above |
| `subject` | `target`, `repository`, `sha`, and `release_id` |
| `summary` | The redacted summary |
| `evidence_url` | The evidence link, or null |
| `text` | One line for a chat channel, such as `Release failed for gateway nckrtl/orbit@0123456789ab: Smoke check web.index failed.`, followed by the evidence link. It escapes `&`, `<`, and `>` as Slack expects |
| `occurred_at` | When the alert was raised, in ISO 8601 UTC |
| `request_id` | The Activity entry's request ID |
| `activity_id` | The Activity entry's id, or null when it could not be written |
| `problem_fingerprint` | The recorded fingerprint, or null |

| Outcome | Reason | When |
| --- | --- | --- |
| `done` | none | The receiver answered with a 2xx status |
| `skipped` | `not_configured` | The URL or the secret is unset |
| `failed` | `rejected` | The receiver answered with another status. The outcome keeps `http_status` |
| `failed` | `unreachable` | The connection failed or timed out |
| `failed` | `error` | Any other failure |

The body serves two kinds of receivers. A receiver that verifies the signature reads the typed fields, such as an agent's intake webhook. A Slack incoming webhook shows `text` and ignores the signature, because its URL is the credential. The Gateway still needs a secret to send, so set any random value for such a receiver.

## Fleet rollout

After each verified Gateway release, the Gateway brings every managed workload Node to the same release, one Node at a time. Each Node runs [`orbit self-update`](/reference/self-update), the same command an operator runs on a Mac. A fleet failure never rolls the Gateway back. [`fleet`](/cli/fleet) shows and resumes the rollout.

### Turn it on

The rollout is off until you set `ORBIT_FLEET_ROLLOUT=true` in the Gateway's environment. While it is off, `orbit-fleet-converge.service` exits at once, and Doctor reports no `node.release_lag`. `fleet:rollout:status` shows `Rollout off`.

| Variable | Default | Effect |
| --- | --- | --- |
| `ORBIT_FLEET_ROLLOUT` | `false` | Run rollouts and the catch-up |
| `ORBIT_FLEET_ROLLOUT_ORDER` | empty | Node names, comma-separated, that go first, in this order. The other Nodes follow in the default order. It never adds or removes a Node |

### Units

`php artisan orbit:gateway-web` and the [runtime handoff](#runtime-handoff) of each release install two units through local `sudo`. A failed install in the handoff only logs a warning; it never fails the release.

| Unit | Work |
| --- | --- |
| `orbit-fleet-converge.service` | A oneshot that runs `php artisan orbit:fleet-converge` as `orbit` from the current release. It never runs inside PHP-FPM or the scheduler |
| `orbit-fleet-converge.timer` | Starts the service 5 minutes after its last run ends, for the catch-up |

After a release is recorded `verified`, the release command runs `sudo systemctl start --no-block orbit-fleet-converge.service` and returns. The service runs the new release's code, so it resolves the desired state of the commit that now serves. A refused start only logs a warning; the timer starts the service within 5 minutes.

The release command starts the service only for a release with a desired fleet state: verify saw the Gateway serve the release's exact commit, and the release ships `orbit:fleet-converge`. Phase 1 of [adopt](#adopt-the-release-layout) never starts it. Its `verified` record only shows that the Gateway serves, often as `dev`, so a rollout never follows that record alone. The rollout follows the phase 2 deploy.

A run that is still busy when a newer release goes current does not take the start; systemd joins it to the running job. So before the run plans and before each Node, it compares the release directory that the current path links to with the directory it runs from. When they differ, the run stops, visits no further Node, and asks systemd for a fresh run 15 seconds later with `sudo systemd-run --on-active=15`. A run never applies an older desired state than the current one. It compares directories, not versions, so a leftover `APP_VERSION` never makes it restart over and over.

### Rollout set and order

The rollout visits a Node when all of these hold:

- it is not a disposable task sandbox;
- it is `active` and runs Linux;
- the Gateway manages it over SSH: it has a WireGuard address and a pinned SSH host key;
- it holds at least one active role other than `gateway`;
- it is not the Gateway's own machine: it holds no `gateway` role and is not the serving host;
- its `/usr/local/bin/orbit` is not a CLI that Orbit did not install.

Roleless Nodes, such as operator machines, and macOS Nodes stay out. Their operators run `orbit self-update`. The Gateway's own Node stays out too. Each release's [runtime handoff](#gateway-node-agent) updates its agent, and its CLI is the release's own `apps/cli`. `fleet:rollout:status` lists every Node it leaves out, with the reason `sandbox`, `inactive`, `platform`, `unmanaged`, `gateway`, `roleless`, or `foreign_cli`.

A task sandbox is an `app-dev` Node that a [task VM](/reference/compute-drivers#task-vms) or an [UpCloud sandbox reservation](/reference/compute-drivers#enroll-an-owned-project-vm) owns. The Gateway creates it for one task group and removes it when the group ends or its review window expires ([ADR 0200](/decisions/0200-run-each-task-group-in-its-own-sandbox-vm)). Provisioning gives it the agent and footprint of the Gateway's release at that time.

The rollout and the catch-up never visit a task sandbox, provisioning installs no Orbit CLI on it, and Doctor reports no `node.release_lag` for it. A group that resumes after its sandbox was destroyed gets a new sandbox Node, provisioned from the current release. The `sandbox` reason comes first, so a sandbox shows it in every state.

A Node leaves the rollout set as `foreign_cli` when the [CLI install](/reference/node-provisioning#orbit-cli) finds a link, a script, or another program at `/usr/local/bin/orbit`. That visit is `skipped`, never `failed`, and Doctor reports `node.cli_foreign`. Every later run probes the Node and, when it answers, inspects the path again, so the Node rejoins once an operator moved the file aside.

The order goes lowest risk first. Each role has a group. A Node with several roles goes in the latest group of its roles, so it waits until every lower-risk Node is done. Inside a group, the Node ID decides.

| Group | Roles |
| --- | --- |
| 1 | `app-dev` |
| 2 | `metrics`, `analytics` |
| 3 | `database`, `websocket`, `vpn`, `router` |
| 4 | `app-prod`, `ingress` |

Orbit has no `agent` or `s3` role yet. The order already places `agent` in group 1 and `s3` in group 2.

### Desired state

One run resolves the desired state of the commit the Gateway serves: the CLI release `cli-v0.N.0` with the SHA-256 of each binary, the agent pin with the SHA-256 of each binary, and the footprint digest of each Node. A footprint digest covers only what Orbit renders from its own code and pins, never a user's sites, Routes, or DNS records. It stores the state on a rollout record for that commit. The record belongs to the commit's `verified` release record, and [`gateway:release:show`](#deploy-a-release) shows it under `fleet_rollout`, with each Node's result.

A Gateway in the release layout rolls out only a commit whose release record is `verified`. A run during a release, before the record exists, does nothing (`release_unverified`). When the configured layout cannot be read, or its link names no complete release, the run rolls out nothing (`release_layout_unreadable`). An in-place Gateway, whose current path is a checkout, has no release records, and its running commit is the desired state. A Gateway version that is not a commit, such as `dev`, rolls out nothing.

CI publishes the CLI release a few minutes after the commit's checks pass, and the Gateway usually deploys the commit first. Until the release exists, the rollout is `waiting` and changes nothing on any Node. Once the release appears, the catch-up starts the sequential visit, which brings the footprint and the CLI to each Node together, with its verify and halt. A rollout that waits longer than 2 hours raises `rollout_stalled` once: CI most likely never published the release. A Node whose `orbit self-update` reports the release as pending is `waiting` too.

### One Node

For each Node, the run takes the Node's role lock, the lock every role converge takes, and runs these steps. The `cli` and `footprint` steps also hold the Node's update lock, `/run/lock/orbit-self-update.lock`; `self-update` takes that lock itself, in between. So a Gateway step and a self-update never run on one Node at the same time. A self-update that keeps the lock longer than 5 minutes leaves the Node `deferred` with `node.update_busy`.

| Step | Work |
| --- | --- |
| `ssh` | Open a new SSH connection. A Node that does not answer is `unreachable`, and nothing runs |
| `node-lock` | Wait up to 2 minutes for the role lock. A busy Node is `deferred` |
| `baseline` | Run Doctor's `node` and `role` families for the Node |
| `cli` | [Install the CLI](/reference/node-provisioning#orbit-cli) when it is missing, and write the Node profile. A foreign CLI makes the Node `skipped` |
| `self-update` | Run `sudo /usr/local/bin/orbit self-update --json` over SSH. `pending` or `incomplete` leaves the Node `waiting`; `self_update.busy` leaves it `deferred`; `failed` fails it |
| `footprint` | [Re-apply the Gateway-rendered footprint](/reference/node-provisioning#converge-the-orbit-footprint) where its digest changed, and the artifact that repairs an owned issue the baseline shows |
| `verify` | Wait up to 90 seconds for the agent to report the pinned version in presence, then run Doctor again |

A step that fails while the Node still answers SSH runs once more, because a dropped channel or a timeout is usually transient. When the Node does not answer a new probe, it is `unreachable`. A `self-update` that answered with its JSON verdict is not retried. A step that meets a busy lock (`node_role.node_busy`, `node.update_busy`, `self_update.busy`, `agent.converge_busy`, `agent.converge_lock_lost`, `node.lock_lost`, `tool.operation_locked`) leaves the Node `deferred`.

When the baseline shows an owned issue, the footprint step re-applies the artifact that repairs it even though its digest matches: `agent` for an agent issue, `caddy` for Caddy drift.

A Caddyfile that the render refuses, because a site cannot be built, is `skipped`: the live Caddyfile stays, Doctor keeps reporting `role.caddy_build_drift`, and the verify tolerates that issue on this Node. A Caddyfile that `caddy validate` refuses can also be a fault in Orbit's own template or Caddy pin.

When the Caddy digest changed, so Orbit's own Caddy code is new, a refusal on the first Node of a rollout, before any Node converged, fails the Node with `node.footprint_caddy_failed` and halts. A refusal on a later Node, or while the step only repairs live drift that the baseline showed, is `skipped` like a render refusal. The first skip in a rollout raises `rollout_caddy_skipped` once, so a kept Caddyfile is never silent.

After a Gateway rollback, the desired CLI release is older than the Nodes' CLI, and `self-update` refuses with `self_update.downgrade_refused`. When the desired state belongs to a `verified` release record, the step runs `self-update` once more with `--allow-downgrade-to=<desired version>`, which allows a downgrade to exactly that version and to no other. An in-place Gateway has no release records, so it never passes the option.

The verify fails when Doctor reports an issue that was not there in the baseline, or any issue the rollout owns: `node.ssh_unreachable`, `node.agent_missing`, `node.agent_binary_mismatch`, `node.agent_inactive`, `node.agent_secret_mismatch`, `node.cli_foreign`, or `role.caddy_build_drift`. It checks Doctor three times, 10 seconds apart, before it fails, because a restarted agent or a reloaded Caddy settles within seconds. An older issue the rollout does not own, such as `node.disk_low`, is kept as evidence. The presence check fails only when the agent reports another version. Without an active `websocket` role, or without any report from the Node's agent, Doctor's binary check stands in for it.

| Outcome | What the rollout does |
| --- | --- |
| `converged` | A step changed the Node and the verify passed. Continue |
| `unchanged` | Nothing changed and the verify passed. Continue |
| `unreachable` | Record it and continue. The catch-up visits the Node again |
| `deferred` | Another operation held a lock the step needs. Continue; the catch-up visits the Node again |
| `waiting` | `self-update` reported `pending`, because the CLI release is not published yet, or `incomplete`, because it skipped a step that should have run. Continue; the catch-up visits the Node again |
| `failed` | The Node answered, but a step or the verify failed. Halt |
| `skipped` | An operator resumed past the Node with `--skip`, or the Node has a foreign CLI and left the rollout set |

### Halt and resume

At the first `failed` Node, the rollout stops. The Nodes after it stay as they were. The rollout record becomes `halted` with the Node, the error code, the message, and the step evidence: the CLI install result, the `self-update` report or its exit code and output, the footprint result, and the verify issues. The Gateway raises the `rollout_halted` [release alert](#release-alerts) once, with the target `fleet` and the release id `fleet-rollout-<id>`, and stores the receipt on the record.

A Node whose `self-update` stays `incomplete` for 6 visits in a row, 30 minutes of catch-ups, raises `rollout_stalled` once, with the reasons in the step evidence. A `deferred` or `unreachable` visit in between keeps the count. It does not halt the rollout.

A halted rollout blocks every later rollout and the catch-up. Fix the cause, then resume:

```bash
orbit fleet:rollout:status
orbit fleet:rollout:resume                 # visit the failed Node again first
orbit fleet:rollout:resume --skip=<node>   # leave that Node for later
```

`fleet:rollout:resume` sets the failed Node back to `pending`, starts the service, and returns. A skipped Node stays `skipped` in this rollout; the next desired state visits it again. When the Gateway moved to a newer commit since the halt, the halted rollout becomes `superseded`, and a rollout of the newest desired state opens with the skip carried over.

| Code | HTTP | Meaning |
| --- | --- | --- |
| `fleet.rollout_not_halted` | 409 | No rollout is halted |
| `fleet.node_not_in_rollout` | 422 | `--skip` names a Node outside the halted rollout |
| `fleet.node_already_converged` | 422 | `--skip` names a Node that already runs the desired state |

### Catch-up

After the rollout, every run visits some Nodes again, one at a time and with the same rules:

| Node | Why the catch-up visits it |
| --- | --- |
| `pending`, `unreachable`, `deferred`, or `waiting` | The rollout did not converge it |
| New in the rollout set | It joined after the rollout opened |
| `converged` or `unchanged`, but drifted | Its agent reports another version, its CLI version differs from the desired release, or its footprint digest differs from the one it last received |

So a Node that was offline is converged within 5 minutes after it returns.

Doctor reports a lagging Node of the rollout set as `node.release_lag` while the rollout is on. A Node outside the set, such as a task sandbox, never gets it. `observed` says why: `no rollout yet`, `not in the rollout`, the Node's outcome, or `drifted`. Doctor reads only the desired state that a run already resolved; it never asks Git or GitHub.

### Nodes being updated

Each Node in `GET /api/v1/nodes` and `GET /api/v1/nodes/{node}` has an `updating` field. It is null unless the Gateway is updating that Node now:

| `kind` | The Node is updating while | `since` | Id field |
| --- | --- | --- | --- |
| `fleet_rollout` | The rollout or the catch-up visits it: the visit has a `started_at` and no `finished_at` | The visit's `started_at` | `rollout`, the rollout id |
| `gateway_release` | A [release record](#release-records) is `running`. Only the Node with the active `gateway` role updates | The record's `created_at` | `release`, the record id |

The other id field is null. A visit clears its `finished_at` when it starts, so a catch-up visit of a converged Node counts too. The rollout visits one Node at a time, so at most one Node is `fleet_rollout`. The Gateway Node is never in the [rollout set](#rollout-set-and-order), so one Node is never both. A `queued` release record does not count, because nothing changed yet.

A visit counts only while a run holds the fleet lock, so a long visit stays `updating` for as long as it runs. A run that dies leaves its visit open. Nothing renews the lock then, so it runs out within 20 minutes and the Node stops reading as updating. The next run ends every open visit before it visits a Node, and broadcasts each of those Nodes. A dead `running` release record ends as `interrupted`, as [Release records](#release-records) describes.

The list reads every Node's state with two queries, one for the visits in progress and one for a running release. It reads the fleet lock only when a visit is open.

The Gateway broadcasts [`node.updated`](/reference/events#node) with the new value when a visit starts and ends, and when a release record starts running and ends. The [web app](/reference/web-app#live-node-and-process-state) shows the Node as `updating`.

## Limits

Automatic releases and the fleet rollout have these limits.

### Release limits

A release that fails after its migrations ran pauses automatic releases until an operator acts. A release never switches back over a migration, because migrations follow no compatibility policy, such as expand and contract. Orbit cannot prove that older code runs on a migrated schema.

Pre-migration snapshots stay on the Gateway host, in `ORBIT_HOME/backups`. Orbit does not copy them off the host. Keep a [complete state set](#preserve-a-complete-state-set) elsewhere.

The release steps up to the switch run the code of the release that was current. A change to those steps takes effect from the release after the one that ships it. The deploying code also writes the release record and raises its alerts. So when the release that adds the [Gateway Node's agent](#gateway-node-agent) update fails that update, its record shows the failure, but no alert is raised. Later releases alert.

### Rollout limits

The rollout updates only the Nodes of the [rollout set](#rollout-set-and-order). Each release's handoff updates the [Gateway Node's agent](#gateway-node-agent). An operator updates an operator machine or a macOS Node with `orbit self-update`, and the CLI [tells the operator](/reference/self-update#the-newer-release-notice) about a newer release.

The rollout visits one Node at a time, so it takes longer as the fleet grows. A halted rollout blocks every later rollout until an operator resumes it.

The Gateway pushes the rendered footprint over SSH. `orbit self-update` replaces only the CLI and the agent. Caddy, cAdvisor, the FPM exporter, Prometheus, Grafana, Plausible, and Reverb keep their own update paths.

### Not built

Each of these needs its own decision:

- copies of the pre-migration snapshots off the Gateway host;
- an expand and contract policy for Gateway migrations;
- `orbit self-update` that applies the rendered footprint on the Node, so the Gateway stops pushing configuration over SSH;
- automatic updates on operator Macs;
- a macOS Node agent;
- a self-update that an agent signal triggers instead of SSH ([The agent only observes](/reference/node-agent#the-agent-only-observes)).

## Why it works this way

These reasons explain the design. Check them before you propose a change.

### The Gateway pulls from GitHub

The Gateway is private, so GitHub cannot reach it, and the GitHub App has [no webhooks](/reference/github-app#no-webhooks). A CI job that pushes to a deploy endpoint with a GitHub OIDC identity was rejected: it needs a new trusted path into the Gateway and new credential handling, and an earlier attempt stalled on exactly that.

A CI deploy job that connects to the Gateway over SSH was rejected too: the CI runners are kept off the Gateway on purpose, so CI jobs cannot reach the control plane ([Self-hosted Gateway runner](/reference/implementation-loop#self-hosted-gateway-runner)). Pulling through the existing GitHub App adds no network path, credential, or Node grant.

### Code decides what ships

Whether a commit passed its checks, and whether a release serves, are deterministic questions. `Required checks`, verify, and smoke answer them, not an agent's judgment. A deploy bot was planned for this duty and rejected for that reason. Judgment is needed only after a pause, and the operator gives it. Every step is a `gateway:release:*` command that an agent can run and a person can read.

### Immutable releases behind one link

An update in place serves a half-changed tree while `git checkout` and `composer install` run, because the Gateway's PHP-FPM pool checks every script for changes on each request. It also needs a maintenance window. A release that is built beside the live one and switched with one rename never serves a mix of two commits. The in-place procedure is gone, not kept beside the release commands, so there is one way to update a Gateway.

### Releases install no development packages

A release runs the Gateway, its artisan commands, and the CLI for the smoke test. None of them needs a development package. The Gateway and the CLI register Boost only when its classes exist, and the CLI binary already ships from a no-dev install. Leaving those packages out cut the two `vendor/` directories from about 277 MB to 115 MB and the install from about 8 s to 5 s on a warm Composer cache, measured on a build host on 8 Oct 2026. A release cannot run `composer test` or `artisan boost:*`; use a checkout for those.

Copying the current release's `vendor/` into the next one when `composer.lock` is unchanged was measured and rejected. The copy, the write bit it needs back, and the `composer install` that still runs to rebuild the autoloader took 6.6 to 7.2 s against 4.6 to 5.1 s for a fresh no-dev install. Hard links are not an option, because Composer rewrites autoload files in place.

### No PHP-FPM restart

A PHP-FPM restart or reload ends the requests in flight, and a Gateway request may run 600 seconds. Caddy resolves the release link for each request instead, so a running request finishes on its release and the next one runs the new release.

### Pause after migrations, never switch back

Older code on a newer schema is untested, and it can damage live data without a visible error. So a release that fails after its migrations pauses, and a person chooses between a forward fix, a forced rollback, and a restore from the snapshot. Refusing automatic releases for commits with migrations was rejected: most commits that change the Gateway ship a migration, so automation would rarely run. The snapshot and the pause cover the risk.

### The Gateway is not an Orbit Instance

The Instance pipeline deploys to Nodes over SSH and runs one PHP-FPM pool per Instance. The Gateway is the control plane on its own host. Its release cannot depend on a healthy Gateway to run it.

### The rollout runs one command on each Node

`orbit self-update` is the one node-local updater, on a Node and on an operator's Mac. A rollout in which the Gateway runs each update step over SSH would work, but it keeps the update logic in the Gateway. With one node-local command, a later move to another trigger changes only the trigger.

### One Node at a time, and a halt

Converging Nodes in parallel is faster, but a bad release would break many Nodes at once. A failed Node halts the rollout, because the next Node would most likely fail the same way, and one broken Node is easier to repair than a fleet. A fleet failure never rolls the Gateway back, because the Gateway already verified and serves.

An unreachable Node changed nothing, so it does not halt the fleet; the catch-up converges it. Halting on it would let one offline Node block every other. A missing CLI release is the normal state for a few minutes after each deploy, so it waits instead of failing. The rollout is off by default, so a Gateway release that adds the units does not reach Nodes before an operator prepared them.
