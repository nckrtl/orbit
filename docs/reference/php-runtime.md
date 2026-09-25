---
title: "PHP runtimes"
description: "How Orbit selects, installs, and tunes the PHP-FPM runtime for development and production Instances."
---

# PHP runtimes

Orbit provisions PHP-FPM from the pinned Sury apt source and fronts every site with Caddy over a Unix socket. This page tells an operator or deployer how development and production services differ, where generated identity and local tuning live on a Node, and how to verify each runtime. [ADR 0021](/decisions/0021-pin-sury-php-fpm-with-opcache-profiles-per-role) owns the shared package source and role defaults, while [ADR 0045](/decisions/0045-isolate-production-php-fpm-by-unix-user) owns production service isolation and tuning ownership.

## Select an Instance runtime

For a development or standalone production Instance, the Gateway reads source metadata after initial source preparation and before runtime or Domain Name System (DNS) publication. [ADR 0034](/decisions/0034-select-appinstance-php-from-composer-constraints) defines this source-driven selection boundary.

Orbit tries PHP 8.5, then PHP 8.4, against the source's Composer constraint. It selects no other version, even if the constraint permits one.

| Source or package result | Runtime result | Error code |
| --- | --- | --- |
| No `composer.json` file | Orbit classifies the source as non-PHP and prepares no PHP runtime. | None |
| Valid `composer.json` without a PHP platform constraint | Orbit selects PHP 8.5. | None |
| Valid `composer.json` whose PHP platform constraint matches a candidate | Orbit selects the first matching candidate: PHP 8.5 before PHP 8.4. | None |
| Invalid PHP platform constraint, or a constraint below, between, or above all candidates | The Gateway stops at PHP selection. | `app-dev.php_version_unsupported` or `app-prod.php_version_unsupported` |
| Selected candidate is unavailable from the pinned Sury source | The Gateway stops when it verifies the runtime source. | `app-dev.php_package_source_unavailable` or `app-prod.php_package_source_unavailable` |

Both failures happen before runtime or DNS publication. At its first provisioning checkpoint, the Gateway stores the selected version together with the Laravel classification as one complete source profile. A development retry at a retained checkpoint requires both values to match before Laravel URL configuration or runtime and Route projection. A changed development profile returns `app-dev.source_evidence_changed`. Production source changes after successful provisioning are operator-owned and an identical creation retry does not inspect them.

A creation retry with `recover_source_profile` inspects the recorded source once, and only when the active Instance has no recorded profile. It stores the Laravel classification and keeps a recorded PHP version. When no PHP version is recorded, it stores the inspected version together with the dedicated production runtime identity derived from it, or refuses with `app-prod.php_runtime_identity_invalid` before it writes.

The Gateway does not infer missing Laravel evidence for a legacy retained checkpoint. An ordinary retry fails closed. The explicit recovery contract, including URL-reconciliation consent, rollback refusal, active-state behavior, and unchanged removal boundaries, is described in [Applications](/domains/applications#provision-the-application-endpoint).

Instance input, persisted Instance state, API responses, the PHP SDK, and the CLI do not expose a PHP-version field. The Node application role owns installation, configuration, and removal of every selected PHP runtime.

## Runtime ownership

Development sites for one PHP version share the distribution PHP-FPM service. Each new production PHP Instance uses the service, pool, socket, and OPcache instance recorded for its production Unix user. Production users on the same Node share the installed PHP version packages but do not share a PHP-FPM master.

The production identity follows fixed names that an operator can inspect.

| Projection | Name or path | Owner | Lifecycle |
| --- | --- | --- | --- |
| Service | `orbit-<production-user>-php<version>-fpm.service` | Gateway | Created and activated for the recorded production user; removed with that Instance's runtime projection. |
| Socket | `/run/php/<production-user>.sock` | Gateway | Created by the owning service and removed when that service stops. |
| Generated identity | Generated PHP-FPM files below `/etc/orbit/php-fpm/<production-user>/generated/`, including the service-specific `master.ini` | Gateway | Replaced only after the complete candidate validates against the recorded user, service, pool, socket, version, home, source paths, and effective master settings. |
| Local tuning | `/etc/orbit/php-fpm/<production-user>/local.conf` | Operating agent | Seeded with Orbit defaults for a new runtime and then preserved byte-for-byte by provisioning, retry, and cleanup. |

Production runtime convergence keeps the shared `/etc/orbit` directory owned by `root:root` with mode `0711`. This mode lets production users traverse to their protected Schedule scripts without letting them list the shared directory. Convergence creates the directory when it is absent and repairs a real `root:root` directory, including mode `0700`. It refuses a symlink, a non-directory, or a directory with different ownership before it changes runtime contents.

The shared-parent repair does not relax its children. In particular, `/etc/orbit/php-fpm` and its protected runtime state remain inaccessible to application users. Convergence preserves sibling contents, generated runtime identity, and local operator tuning.

The generated configuration establishes runtime identity and includes the separate local tuning file. Before activation or an Orbit-owned reload, the Gateway validates the effective configuration and refuses a local or conflicting file that changes the recorded user, service, pool, socket, PHP version, home, or application path. It does not adopt an existing user, service, socket, generated directory, or file whose identity or ownership conflicts with the Instance record.

An interrupted publication resumes from the recorded production identity. A failed candidate activation restores the exact generated files and service state captured before publication. It never replaces the local tuning file during recovery.

Existing production placements without a dedicated service association remain on their recorded shared runtime until an operator explicitly converts the placement. Conversion copies supported local pool tuning into the dedicated runtime's `local.conf`, verifies the complete effective identity, and changes only that Instance's Caddy upstream. It does not reload, restart, or reset another production user's shared or dedicated service. The [production release-layout reference](/reference/deployments#convert-an-existing-production-home) describes the complete conversion and refusal boundary.

New dedicated runtime preparation, retry, removal, and explicit conversion do not rewrite or adopt an unrelated placement. A Node can also run the Gateway or development PHP service; production runtime operations leave those service masters and caches unchanged.

## Project updates

A slug or web-root update may reproject the owning production PHP-FPM service so the effective document root matches the Instance. The Gateway validates the complete effective configuration before it activates or reloads that service. It preserves `/etc/orbit/php-fpm/<production-user>/local.conf` byte-for-byte and does not reload, restart, or reset another production user's service or OPcache. [Applications](/domains/applications#reconcile-an-app-update) describes when this reprojection runs.

## Shared runtime module

Development and existing shared placements use a normal Debian PHP module at `/etc/php/<version>/mods-available/orbit-runtime.ini`, enabled for the FPM Server Application Programming Interface (SAPI) as `/etc/php/<version>/fpm/conf.d/99-orbit-runtime.ini` through `phpenmod`. On every shared-runtime convergence the Gateway compares the rendered module with the installed file, repairs a missing or wrong `conf.d` link, and verifies the effective managed directives through `php-fpm<version> -i`.

The Gateway reloads the shared service only when its managed shared module, enablement, or FPM PCOV enablement changes. A dedicated production operation does not use this publication path. The command-line interface (CLI) SAPI keeps stock defaults (`opcache.enable_cli=0`).

## Runtime defaults

The shared development module and each generated dedicated production master profile apply these directives; [ADR 0021](/decisions/0021-pin-sury-php-fpm-with-opcache-profiles-per-role) records the reason for each value.

| Directive | app-dev | app-prod |
| --- | --- | --- |
| `opcache.enable` | On | On |
| `opcache.memory_consumption` | 512 | 256 |
| `opcache.interned_strings_buffer` | 64 | 32 |
| `opcache.max_accelerated_files` | 65407 | 65407 |
| `opcache.jit` / `opcache.jit_buffer_size` | disable / 0 | disable / 0 |

A shared service on a Node that carries both roles receives the app-dev profile for every PHP version. Each dedicated production master keeps its own app-prod allocation regardless of other Node roles. `opcache.preload`, `file_cache`, and `huge_code_pages` stay off in Orbit defaults.

## Pool policy

An `app-dev` pool revalidates every cached file on every request and leaves `opcache.file_update_protection` at its stock value of 2, so a request serves the file on disk:

```ini
php_admin_value[opcache.validate_timestamps] = 1
php_admin_value[opcache.revalidate_freq] = 0
```

An `app-prod` service sets timestamp validation in its generated service-specific `master.ini`, so compiled code stays in its owning master's memory until a verified cache refresh:

```ini
opcache.validate_timestamps = 0
```

[ADR 0021](/decisions/0021-pin-sury-php-fpm-with-opcache-profiles-per-role) records why each role gets its policy.

## Production cache boundary

Runtime provisioning establishes the dedicated master and its isolated OPcache instance. Production deployment and verified cache refresh are separate operations.

The Gateway derives the production user's service, pool, socket, and OPcache from the Instance's recorded runtime identity. It verifies that the owning service is active and owns the expected socket before it requests a reset through that socket. The reset runs inside the owning FastCGI Process Manager (FPM) runtime. Running `opcache_reset()` from the PHP command line cannot establish this result.

The Gateway reports success only after a later FastCGI observation shows that the reset completed. A reset request that returns successfully is not completion evidence by itself. The operation has a bounded deadline and reports an unavailable socket, a wrong service association, a rejected reset, and a reset still pending at the deadline as distinct failures.

Cache refresh never tries another Instance's socket and never reloads a service as a fallback. A generic reload of the distribution `php<version>-fpm` service does not target a dedicated production runtime. Laravel's `php artisan optimize` caches remain application deployment work.

## Proposed monitoring

The [service metrics](/reference/service-metrics) extension observes each dedicated master's pool and OPcache without changing this deployment boundary. Runtime tuning remains separate from metrics enablement.

## Process management

Both roles use `pm = ondemand` with `pm.process_idle_timeout = 10s` and `pm.max_requests = 500`. `pm.max_children` is 10 on an app-dev pool and 20 on an app-prod pool. The Gateway's own `orbit-gateway` pool uses 8, because its host runs the scheduler and task ticks beside it, and each open agent stream holds one worker. A Gateway request ends after 600 seconds, and Caddy's FastCGI timeouts match. The Gateway ends an API command's remote work after 570 seconds, so a slow command fails with `command.deadline_exceeded` and records its Activity before PHP-FPM ends the request. The longest recorded operation, `instance:register`, took 522 seconds. An agent stream reconnects when its request ends. [ADR 0021](/decisions/0021-pin-sury-php-fpm-with-opcache-profiles-per-role) records why both roles use `ondemand`.

## Caddy

Production sites add an immutable cache header for Vite build output:

```caddyfile
@vite {
    path /build/assets/*
    file
}
header @vite Cache-Control "public, max-age=31536000, immutable"
```

Laravel's Vite plugin fingerprints every file under `public/build/assets`, so browsers can keep them for a year. The `file` matcher limits the header to assets that exist on disk. A request for a removed fingerprint falls through to Laravel's front controller without the header, so a 404 is never cached as immutable. Development sites set no caching header. Workload Caddy reverse-proxies `/__orbit/vite` to `127.0.0.1:5173` for live assets, as the [development-server endpoint](/reference/routes#development-server-endpoint) describes. `php_fastcgi`, `encode zstd gzip`, and `file_server` keep Caddy defaults; Orbit renders no `try_files`, and the `php_fastcgi` default tries `{path}`, then `{path}/index.php`, then `index.php`.

## Inspect production runtime with Doctor

Doctor checks that each production PHP Instance has one dedicated service, pool, and socket association and that another Instance does not share them. It compares the current generated identity files and rejects a `local.conf` override of the recorded user, home, pool, socket, or application path.

Doctor also checks the service's loaded `ExecStart` and `PHP_INI_SCAN_DIR`, the active master's executable and root identity, and the master's ownership of the expected service-owned socket. For each worker that exists during inspection, it checks the parent, user IDs, group IDs, and process root. An idle `ondemand` pool with no workers is valid. The workload Caddy check applies to standalone and Cluster-scoped Routes; it does not inspect the Router as a second Instance runtime.

These observations establish the current configuration and the directly observable runtime association. They do not reconstruct every PHP-FPM directive loaded from an earlier configuration generation. Doctor does not compare an application's mutable working directory with the configured initial directory. It accepts an operating agent's `local.conf` changes when they preserve generated identity, does not compare allowed tuning with Orbit's seeded defaults, and does not require a tuning edit to be reloaded only to satisfy inspection.

Doctor reports bounded drift when a current file or reliable live association does not match. It reports `instance.inspection_failed` as unverifiable when a required service, process, worker, or socket observation cannot be read or parsed, while retaining other findings that it established independently. However, an `ondemand` pool ends idle workers at any time, so a worker that exits while Doctor reads it is skipped. Inspection does not invoke PHP-FPM, create a FastCGI or application request, reload or signal a service, reset a cache, or rewrite a file.

## Verification

On a Node, an operator checks a dedicated service, socket, generated identity, and local tuning separately:

```sh
systemctl is-active orbit-<production-user>-php8.5-fpm.service
systemctl cat orbit-<production-user>-php8.5-fpm.service
stat /run/php/<production-user>.sock
find /etc/orbit/php-fpm/<production-user> -maxdepth 2 -type f -print
```

Effective PHP values are visible from inside a request with `ini_get('opcache.validate_timestamps')` or `opcache_get_configuration()['directives']`. A service PID and OPcache instance belong only to the recorded production user; inspecting another production user must show a different master, service, socket, and cache.
