---
title: "Tools"
description: "Where Tools run, the supported Tool Managers, how Orbit discovers, adopts, installs, updates, and removes one package, and why the contract stays closed."
covers:
  - apps/gateway/app/{Actions,Domain}/Tools/**
  - apps/gateway/app/Providers/ApplicationServiceProvider.php
  - apps/gateway/app/Infrastructure/Tools/**
  - apps/gateway/app/Models/{Tool,ToolManagerRecord}.php
  - apps/gateway/app/Data/Tools/**
  - apps/gateway/app/Http/Controllers/Api/{ToolsController,ToolManagersController,ToolInventoryController}.php
  - apps/gateway/app/Http/Requests/Tools/**
  - apps/gateway/app/Actions/Doctor/ToolDoctorProbe.php
---

# Tools

A Tool is one package that Orbit manages on one Node through one Tool Manager. The Node, the manager, and the package name identify it. Orbit records the Tools it installs or explicitly adopts. Discovery reports other installed packages without creating Tool records. [`tool`](/cli/tool) lists the commands.

Recording a development [Instance branch rename](/cli/instance#orbit-instancerename) does not install, update, adopt, or remove packages. The Gateway keeps branch inspection separate from Tool manager operations; rename does not rerun Project setup.

The [annotator Process preset](/reference/agentation#annotator-process) installs Gateway-owned server files and their verified injection asset, not a package through a Tool Manager. It creates no Tool intent and does not adopt or remove the Node's existing Node.js runtime. Its private file publication and environment projection stay separate from Tool operations.

Development app runtime migration is also separate from Tool operations. It records its plan in a [journal for the Node](/reference/assigned-vite-ports#migrate-port-reservations). The plan covers endpoint reservations, owned systemd references, Vite environment files, and annotator stores. It does not scan installed packages, create Tool intent, or adopt a Node.js runtime. The Gateway binds runtime migration and Tool managers to separate services; sharing a Node does not transfer ownership between them.

## Where Tools run

The Gateway manages Tools only on an active Node that it manages over SSH. That Node runs a supported platform, has a verified WireGuard address, and has a stored SSH host fingerprint. Ubuntu supports the existing managers. [macOS Nodes](/reference/node-provisioning#macos-nodes) support Homebrew formulae, casks, and Vite+ global packages in the enrolled account's existing installations. Any other Node gets `tool.node_inactive` or `tool.node_unmanaged` (HTTP 409) before the Gateway changes anything.

Tool Managers do not belong to roles. A Node with no role can use every manager its platform supports. `apt` and `composer` stay Linux-only. `brew` and `vp` run on Linux and macOS. `brew-cask` runs on macOS only.

## Tool Managers

The Gateway has five managers, fixed in code. There is no plugin registry and no generic script manager. Formulae and casks have separate manager identities, so the same package name cannot select the wrong package kind.

| Manager | Platforms | Package | Scope | Becomes ready |
| --- | --- | --- | --- | --- |
| `apt` | Linux only | An Ubuntu package name | The Node's package database | When `node:add` converges a Linux Node |
| `composer` | Linux only | A `vendor/package` name | Orbit's shared Composer global scope | On first Linux use, or with `app-dev` or `app-prod` |
| `vp` | Linux and macOS | An npm package name | The enrolled account's Vite+ global scope | Linux: first use or an application role. macOS: verified in place |
| `brew` | Linux and macOS | A verified Homebrew Core bottle | `/home/linuxbrew/.linuxbrew` on Linux; the existing prefix on macOS | Linux: pinned revision on first use. macOS: verified in place |
| `brew-cask` | macOS only | An official Homebrew cask | That macOS prefix and its casks; shares the `brew` lock | Verified in place. Orbit does not install it |

`apt` and `composer` are not offered on macOS, and `brew-cask` is not offered on Linux. Install, update, removal, and adoption return `tool.manager_unsupported` (HTTP 422) before a lock or SSH. An absent or conflicting scope still returns `tool.manager_unavailable` (HTTP 409).

On Linux, `composer` uses `COMPOSER_HOME=/opt/orbit/composer`. `vp` uses the first existing Vite+ store for the enrolled account, in this order: `/opt/orbit/vite-plus`, `~/.vite-plus`, then `~/.local/share/vite-plus`. Install, update, removal, and scan all use that store. A later store does not change the choice. The Gateway may install a missing Linux manager on first use.

On macOS, `vp` is the enrolled account's existing Vite+ global store. `brew` and `brew-cask` use the existing Homebrew prefix. The Gateway resolves that account's home from the machine and does not assume `/home`, `getent`, or a Linux bottle tag. It does not install, replace, or repin those scopes, and it does not check out a Homebrew revision.

A missing scope is scan state `absent`. A scope with the wrong owner, a non-official Homebrew origin, or a dirty Linux Homebrew tree is scan state `conflicting`. On macOS, two Vite+ stores, or one store with the wrong owner or shape, is `conflicting`. On Linux, the chosen Vite+ store is `conflicting` when it is a symlink, not a directory, owned by someone else, or its `bin/vp` is missing or not executable. A second Linux store is not a conflict. Adoption, install, update, and removal then return `tool.manager_unavailable` (HTTP 409). Orbit does not repair that scope.

An accepted macOS Homebrew prefix is owned by the enrolled account and has origin `https://github.com/Homebrew/brew`. Apple silicon and an untar-anywhere install are the git repository: `prefix/.git` exists and `prefix/bin/brew` is a regular executable. The Intel `/usr/local` layout keeps the nested repository `prefix/Homebrew/.git` and a `prefix/bin/brew` symlink to `../Homebrew/bin/brew`. Any other shape is conflicting.

The probe prints the prefix and the account home. The script accepts only `/`, ASCII letters, digits, `.`, `_`, and `-` in that home. It stops when the home contains `..` or any other character, including a space, `@`, `+`, or a non-ASCII letter, so the probe fails and no prefix is accepted. A home of `/` is ignored after a successful probe: the prefix stands and the home is unknown. A cask target is user-owned only when the home is known and contains the target. A font under that home, such as `font-fira-code`, is supported. A cask that needs an administrator prompt, such as `stats`, stays `authorization_required`.

`orbit tool:manager:list --node=<id>` shows each manager the Node's platform supports, with one of these states. An unsupported manager is omitted, not listed as `uninstalled`.

| State | Meaning |
| --- | --- |
| `uninstalled` | The Node supports the manager, but Orbit has not installed it yet. It has no ID. |
| `provisioning` | Orbit installs or checks the manager. |
| `active` | The manager is ready. |
| `failed` | Installing or checking the manager failed. The record keeps the failed step and error code. The next install tries again. |

Before each Linux install, the Gateway converges the manager: it installs the manager when it is missing and checks its version. A failure returns `tool.manager_provision_failed` (HTTP 502), keeps the manager `failed`, and creates no Tool. On macOS, install rechecks the existing scope and returns `tool.manager_unavailable` (HTTP 409) when that scope is absent or conflicting. It creates no Tool. An absent macOS scope stays `failed` at step `manager-absent` with error code `node.tool_manager_absent`. A conflicting owner, origin, or Vite+ store stays `failed` at step `manager-conflict` with error code `node.tool_manager_conflict`. A failed macOS probe returns `tool.manager_provision_failed`.

Update and removal use the same `tool.manager_unavailable` code when the macOS scope is absent or conflicting. The Tool stays `failed` for that operation, with its installed version kept, and the same command retries it. A probe that is not an absent or conflicting scope still returns `tool.version_probe_failed`, `tool.update_failed`, or `tool.remove_failed`. A manager stays installed after its last Tool is removed. No command removes a manager.

### The dpkg lock

On Linux, every apt command in the table passes `-o DPkg::Lock::Timeout=300`. On Ubuntu 26.04, apt 3.2.0 applies that timeout only while it takes the dpkg frontend lock and the dpkg admin lock. Those locks are `/var/lib/dpkg/lock-frontend` and `/var/lib/dpkg/lock`. `apt-get install` and `apt-get remove` take them, so they wait up to 300 seconds. The `apt` manager's update is `apt-get install`, so it waits too. A daily upgrade, such as `apt-daily-upgrade`, can hold one of those locks. Install and remove wait for that upgrade to release the lock instead of failing at once.

Caddy and PHP package installation pass this same option. Prerequisite `apt-get update` passes it and does not use it. Update takes the lists lock, `/var/lib/apt/lists/lock`, and fails at once when that lock is busy.

| Caller | Command | Lock wait |
| --- | --- | --- |
| `brew` prerequisites | `apt-get update` | None. The lists lock fails at once. |
| `brew` prerequisites | `apt-get install` of `build-essential`, `procps`, `curl`, `file`, `git`, and `ca-certificates` | Up to 300 seconds on the dpkg locks. |
| `composer` prerequisites | `apt-get update` | None. The lists lock fails at once. |
| `composer` prerequisites | `apt-get install` of `composer`, `git`, and `unzip` | Up to 300 seconds on the dpkg locks. |
| `apt` install and update | `apt-get install` | Up to 300 seconds on the dpkg locks. |
| `apt` remove | `apt-get remove` | Up to 300 seconds on the dpkg locks. |

Version probes, `apt-cache policy`, and the removal plan `apt-get --simulate remove` do not pass the option and do not wait.

When a dpkg frontend or admin lock is still held after 300 seconds, install or remove exits with an error and changes no package. That covers a `brew` or `composer` prerequisite install, and the `apt` manager's install, update, and remove. A prerequisite `apt-get update` does not reach this timeout. A busy lists lock makes that update fail at once.

Either failure on `tool:install` returns `tool.manager_provision_failed` (HTTP 502). The manager stays `failed` at step `materialize` with error code `node.tool_manager_materialization_failed`, and Orbit creates no Tool. Retry the install.

A `composer` prerequisite failure during `app-dev` or `app-prod` convergence leaves the manager `failed` at the same step and error code. The role or `node:add` reports `node.tool_manager_materialization_failed` in `details.error_code`. [Role operations on one Node](/reference/node-provisioning#role-operations-on-one-node) describes how a failed role reports its step.

An `apt` install, update, or remove returns `tool.install_failed`, `tool.update_failed`, or `tool.remove_failed` (HTTP 502). The Tool stays `failed`. Update and removal keep the recorded version. Retry the same command.

## Discover installed packages

`GET /api/v1/tool-inventory?node_id=<id>` is `tool:scan`. The CLI command is `orbit tool:scan --node=<id>`. The only input is `node_id`, a strict integer of an existing Node. Any other query or body field fails with `validation.failed` (HTTP 422). This operation is not a script API.

The scan reads `brew`, `brew-cask`, and `vp` for the enrolled account. It does not inventory `apt` or `composer`. It compares packages with Tool rows by Node, manager, and package. It stores nothing and creates no Tool row. It does not take a manager lock and it does not materialize a manager. A failed, malformed, or truncated read marks that manager `incomplete` and is never an empty `complete` inventory. The other managers still return their own state.

The Gateway resolves the host itself and uses the pinned SSH identity. The response has no raw output, paths, environment values, checksums, or URLs. Success is HTTP 200.

| Field | Type | Meaning |
| --- | --- | --- |
| `data.node_id` | integer | The Node. |
| `data.observed_at` | string | UTC time when the read finished, such as `2026-04-26T12:00:00+00:00`. |
| `data.managers` | array | Exactly three objects, in the order `brew`, `brew-cask`, `vp`. |
| `data.managers[].manager` | string | `brew`, `brew-cask`, or `vp`. |
| `data.managers[].scan_state` | string | `complete`, `absent`, `unsupported`, `incomplete`, or `conflicting`. |
| `data.managers[].packages` | array | Package facts when `scan_state` is `complete`. Otherwise empty, and not an inventory. |
| `meta.request_id` | string | The request id. |

| Scan state | Meaning |
| --- | --- |
| `complete` | The read finished. The package array is the inventory and may be empty. |
| `absent` | This platform supports the manager, but its scope is not installed. |
| `unsupported` | This platform does not offer the manager. `brew-cask` is unsupported on Linux. |
| `incomplete` | The read failed, the output was malformed or truncated, or the Homebrew name list and metadata disagree. |
| `conflicting` | The scope exists on Linux or macOS, but Orbit will not adopt it or replace it. |

Packages inside a `complete` manager are ordered by package name, ascending. A formula and a cask that share a name stay on different managers.

| Field | Type | Meaning |
| --- | --- | --- |
| `manager` | string | The enclosing manager. |
| `package` | string | Unqualified package name. |
| `package_kind` | string | `formula`, `cask`, or `global`. |
| `installed_version` | string or null | Normalized version, at most 255 characters, or null. |
| `dependency` | boolean | True for a Homebrew dependency. False for a root formula, cask, or Vite+ package. |
| `registered` | boolean | True when a Tool row exists for this Node, manager, and package. |
| `tool_id` | integer or null | That Tool's id, or null. |
| `adoption` | string | `supported` or `unsupported`. |
| `adoption_block` | string or null | Null when adoption is supported. Otherwise a block token. |

The block tokens are `protected`, `dependency`, `unsupported_artifact`, `unsupported_source`, `bottle_unavailable`, `version_unreadable`, and `authorization_required`. A dependency or a [protected package](#protected-packages) is never `supported`. An unsupported cask stays in the list with adoption unavailable and one of those tokens. Doctor and the Node's Tools page use this inspector. See [informational package discoveries](/cli/doctor#informational-package-discoveries).

The scan stores a normalized SemVer version, or null. A readable formula revision such as `25.8.1_1`, and a readable cask version such as `3.003`, normalize to null and can still be `supported`. `version_unreadable` means there is no readable version string, such as `latest`. It does not mean readable text failed the SemVer check. `tool:scan` prints a null version as an em dash. The Tools page shows `unreadable`. Doctor prints `version=unknown`.

Homebrew inventory uses two reads for each of `brew` and `brew-cask`. `info --json=v2 --formula --installed` and `info --json=v2 --cask --installed` supply metadata. `list --formula -1` and `list --cask -1` supply installed names without loading formulae or casks. Both use the usual Homebrew environment and do not set `HOMEBREW_FORCE_API_AUTO_UPDATE`.

Homebrew can omit an installed formula or cask from `info` and still exit 0, including when the tap is untrusted or Homebrew refuses to load it. The name stays in `list`. The scan keeps that name, with `installed_version` null and `dependency` false, because the name list does not say whether it is a dependency. Adoption is `unsupported`. The block is `unsupported_source`, or `protected` when the formula name is protected.

A name present in `info` but absent from `list` makes that manager `incomplete`. A failed, malformed, or truncated name list also makes that manager `incomplete`. The scan does not report the `info` packages alone.

Vite+ reports each global root under the name it stored. A stored name that Orbit cannot manage, including a legacy npm name with capitals such as `JSONStream`, stays in that list. Its adoption is `unsupported`, its block is `unsupported_source`, and it is not registered. The `vp` scan is `incomplete` when a name is not a string, is empty, is longer than 255 characters, contains a control character, or appears twice. Those cases are not an inventory.

Scan errors use the [Tool error envelope](#errors). `details.step` is `scan`, `details.outcome` is `manager_failed`, and there is no Tool `id`. A manager state of `incomplete`, `absent`, `unsupported`, or `conflicting` does not fail the HTTP request.

| Code | HTTP | When |
| --- | --- | --- |
| `tool.node_inactive` | 409 | The Node is not `active`. |
| `tool.node_unmanaged` | 409 | The Node has no WireGuard address or no pinned SSH fingerprint. |
| `validation.failed` | 422 | The query or body is not the one `node_id` field. |
| `node_access.required` | 403 | The caller cannot address the Node. |

Scan reads installed names and versions only. It does not run `brew update`, and it does not set `HOMEBREW_FORCE_API_AUTO_UPDATE`. A stale Homebrew API cache does not by itself make the scan `incomplete`.

## Protected packages

Some installed packages keep SSH, the WireGuard tunnel, DNS, Docker, the firewall, sudo, Caddy, or a manager working. Adopting one would let `tool:remove` uninstall it. These names are protected. The set is closed and depends on the manager.

| Manager | Protected package names |
| --- | --- |
| `apt` | `acl`, `attr`, `ca-certificates`, `caddy`, `composer`, `curl`, `dnsmasq`, `docker.io`, `git`, `gnupg`, `libnss-resolve`, `openssh-client`, `openssh-server`, `openssl`, `php-curl`, `php-xml`, `sudo`, `ufw`, `unzip`, `wireguard`, `wireguard-tools` |
| `brew` | `wireguard-tools`, `wireguard-go` |
| `vp` | `pnpm` |

The apt names are every package `NodeBootstrapPackageCatalog` returns from `forNode` and `forRole`, plus `openssh-server` and `wireguard-tools`. The Gateway reads that catalog at the check. It does not keep a second copied list. The table is that union.

`composer` and `brew-cask` have no protected names. The apt package `composer` is protected. The `composer` manager is a different scope and has none. A protected package still appears in scan, with `adoption` `unsupported` and `adoption_block` `protected`. Install, update, and adopt refuse it with no new Tool row. Install and update return `tool.package_protected` (HTTP 409, outcome `manager_failed`). Adopt returns `tool.adoption_unsupported` with that block token. `tool:remove` of an existing row returns `tool.package_protected`, leaves the row, and does not run the uninstaller. `tool.removal_plan_unsafe` does not replace this list. A plan that removes only `docker.io` or only `dnsmasq` is still refused.

## Adopt a Tool

`POST /api/v1/tools/adopt` is `tool:adopt`. The CLI command is `orbit tool:adopt <package> --node=<id> --manager=<manager> [--constraint=<range>] --yes`. MCP and the Tools page call this operation. They do not get a second adoption API. Adoption installs, updates, removes, and repins nothing.

The JSON object allows only these fields. Any other field fails with `validation.failed` (HTTP 422).

| Field | Required | Type | Meaning |
| --- | --- | --- | --- |
| `node_id` | yes | strict integer | An existing Node id, at least 1. |
| `manager` | yes | string | `apt`, `composer`, `vp`, `brew`, or `brew-cask`, at most 32 characters. |
| `package` | yes | string | That manager's package name, at most 255 characters. |
| `version_constraint` | no | string or null | A SemVer range, such as `^14.0`, at most 255 characters. Omit or null for none. |

The Gateway takes the Tool lock for that Node, manager, and package, then the manager scope lock. `brew-cask` uses the `brew` prefix lock. It then reads the live package again. A discovery row is not evidence.

Success returns the same Tool object as install, update, and remove. `status` is `installed`. `failed_operation` and `error_code` are null. `outcome` is set. The object is wrapped in `data`, with `meta.request_id`.

| Field | Type | Meaning |
| --- | --- | --- |
| `id` | integer | The Tool id. |
| `node_id` | integer | The Node. |
| `manager` | string | The manager name. |
| `package` | string | The package name. |
| `version_constraint` | string or null | The stored SemVer range, or null. |
| `status` | string | `installing`, `installed`, `updating`, `removing`, `failed`, or `removed`. `removed` is response-only and is never stored, so list and show never return it. Adoption success is `installed`. |
| `installed_version` | string or null | The live version recorded at creation. It may be non-SemVer when unconstrained. |
| `failed_operation` | string or null | `install`, `update`, or `remove` after a failed mutation. Null on adoption success. |
| `error_code` | string or null | The last mutation error, or null. |
| `outcome` | string or null | The operation outcome. |

| Result | HTTP | `outcome` | Record |
| --- | --- | --- | --- |
| No Tool yet for this Node, manager, package, and constraint | 201 | `applied` | Created as `installed`. The host package is unchanged. |
| The same constraint exists, status is `installed`, and the package is still accepted | 200 | `unchanged` | Not rewritten, including `installed_version`. |
| The same constraint exists, status is `failed`, and the package is still accepted | 200 | `applied` | Repaired to `installed`, with the failure cleared and `installed_version` set. The host package is unchanged. |

A different constraint on an existing Tool is `tool.constraint_conflict`. It does not create a second row. A row left in `installing`, `updating`, or `removing` is not repaired. Adopt returns `tool.state_invalid` and leaves that row. The caller retries the original operation or removes the Tool.

Adoption errors use the Tool envelope. `details.step` is `adopt`. `details.id` is included only after the Gateway has found an existing Tool row. `tool.adoption_unsupported` also includes `details.adoption_block`.

| Code | HTTP | `details.outcome` | When |
| --- | --- | --- | --- |
| `tool.manager_unsupported` | 422 | `manager_failed` | The manager is unknown, or the platform does not offer it. |
| `tool.package_invalid` | 422 | `manager_failed` | The name fails that manager's grammar. |
| `tool.constraint_invalid` | 422 | `constraint_invalid` | The constraint is not a SemVer range. |
| `tool.node_inactive` | 409 | `manager_failed` | The Node is not `active`. |
| `tool.node_unmanaged` | 409 | `manager_failed` | No WireGuard address or pinned SSH fingerprint. |
| `tool.manager_unavailable` | 409 | `manager_failed` | The scope is absent or conflicting. |
| `tool.package_absent` | 409 | `manager_failed` | The package is not installed. |
| `tool.version_probe_failed` | 409 | `manager_failed` | The installed version cannot be read. |
| `validation.failed` | 422 | none | The body has an unknown field or a field of the wrong type. No Tool lookup. |
| `node_access.required` | 403 | none | The caller cannot address the Node. No Tool lookup. |
| `tool.installed_version_unparseable` | 409 | `manager_failed` | A constraint is set and the version is not SemVer. |
| `tool.installed_version_constraint_violated` | 409 | `manager_failed` | The installed version is outside the constraint. This is the same code install uses for that condition. |
| `tool.constraint_conflict` | 409 | `manager_failed` | The existing Tool has another constraint. |
| `tool.state_invalid` | 409 | `manager_failed` | The existing row is `installing`, `updating`, or `removing`. `details.id` is set. |
| `tool.operation_locked` | 409 | `manager_failed` | The Tool lock or the scope lock is busy. |
| `tool.adoption_unsupported` | 409 | `manager_failed` | The package is protected, a dependency, or otherwise has no adopt path. |

`details.adoption_block` uses the scan block tokens. `brew` adoption requires an unqualified Core formula with a compatible verified bottle, so a later update has a supported path. `brew-cask` adoption requires official metadata, a supported artifact, and a checksum, and it refuses a cask that needs an interactive or administrator prompt to upgrade or remove. `vp` adoption requires a root package in the enrolled account's global scope. `apt` and `composer` can be adopted only on Linux, in the scopes above. No adoption takes dependencies or any other package.

Install still refuses an unregistered installed package with `tool.already_installed_unmanaged` (HTTP 409). It does not adopt that package. The caller uses `tool:adopt`.

On Linux, unconstrained adoption records the apt version string, such as `5.8.3-1`. A constraint is checked only when that version normalizes to SemVer. `^1.25` accepts `1.25.0-2ubuntu4`. A version that does not normalize returns `tool.installed_version_unparseable` and creates no Tool row.

## Install a Tool

A caller sends only the Node, the manager, the package name, and an optional version constraint. Each manager checks the package name against its own grammar and builds a fixed command with the name in one argument position. A caller cannot send commands, options, repositories, or environment values.

The Gateway refuses a package that is already on the Node without a Tool record, with `tool.already_installed_unmanaged` (HTTP 409). Use explicit [adoption](#adopt-a-tool) to take ownership.

A new install creates the Tool as `installing`, installs the package, and reads the installed version. A success marks the Tool `installed` and reports `applied`. A failure after the Tool exists marks it `failed` and returns its ID in the error, so you can retry or remove it. Running the same install again retries a Tool whose install failed. An install of a Tool that is already `installed`, with the same constraint, checks the package again. When the package is present, the result is `unchanged`.

The optional constraint is a SemVer range, such as `^0.150`. It only stops an unsafe version. Before an install, the Gateway reads the manager's candidate version. A candidate outside the range records the Tool as `failed` with `tool.version_constraint_blocked` (HTTP 422, outcome `blocked_by_constraint`) and installs nothing. Remove that row, or retry with a constraint the candidate satisfies. The Gateway never searches for another matching version and never downgrades. When the candidate is not SemVer, the same failed row is kept and the code is `tool.candidate_version_unparseable`. A Tool keeps its constraint: installing it again with another constraint fails with `tool.constraint_conflict`.

## Update a Tool

`tool:update` asks the manager for its current candidate and installs it. The result is `applied` when the version changed and `unchanged` when it did not. When the candidate falls outside the stored constraint, the update changes nothing and reports `blocked_by_constraint`. The Tool stays installed. A Homebrew formula that is pinned stays pinned. Update reports `unchanged` and does not clear the pin. If the macOS Homebrew prefix or Vite+ scope is absent or conflicting, update returns `tool.manager_unavailable` (HTTP 409), marks the Tool `failed`, and leaves the recorded version in place.

## Homebrew formulae

The `brew` manager accepts one lowercase formula name from Homebrew Core, without a tap prefix. The name may end in `+`, as in `libsigc++` or `gtk+`. Before an install or update, the Gateway reads the formula's metadata. It requires the `homebrew/core` tap, a stable version, and a compatible bottle with a SHA-256 checksum. Homebrew installs that bottle with `--force-bottle`. Orbit never builds a formula from source. Inventory uses `info --json=v2 --formula --installed` and `list --formula -1`.

Orbit never runs a Homebrew developer command. That includes `brew ruby` and `brew irb`. A developer command writes `homebrew.devcmdrun` into the user's Homebrew git config and changes how a later `brew update` behaves. Orbit does not set `HOMEBREW_DEVELOPER` or `HOMEBREW_DEV_CMD_RUN`.

The compatible tag is computed in the Gateway. On macOS it reads the product version with the fixed command `sw_vers -productVersion` and uses the CPU stored from `uname -m`. A code-owned table maps that version to Homebrew's active bottle symbol. The Gateway does not store the OS version on the Node.

| Product version major | Bottle symbol |
| --- | --- |
| `27` | `golden_gate` |
| `26` | `tahoe` |
| `15` | `sequoia` |
| `14` | `sonoma` |
| `13` | `ventura` |
| `12` | `monterey` |
| `11` | `big_sur` |

When the major is 11 or higher, Orbit uses that major. It does not turn the display alias `10.16` into `big_sur`. A version with no row, including major `10`, has no compatible bottle. Apple silicon uses `arm64_` plus the symbol, so macOS 27 is `arm64_golden_gate`. Intel macOS, `x86_64`, uses the symbol alone. Any other macOS CPU has no compatible bottle. Update this table when Homebrew adds a symbol. Until then that OS has no compatible bottle.

Linux does not use the table. `x86_64` maps to `x86_64_linux`. `aarch64` and `arm64` map to `arm64_linux`.

A bottle file keyed by that tag is compatible. A file keyed `all` is compatible only when the current tag is absent. An older OS tag, such as `arm64_sequoia` on macOS 27, is not compatible. If neither the current tag nor `all` has a checksummed file, the formula is refused before any change. Adopt uses block `bottle_unavailable`.

| Input | Result |
| --- | --- |
| A Homebrew Core formula with a compatible bottle for the Node | Accepted |
| A tap-qualified name, a cask, a URL, a local file, or a Git reference | Refused before any change |
| A formula without a matching bottle | Refused before any change |

On Linux, the Gateway installs Homebrew at a pinned revision. When the prefix already exists, the Gateway accepts it only when the managed user owns it, its origin is the official Homebrew repository, and its working tree is clean. It then checks out the pinned revision. Otherwise it leaves the prefix unchanged and marks the manager `failed`. Tool operations never start or stop a Homebrew service.

On macOS, Orbit verifies and reuses the existing Homebrew installation without checking out another revision and without running `brew update`. Formula operations still force compatible bottles. Updating one root formula may install or upgrade the dependencies that formula requires. It does not upgrade other root packages or dependents that merely depend on it.

Every `brew` and `brew-cask` command sets `HOMEBREW_NO_AUTO_UPDATE`, `HOMEBREW_NO_ANALYTICS`, `HOMEBREW_NO_ENV_HINTS`, `HOMEBREW_NO_INSTALLED_DEPENDENTS_CHECK`, and `HOMEBREW_NO_INSTALL_CLEANUP`. Those last two stop install and upgrade from upgrading unrelated packages or running periodic cleanup. Scan does not set `HOMEBREW_FORCE_API_AUTO_UPDATE`. Install, update, and the adopt bottle check on macOS do set it, so Homebrew can refresh its API data while `HOMEBREW_NO_AUTO_UPDATE` still blocks a git update of the user's Homebrew revision. Linux keeps the pinned revision and does not force that API refresh. Its metadata freshness stays the behavior already shipped with the pinned prefix.

## Homebrew casks

`brew-cask` supports official Homebrew casks on macOS. The caller sends one unqualified token. A token may end in `+`, as in `logi-options+`. The Gateway turns that into the fixed coordinate `homebrew/cask/<token>` and refuses a tap, a URL, a local file, or a caller option before it runs anything. A formula and a cask with the same token stay different Tools, because the manager is part of the identity.

Before install or update, the Gateway reads `brew info --json=v2 --cask` for that coordinate. The cask must come from the `homebrew/cask` tap, use an `https` URL, and name itself with the same unqualified token. The Gateway then applies one refusal, in this order: an unsupported artifact, interactive or administrator authorization, the checksum policy, then an unreadable version.

`brew info --json=v2` returns the current arm64 definition as the base fields. Other Mac definitions are shallow overrides under `variations`, keyed by the same bottle tag formulae use (`sw_vers` plus the code-owned symbol table; arm64 prefixes the symbol, Intel uses the symbol alone). The Gateway applies `variations[<tag>]` before it checks the URL, version, checksum, and artifacts. `sha256` must be one lowercase SHA-256 on that definition. `no_check` fails the checksum policy. Discovery reports that checksum failure as `unsupported_artifact`.

Supported artifacts install into the enrolled user's home or the Homebrew prefix. That includes fonts, binaries, man pages, completions, and user plugins whose target is `/$HOME`, `~/`, or `$HOMEBREW_PREFIX`. `brew info --json=v2` expands a default home target to that account's absolute home, such as `/Users/mini/Library/Fonts/Hack.ttf`. The same expansion applies to uninstall `trash`, `delete`, and `rmdir` paths. An absolute path inside the resolved home is still the user's home.

An app or suite that targets `/Applications`, another account's home, a package installer, a privileged plugin, or an uninstall that uses `pkgutil`, `kext`, `launchctl`, or a system path is `authorization_required`. Preflight, postflight, stage-only, and arbitrary uninstall scripts are `unsupported_artifact`. A disabled cask is too. Zap stanzas are ignored and never run.

Removal does not repeat the disabled, checksum, version, or source gates. It still refuses an arbitrary uninstall script, a package installer, an app in `/Applications`, or any other uninstall that needs interactive or administrator authorization. A disabled, unchecksummed, or `latest` cask can still be removed when that uninstall is safe.

When the official cask is gone, Homebrew says `homebrew/cask/<token>` is unavailable because the tap is not installed. Removal then reads that token from `info --json=v2 --cask --installed`. It uninstalls only when that entry's tap is `homebrew/cask`, and it passes the plain token to `uninstall --cask`. The same bottle-tag override applies.

Install and update use `install --cask` and `upgrade --cask` for the coordinate. Removal of an available cask uses `uninstall --cask` for that coordinate. Inventory uses `info --json=v2 --cask --installed` and `list --cask -1`. A cask that `list` names and `info` omits stays in discovery with `unsupported_source`.

The installed-version read uses `list --versions --cask` with the plain token. Homebrew prints the token and version when the cask is installed. When it is absent, that command exits 1 and prints nothing. A silent exit 1 is not absence by itself. The Gateway then reads `info --json=v2 --cask --installed`. It reports no installed version only when that token is missing, or when the entry's tap is not `homebrew/cask`.

The Gateway runs brew over a non-TTY SSH session with `BatchMode=yes`, so sudo cannot prompt. Install, update, and the metadata read that guards them also set `HOMEBREW_FORCE_API_AUTO_UPDATE`. Inventory, removal, and installed-version reads do not. The Gateway never runs `brew uninstall --zap`, `brew autoremove`, `brew upgrade` without the cask, `brew services`, or a Homebrew developer command.

An unsupported installed cask stays in discovery with `adoption` `unsupported` and one block token. Discovery does not create a Tool and does not mean the cask can be adopted. A supported cask can be installed, updated, and removed as a normal Tool. A policy refusal or a failed command returns that operation's own error: `tool.install_failed`, `tool.update_failed`, or `tool.remove_failed` (HTTP 502). The Tool stays `failed` and can be retried. The error never includes raw Homebrew output. Adoption of a cask that needs an interactive or administrator prompt uses `tool.adoption_unsupported` with `adoption_block` `authorization_required`. Formula and cask operations share the Homebrew prefix lock, including when their manager records have different ids.

## Remove a Tool

`tool:remove` removes an `installed` or `failed` Tool. Every success returns the Tool object with `status` `removed` and `outcome` `applied`. This covers an actual removal, a package that was already absent, and a failed Tool with no probed version. `removed` appears only in that response. It is never stored, so list and show never return it.

The Gateway first reads the installed version. For `apt`, it then plans the removal with `apt-get --simulate remove` and refuses a plan that removes any other package, with `tool.removal_plan_unsafe`. It removes only the recorded package and never runs an autoremove. After the removal, it reads the version again. A Tool whose package is gone is deleted.

`apt` removes a package without purging its configuration files. The Gateway treats a package that dpkg lists as removed with only its configuration left as absent.

| Condition | Result | Tool record |
| --- | --- | --- |
| The package is already absent | Success, `status` `removed` and `outcome` `applied`, with no manager command | Deleted |
| The Tool failed with `tool.version_probe_failed` and never recorded a version | Success, `status` `removed` and `outcome` `applied`, with no probe | Deleted |
| The removal succeeds and the package is gone | Success, `status` `removed` and `outcome` `applied` | Deleted |
| The version probe fails on a Tool with a known package | `tool.version_probe_failed` | Kept as `failed` |
| The macOS scope is absent or conflicting | `tool.manager_unavailable` | Kept as `failed` |
| The removal fails or the package stays | `tool.remove_failed` | Kept as `failed` |

Retry the same command with the Tool ID. The Gateway reads the live package state before it acts again.

## Operation outcomes

Every Tool success body and every Tool error `details.outcome` uses one of these tokens.

| Outcome | Success operations | Meaning |
| --- | --- | --- |
| `applied` | install, update, remove, adopt | A mutation changed the package, adopt created a Tool without changing the host, or remove deleted the Tool. Every remove success uses `applied`. |
| `unchanged` | install, update, adopt | The requested intent already held. |
| `blocked_by_constraint` | update | The candidate is outside the stored constraint. Update leaves the Tool installed. Install records `failed` and installs nothing. |
| `constraint_invalid` | none | The constraint is not a SemVer range. |
| `candidate_version_unavailable` | none | The manager returned no candidate. |
| `candidate_version_unparseable` | none | A constraint is set and the candidate is not SemVer. |
| `manager_failed` | none | The Node or manager rejected the operation. |

Install returns `blocked_by_constraint` only inside `tool.version_constraint_blocked`, and only before it installs a candidate. Adopt does not use that code. An installed version that falls outside the constraint is `tool.installed_version_constraint_violated` for both install and adopt.

## Errors

A Tool error carries a stable `code`, a message, and `details`. The Gateway never stores or returns the raw output of a package manager.

| `details` field | Present |
| --- | --- |
| `step` | Always. Examples are `scan`, `adopt`, `install`, `update`, and `remove`. |
| `outcome` | Always. One token from [Operation outcomes](#operation-outcomes). |
| `id` | When a Tool row exists. An adoption or scan that has not found a row omits it. |
| `adoption_block` | Only on `tool.adoption_unsupported`. One scan block token. |

No other `details` keys are returned.

## Locks

Each mutation locks its Tool and its manager's scope on the Node. A busy lock fails at once with `tool.operation_locked`. `brew` and `brew-cask` share the Homebrew prefix lock. Scan does not take either lock. [Per-Node locks](/reference/node-provisioning#per-node-locks) lists every lock and its term.

`tool.operation_locked` means Orbit's lock is busy. It does not mean a dpkg lock is busy. [The dpkg lock](#the-dpkg-lock) says which apt commands wait for one.

## Check removal with Doctor

The `tool` family of [Doctor](/cli/doctor) compares each Tool record with the Node. A record whose package is absent reports `tool.not_installed`, including a `failed` install that never placed the package. A normalized version that violates the stored constraint reports `tool.version_mismatch`. An unconstrained Tool needs only to be installed; the recorded version is the last operation's result, not desired intent. The report never contains raw dpkg output. Those drift issues use the Tool id as `resource_id` and leave `resource_name` null. Unregistered discoveries are a separate informational kind. They do not replace these drift checks.

## Why it works this way

These reasons explain the design. Check them before you propose a change.

### A closed manager registry

Each manager is code with its own grammar, fixed commands, and tests. So the Tool API cannot run an arbitrary command. Package plugins, per-Tool definitions, generic scripts, and caller-supplied options are rejected alternatives. A new manager needs a new adapter in code.

### Explicit package ownership

An installation alone does not prove Orbit ownership. Discovery shows what exists; installation or explicit adoption establishes what Orbit may manage. Automatic adoption would turn a read into permission to update or remove unrelated software. Tool rows therefore hold selected intent, not host inventory. Orbit removes only the exact package it recorded, without autoremove.

### Managers on demand, independent of roles

A manager serves any Node that the Gateway manages. Tying `vp` and `composer` to application roles would make Tools depend on where applications run, and role removal would have to handle unrelated Tools. Installing every manager on every Node is also rejected, because most Nodes need few of them and would carry needless software. `apt` and `composer` stay Linux-only. `vp` uses the enrolled account's global scope on Linux and macOS. macOS reuses that scope and the existing Homebrew prefix instead of installing a second copy.

### Bottled Homebrew Core only

The formula manager accepts only Homebrew Core bottles with verified checksums, on Linux and macOS. The macOS tag comes from `sw_vers` and the code-owned symbol table, never from a Homebrew developer command. Casks use a separate macOS adapter with official metadata and supported artifacts. Taps, source builds, caller options, and arbitrary installers remain outside both contracts. Separate package identities avoid formula and cask collisions; a shared prefix lock prevents concurrent mutations of their common installation.

### Selected adoption

Discovery shows installed packages and creates no Tool rows. Adoption is the separate choice to own one supported package that is already installed. It does not install, update, remove, or repin the manager. The CLI, MCP, and the Node Tools page call that same Gateway operation.

Owning every installed package was rejected because a scan is not a request to own the machine. Silent adoption during install was rejected because installing and taking ownership are different intentions. Storing discoveries as Tools was rejected because a Tool row is selected intent, not an observation. One identity for a formula and a cask was rejected because the names can collide and the operations differ. Separate locks were rejected because both change the same Homebrew prefix. Adopting tunnel and bootstrap packages was rejected because removal would then uninstall SSH, WireGuard, DNS, Docker, the firewall, sudo, Caddy, or a manager.

A readable version that is not SemVer can still be adopted when no constraint is set. A formula revision such as `25.8.1_1` and a cask version such as `3.003` are null in the scan. Doctor shows `unknown`, the Tools page shows `unreadable`, and `tool:scan` shows an em dash. A constraint cannot be checked, so adopt returns `tool.installed_version_unparseable` and install returns `tool.candidate_version_unparseable`. The package stays where it was.
