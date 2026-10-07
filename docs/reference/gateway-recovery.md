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

Gateway web setup lets Caddy read the regular files and directories under the checkout's `public` directory, also files restored with restrictive permissions. It does not follow symlinks there or change the permissions of other source files. The Gateway `.env` stays at mode `0600`.

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
4. gives Caddy the same access to the release's `public` directory that [Gateway web setup](#gateway-request-logs) gives a checkout, and makes the source directories read-only;
5. writes `REVISION`, then runs `php artisan config:cache` in the release, so the cached configuration holds the release's version.

Only a release with a `REVISION` file is prepared. When the configuration cannot be cached, prepare removes `REVISION` again. The Gateway runs with a cached configuration, so after a change to the shared env file, run `php artisan config:cache` in `/home/orbit/orbit/apps/gateway`. A release that already has it is reused without another build step. A partial release from a failed or interrupted prepare is removed and built again on the next run. It never touches the current release link, the database, or a running service.

Prepare refuses before it writes when the releases directory has less free space than `ORBIT_GATEWAY_RELEASE_MIN_FREE_MB`, 1024 MiB by default. Each release has its own `vendor/` directories. Releases share the Git objects in `shared/orbit.git`, so a release costs about the size of its source and its two `vendor/` directories.

The command prints one JSON object. Success exits 0 with `release`, `sha`, `path`, `reused`, and `duration_ms`. A refused commit exits 2. Every other failure exits 1 with `error_code`, `step`, and `message`.

| Error code | Meaning |
| --- | --- |
| `gateway.release_commit_invalid` | The commit is not a hex SHA of 7 to 40 characters. |
| `gateway.release_commit_unknown` | The repository does not have the commit, or the prefix names more than one commit. |
| `gateway.release_layout_missing` | The shared repository or env file is missing. |
| `gateway.release_in_progress` | Another release step holds the single-flight lock in `ORBIT_HOME/gateway-release.lock`. |
| `gateway.release_disk_low` | The releases directory has less free space than the floor. |
| `gateway.release_conflict` | The release directory holds another commit with the same 12-digit id. |
| `gateway.release_current_incomplete` | The current release has no `REVISION`. Repair it before preparing it again. |
| `gateway.release_layout_invalid` | `ORBIT_GATEWAY_CHECKOUT` is not an absolute `<base>/<checkout>/apps/gateway` path. |
| `gateway.release_lock_unavailable` | The release lock file cannot be opened. |
| `gateway.release_fetch_failed`, `gateway.release_worktree_failed`, `gateway.release_link_failed`, `gateway.release_dependencies_failed`, `gateway.release_access_failed`, `gateway.release_revision_failed`, `gateway.release_configuration_failed` | The named build step failed. The live release is unchanged. |

## Update source

[ADR 0201](/decisions/0201-release-the-gateway-automatically-from-green-main) replaces this in-place procedure with immutable releases that the Gateway builds and switches itself. Until that is built, update the Gateway with the steps in this section.

Keep requests and automation paused. As `orbit`, fetch the selected release or exact commit and install its locked dependencies:

```bash
cd /home/orbit/orbit
git fetch --tags origin
git checkout --detach <NEW_RELEASE_TAG_OR_COMMIT>
composer --working-dir=apps/cli install --prefer-dist --no-interaction
composer --working-dir=apps/gateway install --prefer-dist --no-interaction
composer --working-dir=apps/cli check-platform-reqs
composer --working-dir=apps/gateway check-platform-reqs
cd apps/gateway
php artisan config:clear
php artisan migrate --force
```

The agent view subscriber, `orbit-agent-view.service`, writes only to its cache files in `ORBIT_HOME/cache/agent-view`, never to the database, so it can keep running during a backup and an update. A backup does not need those files: the view rebuilds within seconds. Within 60 seconds of a source change, it exits and systemd starts it with the new code. [Gateway view](/reference/node-agent#gateway-view) describes it.

The Gateway refuses to start when its cache store cannot hold locks across processes. The error names `CACHE_STORE=file` as the fix. `composer install`, `php artisan config:clear`, and `php artisan optimize:clear` still run, so a stale cached configuration can be cleared after `.env` is fixed.

Read the release notes before migrations. Do not run `composer update`, `composer setup`, `key:generate`, or Gateway bootstrap as a generic update step. Dependency installation uses the committed locks; bootstrap changes machine configuration and needs its own explicit instructions.

If every command succeeds, start the services and verify before resuming automation:

```bash
sudo systemctl start php8.5-fpm caddy
cd /home/orbit/orbit
apps/cli/orbit gateway:status
apps/cli/orbit node:list
apps/cli/orbit doctor --json
```

Repeat the first application's DNS and HTTPS check from the [Quickstart](/quickstart#open-the-page). Check the actual body, certificate verification, expected records, and stable identities. A migration exit code alone does not prove a working update. Restart `orbit-runtime-hibernator.timer` and other paused automation only after verification. Retain the backup until these checks and a disposable restore succeed.

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
