---
title: "CLI binaries"
description: "Published Orbit CLI releases for every green main commit: versions, tags, assets, checksums, how to find and install one, the pull-request builds, and how to build locally."
covers:
  - .github/workflows/orbit-cli-binary.yml
  - .github/workflows/orbit-cli-release.yml
  - bin/orbit-build-cli-binary
  - bin/orbit-cli-release-assets
  - bin/orbit-cli-release-version
  - bin/orbit-version
  - apps/cli/box.json
  - apps/cli/phpacker/**
---

# CLI binaries

The standalone `orbit` binary runs the CLI on a machine without PHP or Composer. It is a client: it calls the Gateway over HTTPS, and it never opens a local SQLite database or queries a database on a Node. The Gateway itself installs from a monorepo checkout, as the [Quickstart](/quickstart) shows. This page describes the published releases and the builds. It does not describe how a binary reaches a Node.

## Published releases

Every `main` commit whose `Required checks` passed gets a GitHub release on [nckrtl/orbit](https://github.com/nckrtl/orbit/releases). The `Orbit CLI Release` workflow, `.github/workflows/orbit-cli-release.yml`, starts when a `CI` run on `main` succeeds, from a push or a manual dispatch. A pull-request run never starts it. It confirms that the commit is on the first-parent history of `main` and that its latest `Required checks` run is `success`, then builds and publishes. A commit whose CI fails or is cancelled gets no release. The release appears a few minutes after `Required checks` turns green. In rare cases GitHub refuses the release of a green commit ([Commits without a release](#commits-without-a-release)).

Releases are public, need no login, and do not expire. A published release is never replaced.

### Version and tag

Each release has one version, derived from its commit, and a tag that names it.

| Item | Format | Example |
| --- | --- | --- |
| Release number `N` | `git rev-list --count <commit>` | `4681` |
| Version | `0.N.0` | `0.4681.0` |
| Tag | `cli-v0.N.0`, a lightweight tag on the commit | `cli-v0.4681.0` |
| Title | `orbit 0.N.0` | `orbit 0.4681.0` |

`N` counts every commit that the commit reaches, itself included. `main` only moves forward, so each later `main` commit has a larger `N`. Compare releases by `N`, not by tag text or release date. Release numbers have gaps, because only green commits are published. Only a commit on the first-parent history of `main` is a release commit. A side commit brought in by a merge can reach the same count as a different `main` commit, so it is not given a version.

A released binary reports its version: `orbit --version` prints `Orbit 0.4681.0`. The build passes the version to Laravel Zero's `app:build --build-version`, which stores it in the PHAR. The workflow runs each binary on its own platform and requires that output. Only a release has a version that matches `^0\.[1-9][0-9]*\.0$`. Other builds report a tag-prefixed or hex version that never matches. A pull-request build reports `git describe --tags --always --dirty` from a checkout without tags, so it prints the short commit hash, such as `Orbit 60bccef`. A source checkout reports its nearest tag of any kind, `git describe --tags --abbrev=0`, such as `cli-v0.4681.0`.

### Assets

Each release has one binary per supported platform and a checksum file.

| Asset | Platform | Built on | Run on |
| --- | --- | --- | --- |
| `orbit-0.N.0-linux-x86_64` | Linux x86_64 | Hosted `ubuntu-26.04` | The same runner |
| `orbit-0.N.0-linux-aarch64` | Linux arm64 | Hosted `ubuntu-26.04-arm` | The same runner |
| `orbit-0.N.0-macos-arm64` | macOS on Apple silicon | Hosted `ubuntu-26.04` | Hosted `macos-26` |
| `SHA256SUMS` | Checksums of the three binaries | | |

Each binary is one executable file with a static PHP 8.5 inside. The Linux names follow `uname -m`, as the `orbit-agent` release does. `SHA256SUMS` has the `sha256sum` format: one line per binary, sorted by name, with the lowercase hex digest, two spaces, and the asset name.

### Release contract for clients

The Gateway, a Node installer, and `orbit self-update` rely on these rules. They do not change without a new ADR.

| Item | Rule |
| --- | --- |
| Tag for commit `C` | `cli-v0.N.0`, where `N` is `git rev-list --count C` |
| What `N` counts | Every commit that `C` reaches, not only first-parent commits. A shallow clone gives a wrong `N` |
| Asset URL | `https://github.com/nckrtl/orbit/releases/download/cli-v<version>/orbit-<version>-<platform>` |
| Platforms | `linux-x86_64`, `linux-aarch64`, `macos-arm64` |
| Checksums URL | `https://github.com/nckrtl/orbit/releases/download/cli-v<version>/SHA256SUMS` |
| Commits with a release | Commits on the first-parent history of `main` with a successful `Required checks` run. A side commit never has one |
| Immutability | A published asset never changes. The tag points at `C`, and the release appears with all four assets at once |

`bin/orbit-cli-release-version --tag C` prints the tag and refuses a shallow clone.

A release appears after `CI` completes and the release workflow builds and publishes it. That is usually a few minutes after `Required checks` turns green. The Gateway can deploy a commit before its CLI release exists, and until then every URL above returns 404. A client treats a 404 as "not published yet" and checks again later. It does not install a different version instead. Only the Gateway can name another release: when a commit's release stays missing for 30 minutes, its desired fleet state [falls back](/reference/self-update#fallback-to-an-ancestor-release) to the newest published release of an ancestor commit.

### Find the release for a commit

From a full checkout, print the version or the tag. The script refuses a shallow clone, because its count is wrong.

```bash
bin/orbit-cli-release-version <commit>        # 0.4681.0
bin/orbit-cli-release-version --tag <commit>  # cli-v0.4681.0
```

The tag points at the commit. `https://api.github.com/repos/nckrtl/orbit/git/ref/tags/cli-v0.4681.0` returns the commit SHA as `object.sha`. A 404 means the commit has no release yet, or never will because its checks did not pass.

To find the newest release without a checkout:

```bash
git ls-remote --tags --refs https://github.com/nckrtl/orbit 'cli-v*' \
  | sed 's#.*/cli-v##' | sort -t. -k2,2n | tail -n 1
```

CLI releases are created with `--latest=false`. The repository's **Latest** release is not the newest CLI, so do not use `releases/latest`.

### Install a release

Download the binary and `SHA256SUMS`, check the digest, and install the file.

```bash
version=0.4681.0
asset="orbit-${version}-linux-x86_64"
base="https://github.com/nckrtl/orbit/releases/download/cli-v${version}"
curl -fsSL -O "${base}/${asset}" -O "${base}/SHA256SUMS"
grep "  ${asset}\$" SHA256SUMS | sha256sum --check
sudo install -m 0755 "$asset" /usr/local/bin/orbit
orbit --version
```

On a Mac, use `orbit-${version}-macos-arm64` and `shasum -a 256 --check` instead of `sha256sum --check`.

### Update an installed binary

`orbit self-update` replaces an installed binary with the CLI release of the active Gateway's commit. It checks the SHA-256 against the Gateway and the release `SHA256SUMS`, runs the new binary once, and swaps it in with one rename. It refuses a downgrade unless `--allow-downgrade` is passed, and it leaves a source checkout alone. On a managed Linux Node it also brings `orbit-agent` to the Gateway's pin. Use `sudo` when the binary lives in a directory only root may write. [Orbit self-update](/reference/self-update) describes each step.

```bash
sudo orbit self-update
```

When the Gateway's fleet runs a newer release, other commands print a notice to run `orbit self-update`, at most once a day.

### Publish a missed commit

When a green `main` commit has no release, for example because the workflow failed on a GitHub outage, run `Orbit CLI Release` with `workflow_dispatch` on `main` and pass the full commit SHA. A dispatch from another branch does nothing. The run applies the same checks, including the first-parent history check.

The build uses the commit's own builder, so a commit from before CLI releases existed cannot be published: its builder has no Linux arm64 target. When the release already exists for that commit with every asset, the run changes nothing. When a tag or release for that version points elsewhere or lacks assets, the run fails and replaces nothing. An unfinished draft from an earlier run is deleted and rebuilt.

### Commits without a release

GitHub refuses to create a tag with the workflow's `GITHUB_TOKEN` when the tagged commit's `.github/workflows` files differ from the tip of `main`. GitHub treats that tag as a workflow change, which needs the `workflows` permission, and a job token cannot have it. The release API, the Git references API, and `git push` all answer `HTTP 403: Resource not accessible by integration`.

So a green commit gets no release when both of these are true:

- A newer commit reached `main` before the release workflow published. A rerun of the commit's CI makes that likely.
- The commits between them changed a file under `.github/workflows`.

The publish step then fails with the error `Release refused for a non-tip commit`. A `workflow_dispatch` run for that commit fails in the same way. The Gateway does not wait for such a release forever: its desired fleet state [falls back](/reference/self-update#fallback-to-an-ancestor-release) to the newest published release of an ancestor commit, and the next commit's release replaces it.

## Pull-request builds

The `Orbit CLI Binary` workflow, `.github/workflows/orbit-cli-binary.yml`, builds the three targets on every pull request to `main` and on `workflow_dispatch`. The release workflow calls the same workflow for the commit it publishes. Pull-request builds are packaging checks. They are not part of `Required checks`.

| Target | Build runner | Run runner | Builder | Artifact | File |
| --- | --- | --- | --- | --- | --- |
| Linux x86_64 | `ubuntu-26.04` | The same | `bin/orbit-build-cli-binary linux x64 <version>` | `orbit-linux-x64` | `apps/cli/builds/dist/linux/linux-x64` |
| Linux arm64 | `ubuntu-26.04-arm` | The same | `bin/orbit-build-cli-binary linux arm <version>` | `orbit-linux-arm64` | `apps/cli/builds/dist/linux/linux-arm` |
| macOS Apple silicon | `ubuntu-26.04` | `macos-26` | `bin/orbit-build-cli-binary mac arm <version>` | `orbit-macos-arm64` | `apps/cli/builds/dist/mac/mac-arm` |

The `Build` jobs build every target on Linux. Each Linux job then runs its own binary. The `Run macos-arm64` job downloads the macOS artifact on a hosted Mac and runs it. It needs no PHP, Composer, or checkout. Every run uses an empty environment and requires `orbit --version` to print the build version. A binary that does not start fails the workflow, and the release workflow publishes only when every job passes.

## Build locally

From the repository root, install the CLI and the separate PHPacker project, then build one target.

```bash
composer install --working-dir=apps/cli --no-interaction --prefer-dist
composer install --working-dir=apps/cli/phpacker --no-interaction --prefer-dist
bin/orbit-build-cli-binary linux x64
```

The builder needs PHP with zlib, Composer, rsync, and `apps/cli/phpacker/vendor/bin/phpacker`. It copies `apps/cli` and `packages/php-sdk` to a temporary directory and installs the production dependencies with `--no-dev`. It builds `apps/cli/builds/orbit.phar` with GZ compression from `apps/cli/box.json`. PHPacker then downloads a prebuilt static PHP 8.5 for the target and appends the PHAR to it. PHPacker reads the `php-bin` release list from GitHub. The builder passes `GITHUB_TOKEN` or `GH_TOKEN` to it and tries the build up to four times.

`bin/orbit-cli-release-assets <version> <artifact-dir> <out-dir>` turns the three downloaded artifacts into the release assets and `SHA256SUMS`. It refuses a version that is not `0.N.0`, a missing or empty binary, and a binary built for another platform.

The CLI requires `illuminate/http` as a production dependency and binds `Illuminate\Http\Client\Factory` itself. `realtime:tail` uses Laravel's HTTP client to authorize the Reverb channel after the WebSocket connects. A binary without that package fails there with `Target class [Illuminate\Http\Client\Factory] does not exist`.

## Limits

The binary contract has these limits.

- Only Linux x86_64, Linux arm64, and macOS on Apple silicon are built. Windows and Intel Macs are not.
- The binaries are not notarized. The macOS binary keeps the ad-hoc signature of PHPacker's PHP build. A download with `curl` gets no quarantine flag and runs; a browser download needs `xattr -d com.apple.quarantine`.
- `SHA256SUMS` comes from the same release, so it detects a damaged download, not a forged release.
- A green commit can miss its release ([Commits without a release](#commits-without-a-release)).
- The Gateway installs the binary only on the Nodes of the [fleet rollout set](/reference/gateway-recovery#rollout-set-and-order), as `/usr/local/bin/orbit-<version>` behind the `/usr/local/bin/orbit` link that `orbit self-update` also switches ([Orbit CLI](/reference/node-provisioning#orbit-cli)). Operator machines update themselves with `orbit self-update`.

## Why it works this way

These reasons explain the design. Check them before you propose a change.

### Separate workflows

Packaging time and artifacts stay out of the quality checks in `ci.yml`, so a packaging failure never hides a test failure, and the reverse. The release workflow waits for the `CI` run instead of joining it, so it can publish only what `Required checks` accepted. Both release paths reuse one build workflow, so a pull request tests the same steps that publish a release.

The release workflow runs from `main` with a token that can write releases. Only the publish job holds that token, and it runs only the workflow's own scripts from `main`. The build jobs run the release commit's code with a read-only token. A pull request's code never runs in the release workflow.

### Releases for green main commits

A Node or an updater needs a binary that is always there and needs no login. Workflow artifacts expire and need a GitHub login. A release for every green commit gives each commit that can reach production a matching binary, without a manual tagging step. Commits that failed their checks never get one.

### A fallback instead of a stronger token

Only a token with the `workflows` permission can tag a commit whose workflow files differ from the tip of `main`. The job token cannot have that permission. A GitHub App or personal token with it could also change workflows on `main`, so the release job would hold far more than it needs. The refused case is rare and the next release fixes it, so the Gateway falls back to an ancestor's release instead.

### A version from the commit count

An updater must refuse downgrades, so versions need a total order. `git rev-list --count` gives that order on `main` and maps each version to exactly one commit, with no counter to store. The order holds because the `main` ruleset refuses force pushes, so every later `main` commit descends from every earlier one. A workflow run number would also be ordered, but a re-run or a missed run would break the link to the commit. A version from the commit date is not ordered, because commit dates can go backwards.

The release workflow accepts only that first-parent history. A side commit merged into `main` is not in that line, and it can share a count with a `main` commit.

### Immutable releases that never take Latest

A client that verified a checksum must get the same file later, so a published release is never re-uploaded. `gh release create` uploads to a draft and publishes it last, so the tag and the release appear only with every asset. The repository's single **Latest** marker would jump between CLI and `orbit-agent` releases, and an older commit can finish its checks after a newer one. Clients find a CLI release by its tag instead.

### Build on Linux, run on each platform

PHPacker does not compile. It appends the PHAR to a prebuilt static PHP binary for the target, so any host writes the same file for a target. A Linux runner therefore builds the macOS binary too. What a native runner adds is the proof that the binary starts on its platform. Linux arm64 builds on its own runner, so one job builds and runs it.

The macOS job only runs the binary, so it needs no PHP. In October 2026 `shivammathur/setup-php` could not install PHP 8.5 on `macos-26`. That one failed job blocked every CLI release, and the fleet rollout waited for a Linux binary that was never published. Now the Mac needs nothing that a hosted runner does not already have. The release still waits for the macOS run, so it never publishes a macOS binary that does not start.

The repository is public, so hosted macOS and Linux arm64 runners cost nothing. An earlier design built macOS on mini, a Mac Node on the Orbit network, through a self-hosted runner. That runner was not registered, so CI skipped the macOS job and a macOS binary needed a manual build over SSH. A hosted job also never needs a path into the Orbit network.

### Linux arm64

Managed Linux Nodes can be arm64; the `orbit-agent` release already ships for both. Every managed Node that runs the CLI needs a binary for its architecture.

### PHPacker in its own project

PHPacker requires Symfony 7, and the CLI uses Symfony 8. As a dev dependency of the CLI, it would force a downgrade. A PHAR alone would still need PHP on the machine.
