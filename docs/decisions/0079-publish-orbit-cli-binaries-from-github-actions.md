---
title: "ADR 0079: Publish Orbit CLI binaries from GitHub Actions"
sidebarTitle: "0079 Publish Orbit CLI binaries"
description: "Proposed. Build Orbit CLI binaries for linux-x64 and macos-arm64 on every main merge and pull request."
---

# ADR 0079: Publish Orbit CLI binaries from GitHub Actions

GitHub Actions builds standalone Orbit CLI binaries for linux-x64 and macos-arm64 on every push and pull request to `main`. Orbit Ops downloads those artifacts and owns fleet distribution. The source checkout remains the supported operator install for the source alpha.

## Status

Proposed.

## Context

Orbit Ops needs a toolbox binary on every Node. The monorepo CLI is a Laravel Zero source tree at `apps/cli` that depends on `packages/php-sdk` through a Composer path repository. A Node without PHP and Composer cannot run that checkout.

An earlier Orbit repository built a PHAR from a no-dev staging tree and wrapped it with PHPacker. This repository had only the quality-check workflow. Quality CI must stay independent of binary packaging so a packaging failure does not hide a test failure.

The published binary is a client. It calls the Gateway over HTTPS. It does not restore a local SQLite query path or PDO queries that run on a Node.

## Decision

- A dedicated `Orbit CLI Binary` workflow must run on every push and pull request that targets `main`.
- The workflow must build `linux` `x64` and `mac` `arm` binaries with host PHP 8.5 and PHPacker `--php=8.5`.
- The workflow must upload GitHub Actions artifacts named `orbit-linux-x64` and `orbit-macos-arm64` from `apps/cli/builds/dist/linux/linux-x64` and `apps/cli/builds/dist/mac/mac-arm`.
- `bin/orbit-build-cli-binary` must stage `apps/cli` and `packages/php-sdk`, install production Composer dependencies, build a PHAR with `apps/cli/box.json` GZ compression, and invoke the isolated PHPacker install. It must not stage a `packages/core` tree and must not compress the PHAR a second time.
- `phpacker/phpacker` ^0.6.4 must live in the isolated Composer project at `apps/cli/phpacker`. It must not join `apps/cli` require-dev, because PHPacker requires Symfony 7 and the CLI uses Symfony 8. The staged PHAR install must use `--no-dev`.
- Orbit Ops owns copying a downloaded binary onto Nodes. This decision does not define fleet install, node inventory, or upgrade rollout.
- The source-alpha install path in [Installation](/reference/installation) remains a monorepo checkout. A binary is an additional distribution, not a replacement for Gateway source installation.

## Rejected alternatives

- A job inside `.github/workflows/ci.yml`: packaging time and artifact lifecycle would mix with the required quality checks.
- A PHAR without PHPacker: a Node would still need a host PHP install to run the toolbox.
- Extra platforms in this change: Ops asked for linux-x64 and macos-arm64. More targets wait for an Ops request.
- PHPacker as an `apps/cli` require-dev package: Composer would downgrade the CLI from Symfony 8 to Symfony 7.
- Release-tag-only builds: a merge to `main` must produce a fresh downloadable pair without waiting for a GitHub release.

## Consequences

- Orbit Ops can download named artifacts from the `Orbit CLI Binary` run for a `main` commit.
- Pull requests exercise the same builder so a packaging break appears before merge.
- The quality workflow and the binary workflow fail independently.
- Contributors who change CLI or PHP SDK packaging must keep the artifact names and dest paths in the script, workflow, and [CLI binaries](/reference/cli-binaries) page aligned.

## Affects

- Components: apps/cli
- ADRs: none
- Detail: [CLI binaries](/reference/cli-binaries)
- Verify: `apps/cli` contract tests for the builder usage and artifact names; `bin/orbit-build-cli-binary linux x64` on a machine with PHP 8.5, Composer, rsync, and zlib
