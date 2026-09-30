---
title: "Tools"
description: "Where Tools run, the supported Tool Managers, how Orbit installs, updates, and removes one package, and why the contract stays closed."
covers:
  - apps/gateway/app/{Actions,Domain}/Tools/**
  - apps/gateway/app/Providers/ApplicationServiceProvider.php
  - apps/gateway/app/Infrastructure/Tools/**
  - apps/gateway/app/Models/{Tool,ToolManagerRecord}.php
  - apps/gateway/app/Data/Tools/**
  - apps/gateway/app/Http/Controllers/Api/{ToolsController,ToolManagersController}.php
  - apps/gateway/app/Http/Requests/Tools/**
  - apps/gateway/app/Actions/Doctor/ToolDoctorProbe.php
---

# Tools

A Tool is one package that Orbit manages on one Node through one Tool Manager. The Node, the manager, and the package name identify it. Orbit records the Tools it installs or explicitly adopts. Discovery reports other installed packages without creating Tool records. [`tool`](/cli/tool) lists the commands.

## Where Tools run

The Gateway manages Tools only on an active Node that it manages over SSH. That Node runs a supported platform, has a verified WireGuard address, and has a stored SSH host fingerprint. Ubuntu supports the existing managers. [macOS Nodes](/reference/node-provisioning#macos-nodes) support Homebrew formulae, casks, and Vite+ global packages in the enrolled account's existing installations. Any other Node gets `tool.node_inactive` or `tool.node_unmanaged` (HTTP 409) before the Gateway changes anything.

Tool Managers do not belong to roles. A Node with no role can use every manager its platform supports.

## Tool Managers

The Gateway has five managers, fixed in code. Formulae and casks have separate manager identities, so the same package name cannot select the wrong package kind.

| Manager | Package | Scope on the Node | Installed |
| --- | --- | --- | --- |
| `apt` | An Ubuntu package name | The Node's package database | When `node:add` converges the Node |
| `vp` | An npm package name, such as `@openai/codex` | Orbit's shared Vite+ global scope | On first use, or when `app-dev` or `app-prod` converges |
| `composer` | A `vendor/package` name | Orbit's shared Composer global scope | On first use, or when `app-dev` or `app-prod` converges |
| `brew` | One Homebrew Core formula name | `/home/linuxbrew/.linuxbrew` on Linux; the existing Homebrew prefix on macOS | On first use on Linux; verified in place on macOS |
| `brew-cask` | One official Homebrew cask name | The existing macOS Homebrew prefix and its cask installation | Verified in place on macOS |

`orbit tool:manager:list --node=<id>` shows each manager with one of these states.

| State | Meaning |
| --- | --- |
| `uninstalled` | The Node supports the manager, but Orbit has not installed it yet. It has no ID. |
| `provisioning` | Orbit installs or checks the manager. |
| `active` | The manager is ready. |
| `failed` | Installing or checking the manager failed. The record keeps the failed step and error code. The next install tries again. |

Before each install, the Gateway converges the manager: it installs the manager when it is missing and checks its version. A failure returns `tool.manager_provision_failed` (HTTP 502), keeps the manager `failed`, and creates no Tool. A manager stays installed after its last Tool is removed. No command removes a manager.

## Discover installed packages

`tool:scan` reads the Node's existing Homebrew formulae and casks and the enrolled user's Vite+ global root packages. It compares them with Tool records by Node, manager, and package. It returns package kind, normalized version when available, registered ownership, dependency status, and adoption support. Homebrew dependencies are labeled and never adopted automatically.

Discovery uses fixed read-only commands. It runs no manager bootstrap or metadata update and stores no inventory. A missing or unsupported manager has an explicit scan state. Failure, malformed output, or a truncated inventory makes that manager's scan incomplete; it never becomes an empty successful result. Other manager results can still be shown with their own status. The response includes observation time and bounded package facts, without raw output, paths, or environment values.

Doctor and the Node's Tools page use the same inventory inspector. Unregistered discoveries are informational, including unsupported casks. They do not make the Node unhealthy. See [informational package discoveries](/cli/doctor#informational-package-discoveries).

## Adopt a Tool

`tool:adopt` takes one Node, manager, package, and optional SemVer constraint. It verifies an active managed Node, a supported package kind, the existing manager scope, and the live installed package under the Tool and manager locks. An invalid constraint or a constraint that rejects the installed version stops adoption. The package must be present; a discovery row alone is not evidence at adoption time.

Success creates the exact Tool intent and records the current installed version. It installs, updates, removes, or repins nothing on the host. The existing user and package-manager scope stay in place. Repeating adoption with the same intent returns unchanged; a different constraint conflicts. A missing package, unsupported artifact, conflicting manager, unreadable version, or busy lock creates no Tool. An unconstrained package may retain a non-SemVer version, as other managers do.

An adopted Tool uses the same update, removal, and Doctor checks as a Tool Orbit installed. The Gateway API, CLI, MCP, and web app expose the same adoption operation. Selecting one package never adopts its dependencies or other installed packages. Installation still refuses an existing unregistered package and directs the caller to adoption.

## Install a Tool

A caller sends only the Node, the manager, the package name, and an optional version constraint. Each manager checks the package name against its own grammar and builds a fixed command with the name in one argument position. A caller cannot send commands, options, repositories, or environment values.

The Gateway refuses a package that is already on the Node without a Tool record, with `tool.already_installed_unmanaged` (HTTP 409). Use explicit [adoption](#adopt-a-tool) to take ownership.

A new install creates the Tool as `installing`, installs the package, and reads the installed version. A success marks the Tool `installed` and reports `applied`. A failure after the Tool exists marks it `failed` and returns its ID in the error, so you can retry or remove it. Running the same install again retries a Tool whose install failed. An install of a Tool that is already `installed`, with the same constraint, checks the package again. When the package is present, the result is `unchanged`.

The optional constraint is a SemVer range, such as `^0.150`. It only stops an unsafe version. Before an install, the Gateway reads the manager's candidate version. A candidate outside the range fails with `tool.version_constraint_blocked`, and the Gateway installs nothing. The Gateway never searches for another matching version and never downgrades. When a manager's version cannot be read as SemVer, a constrained install fails. A Tool keeps its constraint: installing it again with another constraint fails with `tool.constraint_conflict`.

## Update a Tool

`tool:update` asks the manager for its current candidate and installs it. The result is `applied` when the version changed and `unchanged` when it did not. When the candidate falls outside the stored constraint, the update changes nothing and reports `blocked_by_constraint`. The Tool stays installed.

## Homebrew formulae

The `brew` manager accepts one lowercase formula name from Homebrew Core, without a tap prefix. Before an install or update, the Gateway reads the formula's metadata. It requires the `homebrew/core` tap, a stable version, and a bottle for the Node's platform and architecture with a SHA-256 checksum. Homebrew verifies that bottle when it installs it with `--force-bottle`. Orbit never builds a formula from source.

| Input | Result |
| --- | --- |
| A Homebrew Core formula with a compatible bottle for the Node | Accepted |
| A tap-qualified name, a cask, a URL, a local file, or a Git reference | Refused before any change |
| A formula without a matching bottle | Refused before any change |

On Linux, the Gateway installs Homebrew at a pinned revision. When the prefix already exists, the Gateway accepts it only when the managed user owns it, its origin is the official Homebrew repository, and its working tree is clean. It then checks out the pinned revision. Otherwise it leaves the prefix unchanged and marks the manager `failed`. Tool operations never start or stop a Homebrew service.

On macOS, Orbit verifies and reuses the existing Homebrew installation without checking out another revision. Formula operations still force compatible bottles. Updating a selected root formula can update dependencies through Homebrew; it never becomes a bulk upgrade of unrelated root packages.

## Homebrew casks

`brew-cask` supports official Homebrew casks on macOS. It validates the cask's metadata, version, checksum, and supported artifact before mutation. Names stay unqualified; taps, URLs, local files, and caller-supplied options are refused. Unsupported artifacts stay visible in discovery with adoption unavailable and a clear reason.

Install, update, and removal target the exact cask through fixed Homebrew commands. An operation that needs unsupported interactive or administrator authorization fails with a clear outcome; it never waits for an agent to supply an arbitrary installer command. Removal never uses zap, autoremove, or a Homebrew service command. Formula and cask operations share the same Homebrew prefix lock.

## Remove a Tool

`tool:remove` removes an `installed` or `failed` Tool.

The Gateway first reads the installed version. For `apt`, it then plans the removal with `apt-get --simulate remove` and refuses a plan that removes any other package, with `tool.removal_plan_unsafe`. It removes only the recorded package and never runs an autoremove. After the removal, it reads the version again. A Tool whose package is gone is deleted.

`apt` removes a package without purging its configuration files. The Gateway treats a package that dpkg lists as removed with only its configuration left as absent.

| Condition | Result | Tool record |
| --- | --- | --- |
| The package is already absent | Success, with no manager command | Deleted |
| The Tool failed with `tool.version_probe_failed` and never recorded a version | Success, with no probe | Deleted |
| The removal succeeds and the package is gone | Success | Deleted |
| The version probe fails on a Tool with a known package | `tool.version_probe_failed` | Kept as `failed` |
| The removal fails or the package stays | `tool.remove_failed` | Kept as `failed` |

Retry the same command with the Tool ID. The Gateway reads the live package state before it acts again.

## Errors

A Tool error carries a stable `code`, a message, and `details` with the `step`, the `outcome`, and the Tool `id` when a Tool exists. The Gateway never stores or returns the raw output of a package manager.

## Locks

Each operation locks its Tool and its manager's scope on the Node. A busy lock fails at once with `tool.operation_locked`. [Per-Node locks](/reference/node-provisioning#per-node-locks) lists every lock and its term.

## Check removal with Doctor

The `tool` family of [Doctor](/cli/doctor) compares each Tool record with the Node. A record whose package is absent reports `tool.not_installed`. A normalized version that violates the stored constraint reports `tool.version_mismatch`. An unconstrained Tool needs only to be installed; the recorded version is the last operation's result, not desired intent. The report never contains raw dpkg output.

## Why it works this way

These reasons explain the design. Check them before you propose a change.

### A closed manager registry

Each manager is code with its own grammar, fixed commands, and tests. So the Tool API cannot run an arbitrary command. Package plugins, per-Tool definitions, generic scripts, and caller-supplied options are rejected alternatives. A new manager needs a new adapter in code.

### Explicit package ownership

An installation alone does not prove Orbit ownership. Discovery shows what exists; installation or explicit adoption establishes what Orbit may manage. Automatic adoption would turn a read into permission to update or remove unrelated software. Tool rows therefore hold selected intent, not host inventory. Orbit removes only the exact package it recorded, without autoremove.

### Managers on demand, independent of roles

A manager serves any Node that the Gateway manages. Tying `vp` and `composer` to application roles would make Tools depend on where applications run, and role removal would have to handle unrelated Tools. Installing every manager on every Node is also rejected, because most Nodes need few of them and would carry needless software.

### Bottled Homebrew Core only

The formula manager accepts only Homebrew Core bottles with verified checksums. Casks use a separate macOS adapter with official metadata and supported artifacts. Taps, source builds, caller options, and arbitrary installers remain outside both contracts. Separate package identities avoid formula and cask collisions; a shared prefix lock prevents concurrent mutations of their common installation.
