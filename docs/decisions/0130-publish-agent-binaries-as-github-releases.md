---
title: "ADR 0130: Publish agent binaries as GitHub releases"
sidebarTitle: "0130 Publish agent binaries as GitHub releases"
description: "Proposed. A tag named agent-v{version} builds static x86_64 and aarch64 agent binaries and publishes them with a checksum file as a GitHub release. The Gateway pins the version and checksums, and Nodes download the assets without credentials."
---

# ADR 0130: Publish agent binaries as GitHub releases

Pushing a tag named `agent-v{version}` runs a GitHub Actions job that builds static Linux binaries of `orbit-agent` for `x86_64` and `aarch64`. The job publishes them, with a `SHA256SUMS` file, as a GitHub release of that tag. The Gateway pins the version and both checksums in code. Nodes download the pinned asset from the release, without credentials.

## Status

Proposed.

This departs from [ADR 0079](/decisions/0079-publish-orbit-cli-binaries-from-github-actions) for the agent only. CLI binaries stay workflow artifacts.

## Context

[ADR 0128](/decisions/0128-run-a-visibility-only-agent-on-managed-nodes) installs the agent on every managed Node over SSH, with a pinned version and checksum. Each Node downloads the binary itself, as it downloads cAdvisor from its GitHub release.

ADR 0079 publishes CLI binaries only as GitHub Actions artifacts. An artifact download needs a GitHub login and expires after the retention period, so a Node cannot fetch a pinned artifact once it expires. The `nckrtl/orbit` repository is public, so a release asset has a permanent URL that any Node can fetch.

The agent must run on Ubuntu Nodes of either architecture without depending on the Node's C library.

## Decision

- A tag `agent-v{version}`, created with the GitHub CLI, releases the agent. `{version}` must equal the version in `apps/agent/Cargo.toml`, or the release job fails before it builds.
- The release job builds static musl binaries for `x86_64-unknown-linux-musl` and `aarch64-unknown-linux-musl` and publishes three assets: `orbit-agent-{version}-linux-x86_64`, `orbit-agent-{version}-linux-aarch64`, and `SHA256SUMS`.
- The release job refuses to replace the assets of an existing release. A published version never changes.
- Pull requests and pushes to `main` build and test the agent in CI, but do not publish.
- The Gateway pins the agent version and one SHA-256 checksum for each architecture in code. A Node downloads the asset for its recorded architecture and installs it only when the checksum matches.
- Upgrading the fleet means: tag a new version, update the Gateway pin in a pull request, deploy the Gateway, and converge each Node.

## Rejected alternatives

- Workflow artifacts, as ADR 0079 uses for the CLI: rejected because they need a GitHub login and expire.
- Build on the Gateway or on each Node: rejected because it puts a Rust toolchain on production hosts and makes builds depend on each host.
- Copy the binary from the Gateway to each Node over SSH: rejected because the Gateway would then have to store and serve binaries. Nodes already download cAdvisor directly.
- Resolve the latest release at install time: rejected because an unpinned version lets a new release reach Nodes without review.

## Consequences

- Any Node with internet access can install a pinned agent, and a reviewed Gateway change is the only way a new version reaches the fleet.
- The first agent release must exist before the Gateway pin can reference it, so its tag points at a feature-branch commit that is already on GitHub.
- The repository gains a Rust CI job and a release workflow.

## Affects

- Components: apps/gateway, apps/docs
- ADRs: departs from [ADR 0079](/decisions/0079-publish-orbit-cli-binaries-from-github-actions) for agent binaries only; supplies the release that [ADR 0128](/decisions/0128-run-a-visibility-only-agent-on-managed-nodes) installs
- Detail: [Node agent](/reference/node-agent#releases)
- Verify: the agent CI job; the release workflow's version and immutability checks; Gateway tests for the pinned download URL and checksum
