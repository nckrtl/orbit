# PHP runtimes

Orbit provisions PHP-FPM from the pinned Sury apt source and fronts every site with Caddy over a Unix socket. This page tells an operator or deployer how development and production services differ, where generated identity and local tuning live on a Node, and how to verify each runtime. [ADR 0021](../decisions/0021-pin-sury-php-fpm-with-opcache-profiles-per-role.md) owns the shared package source and role defaults, while [ADR 0045](../decisions/0045-isolate-production-php-fpm-by-unix-user.md) owns production service isolation and tuning ownership.

## Select an AppInstance runtime

For a development or standalone production AppInstance, the Gateway reads source metadata after initial source preparation and before runtime or Domain Name System (DNS) publication. [ADR 0034](../decisions/0034-select-appinstance-php-from-composer-constraints.md) defines this source-driven selection boundary.

Orbit's code-owned AppInstance candidate set is PHP 8.5 followed by PHP 8.4. The Gateway compares a Composer constraint only with these candidates in this order. It does not select another PHP version, even when that version is syntactically valid.

| Source or package result | Runtime result | Error code |
| --- | --- | --- |
| No `composer.json` file | Orbit classifies the source as non-PHP and prepares no PHP runtime. | None |
| Valid `composer.json` without a PHP platform constraint | Orbit selects PHP 8.5. | None |
| Valid `composer.json` whose PHP platform constraint matches a candidate | Orbit selects the first matching candidate: PHP 8.5 before PHP 8.4. | None |
| Invalid PHP platform constraint, or a constraint below, between, or above all candidates | The Gateway stops at PHP selection. | `app-dev.php_version_unsupported` or `app-prod.php_version_unsupported` |
| Selected candidate is unavailable from the pinned Sury source | The Gateway stops when it verifies the runtime source. | `app-dev.php_package_source_unavailable` or `app-prod.php_package_source_unavailable` |

Both failures happen before runtime or DNS publication. At its first provisioning checkpoint, the Gateway stores the selected version together with the Laravel classification as one complete source profile. A development retry at a retained checkpoint requires both values to match before Laravel URL configuration or runtime and Route projection. A changed development profile returns `app-dev.source_evidence_changed`. Production source changes after successful provisioning are operator-owned and an identical creation retry does not inspect them.

The Gateway does not infer missing Laravel evidence for a legacy retained checkpoint. An ordinary retry fails closed. The explicit recovery contract, including URL-reconciliation consent, rollback refusal, active-state behavior, and unchanged removal boundaries, is described in [Applications](../domains/applications.md#provision-the-application-endpoint).

AppInstance input, persisted AppInstance state, API responses, the PHP SDK, and the CLI do not expose a PHP-version field. The Node application role owns installation, configuration, and removal of every selected PHP runtime.

## Runtime ownership

Development sites for one PHP version share the distribution PHP-FPM service. Each new production PHP AppInstance uses the service, pool, socket, and OPcache instance recorded for its production Unix user. Production users on the same Node share the installed PHP version packages but do not share a PHP-FPM master.

The production identity follows fixed names that an operator can inspect.

| Projection | Name or path | Owner | Lifecycle |
| --- | --- | --- | --- |
| Service | `orbit-<production-user>-php<version>-fpm.service` | Gateway | Created and activated for the recorded production user; removed with that AppInstance's runtime projection. |
| Socket | `/run/php/<production-user>.sock` | Gateway | Created by the owning service and removed when that service stops. |
| Generated identity | Generated PHP-FPM files below `/etc/orbit/php-fpm/<production-user>/generated/`, including the service-specific `master.ini` | Gateway | Replaced only after the complete candidate validates against the recorded user, service, pool, socket, version, home, source paths, and effective master settings. |
| Local tuning | `/etc/orbit/php-fpm/<production-user>/local.conf` | Operating agent | Seeded with Orbit defaults for a new runtime and then preserved byte-for-byte by provisioning, retry, and cleanup. |

The generated configuration establishes runtime identity and includes the separate local tuning file. Before activation or an Orbit-owned reload, the Gateway validates the effective configuration and refuses a local or conflicting file that changes the recorded user, service, pool, socket, PHP version, home, or application path. It does not adopt an existing user, service, socket, generated directory, or file whose identity or ownership conflicts with the AppInstance record.

An interrupted publication resumes from the recorded production identity. A failed candidate activation restores the exact generated files and service state captured before publication. It never replaces the local tuning file during recovery.

Existing production placements without a dedicated service association remain on their recorded shared runtime until an operator explicitly converts the placement. Conversion copies supported local pool tuning into the dedicated runtime's `local.conf`, verifies the complete effective identity, and changes only that AppInstance's Caddy upstream. It does not reload, restart, or reset another production user's shared or dedicated service. The [production release-layout reference](deployments.md#convert-an-existing-production-home) describes the complete conversion and refusal boundary.

New dedicated runtime preparation, retry, removal, and explicit conversion do not rewrite or adopt an unrelated placement. A Node can also run the Gateway or development PHP service; production runtime operations leave those service masters and caches unchanged.

## Shared runtime module

Development and existing shared placements use a normal Debian PHP module at `/etc/php/<version>/mods-available/orbit-runtime.ini`, enabled for the FPM Server Application Programming Interface (SAPI) as `/etc/php/<version>/fpm/conf.d/99-orbit-runtime.ini` through `phpenmod`. On every shared-runtime convergence the Gateway compares the rendered module with the installed file, repairs a missing or wrong `conf.d` link, and verifies the effective managed directives through `php-fpm<version> -i`.

The Gateway reloads the shared service only when its managed shared module, enablement, or FPM PCOV enablement changes. A dedicated production operation does not use this publication path. The command-line interface (CLI) SAPI keeps stock defaults (`opcache.enable_cli=0`).

## Runtime defaults

The shared development module and each generated dedicated production master profile apply these directives; [ADR 0021](../decisions/0021-pin-sury-php-fpm-with-opcache-profiles-per-role.md) records the reason for each value.

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

[ADR 0021](../decisions/0021-pin-sury-php-fpm-with-opcache-profiles-per-role.md) records why each role gets its policy.

## Production cache boundary

Runtime provisioning establishes the dedicated master and its isolated OPcache instance. Production deployment and verified cache refresh are separate operations. A generic reload of the distribution `php<version>-fpm` service does not target a dedicated production runtime, and Orbit does not use it as a fallback. Laravel's `php artisan optimize` caches remain application deployment work.

## Process management

Both roles use `pm = ondemand` with `pm.process_idle_timeout = 10s` and `pm.max_requests = 500`. `pm.max_children` is 10 on an app-dev pool and 20 on an app-prod pool. [ADR 0021](../decisions/0021-pin-sury-php-fpm-with-opcache-profiles-per-role.md) records why both roles use `ondemand`.

## Caddy

Production sites add an immutable cache header for Vite build output:

```caddyfile
@vite {
    path /build/assets/*
    file
}
header @vite Cache-Control "public, max-age=31536000, immutable"
```

Laravel's Vite plugin fingerprints every file under `public/build/assets`, so browsers can keep them for a year. The `file` matcher limits the header to assets that exist on disk. A request for a removed fingerprint falls through to Laravel's front controller without the header, so a 404 is never cached as immutable. Development sites set no caching header. `php_fastcgi`, `encode zstd gzip`, and `file_server` keep Caddy defaults; Orbit renders no `try_files`, and the `php_fastcgi` default tries `{path}`, then `{path}/index.php`, then `index.php`.

## Verification

On a Node, an operator checks a dedicated service, socket, generated identity, and local tuning separately:

```sh
systemctl is-active orbit-<production-user>-php8.5-fpm.service
systemctl cat orbit-<production-user>-php8.5-fpm.service
stat /run/php/<production-user>.sock
find /etc/orbit/php-fpm/<production-user> -maxdepth 2 -type f -print
```

Effective PHP values are visible from inside a request with `ini_get('opcache.validate_timestamps')` or `opcache_get_configuration()['directives']`. A service PID and OPcache instance belong only to the recorded production user; inspecting another production user must show a different master, service, socket, and cache.
