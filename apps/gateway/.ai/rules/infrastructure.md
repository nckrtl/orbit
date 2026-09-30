---
paths:
  - 'app/Infrastructure/**'
---

# Infrastructure

## Use fixed typed argv
Build remote operations from fixed, typed argv in narrow infrastructure contracts. Never add a generic executor, arbitrary script endpoint, Agent, or caller-supplied shell program.

## Preserve an existing healthy Docker CE prerequisite
Install Ubuntu `docker.io` as the fixed default for app-role hosts. When the
fixed `docker-ce`, `docker-ce-cli`, and `containerd.io` packages, the fixed
Docker executable, and the Docker service are already healthy, treat that
stack only as a satisfied private host prerequisite. Do not create Tool intent,
change its repositories or packages, or adopt it for public removal. Run role
prerequisite APT installs with removal disabled.

## Keep secrets out of command arguments
Transport secrets through stdin or narrowly scoped mode-0600 protected files. Secret bytes must never enter local or remote argv, ProcessInvocation state, exception or debug text, API responses, or activity data.

## Publish managed state atomically
Require exact Orbit ownership before mutation. For shared state, lock first, snapshot after locking, write a candidate, validate it, switch atomically, and restore the exact prior file or symlink plus service state when activation fails. Keep an explicit recovery path.

## Search proven behavior before infrastructure design
Search matching repository implementations and tests before inventing infrastructure behavior. The legacy project is optional research, not a checkout dependency. Port proven invariants when compatible, but never port the retired Agent, Docker or Swarm gateway, operation topology, generic executors, Compose, FrankenPHP, or image-building architecture.

## Keep node access binary
Enforce binary directed node access at the HTTP boundary. One access edge permits all commands for its serving node. The active Gateway peer is implicit authority, and access to the Gateway is fleet-wide. Do not add granular permissions, presets, wildcards, or permission compatibility code.

## Use only pinned Sury PHP packages
Require Ubuntu 26.04 Resolute for every managed node role before remote mutation. Report unsupported systems as `Node operating system [id/codename] is not supported.` only after strict parsing. Use `unknown` for missing, malformed, or untrusted values, and never name retired releases in repository text. Use the direct Sury PHP repository with an Orbit-owned scoped keyring, pinned key digest and fingerprints, exact candidate-origin checks against the validated release suite, and atomic recovery. Never use a Launchpad PPA, mix Ubuntu suites, or accept caller-provided package sources.

## Route project JavaScript work through Vite+

Use `vp` for generic project dependency and script commands. Follow Vite+'s
native package-manager selection order; a project without a manager signal
defaults to pnpm. Let Vite+ resolve that project state instead of adding a PHP
resolver.

Vite+ manages Node through `vp env`. It owns package-manager dispatch. Orbit
installs pnpm by default. Orbit installs Bun separately. Bun is a host runtime
on `app-dev` and `app-prod` hosts. `vp install -g` uses Vite+'s managed global store.
Do not replace PHP or Composer commands. Use a native package-manager command
only at an intentional bootstrap, publication, or runtime boundary.

## Use the closed tool manager registry

The active Tool Manager registry contains the code-owned adapters apt, vp,
composer, brew, and brew-cask. Persisted identifiers that are absent from the active registry remain
readable but cannot serve new Tool installations.
apt and composer stay Linux-only. brew accepts verified Homebrew Core bottles on
Linux and macOS. brew-cask accepts official Homebrew casks on macOS and shares
the brew prefix lock. vp uses the enrolled account's Vite+ global scope.
Do not add a generic script API, arbitrary installer, or agent execution path.
Use Vite+ global packages instead of exposing npm as a manager. A nullable
SemVer constraint gates the manager's normal candidate before mutation; it
never selects or downgrades a version.

Never persist or return raw manager stdout or stderr. Reject silent adoption,
protected removal, and unsafe shared-scope removal. Explicit adoption may
register one supported installed package and must not install, update, remove,
or repin it. Reject protected packages. For apt, protect every name returned by
NodeBootstrapPackageCatalog forNode and forRole, plus openssh-server and
wireguard-tools. That includes docker.io and dnsmasq. For brew, protect
wireguard-tools and wireguard-go. For vp, protect the root pnpm. Scan reports
those with adoption block protected. Do not adopt, install, or uninstall them.
Every brew and brew-cask command sets HOMEBREW_NO_AUTO_UPDATE,
HOMEBREW_NO_ANALYTICS, HOMEBREW_NO_ENV_HINTS, HOMEBREW_NO_INSTALLED_DEPENDENTS_CHECK,
and HOMEBREW_NO_INSTALL_CLEANUP. macOS install, update, and adopt bottle checks
also set HOMEBREW_FORCE_API_AUTO_UPDATE. Never run brew update or a Homebrew
developer command, including brew ruby. Scan does not force an API refresh.
Derive the macOS bottle tag from sw_vers -productVersion and the stored CPU
through the code-owned table. The all tag counts only when that tag is absent.
Older OS tags do not count.
APT removal must remove only the exact recorded package. Composer commands
target the exact root package in Orbit's shared global scope. vp targets the
exact root package in the enrolled account's global scope.
Homebrew formula commands accept only unqualified Homebrew Core names with a
stable, SHA-256-described bottle for the Node's platform and architecture.
brew-cask uses fixed official-cask commands and never accepts taps, URLs, local
files, caller options, or zap. Formula commands force bottle use, never start
formula services, remove only the exact recorded package, and never autoremove
dependencies.

Treat managers as protected, role-independent Node capabilities. Allow Tool
mutations on active Linux and macOS Nodes whose WireGuard address and pinned SSH
host identity are managed by the Gateway. Linux roles, exporters, Processes,
Schedules, and the Node agent stay Linux-only.
Materialize a missing Linux manager on first use. On macOS, verify the existing
Homebrew prefix and the enrolled account's Vite+ scope in place and do not
replace them. Retain failed materialization for retry, and retain active manager
state after its final Tool is removed. Roles may require managers during
convergence but do not own them or their Tools. Never remove packages, Tool
intent, or manager state implicitly during role removal.
