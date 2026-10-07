---
title: "Update and recover a Gateway"
description: "Preserve source, state, and keys through a Gateway update, recover from a failed update, find the request logs, and receive release alerts."
covers:
  - apps/gateway/app/{Domain,Infrastructure}/Releases/**
  - apps/gateway/config/app.php
  - apps/gateway/config/logging.php
  - apps/gateway/app/Infrastructure/Logging/**
  - apps/gateway/app/Domain/Gateway/GatewayCacheStore.php
  - apps/gateway/app/Infrastructure/Gateway/GatewayCheckoutAccessConverger.php
  - apps/gateway/app/**/GatewayReleases/**
  - apps/gateway/app/**/*GatewayRelease*.php
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

A Gateway in the release layout runs from immutable releases instead of an in-place checkout ([ADR 0201](/decisions/0201-release-the-gateway-automatically-from-green-main)). Each release is one exact commit, built beside the live one, so preparing a release never changes what the Gateway serves.

| Path | Content |
| --- | --- |
| `/home/orbit/releases/<id>/` | One release: a linked Git worktree of one commit, with its own `vendor/` for `apps/cli` and `apps/gateway`, and a `REVISION` file. `<id>` is the first 12 hex digits of the commit. |
| `/home/orbit/orbit` | A link to the current release. `PATH`, systemd units, the PHP-FPM `chdir`, and the Caddy root keep using this path. |
| `/home/orbit/shared/orbit.git` | The bare repository every release is a worktree of. Its `origin` is the Orbit repository. |
| `/home/orbit/shared/gateway.env` | The one Gateway env file. `apps/gateway/.env` in every release links to it. |
| `/home/orbit/shared/gateway-storage/` | The Gateway storage directory. `apps/gateway/storage` in every release links to it, so the file cache, its locks, and the logs stay the same across releases. |
| `ORBIT_HOME` | Gateway state, outside every release, as before. |

The paths come from `ORBIT_GATEWAY_CHECKOUT`, which names `apps/gateway` below the current link. A release directory is never changed after it is prepared. Its source directories are read-only, so an in-place `git checkout` or `composer install` inside a release fails instead of changing it. Only `apps/gateway/bootstrap/cache` and `apps/cli/storage` stay writable.

The Gateway reports its version in `gateway:status`. When `APP_VERSION` is unset or empty, the version is the full commit in the release's `REVISION` file, so `bin/deploy-verify --sha` works without an env edit. Without either, the version is `dev`. Leave `APP_VERSION` out of the shared env file in the release layout; a set value always wins.

### Prepare a release

As `orbit`, build a release for one commit:

```bash
php /home/orbit/orbit/apps/gateway/artisan gateway:release:prepare <SHA>
```

Name the commit by its hex SHA, 7 to 40 characters. Branch names, tags, and other revision syntax are refused. Prepare fetches the repository's branches through the [GitHub App](/reference/github-app) when the commit is not present yet, and fetches a full SHA directly when no branch holds it any more. Then it:

1. creates the worktree `releases/<id>` for the exact commit;
2. links the shared env file and the shared storage directory into it;
3. runs `composer install` and `composer check-platform-reqs` for `apps/cli` and `apps/gateway`, with the committed locks;
4. gives Caddy the same access to the release's `public` directory that [Gateway web setup](#gateway-request-logs) gives a checkout, and makes the source directories and files read-only;
5. writes `REVISION`, then runs `php artisan config:cache` in the release, so the cached configuration holds the release's version. The cache holds `APP_KEY`, so it is mode `0600`.

Every artisan command of a release runs with a clean environment that has only `HOME`, `PATH`, and `LANG`, so the release reads its configuration from the shared env file alone. These commands, and `composer install`, hold `ORBIT_HOME/gateway-release-step.lock` while they run. When the process that started one dies, for example with its SSH session, the command still finishes. Until it has, the next release step is refused with `gateway.release_in_progress`.

Only a release with a `REVISION` file is prepared. When the configuration cannot be cached, prepare removes `REVISION` again. The Gateway runs with a cached configuration, so a change to the shared env file takes effect only after [`gateway:release:configure`](#apply-an-env-change). A release that already has it is reused without another build step. A partial release from a failed or interrupted prepare is removed and built again on the next run. It never touches the current release link, the database, or a running service.

Prepare refuses before it fetches or writes when the releases directory has less free space than `ORBIT_GATEWAY_RELEASE_MIN_FREE_MB`, 1024 MiB by default. Each release has its own `vendor/` directories. Releases share the Git objects in `shared/orbit.git`, so a release costs about the size of its source and its two `vendor/` directories.

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

### Deploy a release

Deploy is the manual release. Before it changes anything, it:

1. prepares the commit;
2. refuses a commit that does not descend from the current release, with `gateway.release_downgrade`;
3. refuses a commit that lacks a migration the `migrations` table has applied, with `gateway.release_migration_crossed`;
4. caches the release's configuration again from the shared env file, so a broken env file stops the release before any migration.

`--force` skips the two refusals. It never migrates backwards.

Then it migrates when needed and switches `/home/orbit/orbit` to the release with one `mv -T`. The switch runs only when the current path is already a release link. An in-place checkout, or a link to something else, is refused until `gateway:release:adopt`.

```bash
php /home/orbit/orbit/apps/gateway/artisan gateway:release:deploy <SHA>
php /home/orbit/orbit/apps/gateway/artisan gateway:release:deploy <SHA> --force
```

After the switch, deploy:

1. runs the serving phase of the runtime handoff: Caddy, PHP-FPM, and the units;
2. verifies the release, as described below;
3. runs the scheduler phase of the handoff: the scheduler drain and restart, document cleanup, and the OPcache reset;
4. switches the web app to the release's build;
5. runs smoke against that web app.

Verify runs before the scheduler phase, so a broken release is found and switched back without waiting for a long scheduled command or an idle pool.

Verify checks `GET /up` and Gateway status at `ORBIT_GATEWAY_VERIFY_ORIGIN` (default `https://gateway.orbit`). Status must be `ok`, and the version must be the full commit or its 12-digit id. A busy pool can queue `/up` behind long requests, so a failed check runs again after 2, 4, 8, 16, and 30 seconds.

Smoke does not run before the web switch. Any failure after the switch counts, also an error the release code did not expect (`gateway.release_unexpected_failure`):

- Without migrations, deploy switches back to the previous release and restores its web build. It repeats the handoff phases that already ran. The outcome is `switched_back`.
- A previous release that lacks a migration the database applied gets no switch-back. Deploy pauses instead. This can follow an earlier pause.
- After migrations, deploy pauses. It writes `ORBIT_HOME/gateway-release.paused` and records outcome `paused`. It never switches back onto a schema the previous code has not run.
- A switch that fails after migrations pauses too. The previous code then serves the new schema.

After a verified release, deploy removes old releases. It keeps the newest `ORBIT_GATEWAY_RELEASES_KEEP` releases (default 5), and always the current and the previous one.

#### Migrations and the snapshot

Before the switch, deploy compares the migration files the release ships with the `migrations` table. When none are pending, it neither snapshots nor migrates.

When some are pending, deploy first writes a consistent copy of the Gateway database to `ORBIT_HOME/backups/pre-<id>-<time>-<random>.sqlite`, with mode `0600`. Each attempt writes its own file, so a retry after a failed migration never replaces the clean copy. It uses SQLite `VACUUM INTO`, so it needs no `sqlite3` binary. Then the release migrates with its own code, `php releases/<id>/apps/gateway/artisan migrate --force`, while the previous release still serves.

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
3. installs the hibernator and agent-view units again and restarts agent-view. Both units name the stable `/home/orbit/orbit/apps/gateway` path.

The scheduler phase:

1. moves the scheduler to the new release without cutting off a scheduled command;
2. restarts every other Gateway Node Process whose directory is in the Gateway application;
3. resumes [document cleanup](/reference/project-documents#restore-time-cleanup-gate);
4. resets OPcache, described below.

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

When any step fails, the handoff still starts the scheduler unit, so the scheduler never stays down. The handoff prints one JSON object with `caddy`, `fpm`, `opcache`, `scheduler`, `scheduler_unit`, `scheduler_drain`, `processes_restarted`, `cleanup`, `cleanup_error_code`, `agent_view`, and `cleanup_paused`. Run it again by hand after fixing a handoff failure. It takes no release lock, so run it only when no release step is running.

`gateway:release:list` and `gateway:release:show <id>` read the release records, newest first. Each record has the commit, the trigger (`deploy` or `rollback`), the outcome, whether migrations ran, the snapshot path, and each step. Every attempt that names a commit writes a record and an Activity entry.

A failure the commit itself causes, such as a downgrade or a failed migration, is final at once. Any other failure, such as low disk, a fetch error, a slow verify, or an unexpected error, is recorded with `retryable: true` for the first two attempts of a commit; the third is final. A refusal that changes nothing, such as an unknown commit or a downgrade, writes only a failed Activity entry. A step refused by the release lock writes nothing.

One release step holds `ORBIT_HOME/gateway-release.lock`. A second step is refused with `gateway.release_in_progress`.

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
| `gateway.release_smoke_failed` | Smoke failed after the web switch. |
| `gateway.release_switch_back_failed` | The failure was real, and returning to the previous release also failed. |
| `gateway.release_configuration_failed` | The release's configuration could not be cached again. It runs before migrations, so nothing changed. |
| `gateway.release_unexpected_failure` | A step failed with an error the release code did not expect. The message names it. |
| `gateway.release_migration_crossed` | The database has applied a migration the target release does not ship. |
| `gateway.release_downgrade` | The commit does not descend from the current release. |
| `gateway.release_scheduler_missing`, `gateway.release_scheduler_mismatch` | The Gateway Node has no scheduler Process, or it runs outside the Gateway application path. |

When the handoff reports `gateway.release_scheduler_busy`, the old scheduler still finishes its commands and exits. With `Restart=always`, systemd then starts it on the current release. Otherwise, start its unit by hand.

### Roll back

Roll back to return the Gateway to a release it still keeps, for example after a pause or a bad release that verify and smoke did not catch.

```bash
php /home/orbit/orbit/apps/gateway/artisan gateway:release:rollback <id>
php /home/orbit/orbit/apps/gateway/artisan gateway:release:rollback <id> --force
```

`<id>` is the first 12 hex digits of a retained release. Rollback switches to it and runs the same handoff, verify, web switch, and smoke as a deploy. It refuses when the database has applied a migration the target does not ship. `--force` switches the code anyway and names the newest pre-migration snapshot. It does not migrate backwards. A failed verification switches back to the release that was current, because rollback itself does not migrate.

### Apply an env change

Every release caches its configuration, so an edit to `/home/orbit/shared/gateway.env` changes nothing until you cache it again. As `orbit`, after the edit:

```bash
php /home/orbit/orbit/apps/gateway/artisan gateway:release:configure
```

It caches the current release's configuration beside the live cache and renames it into place, so a request never reads half of it. Do not run `php artisan config:cache` in a release: it rewrites the live cache in place. A deploy or rollback caches its target again, so a retained release picks up the edit when it goes current.

## Adopt the release layout

A Gateway installed with the [Quickstart](/quickstart) runs from an in-place checkout at `/home/orbit/orbit`. `gateway:release:adopt` converts it into the [release layout](#release-layout) once, and the Gateway keeps serving throughout. Run it from a temporary checkout of the commit to adopt into, so the in-place checkout needs no update first. After adoption, deploy, roll back, and configure with the release commands. An update in place then fails, because each release is read-only.

### Before you adopt

Check these conditions on the Gateway before you run the command.

- Back up as in [Back up before an update](#back-up-before-an-update).
- `git status --short --untracked-files=no` in `/home/orbit/orbit` prints nothing. Untracked files, such as `.env` backups, are fine.
- The disk has room for one release, about the size of the checkout without `.git`, plus the free-space floor and a database snapshot.
- `python3` is installed. Ubuntu installs it by default. Without it, the swap uses two renames, and the path is missing for the microseconds between them.

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
| Storage | `apps/gateway/storage` moves to `shared/gateway-storage` and is linked back in one process, so its path is missing for microseconds only. |
| Prepare | `releases/<id>` is built for the checkout's own commit. With `--commit`, it gets no web build, because that commit may predate the CI artifact. |
| Swap | `renameat2(RENAME_EXCHANGE)` swaps the checkout directory with a link to that release. The directory stays as `/home/orbit/orbit.pre-adopt-<time>`. |
| Handoff | The temporary checkout's code hands the runtime over, because the checkout's commit may predate the handoff command. The app code that serves is the same as before. |
| Serving | `/up` is up and Gateway status is `ok`. The version may be `dev`, because `APP_VERSION` is commented out. |

Phase 2 deploys `<SHA>` exactly as [Deploy a release](#deploy-a-release) describes, with its snapshot, migrations, handoff, verify, web build, and smoke. Without `--commit`, it deploys the checkout's commit itself, which then must have the command; that adds the web build, the exact version check, and smoke.

The command prints one JSON object with `release`, `sha`, `from`, `pre_adopt_path`, `shared`, `switch`, `phase1`, and `deploy`, the phase-2 release record. `switch.method` is `exchange`, or `rename` with the gap in `switch.gap_us`. `shared.storage_gap_us` is the storage gap. Each phase writes a release record with trigger `adopt` and an Activity entry.

Running it again on a Gateway that finished adoption prints `"already": true` and changes nothing. When an earlier run swapped and then stopped before it verified, for example because its session dropped, the next run hands the runtime over and checks serving again, prints `"resumed": true`, and then runs phase 2.

The kept checkout is a complete way back. Its `.env.pre-adopt` holds the original env file, so keep its permissions, and remove it once a few releases have verified:

```bash
rm -rf /home/orbit/orbit.pre-adopt-<time>
```

The first release has no web build of its own when it came from `--commit`. A rollback to it fails at the web step and switches back; roll back to a later release instead.

### When adoption fails

Each step checks whether it already ran, so after fixing the cause, run the command again.

| Failure | Result |
| --- | --- |
| A refusal, or a failure before the swap | The checkout keeps serving, with its original `.env` back. The storage link stays, as it reaches the same files. |
| Phase-1 handoff or serving check | Adoption swaps the checkout back, restores the original `.env`, and hands the runtime back. The record says `switched_back`. |
| Phase 2 | The Gateway stays adopted. The deploy switches back to the phase-1 release or pauses, as any deploy does. |

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
| `release_paused` | A release failed after its migrations ran, and automatic releases are paused |
| `rollout_halted` | A fleet rollout stopped at a Node that failed |

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
