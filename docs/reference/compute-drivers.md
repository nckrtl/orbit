---
title: "Compute drivers"
description: "Task VMs for every Project and their UpCloud overflow."
covers:
  - apps/gateway/app/{Domain/TaskVms,Infrastructure/TaskVms,Jobs/TaskVms,Actions/TaskVms,Domain/Compute}/**
  - apps/gateway/app/{Models/TaskVm.php,Models/TaskVmImageBuild.php,Console/Commands/*ImageBuildsCommand.php,Console/Commands/PrepareTaskVmHostCommand.php,Console/Commands/PrepareTaskVmHubCommand.php,Console/Commands/BuildTaskVmImageCommand.php,Console/Commands/BuildTaskVmRecipeCommand.php,Console/Commands/BuildUpCloudTaskVmImageCommand.php,Console/Commands/ResolvesTaskVmHostNode.php}
  - apps/gateway/{app/Infrastructure/MachineSoftware/**,app/Console/Commands/MachineRecipeCommand.php,resources/machine-software/**,resources/remote/php/**,resources/remote/lib/artifacts.py}
  - .github/workflows/{php-build-mirror,git-build-watch}.yml
  - bin/{php-build,git-build-watch}
  - apps/gateway/{app/Models/PackageCache*.php,app/Console/Commands/PackageCacheUsageCommand.php,database/migrations/*create_package_cache_tables.php,config/package_cache.php,config/task_vms.php}
  - apps/gateway/resources/task-vms/**
  - apps/gateway/database/migrations/*{create_task_vm_image_builds_table,create_upcloud_task_vm_image_builds_table,add_recipe_to_task_vm_image_builds,add_recipe_identity_to_task_vms,fence_task_vm_image_build_attempts,create_task_vms_table,add_model_proxy_origin_to_task_vms,add_warm_pool_to_task_vms,retire_task_vm_warm_pool,add_upcloud_to_task_vms,add_size_to_task_vms,add_topology_to_task_vms,create_jobs_table,create_failed_jobs_table,create_task_sandboxes_table,add_fleet_enrollment_to_task_sandboxes,add_pi_ready_at_to_task_sandboxes,close_checks_of_destroyed_task_sandboxes,drop_task_sandboxes}.php
---

# Compute drivers

Every task group runs in its own [task VM](#task-vms), which is a normal `app-dev` Node of the dev Cluster. This holds for every Project, `orbit` included. See [Orbit Project](#orbit-project).

Task VMs are off until you [configure them](#configure-task-vms). Until then no task group can start. [ADR 0200](/decisions/0200-run-each-task-group-in-its-own-sandbox-vm) records the design.

## Machine software recipe

The repository's machine software recipe is [`apps/gateway/resources/machine-software/recipe.json`](https://github.com/nckrtl/orbit/blob/main/apps/gateway/resources/machine-software/recipe.json). It declares one Ubuntu snapshot, tool version pins, the [PHP build](#php-build), and the tool sets for `gateway`, `app-dev`, `app-prod`, `operator`, and the task VM base image. Project dependencies, workspace setup, credentials, Node identity, and service configuration are not recipe inputs.

The loader and validator define the recipe contract. [Recipe image builds](#build-the-base-image) use that document for a host-local base image. [Node convergence](/reference/node-provisioning#converge-a-node-to-a-recipe) applies its role tools to production Nodes. Claims require the [current recipe](#claims-require-the-current-recipe), and topologies [import its base image](#prepare-the-topology).

### Recipe schema

The recipe is UTF-8 JSON, not executable PHP or a list of shell commands. The loader accepts schema version `1` only. It rejects missing fields, unknown fields, duplicate object keys, wrong types, empty required values, duplicate list entries, and unsupported sources, architectures, releases, roles, or tool identifiers. A schema change requires an explicit loader change; it is not a way to pass arbitrary installation instructions to a host.

| Field | Contract |
| --- | --- |
| `schema_version` | Integer `1`; this identifies the format, not the machine software version |
| `ubuntu.release` | Ubuntu release used by the current base image, initially `26.04` |
| `ubuntu.snapshot` | UTC snapshot identifier in `YYYYMMDDTHHMMSSZ` form, used at `https://snapshot.ubuntu.com/ubuntu/<snapshot>/`; a syntactically valid date alone does not prove availability |
| `architectures` | Non-empty list drawn from `amd64` and `arm64`, using Debian architecture names |
| `tools` | Object keyed by the loader's known tool identifiers; each value has a concrete `version` (an Ubuntu floor or exact artifact version) and a `source` of `ubuntu`, `artifact`, or `internal` |
| `common` | Tool identifiers included in every role and the base image |
| `php` | The [PHP build](#php-build): `ci`, `shared`, and `versions` |
| `roles` | Object requiring `gateway`, `app-dev`, `app-prod`, and `operator`; optional profiles use known Node role names or `ci-runner`. Each value lists additional tool identifiers and PHP sets |
| `base` | List of additional tool identifiers and PHP sets for the task VM base image; this is an image profile, not a Node role |

An `ubuntu` tool names its Debian `package`. Its `version` is the complete Debian version, including epoch and distribution revision when present, not just an upstream release or a constraint. It resolves only from the selected Ubuntu snapshot's signed package indexes for the declared release and architectures. The release's archive, updates, and security pockets are checked; a recipe pin available only from today's rolling archive fails online validation.

Ubuntu pins are version floors on installed machines. The same Debian package on the native architecture (or `all`) satisfies the recipe at the pinned version. A newer version also satisfies it when `dpkg --compare-versions` orders it above the pin and local APT policy confirms Ubuntu origin in the same release, updates, or security pocket. Convergence and base and role image verification use this rule. Plans and results label accepted newer packages `newer_than_recipe` and include both versions. Older or missing packages install the snapshot pin.

A newer package from another release, a third-party source, or an unproved origin is refused before package changes; it is never downgraded. An installed version listed in any untrusted index is refused even if a trusted index also lists it or offers a newer candidate. Plans label it `unsupported_newer` with a `refusal` field naming `origin:<tool>`.

APT policy from the normal sources or the cached recipe snapshot must associate the installed version with an index whose release metadata names `o=Ubuntu`, `n=resolute`, and `a=resolute`, `resolute-updates`, or `resolute-security`. The index must also come from an Ubuntu archive, security, ports, or snapshot host, or from a matching source scoped to `/usr/share/keyrings/ubuntu-archive-keyring.gpg`. Release metadata alone cannot establish trust. The dpkg status records package identity, architecture, and version, but not its repository or release. A version suffix alone cannot prove origin.

Rolling pocket indexes drop superseded builds. When the installed version appears only in dpkg status, convergence dates it by the earliest matching `install` or `upgrade` action in `/var/log/dpkg.log*`, including compressed rotations. Without an action, it uses the earliest `status installed` record for that version. Later trigger runs do not change the date. It must find that exact package version in a trusted index at `snapshot.ubuntu.com` at that time. A newer Ubuntu candidate alone is not proof.

Snapshot checks use temporary sources and indexes without changing the system APT configuration or indexes. Each run caches indexes and failures by timestamp, including for the post-install assessment. A run permits at most three lookups by install timestamp, each with a 60-second update timeout. Refusals name the cause after `origin:<tool>`: `dpkg-log-missing`, `snapshot-unavailable`, `snapshot-unproved`, or `snapshot-lookup-limit`. Restore retained install logs for missing history. For other refusals, inspect the package's provenance and snapshot availability before retrying; the recipe does not downgrade an unproved build.

The snapshot service's `/ubuntu/` path includes arm64 indexes for resolute, updates, and security. The separate `/ubuntu-ports/` snapshot path returns HTTP 401 as checked on 15 October 2026, so arm64 uses `/ubuntu/` too. A ports build absent from those authenticated indexes remains unproved and is refused.

There is a known fail-closed window between `apt update` and installation. If Ubuntu supersedes a build during that window, the snapshot at its install time can omit it. Convergence then refuses with `snapshot-unproved`, even for a real Ubuntu build. It does not infer provenance from a newer candidate or guess an earlier update time.

Recipe installs use a temporary snapshot source and leave the normal Ubuntu sources in place, so unattended-upgrades can continue installing security updates. Application repairs the exact snapshot source file written by earlier Orbit convergence. It first saves that file beside the APT configuration as `ubuntu.sources.orbit-snapshot-backup`, then restores archive, updates, backports, and security sources with the Ubuntu archive keyring, or ports on arm64, and logs the repair through `logger` with the `orbit-recipe` tag. Other source content is preserved.

The restored sources use the generic Ubuntu hosts. A regional mirror cannot be recovered because main overwrote the original file without a backup. The post-repair refresh reads only `ubuntu.sources`, so a broken third-party repository cannot block it. Dry runs show `repair_ubuntu_sources` in the plan's `actions` and assess the restored sources with temporary indexes; they leave the system sources and indexes unchanged. Without a repair, dry runs use existing indexes.

Ubuntu installs refuse downgrades. Exact Debian artifacts permit downgrades only after an APT simulation rejects every non-artifact downgrade; installed Ubuntu recipe versions remain explicit install targets. Installation ends with `apt-get check` and `dpkg --audit`; audit output also causes failure. Sury PHP artifacts remain exact: the PHP build installer owns their installation and verification, and the Ubuntu floor rule never applies to them.

The recipe pins both Ubuntu `docker.io` and `containerd` for Docker roles and the base image. Migration reads those pins rather than choosing a separate containerd version.

Docker CE is not the recipe's Ubuntu `docker.io` provider. Convergence refuses it with `docker-provider:docker-ce` before planning or installing packages. It never stops containers to change providers. Follow the explicit [Docker provider migration](/reference/docker-provider-migration) during a planned window. That command may downgrade Docker and containerd after inventory and compatibility checks; normal recipe convergence still refuses downgrades.

An `artifact` tool has an `artifacts` object keyed by every declared architecture. Each entry contains an HTTPS `url` and exactly one digest field, `sha256` or `sha512`, with a full lowercase hexadecimal digest. A URL must identify the pinned release, not a moving installer or a `latest` alias. Architecture-independent archives repeat the same URL and digest for each architecture. Artifact contents, not the spelling of the URL or a successful HTTP response, are the integrity check. The validator downloads and hashes the bytes without executing or installing them.

Debian artifact filenames omit a version epoch; the pin and package metadata retain it. Architecture-independent `.deb` packages declare `all` in their metadata.

Git and `git-man` are exact Debian artifacts from Launchpad's `ppa:git-core/ppa`, built for the recipe's Ubuntu release. The `git.packages` list names the owned packages. Both belong to `common`, so every Node profile and task VM image gets the same build. The loader requires matching versions and SHA-256 pins for both architectures. Convergence accepts the pinned build already installed, replaces an Ubuntu build after proving its origin, and refuses a different third-party build with `origin:git` or `origin:git-man`. The Ubuntu provenance rules for other tools stay unchanged.

After verifying both installed package versions, convergence and image builds save their versions in root-owned `/etc/apt/orbit-git.json`. A later recipe pin may replace that recorded build. A missing, malformed, writable, or unowned receipt proves no ownership; an installed version that differs from its receipt still needs Ubuntu provenance or the current exact pin.

Convergence backs up and removes git-core PPA entries for `resolute main`, including deb822 sources with inline signing keys. It preserves unrelated source entries and refuses mixed or unexpected git-core entries. Dry runs report `retire_git_source` and `pin_git` without writing. An Orbit APT preference permits only the pinned Git version, so unattended upgrades cannot introduce a second owner. The APT Tool Manager protects both packages from adoption and removal.

Image builds refresh the snapshot indexes before assessing Git with the Ubuntu tools. Both installers retire the PPA before installation and publish the preference only after the install succeeds. Backups live in `/etc/apt/`, outside APT source directories. A source file with no remaining entries is removed.

Installed binaries built from source package `git` but absent from `git.packages` are refused as `git-dependency:<package>` before Git source or package changes. This includes siblings such as `gitk` and `git-gui`, even at the pinned version. An operator must remove these unmanaged siblings before convergence; the recipe owns only its declared package set.

PPAs delete superseded builds. The recipe-level `artifact_mirror` serves every external Debian artifact as `<package>_<digest>.deb`. The `php-build-mirror` release retains its existing URL and now holds all these artifacts. `bin/php-build mirror` and its workflow skip assets already published. Each installer and the validator tries the mirror before the original URL. A transport failure permits fallback; an integrity failure stops the operation.

Exact Git pins block automatic Ubuntu and PPA security updates. Orbit owns the delay between an upstream fix and a reviewed recipe bump. The daily `Git build watch` workflow queries Launchpad for published Git builds for the recipe release and Ubuntu’s security notice API. It opens or updates one GitHub issue for a newer build or a notice published on or after `git.usn_since`. Network and parsing failures fail the workflow visibly.

Review each notice even when its Ubuntu version differs from the PPA build; the check does not claim that Ubuntu version ordering proves a PPA fix. Close the issue only after reviewing the fixes and rolling out the pin. Advance `git.usn_since` explicitly after reviewing the covered notices. See [ADR 0216](/decisions/0216-run-one-exact-php-build-everywhere#git-artifacts-and-security-updates).

Git bumps remain separate from nightly snapshot and PHP build pull requests. The snapshot flow changes only its date; the PHP bump authenticates Sury's indexes and knows PHP's package sets. Adding Launchpad key verification and a separately reviewed Git bump to that flow is outside this change. To bump Git by hand, verify the PPA's signed `resolute` indexes, change both package versions, URLs, and SHA-256 digests together for amd64 and arm64, download and hash all three distinct files, and validate the recipe. After merge, the mirror workflow retains the files and normal recipe convergence rolls out the build.

The loader has a closed catalog of supported tool identifiers and their source kinds. Each `php<version>-<name>` pin of a declared PHP version is an `artifact` tool; the [PHP build](#php-build) decides which of them exist. A new tool needs a catalog change and validation tests as well as a recipe entry. Every profile reference must resolve to a catalogued entry in `tools`; an unused entry, a missing tool, or an incompatible source is an error. The recipe must record a concrete version, including for an Ubuntu floor: `latest`, `stable`, `lts`, version ranges, and unversioned installer URLs are not pins.

An `internal` tool is an Orbit-built binary pinned by `source_commit` (12 to 40 lowercase Git hexadecimal characters), an exact `version`, and architecture-specific entries in `artifacts`. Each entry has a simple `filename` and a full lowercase `sha256`; filenames resolve under the Gateway's sandbox-artifacts directory (or the command's `--artifact-directory` override). Source commit and digest identify the software; the directory and filename are only byte locations and are not part of the identity. The default directory is `$ORBIT_HOME/sandbox-artifacts`, using the Gateway's configured Orbit home.

Internal pins do not require publishing HTTPS releases. Third-party tools still require HTTPS artifact pins. Online validation fails clearly if a local file is missing or its digest differs. It never executes the binary. Existing installers keep their pre-install hash verification.

The initial Pi pin comes from `apps/pi-server` at source commit `e5933d4c8696`, file `pi-server-e5933d4c8696-linux-x64`, SHA-256 `286620c586b13b60fce4ce71f286d282da185fd95f7971c4109fb52bc8f0a78c`. It supports `amd64` only. The base profile therefore supports only `amd64`; `--architecture=arm64 --role=base` fails rather than inventing a Pi artifact. Other profiles support the intersection of their tools' architectures. Validation checks all artifacts actually declared, including Pi, without requiring nonexistent Pi arm64 bytes.

A profile's effective set is the union of `common` and its own list, sorted by tool identifier. A `php:<version>` entry expands to that version's PHP set. Profiles cannot refer to other profiles, so role inheritance cannot hide software or form cycles. A multi-role Node's set is the union of its roles, not the base image set.

### Role and base tool sets

The recipe pins the OS packages and tool distributions for every profile. Exact versions, artifact URLs, and digests live in the recipe.

| Profile | Tools in addition to `common` |
| --- | --- |
| `common` | Bootstrap packages: `ca-certificates`, `curl`, Git and `git-man` from git-core, `gnupg`, `libnss-resolve`, `openssh-client`, `sudo`, `ufw`, and `wireguard` |
| `gateway` | Caddy from the Caddy project's release, not Ubuntu's Caddy package, and `php:8.5` |
| `app-dev` | `acl`, `attr`, Caddy, Composer, `docker.io`, OpenSSL, `php-curl`, `php-xml`, `unzip`, Vite+ (`vp`), Node, pnpm, npm/npx, and Bun; `php:8.5` and `php:8.4` |
| `app-prod` | The application prerequisites and JavaScript/Composer managers, as for `app-dev`, and `php:8.5` only. The PHP step installs `php:8.4` when an Instance selects it |
| `websocket` | Caddy, Composer, OpenSSL, `php-curl`, `php-xml`, and `php:8.5` |
| `ci-runner` | `acl`, `attr`, `unzip`, and `php:8.5`. A roleless Node that runs CI jobs, such as Sabre, selects it with [`node:converge-recipe --profile=ci-runner`](/reference/node-provisioning#converge-a-node-to-a-recipe). It is not a Node role |
| `operator` | No additional role prerequisite tools; this is the recipe profile for a roleless operator Node, not a new Node role |
| `base` | The full `app-dev` set, explicit image-build packages described below, Rust with Cargo/clippy/rustfmt, the Node agent, and Pi |

The base list explicitly contains the app-dev tools. It does not inherit a role. One pinned distribution can supply several binaries, such as npm and npx, or Rust and Cargo. Every fetched input must have a recorded digest. Production Nodes receive only their role tools.

The base also includes the existing image builder's 26 explicit Ubuntu packages: `openssh-server`; workspace tools `rsync`, `bind9-dnsutils`, `gcc`, and `libc6-dev`; and all 21 libraries in `TaskVmCloudInit::ChromiumLibraries` for Playwright's headless Chromium. These are base-only tools, not common or role inputs. Each uses an exact package version from the same authenticated snapshot on both architectures.

### PHP build

The recipe is the single source of truth for Orbit's PHP build. Every machine that runs Orbit's PHP installs this build and checks it: development and production Nodes, the Gateway, task VMs, and [CI](/reference/implementation-loop#one-php-build-in-ci). A PHP build is the exact Sury package versions and the modules available to CLI and PHP-FPM.

| Field | Contract |
| --- | --- |
| `php.versions.<version>.packages` | The `php<version>-*` pins of that version. Every such pin in `tools` must be in this list |
| `php.versions.<version>.extensions` | The build's modules, in lower case, sorted, without section headers. `Zend OPcache` is `zend opcache`. The list includes `core`, CLI-only `pcov`, and FPM-only `opentelemetry` |
| `php.shared` | Pins that every version needs: `php-common` and `libgd3` |
| `php.ci` | The version that CI installs |
| `php.rollback` | The Ubuntu tool that repacks an installed build for a rollback, `dpkg-repack`. Every profile with a PHP set gets it |
| `php.pcov` | The profiles whose PHP CLI loads PCOV: `app-dev`, `base`, and `ci-runner` |
| `php.opentelemetry` | The profiles that install OpenTelemetry and load it in PHP-FPM only: `app-dev` only |
| `php.purge_extras` | The profiles that purge PHP packages outside the recipe. Only `ci-runner` may be listed |
| `artifact_mirror` | The HTTPS base URL of Orbit's copy of the pinned files, tried before each pin's own URL |

A profile names a PHP set as `php:<version>`. The set is the shared pins, the version's packages, and the rollback tool. OpenTelemetry follows `php.opentelemetry`. A profile cannot name a PHP pin directly to choose individual packages from a set. A version that no profile names is an error.

Extensions with their own release, such as `igbinary`, `imagick`, `opentelemetry`, `pcov`, and `redis`, keep their own versions. The loader also rejects a recipe in which:

- the PHP core pins of a version have different versions. A core pin has a version that starts with `<version>.`, and it must equal `php<version>-common`;
- a Sury pin is built for another Ubuntu release: its version must contain `+ubuntu<ubuntu.release>`;
- `php.ci` names an undeclared version;
- `php.purge_extras` names another profile than `ci-runner`, or `artifact_mirror` is not an HTTPS URL.

PCOV loads in the CLI of the `php.pcov` profiles only, and never in PHP-FPM. Those are the machines that run checks and share CI caches, so their loaded extension set matches CI's. Production Nodes and the Gateway keep PCOV installed but not loaded.

OpenTelemetry is installed for the `php.opentelemetry` profiles and enabled in PHP-FPM only. The `app-dev` profile needs its `OpenTelemetry\Instrumentation\hook()` API for php-data-bridge, an FPM-only bridge that instruments web requests. The installer always disables OpenTelemetry in the CLI, including links enabled by Sury's package hooks. This preserves the CLI extension parity with CI that PHPStan's shared result cache needs.

On a Node with both app-dev and app-prod, dedicated production FPM masters also load OpenTelemetry because they share the Node's FPM extension configuration. Production, CI-runner, base, and Gateway profiles do not install its packages. If a Node loses app-dev, convergence keeps an installed OpenTelemetry package as a known disabled package and disables it in both CLI and FPM. The recipe lists OpenTelemetry among the build's packages and modules; the CLI module check always excludes it.

#### Install the PHP build

`php.install-build`, a [remote program](/reference/remote-programs), is the one installer of the PHP build. Recipe convergence and the PHP step of an Instance send it to the Node. The image build runs it in the guest, and `bin/php-build install` runs it on a CI machine.

Before it changes anything, the installer checks the machine:

1. It refuses another PHP apt source, such as `ppa:ondrej/php`, and names it.
2. It refuses installed PHP packages outside the handled recipe sets, except OpenTelemetry packages kept disabled after app-dev removal.
3. A plan run, such as a dry run, stops here and lists the packages it would change.

Then it gets the files:

1. It deletes the Sury apt source of earlier Orbit releases, so `apt upgrade` cannot move PHP.
2. It downloads each pinned file that is not installed at its version. It tries `artifact_mirror` first, then the pin's URL.
3. It refuses a file whose SHA-256 digest, package name, version, or architecture differs from the pin.
4. It keeps every file in `/var/cache/orbit/php-packages` and never deletes one.

Then it keeps the installed build for a rollback:

1. For each package that the change replaces or purges, it looks for a `.deb` of the installed version in Orbit's cache.
2. Next it looks in apt's archive cache, `/var/cache/apt/archives`.
3. Otherwise it rebuilds the installed package from its files. `dpkg-repack --generate` writes the build tree, and the installer builds the file with `dpkg-deb`. A missing `dpkg-repack` is first installed at its recipe version.
4. Without a rollback file, it refuses with `rollback_unavailable` before any change.

Every rollback file must carry the installed package name, version, and architecture. The installer checks the file, not a tool's exit code: `dpkg-repack` exits 0 even when it writes no file. Each source has an integrity check:

- A cached file is named after its SHA-256 digest, and its bytes must still match that name.
- An apt archive file must match the MD5 sums that dpkg recorded for the installed package, in `/var/lib/dpkg/info/<package>.md5sums`.
- An apt archive file is not used when dpkg recorded no MD5 sums.
- A rebuild comes from the installed files themselves.

The rebuild leaves out each conffile that is not on disk. An operator or Orbit may remove a conffile on purpose, such as the default `www` pool of `php<version>-fpm`, and `dpkg-repack` alone then refuses the package. Sury deletes old builds and the mirror holds only pinned ones, so a download by version is not a reliable source.

The installer keeps a disabled default pool disabled. When `php<version>-fpm` is installed and `pool.d/www.conf` is absent before the change, an install or a rollback that brings it back is undone at once. An enabled default pool stays as it is, and a first install of `php<version>-fpm` keeps the pool it brings.

Then it swaps the build:

1. It installs the files with `--no-remove` and `--allow-downgrades`. A newer or an older build becomes the recipe build, and no other package is removed.
2. Package hooks cannot start or restart a service during the install.
3. It loads PCOV in the CLI on the `php.pcov` profiles only, and never in PHP-FPM.
4. It enables OpenTelemetry in PHP-FPM only on the `php.opentelemetry` profiles and disables it in the CLI on every profile.
5. It checks that `php<version> -m` prints the recipe's extensions for the selected profile.

After a change, it restarts PHP-FPM:

1. It validates each master with `php-fpm<version> -t`. A dedicated production master uses its own `--fpm-config`.
2. It restarts the master and checks that the service is active.
3. It checks that `php-fpm<version> -m` loads the required extensions and excludes PCOV.
4. It checks that OpenTelemetry loading matches the selected profile.
5. It sends a FastCGI request to each pool socket in the master's configuration and waits for the answer.

When a check after the swap fails, the installer rolls back. It reinstalls the previous build with `--allow-downgrades`, restores the module links, and restarts the masters again. It ends with `rolled_back`, or with `rollback_failed` when that fails too.

Each caller sets two options:

| Caller | Extra packages | PHP-FPM |
| --- | --- | --- |
| Recipe convergence | `refuse`, or `purge` on a `php.purge_extras` profile | `all`: the shared service and each active dedicated master; a failure rolls back |
| PHP step of an Instance | `keep`, so a deploy never removes a site's extension | `shared`: the shared service, left running when its pools fail `php-fpm -t` |
| Image build | `refuse` | `none` |
| `bin/php-build install` | `refuse`, or `purge` with `--purge-extras` | `shared` |

The outcomes are `planned`, `current`, `applied`, `platform_unsupported`, `conflicting_source`, `extra_packages`, `unowned_extensions`, `download_failed`, `rollback_unavailable`, `install_failed`, `modules_mismatch`, `fpm_invalid`, `rolled_back`, and `rollback_failed`. After a refusal or a failure, the previous build stays installed.

Some extensions have no package: setup-php, for example, copies `.ini` and `.so` files in directly. An installer run that refuses extras refuses these too, with `unowned_extensions`. An unowned `.ini` counts as an extension only when it has an `extension=` or `zend_extension=` line, and the extension's name comes from the library it loads. A settings-only `.ini`, such as Orbit's own `orbit-runtime.ini`, is never refused, moved, or purged.

Library names are mapped to their `php -m` names first: `opcache.so` is `zend opcache`. An `.ini` that loads a recipe extension from a file no package owns, such as `extension=/opt/x/redis.so`, is refused too, because only package-owned files are checked. The refusal names each `.ini` as `php<version>:<modules>`; an `.ini` that loads several libraries joins them with `+`, as in `php8.5:apcu+redis`. With `--purge-extras`, it removes their `conf.d` links and moves their files to `/var/cache/orbit/php-unowned-extensions/`. A rollback moves them back.

A package at its pinned version can still hold other files, for example a `.so` that setup-php overwrote. In apply mode, the installer runs `dpkg --verify` on every recipe package. It installs a package again from its pinned file when a file's checksum changed or a file is missing. A changed conffile stays, because it may hold an operator's settings. The result lists these packages under `repaired`.

#### The recipe artifact mirror

The `Recipe artifact mirror` workflow, `.github/workflows/php-build-mirror.yml`, keeps Orbit's copy of the pinned files. It runs on each push to `main` that changes the recipe, `bin/php-build`, the artifact URL helper, or the workflow, and on manual dispatch.

`bin/php-build mirror` downloads every external Debian artifact of every architecture and checks its recipe digest. The workflow lists published assets before downloading, skips those files, and uploads each missing file to the `php-build-mirror` release, named `<package>_<digest>.deb`. It retains the existing release URL. That release is a prerelease and never the latest release.

Sury and Launchpad delete superseded builds. The mirror keeps rollbacks, new Nodes, images, and CI on the exact build after that.

#### Bump the PHP build

Sury keeps only its newest build in its pool, so keep the recipe on Sury's newest patch. One pull request changes every pin of a version together, with each URL, SHA-256 digest, and version. A partial change fails the loader.

`bin/php-build bump` makes that change. It downloads Sury's signing key and refuses it unless it matches the pinned SHA-256 digest. It verifies the signed `InRelease` file of the recipe's Ubuntu release with `gpgv`, and checks each package index against it. For every architecture, it then moves each pin of every PHP set, `php-common` and `libgd3` included, to the newest version in the index, with its URL and digest. It stops without writing when Sury is between builds, so that two core pins of one version differ. `--dry-run` prints the changes and writes nothing.

[Nightly recipe maintenance](#nightly-recipe-maintenance) runs it every night, in the same job that opens the snapshot pull request. When the pins change, the Gateway opens one pull request through its GitHub App on the branch `nightly/php-build-<digest>`, named after the new pins and titled with the new PHP releases. It opens no second pull request for the same pins, and never reopens a closed one. The nightly auto-merge takes only date branches, so a maintainer reviews and merges the PHP build pull request. The Gateway needs `python3` and `gpgv` for the bump. Run `php artisan orbit:machine-recipe --validate` to check a bump by hand. After merge:

1. The [mirror](#the-recipe-artifact-mirror) workflow publishes the new files.
1. Each task VM host [builds a new base image](#build-the-base-image).
2. [Nightly recipe maintenance](#nightly-recipe-maintenance) converges each Node, or you run `php artisan node:converge-recipe <node>`. The next [PHP step](/reference/php-runtime#php-build) of an Instance also brings its versions to the recipe.
3. Hosted CI installs the new build in its next run. The self-hosted runners fail until an operator [updates them](/reference/implementation-loop#one-php-build-in-ci).

#### Why one PHP build

PHPStan writes `PHP_VERSION_ID` and the sorted `get_loaded_extensions()` into its result cache metadata, and Pint keys its cache on the full PHP version. [CI cache refs](/reference/implementation-loop#ci-cache-refs) only help where the PHP build matches exactly. One build also gives every machine the same behavior and the same security patches.

`setup-php` with a floating version was rejected: it cannot pin a patch, and it loads extensions that Orbit does not use. A separate pin for each environment was rejected because copies drift. Installing `package=version` from the Sury apt source was rejected because Sury removes a version from its index when it publishes the next one. The recipe's files are checked by digest, and the same files build images and converge Nodes. [ADR 0216](/decisions/0216-run-one-exact-php-build-everywhere) records this decision.

### Recipe identity

The recipe identity is `sha256:<digest>`, computed from the validated document's canonical JSON after removing internal-artifact filenames. Internal source commits and digests remain in the identity; relocating identical internal bytes does not change it. Canonicalization recursively sorts object keys by byte order, preserves array order, emits no insignificant whitespace, leaves slashes and Unicode unescaped, and adds no final newline. Only JSON integers, strings, arrays, objects, booleans, and null are permitted; schema fields further restrict their types. Duplicate keys are rejected before hashing. The identity is derived, not stored as a self-referential field in the recipe.

Whitespace and object-key order do not change the identity. Changing a snapshot, version pin, source commit, digest, role assignment, schema version, or list order does. The identity covers all profiles together; there is no independent version counter for each role and no Git commit SHA masquerading as the recipe version. Git history provides provenance, while the content hash names the exact software intent even when the same recipe appears in another commit. [Node recipe convergence](/reference/node-provisioning#converge-a-node-to-a-recipe) records this complete identity after verification.

[Base image builds](#build-the-base-image) record the same identity in the image and build row. Schema version `1` and a short display hash are not substitutes.

### Show and validate the recipe

The Gateway command `php artisan orbit:machine-recipe` shows the repository recipe's identity, snapshot, tool version pins, and effective profile tool sets. `--role=gateway`, `--role=app-dev`, `--role=app-prod`, or `--role=operator` selects one role; service profiles also cover `vpn`, `router`, `ingress`, `metrics`, `database`, `websocket`, and `analytics`; `--role=base` selects the base image profile. `--path=<file>` selects a candidate recipe for review or a validation fixture. `--architecture=amd64` or `--architecture=arm64` requires each displayed profile to support that architecture; without it, the output lists each profile's supported architectures.

Showing a recipe always performs structural validation and makes no claim about remote availability. Add `--json` for the complete recipe (including URLs, digests, internal filenames, and source commits), its identity, validation state, and selected effective profiles. JSON errors contain only an `error` message and exit nonzero; failed validation never emits a success state.

Add `--validate` to verify availability and integrity as well as structure. For example, from `apps/gateway`, run `php artisan orbit:machine-recipe --validate` before accepting a recipe change. Validation checks the whole recipe across every declared architecture even when `--role` limits the displayed set. It requires network access, `gpgv`, the OS-maintained `/usr/share/keyrings/ubuntu-archive-keyring.gpg`, and readable internal artifact files, but no database, SSH connection, image builder, or root privileges. Use `--artifact-directory=<directory>` to resolve internal filenames from a disposable or staged directory instead of the Gateway's sandbox-artifacts directory. It does not install packages, change apt sources, create Nodes, build images, or change which image a claim can use.

Online validation verifies that the exact snapshot exists for the release and architectures, that its authenticated package indexes contain each exact Ubuntu package version, and that every external or Gateway-local artifact is available and matches its recorded digest. Redirects or server fallback to another snapshot or a rolling archive must not turn an unavailable pin into success.

Transient HTTP 500, 502, 503, and 504 responses receive at most three attempts at the same pinned URL. Network failures are failures to verify, not evidence that a release is invalid or permission to choose another version. A successful structural-only show is not a successful availability check.

| Failure | Required diagnostic |
| --- | --- |
| Invalid JSON or schema | File and field path, with the expected shape or unsupported schema version |
| Unknown role or tool | The rejected name and the valid choices; unresolved references identify the profile and tool |
| Invalid or unavailable snapshot | The snapshot identifier, release, architecture, and failing metadata URL or verification reason |
| Unavailable package or version | Tool identifier, exact requested version, architecture, snapshot/source, and what could not be resolved |
| Missing artifact or digest mismatch | Tool identifier, architecture, URL, and the expected and actual digest when bytes were obtained |
| Network or signature failure | The failing source and reason; report that availability could not be verified rather than silently falling back |

The command exits zero only for a successful requested operation and nonzero for any failure. It never prints a validation-success result for a partially checked recipe. Tests must cover malformed recipes, unknown role and tool names, well-formed but unavailable snapshots and versions, and digest failures with controlled responses; a syntax-only date test or mocked unconditional success does not prove remote validation.

### Why it works this way

A closed, versioned JSON document is reviewable without executing code. A content hash names all role sets together without a manually maintained counter. Separate structural and online checks distinguish malformed intent from unavailable inputs. Explicit artifact hashes cover software that Ubuntu's snapshot cannot freeze. One catalog prevents misspelled tools or roles from silently changing an image. The [image build fence](#build-the-base-image) and [nightly recipe maintenance](#nightly-recipe-maintenance) own publication and Git updates; the validator checks recipe inputs.

One recipe connects development images to production maintenance. A changed recipe rebuilds the whole base from stock on each host. Partial builds and incremental images were rejected because previous image history would become another input. A fixed nightly rebuild without a versioned recipe would not identify the intended software or connect it to production.

Trusted Gateway jobs build images and fill package caches. Agents have root inside task VMs, so letting them publish either shared result would allow one Project to affect another. The cache inventory records which Projects use each package for incident investigation.

Every workspace clones and runs Project setup. Per-Project snapshots would keep setup coupled to image builds, invalidation, and retention. Package and CI caches speed setup without a saved workspace. Fresh VMs also avoid capacity held by idle pre-enrolled guests and their refill lifecycle, at the cost of boot time.

Nightly convergence checks one Node before advancing to the next. This limits failure spread but permits a mixed-recipe fleet and a partly changed Node. Automatic rollback was rejected: the operator investigates and selects a reviewed recipe for recovery.

## Task VMs

A task VM is an Ubuntu 26.04 VM on an Incus host. It boots from the [base image](#build-the-base-image), which each host builds from the same recipe and which holds the `app-dev` tool set. The Gateway creates it for one group, enrolls it as an `app-dev` Node, and destroys it when the group ends. After enrollment, the group uses the normal Instance and task paths, pinned to that Node. Incus provides local capacity; [UpCloud](#upcloud-overflow) provides optional overflow.

### From claim to workspace

A claim moves a group through these steps. Steps 2 to 5 are queued [jobs](#jobs).

1. The scheduler claims a group. `AllocateTaskVmAction` decides the VM's [size](#size-by-declared-topology). It records a new `provisioning` row, and queues `ProvisionTaskVm`.
2. `ProvisionTaskVm` launches the VM from the base image with cloud-init user-data. Its disk is a ZFS clone of the image. On beast, the launch takes under a second. Cloud-init is done about 10 seconds later.
3. `EnrollTaskVm` waits for cloud-init. Then it reads the guest's IPv4 address, and its ed25519 host key fingerprint from the host through `incus`. Enrollment [trusts the image](#skip-what-the-image-holds) for the `app-dev` software.
4. `EnrollTaskVm` runs the normal [Node provisioning](/reference/node-provisioning#add-a-node) for the Node `tvm-<id>`, through the host as [jump host](/reference/node-provisioning#enroll-through-a-jump-host).
5. `PrepareTaskVmRuntime` creates the group's CLIProxyAPI key and starts Pi as `orbit`. See [Run Pi on a task VM](/reference/pi-server#run-pi-on-a-task-vm). The row becomes `ready`.
6. On the next claim, Orbit creates the [task workspace](/reference/tasks#task-vm-workspace) on the VM's Node. It is a normal Instance `task-<group id>`. A routed workspace gets the private Route `task-<id>.<project>.<dev-tld>`.
7. The claim starts the baseline check. Then the same claim projects the Route.

#### Start the baseline check before the Route

The baseline check needs the checkout, its `.env`, and the PHP packages of the Instance's PHP version. It does not need the Route: Pest's browser tests serve the application from their own server.

So a routed workspace on a task VM stops before its Route. After the source, workspace creation runs these steps, in about 1.5 seconds with the current base image:

1. It classifies the source and records the PHP version.
2. It writes `APP_URL`, and for a new `.env` the application key and name, as it does for every development Instance.
3. It installs the `app-dev` PHP packages of that version. On a task VM they are in the [base image](#build-the-base-image), so the step only checks them.

The workspace stays `source_resolved`, and its Route stays `pending`. The claim attaches it to the group and starts the baseline check. Then, in the same claim, it projects the Route as Instance creation does: the certificates, Caddy, PHP-FPM, the Router, and DNS, under the projection lock. The workspace becomes `active`.

The setup steps of the check can change files while the Route grants Caddy access to the checkout: `vp build` rewrites the build output, and Composer republishes assets. So the access walk ignores an entry that vanishes during the walk, and fails on any other error. It sets each directory's default ACL before its access ACLs, so a file that setup creates during or after the walk inherits the right entry: readable by Caddy in the Web root, denied elsewhere.

A Route that fails at the claim logs `The task workspace Route failed after the baseline check started; Orbit tries again before the implementer starts.` Work that uses the Route waits for it: before the first implementer or the final review starts, Orbit tries the Route again. Each failure there counts as a communication failure. After the fifth, the subtask asks for assistance with `The task workspace Route could not be finished, so no agent started.` and the error. So no agent, reviewer, preview, or pull request ever uses a workspace whose Route failed. Cancelling the group removes the workspace with its pending Route.

`AllocateTaskVmAction` [places](#place-task-vms) the VM on a host. The row gets the name `tvm-<row id>`, the next free WireGuard address in the reserved range, and a random Pi token. It also records `claimed_at`, the moment of the claim.

Cloud-init creates the user `orbit` with passwordless sudo and the Gateway's SSH key, and puts it in the system group `incus-admin`, so a [nested topology](#prepare-the-topology) needs no new login. It does nothing else, because the base image already holds `openssh-server`, Chromium's system libraries, the workspace tools, the software of the `app-dev` role, the pinned Rust toolchain, the Node agent, and Pi. Enrollment trusts the recipe image for machine software. The Project's setup steps install the rest, for example its Playwright browsers.

`EnrollTaskVm` checks the VM every second, for up to 2 minutes in one run, and then again 15 seconds later. Once the Incus agent in the guest runs, one check waits in the guest for up to 30 seconds with `cloud-init status --wait`, so the job hears at once when cloud-init is done.

The job goes on when cloud-init reports `done` with no errors and the host reports the guest's bridge address. It reads the guest values only then, before any code but cloud-init has run in the VM. A guest reboot shows the VM stopped for about a second, so a stopped VM gets a second reading 5 seconds later before enrollment fails.

Provisioning links the new Node to the row when it creates the Node record, before any convergence. So the Node is a task VM Node from the start: the fleet rollout skips it, and it gets no Orbit CLI. It never hosts a [Metrics exporter](/reference/metrics#exporter-selection), because the [hub filter](#limit-fleet-traffic-on-the-hub) keeps the Metrics Node out. So its enrollment skips the fleet Metrics reconcile, which converges exporters on every Node and took about 80 seconds on the live fleet.

Each step of the jobs logs one line `Task VM step done.` in the Gateway log, with the VM name, the step, and its seconds. The steps are `create`, which counts from the claim, `boot`, `node-converge`, `apt-tool-manager`, `roles`, `router-lan-ingress`, `dns-selection`, `activate`, `agent`, `enrolled`, `model-key`, `pi-current` or `pi-upload`, `pi-files`, and `pi-server`. The last line, `claim-to-ready`, has the seconds from the claim to `ready`. The workspace creation then logs `workspace-package-cache`, `workspace-source`, `workspace-prepare` for a routed workspace, and `workspace`, which has the seconds of the whole creation. The baseline check starts after `workspace`. The Route then logs `workspace-route`, with the seconds of its projection, which runs while the check runs.

#### Skip what the image holds

Task VM enrollment trusts the [base image](#build-the-base-image) that the same Gateway code built. It does not run the `app-dev` software programs: `bootstrap`, `caddy-package`, `app-dev-prerequisites`, `vp-manager`, and `composer-manager`. It does not read or compare program fingerprints. Every other Node runs every program at enrollment.

Enrollment still renders and applies everything that is specific to the VM: the host key, WireGuard, the firewall, Caddy, DNS, the agent and its secret, and Pi.

New claims [require the deployed recipe](#claims-require-the-current-recipe). The base includes both selectable PHP versions, now `8.5` and `8.4`, and Rust with Cargo, clippy, and rustfmt. The Instance's [PHP runtime check](/reference/php-runtime#development-runtime) verifies installed packages without repeating an installation.

Enrollment uses the host as [SSH jump host](/reference/node-provisioning#enroll-through-a-jump-host) until WireGuard is up.

Until the row is `ready`, the claim returns the group to `todo` with a reason, and tries again on the next tick:

| Reason | Cause |
| --- | --- |
| `Task VM: task VMs are not enabled on this Gateway.` | `task_vms.enabled` is false and the group has no task VM |
| `Task VM: no capacity.` | Every host is inactive or full. See [Place task VMs](#place-task-vms) |
| `Task VM: another placement is running.` | SQLite writer contention exhausted the placement transaction's retries. The next tick tries placement again |
| `Task VM: provisioning.` | The jobs are still running |
| `Task VM: task_vm.invalid_config: <message>` | A `task_vms` value is invalid |
| `Task VM: destroying.` | Cleanup has started |

These reasons are waits: the group asks for no assistance. A `failed` task VM is no wait. The claim returns the group to `todo` without a wait reason, and the group asks for assistance at once with `Task VM failed: tvm-<id>: <code>: <message> Cancel the group to destroy the VM.` The failure sends one assistance event to Coder when the group starts asking. Later claims skip groups with a failed VM and keep the request. The group gets no other VM: cancel it to destroy the VM.

The Node `tvm-<id>` has user `orbit`, role `app-dev`, the dev Cluster, and the reserved WireGuard address.

### Destroy a task VM

When the group ends, Orbit removes its workspace with the VM. The checkout lives on the VM's disk, so Orbit deletes no checkout in the guest. A cancelled group first pushes its stored approval. Pushes, fetches, checks, and agent turns need the VM `ready`, so they finish before the VM is deleted. Workspace removal on a task VM runs these steps:

1. While the VM is `ready`, it runs the Project's teardown steps in the guest. A removal that an earlier attempt started has run them already.
2. It sets the row to `destroying`, revokes the group's model key, and deletes the VM and its workspace clone.
3. It removes the workspace records offline, as for [a VM that died](#a-vm-that-dies-before-its-group-ends).
4. It queues `DestroyTaskVm`, which removes the Node.

A step that fails keeps the Instance and records the error on the row. The group asks for assistance with the removal reason, and the next removal attempt continues. A task VM workspace never holds a host [discovery topology](/reference/incus-topologies): Orbit refuses to acquire one there, so removal skips the topology release.

Each `tasks:tick` also queues `DestroyTaskVm` for every task VM that is not `destroyed` when its group is `completed` or `cancelled`, no [claim](/reference/tasks#claim-and-provision) of the group is in flight, and no Instance is left on its Node. A claim is over once it starts the group, so a group that ends soon after its claim loses its VM on the next tick. Its unique lock has no expiry, so the tick never queues a second copy while one waits or runs. `DestroyTaskVm` then runs these steps:

1. It sets the row to `destroying` and revokes the group's model key.
2. It deletes the VM. Then it deletes any retained workspace volume, because Incus keeps custom volumes after VM deletion. A VM or a clone that is already gone counts as deleted.
3. It removes the Node offline and with force. This sheds its `app-dev` role and removes its Process rows, WireGuard peer, and private DNS records.
4. It sets the row to `destroyed`.

Orbit deleted the VM itself, so the Node removal does not probe the Node's address first. It also runs no Metrics pass, because a task VM Node never hosts a Metrics exporter.

The removal of other Instances moves a checkout within one filesystem only. [Instance removal](/reference/instance-removal) refuses a checkout on a mounted volume with `cross-device:`.

Deletion clears the row's Pi token after the VM is gone. The Node goes before the row becomes `destroyed`, which also clears the token. A row that is not `destroyed` keeps its Node a task VM Node, so Orbit never sends the Gateway's own Pi token to it. When a step fails, the row stays `destroying` with the error, and the job runs again after 1, 5, and then every 15 minutes. A [parked](#park-and-resume) VM is deleted while it is stopped. Only its workspace removal starts it first.

Keep a host's `settings.task_vms` until all its task VMs are `destroyed`, because destroy needs the settings. Node removal refuses live task VMs and configured hosts. Clear the host setting after draining it. Removing a host also deletes its destroyed VM rows.

#### A VM that dies before its group ends

Workspace removal deletes a dead VM like a live one. Only the Project's teardown steps need SSH to the VM. When they cannot run, removal fails and the group asks for assistance with the reason `Workspace removal failed:` or `Merged pull request cleanup failed:`. When the group is `cancelled`, `completed`, `settling`, or `waiting_for_review`, each tick then also queues `DestroyTaskVm` for that task VM. A group in another state, for example one whose cancel failed while the VM ran, keeps its workspace and its assistance request. Cancel it again: cancel then removes the workspace with the VM and queues `DestroyTaskVm`.

The job reads the VM on its host. While the VM runs, it leaves the workspace to the normal removal. When the VM is absent, or still stopped 5 seconds after a stopped reading, it deletes the VM and then removes the workspace offline:

- It records the normal removal journal, with the source steps marked done, because the checkout is gone with the VM.
- It clears the Route through the normal removal projector. The projector skips the VM's Node for a task VM that is `destroying`. The router withdraws the site.
- It deletes the Instance's Process and Schedule records and the Instance row, and clears the group's removal reason.

Then it removes the Node and marks the row `destroyed`, as above. A removal journal that a normal removal left open is finished the same way. A cancelled group's stored approval is lost with the VM.

### Place task VMs

Placement is serialized by the Gateway's SQLite transaction. The claim checks for an existing VM, counts occupied slots, records a new VM, and queues its creation after commit. `AllocateTaskVmAction` selects the active host with the most free slots. Hosts with equal capacity use stable Node order.

Every VM that is neither `destroyed` nor `failed` takes the slots of its size, including parked VMs. When no Incus host has room, eligible groups can use the [UpCloud overflow](#upcloud-overflow). Otherwise the group waits for capacity. Lowering `max_vms` removes no VM; the host receives no new VM until capacity is available.

#### Size by declared topology

At the claim, Orbit decides the size of the group's VM once. It takes the union of the `topology` fields of the group's subtasks that are not cancelled, and the Project's place in `task_vms.medium_projects`. The row stores the size in `size`, and it never changes while the VM exists.

| Size | When | VM | Slots |
| --- | --- | --- | --- |
| `normal` | No subtask declares a topology | The host's `cpus`, `memory`, and `disk` | 1 |
| `medium` | The topology has one test node, or no subtask declares a topology and the Project is in `task_vms.medium_projects` | 4 vCPU, 8 GiB of memory, and a 40 GiB disk | 2 |
| `large` | The topology has two test nodes | 6 vCPU, 12 GiB of memory, and a 60 GiB disk | 3 |

A test node is `app-dev`, `app-prod`, or `app-prod-2`, whatever roles its entry adds. The [test Gateway and the operator](/reference/incus-topologies#declared-nodes) run in every topology and do not count. Each size defines how many test nodes it holds, one in a medium VM and two in a large VM, and the group gets the smallest size that holds its topology. The largest size sets the most test nodes a group may declare.

The sizes come from the nested topology spike on beast. There, the test Gateway, an `app-dev` node, and the operator used about 2.7 GiB together. A large VM that ran four test machines used all of its 12 GiB on the host under IO.

The sizes are caps, not reservations. Incus does not pin the vCPUs, and the guest takes host memory only when it first uses it. Medium and large VMs have free page reporting on their memory balloon, so the host gets back the memory that the guest frees, but not the guest's page cache. Plan for a VM that runs a topology to use all of its memory on the host.

A subtask whose topology needs a larger VM than its group got waits before it starts, with the reason `Task VM: this subtask's topology needs a larger task VM than the group got, and Orbit does not resize it.` This happens when a subtask is added or changed after the claim. Orbit never runs it without its nodes, and never moves the group to another VM. To go on, cancel that subtask, or cancel the group and create it again with the topology declared. See [Orbit Project](#orbit-project).

### Shared package cache

Each configured task VM host has one Gateway-owned package cache at `/var/lib/orbit/package-cache`. The Gateway reads hosts through `TaskVmSettings`; the cache does not select hosts by role or read the environment host list itself. Task VMs receive an Incus directory disk at `/opt/orbit/package-cache` with `readonly=true`. The restricted Incus project permits only this directory as a host disk source; host preparation and the first cache attach set that narrow exception. This host-enforced mount prevents a root agent in the guest from publishing bytes to another Project.

When the default branch moves in the Gateway Git mirror, Orbit compares the blob IDs of `composer.lock`, `package-lock.json`, `bun.lock`, and `pnpm-lock.yaml`, at the root and below `apps/` and `packages/`. Unchanged blobs cause no fill. Changed or removed blobs replace the Project's desired inventory and queue a fill on each configured host. Pending fills coalesce by host; a change during a fill queues a successor that reads the latest desired inventory. Fills run on the `builds` queue and add missing content, without running Project setup, Composer plugins, or package install scripts. A queue outage leaves source fetching usable; the next mirror fetch retries a pending fill dispatch.

Composer consumes native archive cache files. npm consumes its integrity-addressed content cache; Bun consumes extracted cache directories, including registry archives whose root directory uses the package name, as DefinitelyTyped archives do. The JavaScript adapter reads npm lock versions 2 and 3, Bun text locks, and pnpm package inventories. pnpm locks contribute to the shared JavaScript inventory, but pnpm's own store is not seeded. Bundled, Git, local, and private packages remain normal downloads.

Before workspace setup, Orbit copies the Project's missing shared cache files into the workspace's private manager caches. Task VM setup sets `COMPOSER_CACHE_DIR` to `$HOME/.cache/composer`, the same directory that receives the shared Composer archives, so a login shell uses the seeded cache. Metadata, locks, temporary files, and downloads on a miss stay private. The shared mount is always read-only; private copies use no hard links. A package added only on a task branch, an unsupported archive, or a fill not yet complete falls back to the manager's normal registry download.

Run `php artisan task-vms:package-cache-usage <package> <version>` on the Gateway to list the Projects that use that package and version, with the verified archive hashes and host IDs. Usage covers cacheable public artifacts in current default-branch locks, including development packages. Removing a dependency immediately removes its current Project usage from the query; the next successful fill replaces its host inventory, while its bytes remain cached. Failed fills retain the last successful host inventory.

The command returns one JSON object with `projects` (current Project IDs and names) and `cached` (verified Project, host, manager, and SHA-256 rows). An unknown package or version returns empty lists. Retry a failed fill through the Gateway's normal failed-job retry command; fills are safe to repeat.

Private registries and credentialed URLs are out of scope. Trusted fills accept only public GitHub commit archives for Composer and the public npm registry for JavaScript. Workspaces can use their own private registry credentials with normal downloads. The fill never reads a Project's registry credentials. GitHub cache misses use a fresh GitHub App installation token with only `metadata: read` from a covered Project. The token travels through protected stdin and stays out of cache files, command arguments, and logs. The downloader sends it only to the allowed GitHub API and codeload archive hosts; npm receives no token.

Lockfile checksums are verified when supplied; npm integrity is required. Composer archives without a checksum use HTTPS from the allowed commit archive service and record a SHA-256 digest on first fill. This is a transport trust limit, not a lockfile-supplied content guarantee. Each reuse checks the recorded digest. Suspect bytes move into a quarantine directory and are downloaded again; they are not seeded into new workspaces.

Fills do not evict artifacts, including packages no current Project uses. The cache defaults to a 20 GiB byte budget per host and 512 MiB per archive. The operator can change the cache budget through `package_cache.max_bytes`. A full cache stops publication of new artifacts and fails the fill; workspaces still download misses privately. Quarantine bytes count toward the budget. Retention and cleanup are operator work while fills are stopped; no automatic deletion can disrupt a running workspace. CI cache refs and previous image retention are separate policies.

### Clone and set up every workspace

Every new task workspace starts with an empty checkout on the VM's root disk at `<apps directory>/<project slug>/workspace`. The Gateway sends the selected branches and CI cache ref bound to the tested SHA from its [Git mirror](#git-on-a-task-vm). The workspace checks out its task branch, then the baseline check runs all [Project setup steps](/reference/instance-setup), every time. Each claim runs Project setup.

After source resolution has observed the default-branch lockfiles, the Gateway attaches the [read-only package cache](#shared-package-cache) and seeds private manager caches. The `@orbit/ci-caches` setup step imports the selected [CI cache ref](/reference/instance-setup#import-ci-main-caches). Missing packages download into private caches; a missing or invalid CI ref leaves checks cold. Neither miss queues a workspace image build. The fixed checkout path preserves cache paths across claims without sharing a writable checkout.

A task VM has no default checkout and no dependency trees in its recipe image, so `ORBIT_SEED_PATH` and `ORBIT_SEED_COMMIT` are empty. The Project's `seed-dependencies` step therefore skips its reflink copy by design. Fresh task VMs reuse verified Composer archives to build `vendor/`, including `apps/cli/vendor`, without downloading those packages again.

For remaining GitHub downloads, trusted setup receives a fresh GitHub App installation token with only `metadata: read`. Incoming pull-request baselines receive no setup token. GitHub installation tokens expire after one hour. Orbit carries the token on a pipe into setup, then into Composer’s in-memory authentication store for API and codeload requests. It never exports the token into a process environment. Composer plugins can use it while the install runs; child commands and later checks do not inherit it.

The check stops each setup step's process group when its shell exits, times out, or is cancelled. Setup steps must finish their work before returning. Long-lived services belong in [Processes](/reference/processes-and-schedules), not setup background jobs. See the [baseline check](/reference/tasks#baseline-check) for output cleanup and timeout behavior.

Orbit writes no auth file. Setup output redacts the token before writing the check log. The Project check and deliverable commands receive no setup token.

If the App cannot supply a token, setup continues without one. Shared fills retain existing verified GitHub archives and skip uncached GitHub artifacts without authentication, while other packages still fill. Source Git fetches and pushes still run only in the Gateway mirror.

An interrupted claim resumes only its own checkout after the existing source identity checks.

### Git on a task VM

No GitHub token reaches a task VM. The agent in a task VM runs as `orbit` with passwordless sudo, so it could read a token from any process in the VM. So the Gateway runs every Git command that talks to GitHub itself, in its own mirror of the Project. It moves Git objects to and from the VM over the Node's SSH connection, as a pack on standard input or standard output.

#### The mirror

Each Project whose task workspaces or CI-cache setup use the mirror has one bare repository on the Gateway: `storage/app/task-vm-git/<project id>.git`, in the [shared Gateway storage](/reference/gateway-recovery#release-layout). The Gateway fetches into it from the Project repository URL with the Project's [repository access](/reference/github-app#how-orbit-reads-a-repository). The token is in the environment of one `git` process on the Gateway, as for any other read. The required source fetch asks only for the branches that the operation names, with `--no-tags`, into `refs/heads/<branch>`.

A separate optional fetch refreshes `refs/orbit/ci-cache/*` with its own timeout and prunes only that cache namespace. A cache-fetch failure clears the mirror's old cache refs and leaves the required source fetch intact.

The mirror always uses HTTPS, because the token travels in an HTTPS header. A `git@github.com:` or `ssh://git@github.com/` URL becomes `https://github.com/<owner>/<name>.git`. Any other SSH URL fails with `Task VM Git reaches a repository over HTTPS only`.

A lock lets one operation at a time change the mirror of a Project. The lock covers only work on the Gateway and GitHub: a fetch, the packing of a send, and the move of a verified pack. It never covers a transfer to or from a VM. It lasts 40 minutes, longer than a fetch can run, and another operation waits for it at most 300 seconds. Git in the mirror never collects garbage on its own (`gc.auto=0`). After each publish, the publish job runs `git gc --auto` outside the lock.

#### Send branches to the VM

1. The Gateway fetches the operation's branches into the mirror. Only `task-<id>` may be missing on GitHub.
2. It reads the commit IDs of the refs in the VM's checkout. It keeps those that the mirror holds as commits.
3. It packs the objects of the branches that are not in the history of those commits.
4. It sends the pack on the standard input of one SSH command. The VM indexes the pack and sets `refs/remotes/origin/<branch>` to the mirror's commit. A missing `task-<id>` loses its ref.
5. When the default branch was sent, the VM points `refs/remotes/origin/HEAD` at it, as a clone does. Pest's test selection reads the default branch there.
6. In a separate optional transfer, it sends only the cache ref for the selected code SHA. Failure of this transfer does not undo source delivery.

A main checkout uses its selected main commit. A task branch keeps its recorded main baseline in the worktree-private `refs/worktree/orbit/ci-baseline`. Another branch with no recorded baseline whose `HEAD` is a main commit, such as a fresh agent branch, uses that `HEAD` commit, not a newer main commit. [CI cache import](/reference/instance-setup#import-ci-main-caches) validates the ref and payload before using any cache files.

| Operation | Branches |
| --- | --- |
| Workspace creation | The default branch and `task-<id>` |
| [Fetch before a turn](/reference/tasks#fetch-before-a-turn) | The default branch, `task-<id>`, and a pull request base that is not the default branch |
| Base branch fetch | The pull request base |

A new workspace starts from `git init`, with the Project repository URL as `origin`, and gets its branches this way. The log messages about a fresh clone mean this new repository. The VM's refs only shrink the pack. A VM that names commits it does not have gets a pack it cannot complete, and its own Git operation fails.

#### Publish from the VM

A VM sends its pack at its own pace, so the task tick does not publish. The push of an approved commit records the commit and `task-<id>` on the task VM row as `queued`, and queues the job `PublishTaskVmCommit` on the [Git queue](#jobs). The approval stays in review, without a failure. Each later tick reads the row:

- `queued`: the job waits or runs. The tick changes nothing.
- `pushed`: GitHub has the commit at the branch. The approval continues, and the pull request opens.
- `failed`: the tick reports the job's error as a [failed push](/reference/tasks#pull-request-and-settle-metrics), once. The next push after its backoff queues the job again.

The job records its start on the row. A job that has not finished 40 minutes after its start counts as failed. A push that waits behind other jobs stays pending, for up to 2 hours. A push whose job has not started after 2 hours counts as failed too, so a lost job or a worker that never starts cannot hold the group. The job pushes only while the row still names its commit and branch as `queued`, so a repeated or late job changes nothing. It does this:

1. The Gateway fetches the default branch and `task-<id>` into the mirror.
2. It asks the VM for a pack of the approved commit. The pack leaves out the history of the mirror's commits of those branches.
3. The VM writes the pack to standard output, and the Gateway streams it to one file. A pack over 2 GiB stops the VM's command and fails the push with `The task VM sent a pack over 2 GiB`.
4. The Gateway indexes the pack into a quarantine object directory with `git index-pack --strict`. That command checks every object. The quarantine reads the mirror's objects but changes nothing in the mirror.
5. It checks that the approved commit is a commit there, and that its history is complete.
6. Only then does the verified pack move into the mirror. A pack that fails a check leaves nothing behind.
7. It pushes `<commit>:refs/heads/task-<id>` from the mirror with the write token, and records the branch in the mirror.

The job and each of its Git commands end within 1600 seconds. Only the commit ID that the Gateway approved names what it pushes. The VM's branch names, refs, and configuration play no part.

#### What the Gateway trusts

From the VM, the Gateway takes only commit IDs and pack data. A commit ID must be 40 or 64 lowercase hex characters. Git in the mirror never reads a file from the VM. It runs with these settings: `core.hooksPath=/dev/null`, `core.fsmonitor=false`, an empty `credential.helper`, `http.followRedirects=false`, `protocol.allow=never`, and `protocol.https.allow=always`. A pack from the VM goes through `git index-pack --strict`, which runs the object checks that `transfer.fsckObjects` runs for a fetch. Fetches from GitHub skip them, because an old commit in a real repository can fail them.

The Gateway refuses to send a command that carries a source Git credential to a task VM. The separate Composer setup token described above is a narrow exception for dependency downloads.

A source command fails with `task_vm.git_credential_refused` before SSH starts. An `incus` command on a task VM host refuses one too. So an Instance or Project operation that would fetch on a task VM with a token fails there and passes no token. Examples are `instance:destroy` of a task VM workspace, `instance:clone` from one, and `project:update` with a new repository URL while one exists. A task VM workspace goes with its group; see [Destroy a task VM](#destroy-a-task-vm).

The guard knows a credential by the mark that `GitReadScript` puts on its input. A test fails when other code builds the credential header. It also fails when a new file sends remote commands with GitHub access in reach, so each such path gets a review.

The checkout keeps the Project repository URL as `origin`. After setup ends, the agent can run Git there, but its environment holds no setup credential. So it cannot fetch from or push to a private repository.

### Park and resume

A task VM that has nothing to do stops. Its group keeps the VM, and the next work for the group starts it again. A stopped VM uses no CPU or memory on its host. Its disk stays on the host's ZFS pool, so the workspace, its checkout, and Pi's sessions survive.

Each `tasks:tick` parks the `ready` task VM of a group when all of these are true:

- The group is `waiting_for_review` and has a pull request.
- The group has no `todo`, `reserved`, `running`, or `reviewing` subtask, and it is not `preview`.
- The group's reason does not start with `Workspace removal failed: ` or `Merged pull request cleanup failed: `. Those removals retry on the tick and need the VM.
- The group and all of its subtasks settled more than `task_vms.park_after_minutes` minutes ago. The default is 30 minutes.
- The VM has been `ready` for at least as long.

The last rule keeps a VM that Orbit started again, for example for a removal retry, running that long before it can park again. The row's `ready_at` records when the VM last became `ready`: at its first preparation, and at each resume.

The tick sets the row to `parked` and queues `ParkTaskVm`, which stops the VM with `incus stop`. After three failed attempts, the row keeps its error and stays `parked`, and Orbit logs a warning with the VM and its error. Parking failure does not ask for group assistance or replace an existing request. New subtasks still wake the VM, and a reviewed, green pull request can still merge. A successful resume or park clears the row error. A late failure after the VM wakes changes nothing. A `parked` VM still takes its slot on the host, so a resume never needs a free slot.

Work for the group starts the VM again: a new subtask after a push, a review fix, a reviewer run, a base merge, the workspace removal after a merge, a completion, and a destroy that must first remove the workspace. That work waits while the VM is `parked`. The group shows the reason `Task VM: resuming.`, and Orbit queues `ResumeTaskVm`. The job runs these steps:

1. It starts the VM with `incus start`. A VM that already runs counts as started.
2. It checks once whether the VM answers over WireGuard, `orbit-agent.service` is active, and Pi answers `GET /capabilities` with the group's Pi token.
3. If the VM is not ready, the job calls `release(2)` and returns.
4. Once all checks pass, it sets the row to `ready`.

Horizon retries a released job when it is due. The worker is free for other task VMs between checks. The resume job does not sleep or poll in a long-running job.

The next tick then continues the work, and the reason clears. Orbit never runs a workspace command, an agent turn, a fetch, or a push on a VM that is not `ready`. Those operations fail with `task_vm.parked` while it is `parked`. A merged group's workspace removal and the sweep of ended groups wait without a failure. A manual `tasks:complete` records the reason `Workspace removal failed: ` with that error, and the sweep removes the workspace once the VM is `ready`. Completion removes the workspace under the group's admission lock, so a tick cannot park the VM during that removal.

The queued resume has a fixed 9-minute deadline exposed through Laravel's `retryUntil()`. That deadline stays the same across releases and retries. When Laravel stops retrying at the deadline, `failed()` maps expiry to `task_vm.resume_timeout` and marks the still-parked row `failed`. Readiness releases do not exhaust an attempt count before that deadline. The group asks for assistance with the reason `Task VM failed:`. A `parked` VM that has no workspace left is deleted without a start. Destroy on merge is otherwise unchanged.

### Sweep task VM hosts

Every 10 minutes, while task VMs are enabled or the [UpCloud](#sweep-upcloud) token file is set, the Gateway scheduler queues the job `SweepTaskVmHosts`. While task VMs are enabled, for each configured host that is an active Node, the job checks that the host's Incus project exists with `incus project show`, then lists the names of its instances and compares them with the task VM rows. Incus lists a project that does not exist as empty, so a missing project fails the host, and the job changes nothing there:

- An **orphaned VM** is an instance named `tvm-<number>` in the project that has no task VM row on that host which is not `destroyed`. The job deletes it with `incus delete --force`.
- A **missing VM** is a `ready` or `parked` task VM row on that host whose instance is gone.
- An **orphaned clone** is a storage volume `tvm-<number>-workspace` in the project whose task VM has no row on that host that is not `destroyed`. The job deletes it after the orphaned VMs.

The job fails a missing VM only when two sweeps in a row miss it. The first miss is kept for an hour, and a sweep that finds the VM forgets it. On the second miss, the job sets the row to `failed` with `task_vm.vm_missing`. The group asks for assistance with the reason `Task VM failed:`.

Cancel or complete the group of a missing VM to destroy its Node, as for [a VM that dies before its group ends](#a-vm-that-dies-before-its-group-ends).

The job changes only the host's configured Incus project, such as `orbit-tasks`. It reads the other projects only to [report strays](#report-strays).

It retains a base image builder `tvm-image-<build id>` and its smoke VM while the build row on that host is `running`, whatever its age, or is an unclaimed `failed` build updated within the last 24 hours. Cleanup claims an older failed build before deleting its VMs, so it cannot race a resume. Builders without a row or with a completed or cleanup-claimed row are eligible for deletion. It never changes another project, an instance outside the task VM and base image builder names. Rows in `provisioning`, `failed`, or `destroying` are never missing: their own jobs check the VM.

[Doctor](/cli/doctor) reports the VM findings in the `node` family of the host, without changes. The sweep deletes orphaned claim clones. Doctor's codes are `node.task_vm_orphaned` for an orphaned VM and `node.task_vm_missing` for a missing one. Doctor reports a missing VM on its first miss. A host whose project does not exist or cannot be listed reports `node.task_vm_inspection_failed`. Doctor checks a host only while it has `settings.task_vms` and is reachable over SSH. The fleet rollout ignores these codes.

Doctor does not inspect the Node of a `parked` task VM, because the VM is stopped on purpose. Its `node` family reports one informational `node.task_vm_parked`, and the other families check nothing. So no problem is filed for a parked VM.

### Report strays

The sweep and Doctor also report what Orbit does not own on a task VM host, and the records that should be gone. Doctor never changes any of it. The sweep deletes base image builders and their smoke VMs only when they are eligible for cleanup, as described above; recent unclaimed failed builds are retained for resume. For every other stray, neither tool changes it: the operator decides. The sweep logs one warning per stray on every run, and Doctor reports one finding per stray in the host's `node` family.

A **stray instance** is an Incus instance in any project of the host that is none of these:

- a task VM `tvm-<number>` in the host's project, which the [sweep](#sweep-task-vm-hosts) checks against its row;
- a retained base image builder `tvm-image-<build id>` or smoke VM in the host's project (see [the sweep rules](#sweep-task-vm-hosts));
- an entry of the host's `operator_owned` list.

A **stray volume** is an Incus custom volume in any project and pool of the host that is none of these:

- a legacy claim clone `tvm-<number>-workspace` in the host's project and pool;
- an entry of the host's `operator_owned` list.

The report lists every project with `incus query /1.0/instances?all-projects=true`, and the custom volumes of every storage pool with `incus query /1.0/storage-pools/<pool>/volumes/custom?all-projects=true`. Images, networks, and profiles are not part of it. A host whose list fails reports `node.task_vm_inspection_failed`, and the sweep fails that host.

A **stray record** is a `ready` or `parked` task VM row on the host with no group, or whose group is `completed` or `cancelled`, for more than one hour. The hour counts from the later change of the row and its group. A group that `failed` is still open: its VM stays for the operator. Normally the [destroy of ended VMs](#destroy-a-task-vm) removes such a VM within minutes, so a stray record means that destroy is stuck.

A **stray Node** is a Node named `tvm-<number>` that has no task VM row that is not `destroyed`, 15 minutes after the Node was created. Enrollment creates the Node and links it to its row in the same job, so 15 minutes covers a slow enrollment. Doctor reports it in the Node's own `node` family, also while the Node is unreachable. The sweep logs it while task VMs are enabled.

| Code | Kind | Meaning |
| --- | --- | --- |
| `node.task_vm_stray_instance` | drift | A stray instance, observed as `<project>/<name>` |
| `node.task_vm_stray_volume` | drift | A stray volume, observed as `<project>/<pool>/<name>` |
| `node.task_vm_stray_record` | drift | A stray record, observed as the task VM name and its state |
| `node.task_vm_stray_node` | drift | A stray Node |

To clear a finding, delete the stray after you check it, or add an operator-owned guest to `operator_owned`. For a stray record, cancel or complete its group, or destroy the VM. For a stray Node, remove the Node with `orbit node:remove`.

### States

The `TaskVmState` enum has six cases.

| State | Meaning |
| --- | --- |
| `provisioning` | Orbit is creating, enrolling, or preparing the VM |
| `ready` | Pi runs, and Orbit can create the workspace |
| `parked` | Orbit stopped the VM, or is starting it again. The disk stays. See [Park and resume](#park-and-resume) |
| `failed` | A job failed. The row keeps `error_code` and `error_message`. The group shows the error as its reason and asks for assistance |
| `destroying` | Cleanup has started |
| `destroyed` | The VM, the Node, and the model key are gone. The row stays for audit |

Orbit derives progress inside `provisioning` from facts: the VM exists, the row has a Node, the Node is active, and the Pi Process exists. A group has at most one task VM that is not `destroyed`. `failed` is final for the row. Cancel the group to destroy the VM.

### Jobs

The task VM jobs run on the [Gateway queue](/reference/gateway-queue), which Laravel Horizon works. Each job loads its row again, does nothing when the row has moved on, and is unique for its task VM. Task VMs need the queue: set `ORBIT_GATEWAY_QUEUE_CONNECTION` before you enable them.

[Gateway queue](/reference/gateway-queue#queues) owns the queue assignments, worker counts, timeouts, and redelivery policy. Each image chain orders its steps. A durable row permits one running build per host; builder and smoke VMs do not count against `max_vms`.

| Job | Retries |
| --- | --- |
| `ProvisionTaskVm` | Once after 30 seconds |
| `EnrollTaskVm` | Fails at once on a cloud-init error, an absent or stopped VM, or a boot over 10 minutes. Retries other errors twice. Stops after 60 tries, waits included |
| `PrepareTaskVmRuntime` | Twice, 30 seconds apart |
| `DestroyTaskVm` | Until it succeeds |
| `ParkTaskVm` | Twice, 30 seconds apart. A failure stays on the row as its error, and the row stays `parked` |
| `ResumeTaskVm` | `release(2)` between checks until `retryUntil()` expires after 9 minutes; `failed()` maps expiry to `task_vm.resume_timeout`. Readiness waits do not exhaust retries. Start errors fail after three exceptions |
| `SweepTaskVmHosts` | Never. It runs again 10 minutes later |
| `RefreshTaskVmBaseImages` | Never. The next minute checks again |
| Base image step jobs | Poll until the step's deadline. Failure stops the chain. Failed builders stay available for resume |
| `RefreshUpCloudTaskVmBaseImage` | Never. An attempted identity needs an explicit `task-vms:build-recipe --retry`; a new recipe identity can build automatically |

The jobs log each step with its seconds as `Task VM step done.`. The wait for the worker is a step of its own, apart from the work: `queue` before `create` in `ProvisionTaskVm`, and `enroll-queue` before `boot` in `EnrollTaskVm`. The guest boots during `enroll-queue`. A `boot` after a released try counts from the launch.

When `ProvisionTaskVm`, `EnrollTaskVm`, `PrepareTaskVmRuntime`, or `ResumeTaskVm` has no retries left, or runs past its timeout, the row becomes `failed` with the job's error code, or `task_vm.job_failed` when the error has none. The group asks for assistance, so `tasks:status` lists it. After `ProvisionTaskVm`, `EnrollTaskVm`, or `PrepareTaskVmRuntime` the reason is `Task VM failed: tvm-<id>: <code>: <message> Cancel the group to destroy the VM.`, the same reason that a [claim](#from-claim-to-workspace) sets; after `ResumeTaskVm` it is `Task VM failed: <code>: <message>`. `EnrollTaskVm` never creates the VM again. The jobs run one at a time, so the 10-minute boot limit counts from the moment `ProvisionTaskVm` launched the VM, not from the row.

#### Claims and resumes across a release

The [Gateway release](/reference/gateway-queue#releases) calls `horizon:terminate`, not a systemd restart. Horizon lets an active claim or resume finish on its original release before the new worker starts. A crashed worker's reserved job becomes available again after the queue's `retry_after`; per-VM uniqueness, state checks, and idempotent preparation keep that retry safe.

An old resume payload uses its original enqueue time for a fixed deadline, which survives its subsequent releases. Its old queue envelope can still exhaust its three-attempt limit before that deadline. Both expiry and that old attempt limit follow the same `task_vm.resume_timeout` failure path.

### Incus provider

`IncusTaskVmProvider` runs `sudo -n incus --project <project> …` on the host Node over SSH, as the host's managed user. It launches the image alias `orbit-task-base` as a VM with the row's `name`, the host's `cpus`, `memory`, and `disk`, and `eth0` on the host's `network` with `security.port_isolation=true`. The user-data goes on stdin. The provider never creates a network.

A [medium VM](#size-by-declared-topology) gets `limits.cpu=4`, `limits.memory=8GiB`, and a 40 GiB root disk instead, and a large VM `limits.cpu=6`, `limits.memory=12GiB`, and a 60 GiB root disk. Their config on stdin also sets `raw.qemu.conf` to turn on free page reporting for the balloon device `qemu_balloon`. Incus applies that key only when the VM starts, so the launch sets it.

When the launch fails and the project has no `orbit-task-base` alias, create fails with `task_vm.base_image_missing`. [Build the recipe](#build-the-base-image) on the host. Orbit never falls back to the stock image: a VM from it has no `openssh-server`, so it cannot enroll.

Create, start, stop, and delete are idempotent by name. A running VM counts as created and started, a stopped or absent VM counts as stopped, and an absent VM counts as deleted. Stop waits up to 120 seconds for the guest to shut down, and then stops the VM with force.

Create starts a VM that exists but is stopped, and fails with the `incus start` error when it cannot. A launch that reports an error counts only when the VM runs afterwards. Otherwise create fails with the launch error. A delete that reports an error counts when the VM is gone. The claim checks `task_vms.enabled` before it allocates a VM. The jobs of an existing row use the provider whatever `enabled` says, so Orbit still destroys task VMs after you turn them off.

### Prepare an Incus host

Run `task-vms:prepare-host {node}` once for each host. It sends `resources/task-vms/incus-host.sh` to the host over SSH and runs it with `sudo -n bash -s --`. The script is idempotent and prints `{"ok":true}`.

First it checks the ZFS storage pool that the host's `pool` names. It creates a missing pool from the host's `zfs_dataset`, such as `fast/orbit-tasks`. That dataset must not exist yet, and Incus creates it. The script refuses an existing dataset and calls no Incus command for it, because Incus would unmount that dataset and destroy it with the pool. Without `zfs_dataset`, a missing pool fails. An existing pool must use the `zfs` driver. With `zfs_dataset` set, the pool's source must be that dataset. Then it sets up these parts:

- The Incus project, such as `orbit-tasks`, with `features.networks=false`, and the stock image alias `ubuntu-26.04-vm` from `images:ubuntu/26.04/cloud`. Only the [base image](#build-the-base-image) build launches it.
- The bridge, such as `orbittask0`, with IPv4 NAT and no IPv6.
- The ACL `<bridge>-egress`, which the bridge gets at creation.
- ACL egress drops private, link-local, CGNAT, and multicast ranges, and allows the rest.
- ACL ingress allows TCP 22 from the bridge address and rejects the rest.
- The project's `default` profile: `eth0` on the bridge with `security.port_isolation=true`, and the root disk on the host's pool.
- The project restrictions `restricted=true`, `restricted.networks.access=<bridge>`, and `restricted.virtual-machines.lowlevel=allow`.
- One host rule: `ufw route allow in on orbittask+ comment 'orbit-task-vms'`.

Task VMs need nothing that the restrictions forbid, except low-level config: medium and large VMs set `raw.qemu.conf` for free page reporting. Only the Gateway sets instance config in the project.

The command takes these values from the host Node's typed `settings.task_vms`. See [Configure task VMs](#configure-task-vms). The script checks the form of each argument again before it passes it to Incus as root, and changes nothing when one is invalid. The bridge and the ACL live in the Incus project `default`, and the script names that project in every network and ACL command.

Orbit reserves bridge names that start with `orbittask`. Every such bridge must carry its egress ACL, because the ufw rule accepts forwarded traffic from all of them. Only `incus-host.sh` creates these bridges, and it attaches the ACL at creation. Never create an `orbittask` bridge in another way.

The restrictions forbid privileged and nested containers, host devices, backups, snapshots, and every network except the task bridge. Host disks are limited to the [read-only package-cache directory](#shared-package-cache). Low-level VM config is allowed for memory reporting.

The dropped egress ranges are `10.0.0.0/8`, `172.16.0.0/12`, `192.168.0.0/16`, `169.254.0.0/16`, `100.64.0.0/10`, `224.0.0.0/4`, and `240.0.0.0/4`. Port isolation blocks traffic between VMs on the same bridge. Both are needed. The ufw rule lets bridge traffic pass the host's forward policy, and replies use the existing rule for established connections.

### Build the base image

Run `php artisan task-vms:build-recipe` on the Gateway to queue the current recipe on every active host in `settings.task_vms`. It returns build IDs without waiting for remote work. Repeating it for an unchanged recipe reuses the same rows, including failed rows; resume a failure with `task-vms:build-image NODE --resume=ID`, or restart failed builds with `task-vms:build-recipe --retry` after cleanup. `--retry` applies to both Incus builds and UpCloud template attempts.

A recipe revert reuses a retained verified base. If pruning removed it, the chain rebuilds that identity from stock. `task-vms:image-builds` (also `task-vms:list-image-builds`) shows Incus recipe identities, hosts, steps, fingerprints, and immutable aliases, plus recent UpCloud attempt IDs, identities, states, times, and template UUIDs. `task-vms:build-recipe` prints the current UpCloud attempt and whether a refresh was requested or a verified template already exists. `task-vms:build-image NODE` builds the current recipe on that host; every recipe starts from stock.

Release handoff queues builds immediately when the deployed recipe identity changes. The scheduler checks each minute to recover missed submissions and pick up new hosts. There is no fixed nightly Incus rebuild. The [nightly snapshot-date PR](#nightly-recipe-maintenance) supplies the daily recipe change. Unchanged releases do not build images. A host added later builds the current recipe on its next check. The same reconcile path refreshes configured UpCloud templates when their recipe identity differs.

Each host uses [ADR 0206's chain model](/decisions/0206-build-task-vm-images-as-chains-of-small-jobs) with `Bus::chain` on `builds` and one `task_vm_image_builds` row for the base image. The row stores the full recipe document, identity, desired generation, and the base image location. Steps launch and boot a stock base builder, install snapshot packages and pinned artifacts, prepare boot, clean, publish, and smoke it. The shared base preserves the source image’s DNS configuration, including link DNS for internal names. Topology Nodes use that same base fingerprint for every role.

Each phase is a separate queued job. No workspace or Project setup runs and neither package caches nor CI cache refs enter an image.

Images live in each host's configured Incus project. The base is a VM image. Its immutable alias uses `<image_alias>-recipe-<first 12 hex characters of the recipe SHA-256>-<profile>`, where the built profile is `base`. The shared alias helper shortens the configured prefix when needed to keep every alias within Incus’s 64-character limit. The database and image properties keep the full recipe identity. Every image records `orbit.recipe` and `orbit.profile` properties and `/etc/orbit-machine-recipe.json`. The build row's `images` field exposes project, alias, fingerprint, and instance type for the [topology importer](/reference/incus-topologies#one-recipe-base-image).

Only a built and smoke-tested base can promote the host's base alias. Consumers select the immutable base through the succeeded build row. A database write fence serializes promotion with desired-recipe changes, so an older build cannot move the base alias after a newer recipe is desired. A failed build leaves the previous images in place. The previous recipe does not satisfy the current-recipe claim gate.

Failed recipe phases record the last 40 lines of command output in the build step error, with secrets redacted and a size limit. The error names the phase log under `/var/log/orbit/recipe-images/<profile>-<phase>.log` in the retained builder. Logs are root-only. Installer logs include subprocess stdout, stderr, exit status, and Python exceptions. If the builder cannot be reached, the error reports that the log is unavailable. Publishing runs on the Incus host, so a publish failure reports the host command error and host name.

A running older build stops at its next step before that host admits the newest desired recipe. Failed resources remain available for resume for 24 hours; cleanup and resume share a database fence so only one can claim them. A restart gives the builder a name for that attempt, so delayed cleanup of an older attempt cannot delete the new builder or smoke VM.

After promotion, the final chain step prunes old recipe images on that host. It keeps the current and previous promoted base images, images used by existing VMs, and images of other running builds. It also removes the unused gateway, app-dev, app-prod, and operator aliases for host-local build records, including current and previous recipes. Images and aliases used by existing VMs stay until unused. Otherwise aliased or retained base images stay; only their unused role aliases are removed. Pruning failures resume without rebuilding. The base-only chain has 14 jobs, including promotion and pruning.

### Nightly recipe maintenance

At 03:00 Europe/Amsterdam (01:00 UTC in summer, 02:00 UTC in winter), the Gateway opens a PR against main through its existing GitHub App. It reads main and advances only `ubuntu.snapshot` to the current date at UTC midnight. Exact package versions and Sury artifact URLs and hashes stay pinned; there is no separate Sury snapshot field. An unchanged or newer date creates no PR. A date branch makes repeated submissions reuse the same PR. The same job also opens the [PHP build](#bump-the-php-build) pull request when Sury publishes a newer PHP build.

Before opening tonight's PR, the nightly job checks every open `nightly/recipe-*` PR. If Required checks failed and main advanced beyond the commit's parent, it closes an untouched older-date App PR so tonight's PR replaces it. For today's date it rebuilds the PR as one fresh Gateway App commit on current main, using an expected-head force update so a concurrent push cannot be overwritten.

Recovery is one-shot per PR: any previous force push or close/reopen blocks it. Before writing, it verifies App authorship, the single date-only commit, the PR timeline, and body edit history, then rechecks the PR head. It leaves green, pending, or closed PRs unchanged, as well as PRs modified by a person. Other bots' reviews and edits also block recovery; only cross-references are ignored.

A short recovery comment is best-effort. GitHub recovery errors raise an alert without stopping tonight's snapshot or PHP build PR.

A cross-reference from another issue or PR is not a modification and does not block refresh; unknown or truncated history still blocks it.

Keeping a same-date PR preserves its URL and CI history; rerunning CI alone would reuse the stale merge commit. Closing an older failed PR instead avoids keeping both that PR and tonight's replacement open.

Each minute the Gateway merges its date PRs after Required checks pass on the exact head. It re-reads the PR files and patch at merge time and requires one App-authored commit, its App as PR author, and only the single `ubuntu.snapshot` line change. Any extra change, commit, or different author blocks the merge and raises the existing alert; the merge uses the expected head SHA. This reuses the existing App and PR API without Actions secrets or a second publisher.

The jobs run only from the serving release while task VMs are enabled. Merge and deployment have no fixed time. The existing release handoff and per-minute image reconcile build, smoke-test, and promote the base on each configured host. Maintenance adds no build trigger or composed-topology smoke run. Every host must promote the deployed identity and generation before fleet convergence starts. A failed build halts and alerts the operator.

On the next available minute, the maintenance job visits one Node. Later ticks visit the next Node only after its predecessor passes. Order is app-dev, services, app-prod, vpn, Gateway last, with Node ID inside a tier and the latest tier for a multi-role Node.

Only Nodes whose roles are all app-dev start as soon as every configured host promotes, at any hour. Shared services, app-prod, vpn, and Gateway start only from 03:00 inclusive to 06:00 exclusive Europe/Amsterdam, in strict tier order after every app-dev-only Node is done. Outside the window, the sequence stays ready until the next night. A running visit may finish past 06:00. The serving Gateway also goes last.

Only active managed Linux Nodes with roles participate, and a roleless Node that recorded the `ci-runner` profile, such as Sabre, which joins the service tier. Task VMs, macOS, and other roleless Nodes are excluded. Each visit uses [Node recipe convergence](/reference/node-provisioning#converge-a-node-to-a-recipe), including inventory, health, and Doctor checks. Nodes already verified on that identity need no repeat installation.

The first build row for the current recipe carries `fleet_convergence`, shown by `task-vms:image-builds`: generation, progress, stopping point, per-Node changes, and the alert receipt. This adds no ledger table.

A Node failure or an interrupted visit halts the sequence. Release alerts record Activity, file an assistance problem, and send the configured signed webhook. Nodes after the failure stay untouched; earlier changes remain. There is no automatic retry or rollback. Investigate with Doctor and the report, then converge manually or merge a recipe correction. Pinning the previous snapshot through a PR is manual recovery; retained images can be reused. A new desired generation starts a new sequence. Each visit checks the deployed identity so an older release cannot advance it.

### Claims require the current recipe

A new Incus claim requires a succeeded, promoted base image for the deployed recipe on its selected host. Fresh claims use that image's fingerprint. The VM records `recipe_identity` and `image_fingerprint` at allocation; launch uses the fingerprint, so a later alias move cannot change the selected image. Enrollment checks the observed fingerprint and trusts the image's software, as ADR 0206 requires. There is no full-enrollment fallback for stale images.

A host still building, missing, or failing the current recipe cannot serve a new claim. The group's `capacity_wait_reason` says `Task VM: waiting for recipe <12-character digest> images on <host>.` Another host with a ready current base can serve it. Image unavailability never triggers UpCloud overflow. An existing group keeps its VM and recorded recipe across recipe changes, failures, parking, and resume. UpCloud claims also require a verified template for the current recipe.

### Add a task VM host

A second host needs Incus, ZFS, and ufw, and its managed user needs passwordless `sudo`. Then:

1. Set the host Node's [`settings.task_vms`](/reference/node-settings#configure-a-task-vm-host) with `node:settings` or the Node settings API. Use a unique `cidr` and a new `zfs_dataset`, such as `data/orbit-tasks`.
2. Run `task-vms:prepare-host {node}`. It creates the pool from the new dataset and sets up the host.
3. Run `task-vms:build-image {node}` to build the current recipe locally.

To remove a host, lower its capacity to stop new placements, wait until all its task VMs are destroyed, then clear `settings.task_vms`. Its Incus project, images, bridge, ACL, pool, and any unused legacy workspace volumes remain for manual removal.

### Limit fleet traffic on the hub

This filter relies on the [Caddy guard](#refuse-task-vms-in-caddy). Reverb shares TCP 443 on the `websocket` Node with every other private Caddy site on that Node, such as `executor.orbit`, so an L4 rule cannot tell the sites apart. Run `task-vms:prepare-hub` only after you configure task VMs and rebuild Caddy on every Node that serves sites.

Run `task-vms:prepare-hub` once, and again when one of the values below changes. It runs `resources/task-vms/hub.sh` on the `vpn` Node. The script installs the nft table `inet orbit_task_vms` and the oneshot unit `orbit-task-vms-hub.service`. The unit loads the table after `nftables.service` and before `wg-quick@orbit` starts. The tunnel pulls the unit in but does not depend on it, so a failed load never stops the fleet VPN. The script checks that the table is loaded, is idempotent, and prints `{"ok":true}`.

The command takes every value from Gateway records and settings:

- the reserved range from `task_vms.wireguard_range`;
- the VPN DNS address: the `vpn.dns_server` setting, or else the WireGuard address of the `vpn` Node;
- the Node with the `gateway` role, and the Pi port `orbit.pi.port` (`ORBIT_PI_PORT`, default `3774`);
- Reverb at `reverb.orbit`, which is TCP 443 on the Node with the `websocket` role;
- the host and port of `task_vms.model_proxy_origin`;
- the router of the dev Cluster.

The table filters only the reserved range. Its first rule passes every packet that has no address in the range, so traffic between other Nodes does not change. The range must be inside the WireGuard subnet, because every Node routes only the subnet through the tunnel. `TaskVmSettings` checks that. The command refuses a range that holds the address of a Node not named `tvm-<id>`. It also refuses a missing dev Cluster or origin, and an origin whose host is not an IPv4 address outside the range.

| Direction | Allowed |
| --- | --- |
| From task VMs | The Gateway API on TCP 443, CLIProxyAPI on TCP 8317, Reverb on TCP 443, and DNS on port 53 of the VPN DNS address |
| To task VMs | The Gateway on TCP 22 and the Pi port, and the dev Cluster router on TCP 80, 443, and 5173 |

The table accepts established traffic and drops all other traffic to or from the range. The WireGuard address allocator skips the range for other Nodes. Only `AllocateTaskVmAction` assigns addresses in it. A task VM Node has no access grants to other Nodes.

### Refuse task VMs in Caddy

TCP 443 on the Gateway Node and on the `websocket` Node is one Caddy listener for every private site on that Node, so the hub table cannot tell `reverb.orbit` from `executor.orbit` or `analytics.orbit`. Caddy closes that gap. On every Node, every site except `gateway.orbit` and `reverb.orbit` aborts a client in `task_vms.wireguard_range`. [Caddy configuration](/reference/caddy-configuration#listener-addresses) shows the guard.

Caddy renders the guard only after you configure task VMs: you enable them, or you set `dev_cluster_id`, `model_proxy_origin`, or a host. Until then, every Caddyfile stays as it was. The guard reads only that condition and `task_vms.wireguard_range`. Another invalid task VM value never changes a Caddyfile or blocks a build.

The next Gateway release rebuilds only the Gateway Node's Caddyfile. After you set these values, run `php artisan orbit:caddy-build NODE` for every other Node that serves sites, before the first task VM enrolls. Until then, Doctor reports `role.caddy_build_drift` on each of those Nodes. Unset these values only after every task VM is destroyed, because the next build of each Node then drops the guard.

While task VMs are configured and the range is invalid, every Node Caddy build refuses at stage `render` and names the problem, so live Caddyfiles do not change. This includes the Gateway release handoff, which fails with `gateway.release_caddy_failed`. So after [`gateway:release:configure`](/reference/gateway-recovery#apply-an-env-change), run `php artisan orbit:caddy-build NODE --dry-run` for the Gateway Node. It prints `Build refused:` and the problem when the range is invalid.

### Placement invariant

A task VM workspace is a normal Instance on a normal Node, so generic Instance operations need no sandbox guard. One rule protects the VM: an Instance on a task VM Node must be its group's `task-<group id>` workspace in the group's Project. The rule runs whenever an Instance is saved, so it covers create, clone, transfer, and register. Any other Instance fails with HTTP 409 `task_vm.foreign_instance`. Orbit also refuses an access grant from a task VM Node with HTTP 409 `task_vm.access_refused`. The fleet rollout skips task VM Nodes with the reason `sandbox`. Shared groups never get a workspace on one.

### Configure task VMs

Global keys live in the Gateway's `config/task_vms.php`. Set them in the Gateway's environment. Per-host configuration lives in the operator-owned [`settings.task_vms`](/reference/node-settings#configure-a-task-vm-host) on each host Node, not in the environment and not in its roles.

| Key | Variable | Meaning |
| --- | --- | --- |
| `task_vms.enabled` | `ORBIT_TASK_VMS_ENABLED` | Allows new task VMs. Default `false` |
| `task_vms.dev_cluster_id` | `ORBIT_TASK_VMS_DEV_CLUSTER_ID` | The ID of the Cluster that task VM Nodes join |
| `task_vms.wireguard_range` | `ORBIT_TASK_VMS_WIREGUARD_RANGE` | The reserved WireGuard range. Default `10.44.0.128/25`, the upper half of the default VPN subnet `10.44.0.0/24` |
| `task_vms.model_proxy_origin` | `ORBIT_TASK_VMS_MODEL_PROXY_ORIGIN` | The CLIProxyAPI origin that Pi on the VM uses, such as `http://10.44.0.3:8317`. An `http` or `https` origin with no path, query, or credentials. It must be the CLIProxyAPI URL of the [proxycli extension](/reference/proxycli) |
| `task_vms.pi.artifact_path` | `ORBIT_TASK_VMS_PI_ARTIFACT_PATH` | The absolute path of the pinned Pi executable on the Gateway |
| `task_vms.pi.artifact_sha256` | `ORBIT_TASK_VMS_PI_ARTIFACT_SHA256` | Its lowercase SHA-256 digest. Set both Pi artifact values or neither |
| `task_vms.pi.models` | `ORBIT_TASK_VMS_PI_MODELS` | A JSON list of the models Pi offers. Default `[]` |
| `task_vms.medium_projects` | `ORBIT_TASK_VMS_MEDIUM_PROJECTS` | A JSON list of Project IDs whose groups get a [medium VM](#size-by-declared-topology) when no subtask declares a topology. Default `[]` |
| `task_vms.topology_paths` | `ORBIT_TASK_VMS_TOPOLOGY_PATHS` | A JSON object from Project ID to a list of path patterns that need a [declared topology](#prove-work-on-a-topology). Default `{}` |
| `task_vms.park_after_minutes` | `ORBIT_TASK_VMS_PARK_AFTER_MINUTES` | How many minutes a group waits for review before its VM [parks](#park-and-resume), from 1 to 10080. Unset or empty means `30`. `0` and other values are an error |

Each task VM stores `model_proxy_origin` with its model key, and revokes the key at that origin. So changing the origin never strands a key. A task VM that holds a key at another origin cannot prepare Pi again.

`TaskVmRuntime` reaches CLIProxyAPI only through the core interface `App\Domain\TaskVms\ModelKeyIssuer`: `assertReady()`, `origin()`, `issue(origin, key)`, and `revoke(origin, key)`. The [proxycli extension](/reference/proxycli) binds it and keeps the management key and the anchor key, so task VM code never sees them. `TaskVmRuntime` creates the group key and stores it with its origin on the task VM row before it calls `issue()`, so a retry registers the same key.

Every key change holds the lock `ModelKeyIssuer::Lock` (`orbit:compute:model-keys`). Under that lock, preparation calls `assertReady()` before it stores a key, and refuses with `task_vm.model_key_failed` (409) while the router cannot revoke keys. The `tasks` extension depends on `proxycli`, and enabling `tasks` calls `assertReady()`, which refuses until proxycli holds a management key. `proxycli:teardown` holds the same lock and refuses while a task VM holds a key. See [Teardown](/reference/proxycli#teardown).

The [Node settings field table](/reference/node-settings#task-vm-fields) owns the host configuration. The containing Node supplies host identity. `operator_owned` keeps listed resources out of the [stray report](#report-strays).

Placement selects active Nodes with this setting and capacity reads their slot budget. Host preparation uses its pool, network, and image alias. Package-cache targeting follows the same setting, without making `app-dev` a host marker.

Deployment [migrates the existing environment list](/reference/node-settings#migrate-the-environment-host-list) into Node 9 (`beast`) and Node 13 (`shark`), preserving live values and defaults rather than changing host resources. It preserves other Node settings and does not create host intent on `services` or any other Node merely because it is `app-dev`. After that copy, runtime consumers stop reading `ORBIT_TASK_VMS_INCUS_HOSTS`; there is no fallback. Verify the stored settings, then remove the variable from `gateway.env` with [`gateway:release:configure`](/reference/gateway-recovery#apply-an-env-change).

`TaskVmSettings` validates the configuration once, when it is first used. It checks every value that is set, also while task VMs are off, so you can prepare hosts and the hub before you enable task VMs. An unset or empty value stays absent, and malformed JSON is an error. Enabling task VMs also requires `dev_cluster_id`, `model_proxy_origin`, and at least one host.

The reserved range must always be a private network. As soon as task VMs are enabled, or the Cluster, the origin, or a host is set, the range must also be a smaller network inside the Gateway's VPN subnet, and no host's `cidr` may overlap that subnet. While none of them is set, Orbit does not compare the range with the subnet, so a fleet on another subnet keeps working. Invalid configuration fails with `task_vm.invalid_config` (HTTP 500).

### Errors

`IncusTaskVmProvider` reads the host's output: the instance state, its one IPv4 address inside the bridge range, and the host key fingerprint. `TaskVmGuest` reads the guest's output the same way for every provider: the cloud-init status. Invalid output fails with `task_vm.invalid_host_output`, and a failed guest command with `task_vm.host_command_failed`, also on UpCloud. Every task VM error code starts with `task_vm.`. The setup commands print the code and message, and for `task_vm.setup_failed` the last lines of the script's error output. After that check, Orbit trusts its own records.

| Code | HTTP | Cause |
| --- | --- | --- |
| `task_vm.invalid_config` | 500 | A `task_vms` value is invalid, or the proxycli CLIProxyAPI URL is not `model_proxy_origin`. See [Configure task VMs](#configure-task-vms) |
| `task_vm.unknown_host` | 409 | The Node is not active or has no valid `settings.task_vms` |
| `task_vm.invalid_gateway_key` | 500 | The Gateway's SSH public key is not one OpenSSH public key line |
| `task_vm.workspace_mismatch` | 409 | A group's workspace is not on its ready task VM |
| `task_vm.foreign_instance` | 409 | An Instance on a task VM Node is not its group's workspace |
| `task_vm.access_refused` | 409 | An access grant names a task VM Node as its consumer |
| `task_vm.range_in_use` | 409 | A Node not named `tvm-<id>` holds an address in the reserved range |
| `task_vm.fleet_unavailable` | 409 | The fleet has not exactly one active `vpn`, `gateway`, or `websocket` Node, the dev Cluster has no active router, or one of these Nodes has no WireGuard address |
| `task_vm.setup_unavailable` | 409 | The setup script is missing, or the target Node has no WireGuard address |
| `task_vm.setup_failed` | 409 | The setup script failed or did not print `{"ok":true}` |
| `task_vm.invalid_host_output` | 502 | Incus or a task VM guest returned output that fails these checks |
| `task_vm.host_command_failed` | 502 | An `incus` command on the host, or a guest command in a task VM, failed |
| `task_vm.bootstrap_failed` | 502 | Cloud-init in the VM reports `error` |
| `task_vm.not_enrolled` | 409 | The task VM has no Node yet, so Pi cannot be prepared |
| `task_vm.model_key_failed` | 409 or 502 | CLIProxyAPI did not confirm the group's model key, the proxycli extension holds no management key, or another key operation runs |
| `task_vm.runtime_failed` | 502 | A Pi step failed on the VM: the executable, its digest, `models.json`, the token, or the `pi-server` Process |
| `task_vm.vm_not_running` | 502 | During enrollment, the VM is absent, or it stayed stopped |
| `task_vm.bootstrap_timeout` | 504 | Cloud-init was not `done` 10 minutes after the row was created |
| `task_vm.job_failed` | 500 | A task VM job failed with an error that has no code of its own |
| `task_vm.base_image_missing` | 409 | The host's project has no `orbit-task-base` image. Run `task-vms:build-image` |
| `task_vm.image_build_failed` | 502 | A base image build failed. The build row records the step and error |
| `task_vm.image_build_running` | 409 | A recipe image build on the host is already running |
| `task_vm.parked` | 409 | The group's task VM is `parked`. Orbit starts it, and the work runs once it is `ready` |
| `task_vm.resume_timeout` | 504 | A `parked` VM did not become ready before its fixed 9-minute resume deadline |
| `task_vm.vm_missing` | 502 | The sweep found a `ready` or `parked` task VM gone from its host |
| `task_vm.git_credential_refused` | 409 | A command that carries a GitHub credential was about to reach a task VM or an `incus` command. See [Git on a task VM](#git-on-a-task-vm) |

### Limits

Incus accepts DNS before the ACL, so a task VM can query port 53 on any host address and read instance names from other bridges. This risk is accepted. Transfers over WireGuard may pass through the VPN hub.

Parking keeps the VM's slot and disk, but its private Route does not answer and Doctor skips its Node. Set [preview](#request-a-preview) to keep a VM running during review.

The [Git mirror](#the-mirror) needs repository-sized Gateway storage. A fresh workspace receives the full history of its selected branches, without tags. Fetch, pack, and push operations serialize per Project, with a 300-second lock wait. Operations that would send a credential to a VM require its group to end first.

The hub filter fails open to keep the fleet VPN up. If a later `nftables.service` restart removes the table, run `task-vms:prepare-hub` again; its unit can still show active while the table is absent.

### Why task VMs work this way

[ADR 0200](/decisions/0200-run-each-task-group-in-its-own-sandbox-vm) records why the VM edge is the boundary, why egress is open, why task VMs use a prepared base, and the alternatives it rejected. Host output is validated once, and Gateway records are trusted after that, so six states and no marker timestamps describe the whole lifecycle. `claimed_at` times the claim. Parking reads the settle times the group already records.

The agent in a task VM has root through sudo, so a token anywhere in the VM, even in another user's process, is the agent's token. The Gateway already holds the tokens and already reaches every task VM over SSH. So Git that talks to GitHub runs on the Gateway, and only objects cross the VM edge.

Packs on the standard input and output of the existing SSH commands need no new transport, keep the check that a command reaches only its group's workspace. The Gateway checks what comes back the way a fetch checks a remote: only the approved commit ID names what it pushes, and `index-pack --strict` checks every object.

Most groups wait for review far longer than they work, so parking frees the host's CPU and memory for other groups. A stop keeps the disk, so a resume continues the same workspace in seconds instead of a new claim. Placement by free slots spreads VMs across hosts and keeps every host inside its budget. The sweep catches what a crash between two steps leaves, and it acts only on names and the project that Orbit owns.

These alternatives were also rejected:

| Alternative | Why not |
| --- | --- |
| A fallback to the stock image | A VM from it cannot enroll, so the failure would come later and less clearly |
| A snapshot before each park | ZFS keeps the stopped disk, and a resume continues it. A snapshot adds a copy that nothing restores |
| A parked VM frees its slot | A resume could then find its host full, and the group's work would wait behind new groups |
| Placement in host order only | The first host fills before the second takes any VM |
| A token in the VM for each fetch and push | The agent can read any process in the VM. A read token exposes every private branch, and the write token lets the agent push any commit |

## UpCloud overflow

When beast and shark have no free slot, a group of an opted-in Project may get its [task VM](#task-vms) at UpCloud. The overflow is off by default. It needs both the Gateway-wide switch `ORBIT_TASK_VMS_UPCLOUD_ENABLED=true` and the Project's ID in `ORBIT_TASK_VMS_UPCLOUD_PROJECTS`. Without both, the group waits with `Task VM: no capacity.` as before.

An UpCloud task VM follows the same path as an Incus one. It is a `task_vms` row with the provider `upcloud` and no host Node. The same jobs create, enroll, park, resume, and destroy it.

It gets the same task VM user-data, enrolls as the `app-dev` Node `tvm-<id>` of the dev Cluster with an address from the reserved WireGuard range, and starts from a verified [UpCloud template](#build-the-upcloud-template). UpCloud uses the same `recipe.json` base-profile installer as Incus.

Its template's `orbit.recipe` storage label records the full recipe identity, and each new claim records the verified template UUID for that identity. Template lookup runs before the admission transaction; admission rechecks the deployed identity and capacity. Provisioning clones the recorded UUID, including after a recipe change. So the hub filter, the Caddy guard, the rollout exclusion, and the placement invariant apply unchanged. Each claim [clones and sets up its workspace](#clone-and-set-up-every-workspace), as on Incus, without a shared host package cache on UpCloud.

### Place a VM at UpCloud

Placement creates a new VM on the Incus host with the most free slots, as in [Place task VMs](#place-task-vms). Only when no host has a free slot does a new VM go to UpCloud, and only while the switch is on, the group's Project is opted in, and fewer than `max_vms` UpCloud VMs are not `destroyed`. Parked and failed UpCloud VMs count. A VM never moves to another provider.

### Create and reach an UpCloud server

The server is named `tvm-<id>` in its title and hostname, and carries the label `orbit-task-vm=<id>`. Orbit records its UUID. A create whose answer was lost is found again by its label, so Orbit never sends a second create. Before each create, Orbit counts the account's servers with the `orbit-task-vm` label and refuses at `max_vms` with `task_vm.upcloud_budget_full`. A server that lost its row counts too.

The server clones the newest verified template for the claim's recorded recipe onto a disk with the storage tier of its plan, such as `standard` for a Starter plan. The default plan includes 30 GB of that storage, so the default disk costs nothing extra. It has one public IPv4 interface, and no utility or private network, so it reaches no private address of the account. Fleet traffic goes only through WireGuard and the hub filter. Console access is off. The UpCloud firewall stays off: it is stateless, so reply traffic needs source-port rules that open other ports too.

The server boots with ufw on, from the template. ufw denies all incoming traffic except public SSH on port 22, so Caddy and the other services in the image are not public before enrollment. The template holds the exact rule `orbit:public-ssh-recovery` that enrollment opens on a new Node. Enrollment finds it in place and changes nothing, then the `app-dev` role adds WireGuard trust and closes public SSH, as on any public `app-dev` Node.

UpCloud has no trusted host that can read the guest's SSH host key, as Incus does. So the Gateway generates an ed25519 host key for each server, adds it to the user-data, and pins its fingerprint. No connection trusts a key on first use. Root login stays off. The Gateway reads cloud-init over SSH as `orbit` at the public address, and enrollment needs no jump Node.

A park stops the server cleanly, and a hard stop follows after 150 seconds. UpCloud bills a stopped server for its storage only. A destroy stops the server, deletes it with its disk, and waits until it is gone.

### Build the UpCloud template

`php artisan task-vms:build-upcloud-image` builds the template on the Gateway. It creates billed servers. The build prints each stage with its time:

| Stage | What it does |
| --- | --- |
| `sweep` | Deletes the build servers and unverified templates that an interrupted build left |
| `launch` | Boots a builder server from the stock Ubuntu 26.04 template with the base image builder user-data |
| `cloud-init`, `orbit-user` | Waits for cloud-init over SSH, and checks the pinned `orbit` uid and gid |
| `packages`, `javascript`, `rust`, `agent`, `pi` | Installs the base profile through the shared recipe installer with exact versions and integrity pins |
| `recipe-marker`, `verify`, `masks` | Writes the recipe identity and profile marker, verifies the recipe software, and masks the first-boot and apt timers |
| `firewall` | Turns ufw on: incoming traffic denied, except the public SSH rule `orbit:public-ssh-recovery` on port 22 |
| `clean` | Runs `base-image-clean.sh` |
| `templatize`, `delete-builder` | Stops the builder, turns its disk into the template `orbit-task-base-<time>-unverified`, and deletes the builder |
| `smoke` | Boots a server from the template with the task VM user-data, and checks cloud-init, ufw, and the key, uid, and gid of `orbit` |
| `promote`, `prune` | Records the `orbit.recipe` storage label, renames the template to `orbit-task-base-<time>`, and deletes older verified templates except those pinned by live VMs awaiting server creation |

A failed build deletes its servers and its unverified template, and keeps the previous template. Without a verified template for the claim's recipe, a create fails with `task_vm.base_image_missing`.

One build runs at a time. A build marks its time stamp as running for two hours, and no sweep deletes the servers or the template of a marked build.

### Refresh the UpCloud template on recipe changes

The per-minute recipe reconcile and release handoff queue `RefreshUpCloudTaskVmBaseImage` on the `builds` queue when UpCloud is configured and no verified template matches the deployed recipe. There is no separate nightly schedule. The job checks the identity again before building. A database ledger records each attempt before remote work, including failed and interrupted builds. Reconcile does not dispatch that identity again unless `task-vms:build-recipe --retry` explicitly requests it. A new identity can build automatically. A queued build that finds an earlier attempt refuses with `task_vm.image_build_superseded`; use the explicit retry command instead of redispatching it. The manual `task-vms:build-upcloud-image` command also starts an explicit attempt.

Reconcile raises a recipe-maintenance alert when the current identity has a failed attempt, or a running attempt at least two hours old, and no verified template. The alert names the identity and attempt and includes `php artisan task-vms:build-recipe --retry`. Repeated alerts for an attempt are limited to one per hour. The build command also reports this fenced state. Reconcile reports only the latest attempt and stops alerting once a verified template exists. `--retry` also retries failed Incus builds.

The build keeps the two newest verified templates and any template pinned by a live VM awaiting server creation. Pruning ignores the source template reference once the VM has a server UUID. It also runs with overflow off without enabling claims. A failed build keeps the previous template, but a stale template cannot serve a new claim. Reconcile reports UpCloud token, API, or refresh-dispatch errors and continues Incus submission recovery and image scheduling.

The build takes about 20 minutes and occupies one `builds` worker. When it runs past its 1700 seconds, the worker stops it, and the sweep deletes its servers two hours after its start.

### Sweep UpCloud

While the token file is set, also with the switch or task VMs off, the [host sweep](#sweep-task-vm-hosts) lists the servers with the `orbit-task-vm` label. So no server that Orbit created keeps costing money. The sweep deletes each server named `tvm-<label value>` without a row that is not `destroyed`. A `ready` or `parked` UpCloud row whose server is gone on two sweeps in a row becomes `failed`, and its group asks for assistance. It also deletes build servers and unverified templates older than two hours, which a killed build leaves, except those of a build that is still marked as running.

### Configure UpCloud

Set these values in the Gateway environment. `TaskVmSettings` validates them once, with the other [task VM settings](#configure-task-vms). While the token file is unset, the overflow is not configured, and Orbit sends no UpCloud request.

| Variable | Meaning | Default |
| --- | --- | --- |
| `ORBIT_TASK_VMS_UPCLOUD_ENABLED` | The Gateway-wide switch. It needs task VMs enabled, the token file, and `ORBIT_TASK_VMS_UPCLOUD_MAX_VMS` | `false` |
| `ORBIT_TASK_VMS_UPCLOUD_PROJECTS` | A JSON list of the IDs of the Projects that may overflow | `[]` |
| `ORBIT_TASK_VMS_UPCLOUD_TOKEN_FILE` | The absolute path of the token file: a regular file of the Gateway user with mode `0600` and one `token: ucat_…` line | Unset |
| `ORBIT_TASK_VMS_UPCLOUD_MAX_VMS` | The most UpCloud task VMs, from 1 to 16. Required once the token file is set | Unset |
| `ORBIT_TASK_VMS_UPCLOUD_ZONE` | The UpCloud zone | `nl-ams1` |
| `ORBIT_TASK_VMS_UPCLOUD_PLAN` | The server plan. UpCloud must list it | `STARTER-2xCPU-4GB` |
| `ORBIT_TASK_VMS_UPCLOUD_DISK_GB` | The root disk in GB, from 10 to 200 | `30` |
| `ORBIT_TASK_VMS_UPCLOUD_SOURCE_TEMPLATE` | The UUID of the stock Ubuntu 26.04 template that the build boots | `01000000-0000-4000-8000-000030260200` |

The token goes only to `https://api.upcloud.com/1.3`. The Gateway reads it from the file for each request.

| Error | Meaning |
| --- | --- |
| `task_vm.upcloud_budget_full` (409) | The account already runs `max_vms` task VM servers |
| `task_vm.upcloud_failed` (502) | An UpCloud request failed or did not answer |
| `task_vm.upcloud_invalid` (502) | UpCloud answered with data that does not match what Orbit created |
| `task_vm.upcloud_timeout` (504) | A server or template did not reach the expected state in time |
| `task_vm.upcloud_token_unavailable` (500) | The token file is missing, not private, or has no token |
| `task_vm.upcloud_not_configured` (409) | An UpCloud command ran while the token file is unset |
| `task_vm.host_key_failed` (500) | The Gateway could not generate or read the pinned host key |

### Why the UpCloud overflow works this way

One path for every provider keeps the security boundary and the code the same: only the provider API differs. Two switches keep billed VMs off unless the operator chose them for the fleet and for the Project.

The Project list lives in the Gateway environment next to the switch, so no Project field, API, or CLI change is needed. A count of the account's labelled servers backs the count of rows, so a lost row cannot raise the bill. A pinned host key that the Gateway generates replaces the trusted host that UpCloud lacks. A rename after the smoke boot marks a template as verified, so no VM clones an untested one.

| Alternative | Why not |
| --- | --- |
| A Project field for the opt-in | It needs a migration, API, CLI, SDK, and MCP changes for a switch that only the operator sets |
| The UpCloud firewall | It is stateless. Reply traffic needs source-port rules, which also let anyone reach other ports from those source ports |
| Trust on first use for the host key | The first connection could reach another machine at a reused address |
| The stock template with a full enrollment | Each VM would install the `app-dev` software, and its image would differ from the Incus base image |

## Orbit Project

A group of the `orbit` Project runs on a task VM, like the group of any other Project. Its workspace is the Instance `task-<group id>` without a Route, and every claim clones from the Gateway mirror and installs the dependencies of all five Composer projects and the web app. Its task check is root `composer check`, which runs `bin/review-check` on the VM as `orbit`. [ADR 0200](/decisions/0200-run-each-task-group-in-its-own-sandbox-vm#orbit-project) records the design.

Configure the Project like this:

- List it in `task_vms.medium_projects`, so its groups get a [medium VM](#size-by-declared-topology) with 4 vCPU. Its check runs the test suites in parallel.
- Add the setup step [`@orbit/ci-caches`](/reference/instance-setup#import-ci-main-caches) after the install steps.

A task VM has no main cache store, so without the `@orbit/ci-caches` step every check runs all tests. With it, a fresh workspace gets the test-impact graphs and quality caches of CI. Then the check runs only the affected tests, as on beast.

The setup and teardown steps run on the VM as `orbit`. The `seed-dependencies` step does nothing there, because a task VM workspace has no `ORBIT_SEED_PATH`: the install steps build fresh dependency trees using private caches seeded from the read-only host cache.

### Declare a topology

A subtask declares the test nodes it needs in its `topology` field, for example `["app-dev"]` or `["app-dev", "app-prod+ingress"]`. Each entry names one node, `app-dev`, `app-prod`, or `app-prod-2`, at most once. After a `+` it may add the role `ingress`. `app-prod-2` needs `app-prod` in the same list. A role that conflicts with the node's base role, such as `app-dev+ingress`, is refused, by the [role compatibility](/reference/node-provisioning#role-compatibility) rules. The format takes more roles once one is proven to converge on a test node. Subtask creation and group creation keep the declaration. Only a subtask that has not started can change it.

The subtasks of a group declare at most two test nodes together, as many as a large VM holds. In the spike, a 12 GiB VM was full with two, so a third would run it out of memory. A group that declares more is refused with `tasks.invalid_topology`.

The Gateway validates a declaration once, when a request or `tasks:render-prompt` gives it. It trusts a stored declaration. So a node or role that leaves the format later never fails a tick or a response for a subtask that declares it; the harness refuses that node when the topology is prepared.

The group's topology is the union of its subtasks that are not cancelled: each node once, with every role any subtask adds to it. It decides the [VM size](#size-by-declared-topology) at the claim.

### Prepare the topology

Before a subtask that declares a topology runs its baseline check or its implementer, Orbit prepares the group's topology in the VM. It starts the systemd unit `orbit-task-topology-<run>` as `orbit` and polls it on each tick. The subtask waits meanwhile with `Task VM: preparing the topology <nodes>.` The unit runs `resources/task-vms/topology.sh` from the workspace checkout:

| Step | What happens |
| --- | --- |
| `host-setup` | `sudo bin/e2e-topology-host-setup --pool-size=SIZE`: Incus, the btrfs pool, and the [isolation table](/reference/incus-topologies#run-topologies-nested-in-a-task-vm). The pool is 25 GiB in a medium VM and 45 GiB in a large VM. |
| `images` | `bin/e2e-topology-images ensure --nodes=<union> --recipe-images=PATH` imports the claimed recipe's single base image by fingerprint and builds none. |
| `acquire` | `bin/e2e-topology acquire TASK-<group> <checkout> --nodes=<union> --replace`, which first releases the topology the VM still holds from an earlier subtask. After a failure it runs once more; the log records the retry. |

For medium and large VMs, the preparation job on `task-vms` transfers the single prebuilt base image before the VM becomes ready. This also supports subtasks added later within that VM's size. The manifest records the VM's recipe. The [harness import contract](/reference/incus-topologies#one-recipe-base-image) defines validation and archive cleanup.

Every task VM's user `orbit` is in the `incus-admin` group from its first boot, so agents can run `bin/e2e-topology` once Incus is installed. The row stores the union in `topology`, its state in `topology_state` (`preparing` or `ready`), and the run in `topology_run`.

A ready topology serves every next subtask whose declaration it holds. A subtask that declares more starts the unit again, which releases the topology and acquires the new union; the images stay. A [parked](#park-and-resume) or resumed VM forgets its topology, because its test machines stop with it, and the next subtask prepares it again. A shared group cannot run a topology: its subtask waits with `Declared topology runs only in a task VM group.`

A VM can also restart on its own. So before each turn of a subtask with a ready topology, Orbit reads the run's state. The run records the VM's boot ID, and a run from an earlier boot is `stale`. Orbit then prepares the topology again.

When the unit fails, or stops without a result, the subtask asks for assistance with the step and the end of its log. The row forgets the run, so a resolution prepares again. A unit runs for at most 75 minutes. A start stops the units of earlier runs first, so one preparation runs at a time. When the start call fails, Orbit reads the run's state: a unit that runs or finished is the run, and only a run without a unit fails. Host setup gets the Gateway's VPN subnet, so its input rule covers the VM's own address.

### Prove work on a topology

The implementer and the reviewer use the topology with `bin/e2e-topology`, as the [using-incus-topologies](https://github.com/nckrtl/orbit/blob/main/.agents/skills/using-incus-topologies/SKILL.md) skill describes. Their prompts name the declared nodes. They never acquire or release it.

The reviewer proves each deliverable on the declared nodes with `bin/e2e-topology exec TASK-<group> NODE --argv=JSON --record=LABEL`. The harness writes the node, the command, the exit code, and the output to the [evidence log](/reference/incus-topologies#evidence-log). When the reviewer approves a subtask that declares a topology, Orbit reads the newest MiB of the evidence. The approval passes only when the evidence holds, for each node the subtask declares, a record that counts. Otherwise Orbit reminds the reviewer with the nodes that lack evidence, and the subtask is not approved.

The [evidence log](/reference/incus-topologies#evidence-log) defines the JSON record. Orbit reads the newest MiB of `.e2e/evidence.jsonl`, skipping partial or malformed lines. A record counts when:

- its command exited 0;
- it started after the implementer's last handoff and before Orbit reads the evidence;
- its command does work on the Node. `true`, `:`, `false`, `exit`, `echo`, `printf`, `sleep`, and `yes` do not count, including inside `sh -c` or `bash -c`.

The gate is a cooperative check, not a control against forgery. The agent owns the VM and can write the file directly. The gate catches a reviewer that did not run anything on a declared node, and a person audits the records.

Orbit adds the counted records, with their time, node, label, and command, to the approval, and so to its commit, for a person to audit. Appending to `.e2e/evidence.log` is not a workspace change: the file is ignored, and the reviewer's prompt says so.

`task_vms.topology_paths` is the safety net. It maps a Project ID to path patterns in the syntax of [file deliverables](/reference/tasks#deliverables). For Orbit, list its Node, agent, guest, deployment, and routing code, for example:

```json
{"46": ["apps/agent/**", "apps/e2e/resources/guest/**", "apps/gateway/app/{Domain,Infrastructure,Actions}/{Nodes,Deployments,Routes,Caddy,WireGuard}/**"]}
```

Orbit checks this when the handoff check of a group passes. It refuses the handoff when the group's own changes touch a matching path and no subtask of the group declares a topology. It compares the candidate tree with the merge base of HEAD and the current `origin/main`, including uncommitted files. Changes inherited from main do not count; the group's own changes, including merge resolutions, still count. An unreadable merge base or diff fails the handoff. See the [handoff rubric](/reference/tasks#rubric-and-reminders).

The reminder starts with `This subtask changes paths that need real machines`. The implementer reverts those changes, or ends its turn blocked so the operator can add a subtask with a topology.

## VM power

Task group responses report `vm_power` separately from task status. For a [task VM](#task-vms), it is `running` while the VM is `ready`, `stopped` while it is [`parked`](#park-and-resume), and `destroyed` once it is `destroyed`. In the other states it is `null`, and the group's wait reason names the state. The CLI shows this value as **VM power** when it is not `null`.

## Request a preview

Set `preview: true` when creating or updating a task group to keep its VM running during review. The default is `false`. Preview intent can change while a group is in backlog, waiting, or active; ended groups refuse it. Title, brief, and status updates keep their existing restrictions. Shared groups store the intent without changing shared-host power. A task VM with `preview` is never [parked](#park-and-resume). Setting `preview` does not start a VM that is already parked. The next work for the group starts it.

Use `orbit tasks:create --preview` or `orbit tasks:update <group> --preview`. Use `--no-preview` on update to release the preview. The two update flags are mutually exclusive. The API accepts JSON booleans; omitted updates preserve the current intent.
