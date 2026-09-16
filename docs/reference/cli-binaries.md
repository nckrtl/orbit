---
title: "CLI binaries"
description: "GitHub Actions artifact names, dest paths, and download steps for the Orbit CLI toolbox binary."
---

# CLI binaries

Orbit Ops downloads a standalone `orbit` binary from GitHub Actions and copies it onto Nodes. This page is the artifact contract. It does not describe fleet rollout, node inventory, or upgrade orchestration.

The operator path for the source alpha remains a monorepo checkout. See [Install from source](/reference/installation). A binary does not replace Gateway source installation. [ADR 0079](/decisions/0079-publish-orbit-cli-binaries-from-github-actions) owns this split.

## When GitHub builds a binary

The `Orbit CLI Binary` workflow in `.github/workflows/orbit-cli-binary.yml` runs on every push and pull request that targets `main`. Each run builds both supported targets from the same commit.

| Target | Builder invocation | Artifact name | Upload path |
| --- | --- | --- | --- |
| Linux x86_64 | `bin/orbit-build-cli-binary linux x64 <version>` | `orbit-linux-x64` | `apps/cli/builds/dist/linux/linux-x64` |
| macOS Apple silicon | `bin/orbit-build-cli-binary mac arm <version>` | `orbit-macos-arm64` | `apps/cli/builds/dist/mac/mac-arm` |

Each upload path is a single executable file, not a directory. The version string comes from `bin/orbit-version` and is `git describe --tags --always --dirty` for that checkout.

## Download an artifact

Open the `Orbit CLI Binary` workflow run for the `main` commit you want. Download the artifact that matches the Node operating system.

```bash
gh run download <run-id> --repo nckrtl/orbit --name orbit-linux-x64 --dir /tmp/orbit-cli
install -m 0755 /tmp/orbit-cli/linux-x64 /usr/local/bin/orbit
orbit --version
```

Replace `orbit-linux-x64` and `linux-x64` with `orbit-macos-arm64` and `mac-arm` on Apple silicon. `gh run list --repo nckrtl/orbit --workflow "Orbit CLI Binary" --branch main` lists recent runs.

A pull-request run uses the same artifact names. Treat those binaries as packaging checks, not fleet releases.

## Local rebuild

From the monorepo root, install the CLI and the isolated PHPacker project, then build one target.

```bash
composer install --working-dir=apps/cli --no-interaction --prefer-dist
composer install --working-dir=apps/cli/phpacker --no-interaction --prefer-dist
bin/orbit-build-cli-binary linux x64
```

The builder stages `apps/cli` and `packages/php-sdk`, installs production Composer dependencies, writes `apps/cli/builds/orbit.phar`, and asks PHPacker for PHP 8.5. Host PHP must provide the zlib extension. The command requires Composer, PHP, rsync, and `apps/cli/phpacker/vendor/bin/phpacker`. PHPacker stays in that isolated project because it requires Symfony 7.

## Limits

Orbit Ops owns how a downloaded file reaches a Node. This repository does not install the binary onto the fleet.

The binary talks to the Gateway over HTTPS. It does not open a local SQLite database or run on-node PDO queries.

Windows and linux-arm64 are outside this contract.
