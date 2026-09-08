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
composer, and brew. Persisted identifiers that are absent from the active registry remain
readable but cannot serve new Tool installations.
Use Vite+ global packages instead of exposing npm as a manager. A nullable
SemVer constraint gates the manager's normal candidate before mutation; it
never selects or downgrades a version.

Never persist or return raw manager stdout or stderr. Reject unmanaged package
adoption, protected removal, and unsafe shared-scope removal.
APT removal must remove only the exact recorded package. VP and Composer
commands target the exact root package in their Orbit-owned shared scopes.
Homebrew accepts only unqualified Homebrew Core formula names with a stable,
SHA-256-described bottle for the Node's Linux architecture. Its fixed commands
force bottle use, never start formula services, remove only the exact recorded
formula, and never autoremove dependencies.

Treat managers as protected, role-independent Node capabilities. Allow Tool
mutations only on active Linux Nodes whose WireGuard address and pinned SSH
host identity are managed by the Gateway. Materialize a missing manager on
first use, retain failed materialization for retry, and retain active manager
state after its final Tool is removed. Roles may require managers during
convergence but do not own them or their Tools. Never remove packages, Tool
intent, or manager state implicitly during role removal.
