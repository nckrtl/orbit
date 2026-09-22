---
title: "Update and recover a Gateway"
description: "Preserve source, state, and keys through an update or recovery."
---

# Update and recover a Gateway

This guide helps an operator preserve Gateway state during a source update and recover when an update fails. It covers the installation layout from the [Quickstart](/quickstart#install-orbit). Test the procedure on a disposable copy before relying on it for important data.

Gateway web setup grants Caddy access to regular files and directories under the checkout’s `public` directory, including files restored with restrictive permissions. It does not follow public symlinks or change private source permissions. The Gateway `.env` stays at mode `0600`.

## Preserve a complete state set

The database alone is not a recoverable Gateway backup. Keep these inputs together and store the backup outside the machine, with access limited to administrators.

| Input | Why it matters |
| --- | --- |
| Exact monorepo commit and lock files | Reinstalls the matching CLI, Gateway, and SDK code. |
| Gateway `.env` | Holds configuration and may contain the encryption key or a custom database path. |
| Complete `ORBIT_HOME` | Includes `gateway.sqlite`, SQLite journal files, SSH identity and known hosts, WireGuard keys, the root CA, generated state, and a possible `gateway.app-key`. |
| Machine configuration or VM snapshot | Preserves service, firewall, DNS, network, ownership, and package state if an update changes them. |
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

Adjust the paths when `ORBIT_HOME` or `DB_DATABASE` differs. Include an external database path and its SQLite sidecar files. Pause Gateway timers and external automation too; stopping the web services alone does not stop console writers. Do not copy a live SQLite file without its journal state. Transfer the archive to protected storage and verify that it can be read before changing source.

The archive contains secrets. Do not attach it to a bug report or commit it to Git. A successful archive listing verifies readability, not restore behavior.

## Update source

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
