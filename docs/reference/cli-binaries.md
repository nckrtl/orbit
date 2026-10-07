---
title: "CLI binaries"
description: "When the standalone orbit binary is built, the artifact names and paths, the builders, and how to download or rebuild one."
covers:
  - .github/workflows/orbit-cli-binary.yml
  - bin/orbit-build-cli-binary
  - bin/orbit-version
  - apps/cli/box.json
  - apps/cli/phpacker/**
---

# CLI binaries

The standalone `orbit` binary runs the CLI on a machine without PHP or Composer. It is a client: it calls the Gateway over HTTPS, and it never opens a local SQLite database or queries a database on a Node. The Gateway itself installs from a monorepo checkout, as the [Quickstart](/quickstart) shows. This page describes the artifacts. It does not describe how a binary reaches a Node.

## When a binary is built

The `Orbit CLI Binary` workflow, `.github/workflows/orbit-cli-binary.yml`, runs on every push and pull request to `main`, and on `workflow_dispatch`.

| Target | Host | Builder | Artifact | File |
| --- | --- | --- | --- | --- |
| Linux x86_64 | Hosted `ubuntu-26.04` | `bin/orbit-build-cli-binary linux x64 <version>` | `orbit-linux-x64` | `apps/cli/builds/dist/linux/linux-x64` |
| macOS Apple silicon | mini | `bin/orbit-build-cli-binary mac arm <version>` | `orbit-macos-arm64` | `apps/cli/builds/dist/mac/mac-arm` |

Each artifact is one executable file. The version comes from `bin/orbit-version`, which runs `git describe --tags --always --dirty`. A pull-request run uses the same artifact names. Treat those binaries as packaging checks.

## mini, the macOS builder

mini is the Mac ARM Node on the Orbit network: Node name `mini`, WireGuard address `10.44.0.9`, SSH user `nckrtl`. Hosted `macos-*` runners never build this target.

The macOS job uses `runs-on: [self-hosted, macOS, ARM64, mini]` and runs only when the repository variable `ORBIT_MINI_RUNNER` is `true`. To enable it, install a GitHub Actions runner on mini with those labels and set the variable. mini must already have PHP 8.5, Composer 2, rsync, and zlib.

Until then, GitHub Actions skips the job. Build on mini over SSH instead, in a checkout of the commit you want:

```bash
ssh nckrtl@10.44.0.9
composer install --working-dir=apps/cli --no-interaction --prefer-dist
composer install --working-dir=apps/cli/phpacker --no-interaction --prefer-dist
bin/orbit-build-cli-binary mac arm
```

The result is `apps/cli/builds/dist/mac/mac-arm`.

## Download an artifact

Open the `Orbit CLI Binary` run for the `main` commit you want, and download the artifact for the machine's operating system.

```bash
gh run list --repo nckrtl/orbit --workflow "Orbit CLI Binary" --branch main
gh run download <run-id> --repo nckrtl/orbit --name orbit-linux-x64 --dir /tmp/orbit-cli
install -m 0755 /tmp/orbit-cli/linux-x64 /usr/local/bin/orbit
orbit --version
```

For macOS, use `orbit-macos-arm64` and `mac-arm`, when the run has that artifact.

## Build locally

From the repository root, install the CLI and the separate PHPacker project, then build one target.

```bash
composer install --working-dir=apps/cli --no-interaction --prefer-dist
composer install --working-dir=apps/cli/phpacker --no-interaction --prefer-dist
bin/orbit-build-cli-binary linux x64
```

The builder needs PHP with zlib, Composer, rsync, and `apps/cli/phpacker/vendor/bin/phpacker`. It copies `apps/cli` and `packages/php-sdk` to a temporary directory and installs the production dependencies with `--no-dev`. It builds `apps/cli/builds/orbit.phar` with GZ compression from `apps/cli/box.json`, and then asks PHPacker for a PHP 8.5 binary. PHPacker reads the `php-bin` release list from GitHub. The builder passes `GITHUB_TOKEN` or `GH_TOKEN` to it and tries the build up to four times.

The CLI requires `illuminate/http` as a production dependency and binds `Illuminate\Http\Client\Factory` itself. `realtime:tail` uses Laravel's HTTP client to authorize the Reverb channel after the WebSocket connects. A binary without that package fails there with `Target class [Illuminate\Http\Client\Factory] does not exist`.

## Limits

The binary contract has these limits.

- Only Linux x86_64 and macOS Apple silicon are built. Windows and Linux ARM are not.
- Orbit does not install the binary on Nodes, track which Node runs which version, or roll out upgrades.

[ADR 0202](/decisions/0202-the-fleet-follows-the-gateway-through-orbit-self-update) lifts the last limit. CI publishes the binaries of each green `main` commit as a GitHub release, the Gateway installs the CLI on managed Nodes, and `orbit self-update` updates a machine to the Gateway's release.

## Why it works this way

These reasons explain the design. Check them before you propose a change.

### A separate workflow

Packaging time and artifacts stay out of the quality checks in `ci.yml`, so a packaging failure never hides a test failure, and the reverse.

### Every push, not only tags

A merge to `main` gives a fresh Linux binary right away, without waiting for a release.

### PHPacker in its own project

PHPacker requires Symfony 7, and the CLI uses Symfony 8. As a dev dependency of the CLI, it would force a downgrade. A PHAR alone would still need PHP on the machine.

### A native macOS build on mini

PHPacker can write a Mach-O file on Linux, but only a build on Apple silicon is the native binary. The runner variable keeps the job from waiting forever for a runner that is not there. A hosted job never connects into the Orbit network.
