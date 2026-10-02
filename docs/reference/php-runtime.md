---
title: "PHP runtimes"
description: "How Orbit selects a PHP version for an Instance, runs shared development and dedicated production PHP-FPM services, and refreshes production OPcache."
covers:
  - apps/gateway/app/Domain/Instances/{InstancePhpVersionCatalog,ComposerSourceClassifier,ProductionPhpRuntimeIdentity,ProductionPhpRuntimeManager}.php
  - apps/gateway/app/Infrastructure/Instances/{RemoteProductionPhpRuntimeManager,RemoteProductionInstanceSourceLifecycle,ProductionPhpRuntimeConfigRenderer,ProductionPhpRuntimeConfiguration}.php
  - apps/gateway/app/Infrastructure/AppProd/{RemoteAppProdPhpFpmManager,RemoteAppProdSourceManager}.php
  - apps/gateway/app/Infrastructure/Nodes/{PhpFpmRuntimeIniRenderer,RemotePhpPackageManager}.php
  - apps/gateway/app/Infrastructure/AppDev/{DevelopmentPhpFpmConfigRenderer,RemoteAppDevPhpFpmManager}.php
  - apps/gateway/app/Infrastructure/SharedOrbitDirectory.php
---

# PHP runtimes

Orbit installs PHP from the pinned Sury apt source and serves each site through PHP-FPM over a Unix socket. Development Instances on a Node share one PHP-FPM service per PHP version. Each production Instance that serves PHP gets its own PHP-FPM service, with its own OPcache.

## Select the PHP version

The Gateway reads the source's `composer.json` once, before it publishes the runtime or DNS. It tries PHP 8.5, then PHP 8.4, and picks the first version that the `require.php` constraint allows.

| Source | Result |
| --- | --- |
| No `composer.json` | No PHP. Orbit prepares no PHP runtime. |
| `composer.json` without `require.php` | PHP 8.5. |
| A constraint that 8.5 or 8.4 meets | The first match, 8.5 before 8.4. |
| An invalid constraint, or one that neither version meets | `app-dev.php_version_unsupported` or `app-prod.php_version_unsupported`. |
| The version is missing from the Sury source | `app-dev.php_package_source_unavailable` or `app-prod.php_package_source_unavailable`. |

The Instance records the selected version in its [source profile](/domains/applications#provision-the-application-endpoint). There is no input or output field to choose a version. The Node role installs, configures, and removes every selected version.

Orbit does not recover missing source profiles on older Instances. [Projects: One public name without compatibility](/reference/projects#one-public-name-without-compatibility) explains the no-legacy-support rule.

## Development runtime

Development sites share the distribution service `php<version>-fpm`. Each site has its own pool and socket.

Orbit publishes one module per version at `/etc/php/<version>/mods-available/orbit-runtime.ini` and enables it for FPM only, as `/etc/php/<version>/fpm/conf.d/99-orbit-runtime.ini`. The CLI keeps stock settings. At each convergence, the Gateway compares the module with the installed file, repairs the link, and reloads the service only when the module or its enablement changed.

A development pool checks every file on every request, so a saved file is served at once:

```ini
php_admin_value[opcache.validate_timestamps] = 1
php_admin_value[opcache.revalidate_freq] = 0
```

`opcache.file_update_protection` stays at its stock 2 seconds, because file times have one-second resolution.

## Production runtime

Each production Instance that serves PHP has a dedicated service for its Unix user. Production users on one Node share the installed PHP packages, but not a PHP-FPM master.

| Part | Name or path | Owner |
| --- | --- | --- |
| Service | `orbit-<production-user>-php<version>-fpm.service` | Gateway |
| Pool | `orbit-<production-user>` | Gateway |
| Socket | `/run/php/<production-user>.sock`, mode `0660`, group `caddy` | Gateway |
| Generated files | `/etc/orbit/php-fpm/<production-user>/generated/`, including `master.ini` | Gateway |
| Local tuning | `/etc/orbit/php-fpm/<production-user>/local.conf` | Operator |

Orbit seeds `local.conf` with its defaults for a new service. After that, provisioning, retries, updates, and removal never change it. The generated files set the identity: the user, pool, socket, PHP version, home, and application path. Before it starts or reloads the service, the Gateway validates the effective configuration. It refuses a `local.conf` that changes the identity. It never adopts an existing user, service, socket, or file that belongs to something else.

A failed start restores the generated files and service state from before the change. An interrupted change resumes from the recorded identity.

The shared `/etc/orbit` directory stays `root:root` with mode `0711`, so production users can reach their Schedule scripts without listing the directory. `/etc/orbit/php-fpm` stays closed to application users.

A `laravel-app` Instance serves PHP. A `monorepo` Instance serves PHP only when it has a Route and a Laravel source. A `laravel-package` can select a PHP version from its Composer constraint but is not classified as a Laravel application or served through PHP-FPM. Other types start no PHP-FPM master. Production source inspection applies the Instance's project type when it classifies the Composer and Artisan metadata. It [checks the production checkout](/reference/instance-cloning#destination-checks) as the production user, even when the SSH user's home is private.

## OPcache settings

The development module and each production `master.ini` apply these values.

| Directive | `app-dev` | `app-prod` |
| --- | --- | --- |
| `opcache.enable` | On | On |
| `opcache.memory_consumption` | 512 | 256 |
| `opcache.interned_strings_buffer` | 64 | 32 |
| `opcache.max_accelerated_files` | 65407 | 65407 |
| `opcache.validate_timestamps` | 1, per pool | 0 |
| `opcache.jit` / `opcache.jit_buffer_size` | disable / 0 | disable / 0 |

A shared service on a Node with both roles uses the `app-dev` values. A dedicated production master always uses the `app-prod` values. `opcache.preload`, `file_cache`, and `huge_code_pages` stay off.

## Process management

Every pool uses `pm = ondemand` with `pm.process_idle_timeout = 10s` and `pm.max_requests = 500`. `pm.max_children` is 10 for a development pool and 20 for a production pool. Production tuning lives in `local.conf`.

The Gateway's own `orbit-gateway` pool uses 8 children and a 600-second request limit. Its API commands stop remote work after 570 seconds; see [Activity](/cli/activity#interrupted-requests).

## Refresh production code

A production master keeps compiled code until a cache refresh. Each [deployment](/reference/deployments) and rollback refreshes the cache of its own service:

1. The Gateway checks that the service is active and owns the expected socket.
2. It asks for `opcache_reset()` through that socket, inside the service.
3. It reports success only after a later request shows the reset finished.

The refresh never touches another Instance's socket and never reloads a service. `opcache_reset()` from the PHP CLI cannot reach the service's cache. Laravel's `php artisan optimize` is an application deploy step.

| Code | Cause |
| --- | --- |
| `app-prod.php_cache_socket_unavailable` | The socket does not answer. |
| `app-prod.php_cache_association_invalid` | The service or socket does not match the record. |
| `app-prod.php_cache_reset_rejected` | PHP rejected the reset. |
| `app-prod.php_cache_reset_pending` | The reset did not finish before the deadline. |

## Doctor

[Doctor](/cli/doctor) checks that each production PHP Instance has one service, pool, and socket, and shares none of them. It compares the generated files and rejects a `local.conf` that changes the identity. It also checks the service's `ExecStart` and `PHP_INI_SCAN_DIR`, the master process, its socket, and the user and parent of each worker. An idle pool with no workers is valid.

Doctor reads only. It never starts PHP-FPM, sends a request, reloads a service, or resets a cache. It does not compare allowed tuning with Orbit's defaults. A process that exits while Doctor reads it is not drift. When Doctor cannot read a required fact, it reports `instance.inspection_failed`.

## Check a Node

Run these commands on the Node to inspect one production runtime:

```sh
systemctl is-active orbit-<production-user>-php8.5-fpm.service
systemctl cat orbit-<production-user>-php8.5-fpm.service
stat /run/php/<production-user>.sock
find /etc/orbit/php-fpm/<production-user> -maxdepth 2 -type f -print
```

Another production user must show a different service, socket, master, and cache.

## Why it works this way

These reasons explain the design. Check them before you propose a change.

### Sury packages, not a custom PHP build

A static-php build is 10 to 15 percent slower on Orbit's Nodes, lacks `pdo_sqlite` and `pdo_pgsql`, and its glibc variant crashes on some CPUs. A custom build would also make Orbit own PHP security updates. So Orbit uses the pinned Sury packages.

### OPcache sizing per role

The stock limit of 10,000 cached files overflows with two Laravel checkouts on one Node. OPcache memory belongs to the master, so sizing is set per service. Timestamp checks are a per-pool setting.

### Development checks every file

Checking every file costs about 1.5 ms per request. Turning OPcache off costs about 80 ms. So development keeps OPcache on and checks every request.

### No JIT

The tracing JIT gives a Laravel request no measurable gain and has known crash classes.

### One master per production user

All pools under one master share one OPcache. A reset through one pool's socket clears the cache of every pool. So each production user gets its own master, and a refresh never affects another application. The cost is one cache allocation and service per production Instance.

### Tuning stays on the Node

The operator tunes `local.conf` on the Node. Storing tuning in the Gateway was rejected: it adds storage and synchronization for settings the operator can edit in place. Tuning in the generated files was rejected, because Orbit rewrites them.

### PHP from Composer

A PHP project already states its supported versions in `composer.json`. A PHP version field on the Instance was rejected, because it would duplicate that intent and need its own update lifecycle.
