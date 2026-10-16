---
title: "Docker CE migration"
description: "Planned-window migration of Beast and Shark to Ubuntu docker.io."
covers:
  - apps/gateway/app/Console/Commands/MigrateDockerCommand.php
  - apps/gateway/app/Actions/Fleet/MigrateDockerAction.php
  - apps/gateway/resources/remote/docker/migrate.py
  - apps/gateway/resources/remote/lib/recipe.py
---

# Docker CE migration

Every Linux Node uses the recipe's Ubuntu `docker.io`. Recipe convergence refuses Docker CE with `node.recipe_docker_provider` and reason `docker-provider:docker-ce`. Nightly and fleet convergence never migrate the provider. Fleet convergence records the Node as skipped with reason `docker-provider:docker-ce` and continues to other Nodes. The operator runs this internal Gateway command for one Node during a maintenance window. Its output is JSON; it has no interactive prompts.

## Beast and Shark window

Nick runs this procedure. This task does not change either Node. Beast has CUDA containers using the nvidia runtime and non-Orbit containers, including diction-whisper, ofide, mailpit, ohdear and dngdmt. Shark has prometheus, grafana, pantry-sync-redis and agent-evals. Confirm the inventory on the day: these names are not a manifest.

1. Schedule downtime for every container. Verify independent backups of databases, named volumes and bind mounts. Pause deployments and scheduled fleet maintenance.
2. On the Gateway checkout for the migration, run `php artisan node:migrate-docker Beast --dry-run`. Repeat for Shark. Review container names, package changes, source backups and fallback paths.
3. Resolve any refusal before the window. Dry-run reads the Node and checks registry access but writes no files, stops nothing and does not run apt update.
4. Run `php artisan node:migrate-docker Beast --yes`. Migrate only one Node at a time. Run the Shark command after Beast passes its checks.
5. Check every container's final state in the JSON report. Verify application traffic, database contents, monitoring and DNS.
6. On Beast, verify a real CUDA request and the nvidia runtime. On Shark, verify Grafana dashboards and Prometheus ingestion.
7. Run `php artisan node:converge-recipe Beast --dry-run`, then convergence and Doctor. Repeat for Shark. Restore scheduled maintenance after both Nodes pass.

## Safety and recovery

The command records complete Docker inspect documents for every container, image, network and volume in `/var/lib/orbit/docker-migration/inventory.json`, with a root-only directory and files. This includes environment secrets: never attach the journal to a task or copy it into logs. The public report exposes names, state and Process IDs, not inspect data. The journal pins the recipe identity; resume using the same checkout. Run the same command with `--yes` after a failure.

The prepare phase runs in a transient systemd unit, independent of the SSH session. The journal records each stage, including `swapping` before apt removes anything. Resume repairs interrupted dpkg configuration and dependencies before retrying the swap. Only loaded Docker and containerd units are stopped. Apt uses the normal locks, configuration and logs with recipe snapshot sources. It retains the original inventory and checks the current state rather than taking a new baseline. Do not delete the journal or run Docker prune.

The transaction replaces `docker-ce`, `docker-ce-cli`, `containerd.io`, `docker-buildx-plugin`, `docker-compose-plugin` and any `docker-ce-rootless-extras` with `docker.io`, `containerd`, `docker-buildx` and `docker-compose-v2`. It pins Docker and containerd to the recipe, using authenticated recipe snapshot indexes. It also removes `docker-model-plugin` when installed and refuses unrelated package removals or downgrades. An apt simulation refuses removal of unrelated packages. Orbit removes Docker repository entries after writing byte-for-byte backups directly in `/etc/apt`, using the same helper as recipe source retirement. Mixed or unrecognized Docker source entries refuse. `/etc/docker/daemon.json` stays byte-for-byte unchanged, including the nvidia runtime.

Before the swap, the journal records the storage driver and copies Docker container, image, volume and network metadata and containerd metadata databases while both daemons are stopped. After the swap, bounded daemon startup retries precede checks of every image, container and volume. The command mounts each container layer with the changes API and starts and stops one recorded container with its restart policy disabled. All restart policies remain disabled until verification passes.

Engine startup failure or unreadable state refuses until the operator repeats the command with `--fallback`. This explicit choice selects a fresh Docker data root under the journal directory and a systemd drop-in. The original Docker and containerd directories are not renamed or deleted. The fresh daemon manages its own containerd rather than reading the newer system containerd state.

Named volumes in the fresh engine use local bind-backed volumes pointing to their original mountpoints; bind mounts keep their original source. No volume data is deleted or copied. Keep the journal and original data directories for as long as these volumes are used.

Fallback gives each custom bridge network its original Linux bridge name, including Docker's generated `br-<original-ID>` name. Docker can leave that bridge and its connected routes behind after shutdown. Reusing it avoids a second bridge competing for the same subnet. Resume refuses a network whose configuration differs from inventory; it does not delete bridges or routes to force recovery.

In fallback, non-Orbit containers are recreated from the recorded configuration. Pullable images use recorded repository digests, not moving tags, and their IDs must match inventory. Local-only images are saved in root-only journal archives before shutdown. Changed non-Orbit writable layers are committed and saved before shutdown, then committed again after a clean stop to capture shutdown writes. Fallback loads these archives before recreating containers. Orbit writable layers are disposable and are not checked; their stored specs and data mounts are the recovery contract. The original image tags are restored locally.

Orbit Processes are reconciled from the Gateway's stored specs. Containers that were stopped stay stopped; desired-running Orbit Processes restart. A partial recreate resumes by checking the already-created artifact against its inventory configuration. All original container names appear in the final report, with state and health. The report waits up to two minutes for starting health checks. Missing containers, incorrect running states, failed Process reconciliation or failed health checks fail the command.

The command checks registry access before stopping when a pullable digest is available. It refuses foreign image platforms, unsupported mounts, Swarm, namespace sharing, links and auto-remove. Bind, named volume and tmpfs mounts are accepted. A refusal after stopping but before the swap restarts only containers that were running at inventory time. It also refuses plugin volumes or logging, volume driver options and non-bridge custom networks.

Inventory uses the CE engine's latest API. Nondefault settings outside the Ubuntu engine's API 1.52 contract refuse rather than disappearing during recreation. Fallback preserves non-Orbit writable layers in committed images; keep application data in volumes for independent backup. Final container states are reported on failure as well as success. Resume compares effective daemon defaults instead of treating omitted fields as drift.

Orbit containers need matching stored Process specs. Every data mount must appear in those specs, including image-declared anonymous volumes. Give those volumes explicit names in the specs before migration. A changed spec or runtime refuses before the package swap.

Custom Docker data roots or daemon host/containerd settings require a separate reviewed migration. This command does not rewrite those settings. Resolve a refusal rather than bypassing it.

## Why it works this way

Docker 29.7/29.8 and containerd 2.3 are newer than the recipe's Docker 29.1 and containerd 2.2. A successful package install does not prove on-disk compatibility. The command inventories before stopping, verifies old state explicitly, and has a clean-engine recovery path that does not consume newer metadata. Downtime and a possible image re-pull are preferable to silently assuming downgrade compatibility. Migration remains separate from convergence so a nightly run cannot stop unmanaged services.

## Rollback and no-engine recovery

There is no automatic engine rollback. Before the window, retain the installed CE package versions and obtain their deb files and dependencies independently. Keep the apt source backups, the metadata backup under the journal, and independent data backups. A metadata copy is not a backup of volume contents.

If apt fails after removing CE, leave containers stopped and rerun the same recipe command with `--yes`. The `swapping` stage runs `dpkg --configure -a` and a simulated, guarded `apt-get -f install` before retrying the pinned install. This path does not need a working Docker socket. Do not remove the journal or restart services by hand during verification.

If the downgraded engine cannot mount the recorded layers, review the failure states and run `php artisan node:migrate-docker Beast --yes --fallback` (or Shark). This starts a fresh engine and loads saved local images and committed layers. It never deletes original metadata or volume data.

If recovery cannot proceed, keep Docker and containerd stopped. Restore the retained CE packages and original daemon configuration and remove only the migration-owned systemd drop-in after checking its contents. Restore Docker repository entries from their `/etc/apt/*.orbit-docker-backup` files only if CE repository access is needed. If the old engine has modified metadata, restore the offline metadata copy before starting CE; preserve the original directories and get a reviewed recovery plan rather than overwriting uncertain state. Verify every container and application before ending the window.

The journal contains credentials, image archives and volume backings. Never delete it while fallback volumes use paths beneath it. To clean up after a native-engine success, stop migration activity, archive the root-only journal to protected backup storage, check that no Docker data root, systemd drop-in or volume device references it, then remove the journal. After fallback, first migrate bind-backed volumes and the fresh engine to separately reviewed permanent storage; only then perform that cleanup.
