---
title: "ADR 0201: Release the Gateway automatically from green main"
sidebarTitle: "0201 Gateway auto-release"
description: "In progress. The Gateway pulls every main commit that passes Required checks, builds an immutable release for it, switches atomically, verifies and smoke-tests it, and switches back or pauses and alerts on failure. The web app moves with it."
---

# ADR 0201: Release the Gateway automatically from green main

Every push to `main` that passes CI's `Required checks` is released to the live Gateway automatically. The Gateway pulls the change itself. It builds an immutable release for the exact commit, switches to it atomically, then verifies and smoke-tests it. On failure it switches back, or pauses and alerts when migrations already ran. The web app moves with it. Releasing the Gateway becomes a Gateway feature instead of a manual or bot duty.

## Status

In progress.

Principle: [Deterministic first](/mission#principles). CI checks and code decide whether a commit ships and whether a release is healthy, not an agent's judgment. It also serves [Agents operate, humans steer](/mission#principles): every step is a command (`gateway:release:*`) that an agent can run and a human can read. It serves [Security fits the real threat model](/mission#principles): the Gateway stays private, and no new network path, credential, or Node grant is added. And it serves [One way, one name](/mission#principles): the in-place update procedure is removed, not kept beside the release commands.

## Context

Today the Gateway runs from an in-place checkout at `/home/orbit/orbit` ([Update source](/reference/gateway-recovery#update-source)). An update stops Caddy and PHP-FPM, runs `git checkout --detach`, `composer install`, and `migrate --force`, then restarts and verifies. An agent does this by hand after merges. ProdBot was meant to own it ([nckrtl/team](https://github.com/nckrtl/team), "ProdBot: deploys every push to main"), but the Grok bots are decommissioned. Measured on origin/main `dc629e7fb` (7 Oct 2026):

- **No tooling.** There is no deploy endpoint, workflow, or command. `bin/deploy-verify` ([ADR 0198](/decisions/0198-keep-delivery-line-proofs-as-repo-commands)) only checks a deploy. `APP_VERSION` is set by hand in `.env`.
- **The in-place update is unsafe while serving.** The PHP-FPM pool has `opcache.validate_timestamps=1` and `revalidate_freq=0`, so requests during `git checkout` or `composer install` run a half-changed tree. `reload-or-restart php8.5-fpm` kills in-flight requests, which can last up to 600 s.
- **Long-running processes keep old code.** The scheduler (`schedule:work`, an Orbit Process unit) keeps its code until restarted. Restarting it pauses Project Document cleanup until a reconcile and resume ([Restore-time cleanup gate](/reference/project-documents#restore-time-cleanup-gate)). `tasks:tick` holds a 300 s lock. `orbit-agent-view` restarts itself only when `git rev-parse HEAD` of its base path changes.
- **The Gateway is unreachable from outside.** The GitHub App receives no webhooks because the Gateway is private ([No webhooks](/reference/github-app#no-webhooks)). Sabre's CI runners are firewalled from the Gateway by design (`github-runner-egress`, [Self-hosted Gateway runner](/reference/implementation-loop#self-hosted-gateway-runner)).
- **CI does not test every main commit.** `ci.yml` cancels in-progress runs for every ref, so a burst of pushes leaves earlier main commits without a green run.
- **Migrations have no compatibility policy** and no automated backup. Recovery means restoring the [state set](/reference/gateway-recovery#preserve-a-complete-state-set).
- **Reusable parts exist.**
  - `orbit:deploy-development-defaults` already polls a branch every minute, skips an unchanged SHA, and coalesces pushes.
  - `HttpGitHubApi::checkRuns` and `HttpGitHubTiaBaseline` already read check runs, workflow runs, and artifacts with App tokens.
  - `bin/web-deploy` already uses `releases/<sha12>` and an atomic `current` link for the web app.

## Decision

The Gateway releases itself from green `main` through the steps below. The operator turns automation on and decides what happens after a pause.

### What ships

A **releasable commit** is the newest commit on `main` where all of these hold:

1. GitHub reports the check run `Required checks` as completed with conclusion `success` for that exact `head_sha`.
2. It is a descendant of the deployed commit, so a release never downgrades.
3. It has not already failed a release.

`ci.yml` cancels in-progress runs only for pull requests, so every main push gets a full run. When several commits are green, the newest ships, and bursts coalesce into one release.

### Who triggers it

The Gateway pulls. A systemd timer, `orbit-gateway-release.timer`, starts the oneshot `orbit-gateway-release.service` every minute. The service runs `php artisan gateway:release:auto` from the **current** release. It never runs inside PHP-FPM or the scheduler, because it restarts the scheduler and can reload PHP-FPM. It reads GitHub through the existing GitHub App with Checks and Actions read tokens.

Auto-release is **disabled by default**. `orbit gateway:release:auto enable|disable|status` turns it on and off. The same steps run manually with `orbit gateway:release:deploy <sha>`.

### Release layout

| Path | Content |
| --- | --- |
| `/home/orbit/releases/<sha12>/` | A linked worktree of the exact commit, with its own `vendor/` for `apps/cli` and `apps/gateway`, and a `REVISION` file |
| `/home/orbit/orbit` | A symlink to the current release, so `PATH`, units, the FPM `chdir`, and the Caddy root keep their paths |
| `apps/gateway/.env` in each release | A link to one shared env file. `ORBIT_HOME` stays outside releases |
| `/home/orbit/web/releases/<sha12>/`, `/home/orbit/web/current` | The web app, in its existing [web directory](/reference/web-app#web-directory) layout |

The Gateway keeps five releases. A release directory is never modified after it is prepared. `config('app.version')` falls back to `REVISION` when `APP_VERSION` is unset, so `bin/deploy-verify` works without editing `.env`.

### Release steps

One release runs at a time, under a single-flight lock:

1. **Prepare** `releases/<sha12>`: fetch with an App token, create the worktree, link the env file, run `composer install` and `check-platform-reqs` for the CLI and the Gateway, write `REVISION`, and download the CI artifact `web-dist-<sha>` into `web/releases/<sha12>`. A failure here changes nothing live.
2. **Snapshot**, only when migrations are pending: a consistent copy of the Gateway database through PDO `VACUUM INTO` into `$ORBIT_HOME/backups/pre-<sha12>.sqlite`. This needs no `sqlite3` CLI. A few snapshots are kept.
3. **Migrate** from the new release, before the switch.
4. **Switch** `/home/orbit/orbit` to the new release with one rename (`mv -T`). The Gateway site's `php_fastcgi` sets `resolve_root_symlink`, so new requests resolve the new release and in-flight requests finish on the old one. PHP-FPM is not restarted.
5. **Hand off runtime:**
   - Re-converge Caddy, the FPM pool, and Gateway units only when their rendered output changed. Caddy reloads gracefully. FPM reloads only for a pool change.
   - Interrupt the scheduler (`schedule:interrupt`), wait for the `tasks:tick` lock, and restart the scheduler Process unit.
   - Run `project-documents:cleanup:reconcile`, then `project-documents:cleanup:resume` with its report. A report that cannot authorize resume leaves cleanup paused, and the release alerts.
   - Restart `orbit-agent-view`.
6. **Verify:** `/up` is up, Gateway status is `ok`, and its version equals the commit. These are the `bin/deploy-verify` checks.
7. **Switch the web** `current` link to `web/releases/<sha12>`.
8. **Smoke test** with the repository command `bin/gateway-smoke` (JSON, about 60 s), run against the new release:
   - authenticated CLI reads on the Gateway;
   - the web app's `index.html` and one hashed asset served through Caddy;
   - the scheduler running and `tasks:tick` firing after the handoff;
   - agent-view running.

Every release writes a **release record**: commit, trigger (`auto`, a manual caller, or rollback), each step's outcome and timing, the snapshot path, verify and smoke output, and the result. Every release also writes an Activity entry. `orbit gateway:release:list|show` reads the records.

### Failure

| Failure | Action |
| --- | --- |
| In prepare | Nothing changed. The commit is marked failed, and a newer releasable commit is tried on a following tick |
| In verify or smoke, no migrations ran | Switch back to the previous release and its web build, and repeat the runtime handoff for it. Mark the commit failed. Alert |
| In verify or smoke, after migrations ran | **Pause** auto-release and alert. Do not switch back automatically |

Old code on a migrated schema is not proven safe. After a pause, the operator decides between a forward fix, `orbit gateway:release:rollback <sha12> --force`, and a restore from the snapshot.

An **alert** is a failed Activity entry plus an outbound HMAC webhook. The [outer loop](/reference/tasks#outer-loop) collects the failed entry, and `problems:file` files it as a problem. The webhook uses the pattern of the existing Coder settle notifier and is configured to reach Anna.

`orbit gateway:release:rollback <sha12>` switches to a retained release. It refuses to cross migrations without `--force`.

### Adoption and ownership

- `gateway:release:adopt` converts the in-place checkout into the layout once. It refuses when the checkout has local changes.
- After adoption, the steps that update a checkout in place refuse to run on a release symlink, and the release commands replace the [Update source](/reference/gateway-recovery#update-source) procedure.
- The Gateway owns releasing itself. The operator (Anna, with Nick's approval for material risk) owns adoption, enabling auto-release, and decisions after a pause.
- This replaces ProdBot's deploy and verify duty for the Gateway.

## Rejected alternatives

- **Push from CI to a `POST /api/deploy` endpoint with a GitHub OIDC identity.** It needs a new trusted path into a private Gateway and new credential handling, and the earlier attempt (ORB-1344) stalled on exactly that. Pulling needs no new path.
- **A deploy job in `ci.yml` that SSHes to the Gateway from Sabre.** Sabre is firewalled from the Gateway on purpose. Opening it would let CI jobs reach the control plane.
- **GitHub webhooks.** The Gateway is private, and the App is registered without a webhook.
- **Keep the in-place `git checkout`.** It serves a half-changed tree during the update and needs a maintenance window.
- **Restart PHP-FPM on every release.** It kills in-flight requests. Resolving the root symlink per request makes a restart unnecessary.
- **Roll back code automatically after migrations ran.** Older code on a newer schema is untested, and it can damage live data silently. Pausing makes it a human decision.
- **Refuse auto-release for commits with migrations.** Most commits that touch the Gateway ship a migration, so automation would rarely run. The snapshot plus a pause on failure covers the risk.
- **Deploy the Gateway as an Orbit Instance.** The Instance pipeline deploys to Nodes over SSH and runs per-Instance PHP-FPM. The Gateway is the control plane on its own host and cannot depend on itself being healthy.
- **Build the web app on the Gateway.** That needs Node and Bun on the control plane. CI already builds it.
- **Keep a bot (ProdBot) in charge.** Shipping a green commit and checking it are deterministic. The bots are also down. A bot stays useful only for judgment after a pause.

## Consequences

- Every green main commit reaches the Gateway within about a minute after CI, with no maintenance window and no dropped requests in the normal path.
- Releases, rollbacks, and their evidence are visible in one record and in Activity.
- A deployer change takes effect one release after it ships, because the oneshot runs the previous release's code. The first release with the deployer is deployed manually after adoption.
- Un-cancelled main runs add load on Sabre's runners.
- Disk use grows to about five copies of `vendor/`.
- A bad migration can still pause releases until an operator acts. Off-host snapshot copies and an expand/contract migration policy are follow-ups.
- Agents that deploy by hand must switch to `gateway:release:deploy` at adoption.

## Affects

- Components: apps/cli, apps/e2e, apps/gateway, packages/php-sdk
- ADRs: [ADR 0198](/decisions/0198-keep-delivery-line-proofs-as-repo-commands) (`bin/deploy-verify` becomes the release's built-in verify and stays usable from outside)
- Detail: these pages receive the decision.
  - [Update and recover a Gateway](/reference/gateway-recovery): new "Release layout", "Deploy a release", "Automatic releases", and "Roll back" sections replace "Update source".
  - [Web app](/reference/web-app): the web app comes from the CI artifact; `bin/web-deploy` remains for manual use.
  - [Feature delivery: CI](/reference/implementation-loop#ci): `.github/workflows/ci.yml` cancels only pull-request runs and uploads `web-dist-<sha>` on `main`.
  - [Delivery-line proofs: bin/deploy-verify](/reference/delivery-line#bindeploy-verify): the same checks run as each release's verify step.
- Verify: tests and a disposable Gateway clone prove these outcomes.
  - A failed or interrupted prepare leaves the live release untouched.
  - A continuous HTTP probe and a long request across the switch see no errors, on a disposable Gateway clone.
  - Verify or smoke failure without migrations switches back. After migrations it pauses and alerts.
  - The scheduler handoff leaves `tasks:tick` running and document cleanup resumed.
  - Only a commit with a successful `Required checks` run for its exact SHA is released. Downgrades and failed commits are refused.
  - After adoption, `bin/deploy-verify --sha <sha>` passes for an automatic release.
