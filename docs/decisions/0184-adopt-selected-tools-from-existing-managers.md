# ADR 0184: Adopt selected tools from existing managers

An explicit adoption operation lets Orbit own one supported package that is already installed. Discovery alone creates no Tool intent.

## Status

In progress.

Principle: [One way, one name](/mission#principles). The web app, CLI, and MCP use the same Gateway adoption operation and Tool identity.

## Context

[Tools](/reference/tools) belong to Orbit only when Orbit installed them. The Mini already has useful Homebrew and Vite+ packages. Removing and reinstalling those packages would change a working machine just to establish ownership.

Homebrew formulae and casks may use the same package name. They also share a prefix and dependencies. A Tool must identify the exact kind of package, and concurrent operations must not mutate the same manager scope independently.

## Decision

Add explicit, per-package adoption to the Gateway API, CLI, MCP, and web app. Input remains the Node, manager, package, and optional version constraint. Resolve the manager and verify package support, ownership of its installation scope, the live installed version, and the constraint under the operation locks before creating a Tool record. Adoption installs, updates, and removes nothing on the host.

The existing `brew` manager owns Homebrew Core formulae. A separate adapter, fixed in code, manages official Homebrew casks on macOS as `brew-cask`. They share the Homebrew prefix lock. Package identity remains Node, manager, and package, so a formula and cask with the same name stay distinct. `vp` owns the enrolled user's Vite+ global root packages.

Homebrew formula operations require compatible verified bottles. Cask operations use official metadata, a supported artifact and checksum, and fixed install, upgrade, and uninstall commands. Taps, URLs, local files, caller options, source builds, and cask zap are outside the input contract. An unsupported artifact or required interactive authorization is a reported failure, never a request for a coding agent to run an arbitrary installer.

Adoption uses existing managers without repinning or replacing them. It rejects an unsupported package, a protected package, an absent package, an unavailable or conflicting manager, a version constraint mismatch, or a busy operation. Protected apt names are every package `NodeBootstrapPackageCatalog` returns from `forNode` and `forRole`, plus `openssh-server` and `wireguard-tools`. That includes `docker.io` and `dnsmasq`. Protected Homebrew formulae are `wireguard-tools` and `wireguard-go`, and the Vite+ root is `pnpm`. Adopting one would let removal uninstall SSH, the tunnel, DNS, Docker, the firewall, sudo, Caddy, or a manager. Repeating adoption with the same intent is unchanged; different intent is a conflict. A `failed` row with the same constraint can be repaired to `installed` without changing the host package. A row left `installing`, `updating`, or `removing` is refused. Installation still refuses an existing unregistered package and directs the caller to adoption.

Once adopted, a package uses the manager's normal update and removal behavior. Updates target one registered root package; Homebrew may manage its required dependencies. Orbit never runs a bulk upgrade, autoremove, or a Homebrew service operation. Dependencies are identified in discovery and are never adopted automatically.

Discovery is bounded, read-only, and scoped to the enrolled account. It returns normalized package facts and scan status, including unsupported packages and dependencies. It stores no package inventory or new Tool rows. A partial or failed scan is not an empty successful scan. Doctor and the web app use the same inspector.

## Rejected alternatives

- Adopt every installed package: discovery does not express the user's selected ownership.
- Make installation silently adopt an existing package: installation and taking ownership are different user intentions.
- Store every discovered package as a Tool: Tool rows would mix desired intent and observations.
- Give formulae and casks the same identity: names can collide and operations differ.
- Give formulae and casks independent scope locks: both mutate the same Homebrew installation.
- Adopt every installed package, including tunnel and bootstrap packages: removal would then be able to break SSH, WireGuard, or a manager.

## Consequences

- Existing tools can move under Orbit one at a time without being reinstalled.
- Every supported adopted package has an update and removal path.
- Homebrew casks add an adapter and platform-specific artifact handling.
- Discovery and adoption are available to agents through MCP and to humans through the same API.

## Affects

- Components: apps/gateway, apps/cli, packages/php-sdk, apps/web
- ADRs: none
- Detail: [Tools: Discover installed packages](/reference/tools#discover-installed-packages), [Tools: Adopt a Tool](/reference/tools#adopt-a-tool)
- Verify: read-only discovery, exact package identity, adoption retries and failures, shared Homebrew locks, and formula, cask, and Vite+ lifecycle tests
