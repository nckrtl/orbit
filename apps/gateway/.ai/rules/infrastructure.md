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
Require the role-supported Ubuntu release before remote mutation. Base and app-dev nodes support Noble and Resolute; Gateway, VPN, and app-prod require Resolute. Use the direct Sury PHP repository with an Orbit-owned scoped keyring, pinned key digest and fingerprints, exact candidate-origin checks against the validated release suite, and atomic recovery. Never use a Launchpad PPA, mix Ubuntu suites, or accept caller-provided package sources.

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

The closed tool manager registry contains apt, vp, and composer.
Use Vite+ global packages instead of exposing npm as a manager. A nullable
SemVer constraint gates the manager's normal candidate before mutation; it
never selects or downgrades a version.

Never persist or return raw manager stdout or stderr. Reject unmanaged package
adoption, protected removal, and unsafe shared-scope removal.
APT removal must remove only the exact recorded package. VP and Composer
commands target the exact root package in their Orbit-owned shared scopes.

VP and Composer are available only while an app-dev or app-prod assignment is
provisioning or active. Public install and update require an active app role.
Block last-app-role removal while non-protected VP or Composer tool intent
exists; require explicit tool removal first. Successful last-app-role removal
retains protected manager rows but marks VP and Composer unavailable until a
later role convergence reactivates them. Never remove packages or tool intent
implicitly from role removal.
