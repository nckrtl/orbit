---
title: "CLI binaries"
description: "Artifact names, dest paths, builders, and download steps for the Orbit CLI toolbox binary."
---

# CLI binaries

Orbit Ops downloads a standalone `orbit` binary and copies it onto Nodes. This page is the artifact contract. It does not describe fleet rollout, node inventory, or upgrade orchestration.

The operator path for the source alpha remains a monorepo checkout. See [Install from source](/reference/installation). A binary does not replace Gateway source installation. [ADR 0079](/decisions/0079-publish-orbit-cli-binaries-from-github-actions) owns this split.

## When a binary is built

The `Orbit CLI Binary` workflow in `.github/workflows/orbit-cli-binary.yml` runs on every push and pull request that targets `main`, and on `workflow_dispatch`.

| Target | Host | Builder invocation | Artifact name | Upload path |
| --- | --- | --- | --- | --- |
| Linux x86_64 | Hosted `ubuntu-latest` | `bin/orbit-build-cli-binary linux x64 <version>` | `orbit-linux-x64` | `apps/cli/builds/dist/linux/linux-x64` |
| macOS Apple silicon | mini | `bin/orbit-build-cli-binary mac arm <version>` | `orbit-macos-arm64` | `apps/cli/builds/dist/mac/mac-arm` |

Each upload path is a single executable file, not a directory. The version string comes from `bin/orbit-version` and is `git describe --tags --always --dirty` for that checkout.

linux-x64 always builds on hosted GitHub Actions. macos-arm64 builds on mini only. Hosted `macos-*` runners do not build this target.

## mini, the macos-arm64 builder

mini is the Mac ARM node on the Orbit mesh.

| Field | Value |
| --- | --- |
| Node name | `mini` |
| WireGuard address | `10.44.0.9` |
| SSH user | `nckrtl` |

The GitHub Actions job that builds macos-arm64 uses `runs-on: [self-hosted, macOS, ARM64, mini]`. That job runs only when the repository variable `ORBIT_MINI_RUNNER` equals `true`. This repository does not register that runner or change the fleet.

To enable the job, install a GitHub Actions runner on mini, add the labels `self-hosted`, `macOS`, `ARM64`, and `mini`, and set `ORBIT_MINI_RUNNER` to `true`. Host PHP 8.5, Composer 2, rsync, and zlib must already be on that machine. The job does not install them.

Until the runner is online and the variable is set, GitHub Actions skips the macos-arm64 job. Build on mini over SSH from a host that can reach the mesh.

```bash
ssh nckrtl@10.44.0.9
```

On mini, in a checkout of the commit you want:

```bash
composer install --working-dir=apps/cli --no-interaction --prefer-dist
composer install --working-dir=apps/cli/phpacker --no-interaction --prefer-dist
bin/orbit-build-cli-binary mac arm
```

The dest file is `apps/cli/builds/dist/mac/mac-arm`. Orbit Ops owns how that file reaches a Node.

## Download an artifact

Open the `Orbit CLI Binary` workflow run for the `main` commit you want. Download the artifact that matches the Node operating system.

```bash
gh run download <run-id> --repo nckrtl/orbit --name orbit-linux-x64 --dir /tmp/orbit-cli
install -m 0755 /tmp/orbit-cli/linux-x64 /usr/local/bin/orbit
orbit --version
```

Replace `orbit-linux-x64` and `linux-x64` with `orbit-macos-arm64` and `mac-arm` when that artifact exists on the run. `gh run list --repo nckrtl/orbit --workflow "Orbit CLI Binary" --branch main` lists recent runs.

A pull-request run uses the same artifact names. Treat those binaries as packaging checks, not fleet releases.

## Local rebuild

From the monorepo root, install the CLI and the isolated PHPacker project, then build one target.

```bash
composer install --working-dir=apps/cli --no-interaction --prefer-dist
composer install --working-dir=apps/cli/phpacker --no-interaction --prefer-dist
bin/orbit-build-cli-binary linux x64
```

The builder stages `apps/cli` and `packages/php-sdk`, installs production Composer dependencies, writes `apps/cli/builds/orbit.phar`, and asks PHPacker for PHP 8.5. Host PHP must provide the zlib extension. The command requires Composer, PHP, rsync, and `apps/cli/phpacker/vendor/bin/phpacker`. PHPacker stays in that isolated project because it requires Symfony 7.

Build macos-arm64 on mini. Do not treat a Mach-O file produced on Linux as the contract binary.

## Limits

Orbit Ops owns how a downloaded file reaches a Node. This repository does not install the binary onto the fleet.

The binary talks to the Gateway over HTTPS. It does not open a local SQLite database or run on-node PDO queries.

Windows and linux-arm64 are outside this contract.
