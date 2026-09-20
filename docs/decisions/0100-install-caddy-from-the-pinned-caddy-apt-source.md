---
title: "ADR 0100: Install Caddy from the pinned Caddy apt source"
sidebarTitle: "0100 Install Caddy from the pinned Caddy apt source"
description: "Proposed."
---

# ADR 0100: Install Caddy from the pinned Caddy apt source

In the context of Nodes that serve every Orbit site through Caddy, facing an Ubuntu archive that ships Caddy 2.6.2 while Orbit's own rendered configuration uses directives that need 2.8 or newer, we decided for an apt source that Orbit owns and pins to the Caddy project's stable repository, together with a checked minimum release, and against dropping the directives or vendoring a binary, to achieve a Caddy that Orbit can render against and that keeps receiving security updates from its publisher, accepting that Orbit now depends on one more external package source and must update the pinned signing key when the Caddy project rotates it.

## Status

Proposed.

## Context

Every role that serves HTTP installs the `caddy` package from the Ubuntu archive. On Ubuntu 26.04 that is Caddy 2.6.2, released in 2022. Orbit renders Caddy configuration from the Gateway and validates each candidate with `caddy validate` before publishing it, so a directive the installed Caddy does not know fails the whole publication rather than half-applying.

That is not hypothetical. Since [ADR 0074](/decisions/0074-hibernate-idle-app-dev-appinstance-processes) gained a profiling probe, the `app-dev` site renders `log_skip`, which the Caddy project renamed from `skip_log` in 2.8.0. Every `app-dev` Caddy render now fails on a Node running the archive package, so no new App instance, domain, or Route can be published there. The rendered configuration also uses `handle_errors`, `forward_auth`, `lb_policy`, and `fail_duration`; `log_skip` is the first one to move past the archive version, and it will not be the last.

[ADR 0021](/decisions/0021-pin-sury-php-fpm-with-opcache-profiles-per-role) already rejected leaving Caddy to a non-archive source, but on performance grounds alone: a Caddy from the release archive served the same PHP-FPM socket at the same rate as the Ubuntu package. That measurement still holds and says nothing about which directives the binary understands.

Orbit already owns one pinned third-party apt source. PHP comes from Sury through `/etc/apt/sources.list.d/orbit-php.sources`, signed by a keyring Orbit publishes after checking the downloaded key against a pinned digest and fingerprint, and Orbit refuses a package whose candidate comes from a different origin. That mechanism is the precedent this decision reuses.

## Decision

- Orbit owns an apt source for Caddy on every Node whose roles need it: `/etc/apt/sources.list.d/orbit-caddy.sources`, pinned to the Caddy project's stable Debian repository and signed by `/usr/share/keyrings/orbit-caddy.gpg`. Orbit publishes the keyring only after the downloaded key matches a pinned SHA-256 digest and a pinned primary fingerprint, exactly as it does for the Sury PHP source.
- Orbit converges that source before installing role packages and refuses to continue when the `caddy` candidate comes from any other origin. A Node that already carries the archive package upgrades on the next role convergence; no rebuild is needed.
- Orbit requires Caddy 2.8.0 or newer and checks the installed version after the install step. A Node below the floor fails role convergence with a named error, rather than failing at the first Caddy publication.
- Doctor reports a Caddy below the floor as `role.caddy_version_unsupported`, naming the floor as expected and the installed release as observed. Doctor stays verify-only, as [ADR 0004](/decisions/0004-verify-only-doctor-boundary) requires; `orbit node:role:add <node> <role> --converge` is the repair.
- Orbit does not pin an exact Caddy version. The pinned source decides which release is current; Orbit only states the floor it renders against.
- This decision amends [ADR 0021](/decisions/0021-pin-sury-php-fpm-with-opcache-profiles-per-role). That record's rejected alternative covered building Caddy for speed; it did not consider the directive surface, and the performance finding behind it is unchanged.

## Rejected alternatives

- Keep the Ubuntu package and render only directives Caddy 2.6.2 understands: rejected because 2.6.2 has no way to exclude a single request from a site's access log, which the hibernation probe needs, and because it would freeze Orbit's configuration surface at a release the publisher no longer supports.
- Vendor a Caddy binary or install the release archive: rejected because Orbit would then own Caddy's security updates instead of `unattended-upgrades`, and a binary outside dpkg is invisible to the package expectations Doctor already checks.
- Pin an exact Caddy version: rejected because a security release would then wait for an Orbit change. Pinning the source origin and stating a floor gives the same protection against a surprise downgrade without holding back patches.
- Check the version only in Doctor and let convergence proceed: rejected because the operator would first meet the problem as `app-dev.caddy_config_failed` during an unrelated publication, with no indication that the Caddy version caused it.
- Detect the installed version in the Gateway and render a directive set per version: rejected because it doubles the number of configurations Orbit must render and test, to support a release the publisher has moved well past.

## Consequences

- An `app-dev` site can render every directive Orbit needs, and a directive the Caddy project adds becomes available without another provisioning decision.
- Nodes provisioned before this decision upgrade from 2.6.2 to the current stable release on their next role convergence. Orbit owns `/etc/caddy/Caddyfile` as a symlink into its own versions directory, and dpkg keeps a modified conffile, so the live configuration survives the upgrade.
- Orbit depends on the Caddy project's package host during provisioning and role convergence. When it is unreachable, the step fails with a named error rather than silently installing the archive package.
- The Caddy project's package binaries are built without cgo, so Caddy resolves names from `/etc/resolv.conf` rather than through NSS. An Orbit Node points `/etc/resolv.conf` at the systemd-resolved stub, which answers the private `.orbit` zone, so `forward_auth` to `gateway.orbit` keeps working. A machine that resolves `.orbit` only through NSS breaks under the new binary, so the Incus guest image has to carry the same resolver setup.
- When the Caddy project rotates its signing key, provisioning fails until the pinned digest and fingerprint are updated in Orbit. That is the intended cost of pinning.

## Affects

- Components: apps/gateway, apps/e2e
- ADRs: [ADR 0021](/decisions/0021-pin-sury-php-fpm-with-opcache-profiles-per-role)
- Detail: docs/reference/node-provisioning.md
- Verify: `apps/gateway` Pest suite covers the source-convergence command, the origin check, and the version floor; on a Node, `apt-cache policy caddy` names the Orbit source and `caddy version` reports 2.8.0 or newer
