---
title: "Tools"
description: "Where Tools run, the four Tool Managers, how Orbit installs, updates, and removes one package, and why the contract stays closed."
covers:
  - apps/gateway/app/Actions/Tools/**
  - apps/gateway/app/Domain/Tools/**
  - apps/gateway/app/Infrastructure/Tools/**
  - apps/gateway/app/Models/{Tool,ToolManagerRecord}.php
  - apps/gateway/app/Data/Tools/**
  - apps/gateway/app/Http/Controllers/Api/{ToolsController,ToolManagersController}.php
  - apps/gateway/app/Http/Requests/Tools/**
  - apps/gateway/app/Actions/Doctor/ToolDoctorProbe.php
---

# Tools

A Tool is one package that Orbit manages on one Node through one Tool Manager. The Node, the manager, and the package name identify it. Orbit records only the Tools it installed. It never scans a Node for packages and never adopts one. [`tool`](/cli/tool) lists the commands.

## Where Tools run

The Gateway manages Tools only on an active Node that it manages over SSH. That Node runs Linux, has a WireGuard address, and has a stored SSH host fingerprint. Any other Node gets `tool.node_inactive` or `tool.node_unmanaged` (HTTP 409) before the Gateway changes anything.

Tool Managers do not belong to roles. A Node with no role can use every manager.

## Tool Managers

The Gateway has four managers, fixed in code.

| Manager | Package | Scope on the Node | Installed |
| --- | --- | --- | --- |
| `apt` | An Ubuntu package name | The Node's package database | When `node:add` converges the Node |
| `vp` | An npm package name, such as `@openai/codex` | Orbit's shared Vite+ global scope | On first use, or when `app-dev` or `app-prod` converges |
| `composer` | A `vendor/package` name | Orbit's shared Composer global scope | On first use, or when `app-dev` or `app-prod` converges |
| `brew` | One Homebrew Core formula name | The Homebrew prefix `/home/linuxbrew/.linuxbrew` | On first use |

`orbit tool:manager:list --node=<id>` shows each manager with one of these states.

| State | Meaning |
| --- | --- |
| `uninstalled` | The Node supports the manager, but Orbit has not installed it yet. It has no ID. |
| `provisioning` | Orbit installs or checks the manager. |
| `active` | The manager is ready. |
| `failed` | Installing or checking the manager failed. The record keeps the failed step and error code. The next install tries again. |

Before each install, the Gateway converges the manager: it installs the manager when it is missing and checks its version. A failure returns `tool.manager_provision_failed` (HTTP 502), keeps the manager `failed`, and creates no Tool. A manager stays installed after its last Tool is removed. No command removes a manager.

## Install a Tool

A caller sends only the Node, the manager, the package name, and an optional version constraint. Each manager checks the package name against its own grammar and builds a fixed command with the name in one argument position. A caller cannot send commands, options, repositories, or environment values.

The Gateway refuses a package that is already on the Node without a Tool record, with `tool.already_installed_unmanaged` (HTTP 409). It never takes over a package that someone else installed.

A new install creates the Tool as `installing`, installs the package, and reads the installed version. A success marks the Tool `installed` and reports `applied`. A failure after the Tool exists marks it `failed` and returns its ID in the error, so you can retry or remove it. Running the same install again retries a Tool whose install failed. An install of a Tool that is already `installed`, with the same constraint, checks the package again. When the package is present, the result is `unchanged`.

The optional constraint is a SemVer range, such as `^0.150`. It only stops an unsafe version. Before an install, the Gateway reads the manager's candidate version. A candidate outside the range fails with `tool.version_constraint_blocked`, and the Gateway installs nothing. The Gateway never searches for another matching version and never downgrades. When a manager's version cannot be read as SemVer, a constrained install fails. A Tool keeps its constraint: installing it again with another constraint fails with `tool.constraint_conflict`.

## Update a Tool

`tool:update` asks the manager for its current candidate and installs it. The result is `applied` when the version changed and `unchanged` when it did not. When the candidate falls outside the stored constraint, the update changes nothing and reports `blocked_by_constraint`. The Tool stays installed.

## Homebrew formulae

The `brew` manager accepts one lowercase formula name from Homebrew Core, without a tap prefix. Before an install or update, the Gateway reads the formula's metadata. It requires the `homebrew/core` tap, a stable version, and a bottle for the Node's architecture with a SHA-256 checksum. Homebrew verifies that bottle when it installs it with `--force-bottle`. Orbit never builds a formula from source.

| Input | Result |
| --- | --- |
| A Homebrew Core formula with a Linux bottle for the Node | Accepted |
| A tap-qualified name, a cask, a URL, a local file, or a Git reference | Refused before any change |
| A formula without a matching bottle | Refused before any change |

The Gateway installs Homebrew at a pinned revision. When the prefix already exists, the Gateway accepts it only when the managed user owns it, its origin is the official Homebrew repository, and its working tree is clean. It then checks out the pinned revision. Otherwise it leaves the prefix unchanged and marks the manager `failed`. Tool operations never start or stop a Homebrew service.

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

The `tool` family of [Doctor](/cli/doctor) compares each Tool record with the Node. A record whose package is absent reports `tool.not_installed`. A version that differs from the record reports `tool.version_mismatch`. The report never contains raw dpkg output.

## Why it works this way

These reasons explain the design. Check them before you propose a change.

### A closed manager registry

Each manager is code with its own grammar, fixed commands, and tests. So the Tool API cannot run an arbitrary command. Package plugins, per-Tool definitions, generic scripts, and caller-supplied options are rejected alternatives. A new manager needs a new adapter in code.

### Only Tools that Orbit installed

A package on a Node does not prove that Orbit owns it. Adopting a package would let Orbit update or remove software that another actor installed. So Orbit refuses such a package and removes only the exact package it recorded, without autoremove.

### Managers on demand, independent of roles

A manager serves any Node that the Gateway manages. Tying `vp` and `composer` to application roles would make Tools depend on where applications run, and role removal would have to handle unrelated Tools. Installing every manager on every Node is also rejected, because most Nodes need few of them and would carry needless software.

### Bottled Homebrew Core only

Taps, casks, and source builds let a caller choose code that runs on the Node. A bottle from Homebrew Core with a verified checksum keeps the software source fixed. The cost is a smaller package set than Homebrew offers.
