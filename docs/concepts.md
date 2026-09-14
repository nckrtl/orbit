# Concepts

These terms describe the parts of Orbit. Follow the links for commands and details.

- **Gateway** — The central Orbit service. It stores every machine and application record, authorizes each action, and applies changes to machines over SSH. See [Architecture](/architecture#gateway).
- **Node** — A machine connected to Orbit. It runs one or more roles and reaches the Gateway over WireGuard. A Node outside a Cluster is standalone.
- **Cluster** — An optional group of Nodes that share routing. It has a name and may have a development top-level domain (TLD), such as `test`. See [Routes](/reference/routes).
- **App** — An application record with one Git repository, a default branch, and a web root. App instances share these defaults. See [Apps](/reference/apps).
- **Repository identity** — The Git host and path, without a trailing `.git`. Equivalent SSH and HTTPS URLs identify the same repository and belong to one App. See [Apps](/reference/apps#keep-one-repository-owner).
- **App instance** — One managed copy of an App on a Node. Development uses a Git checkout or worktree; production uses releases. Each active App instance has one Route. See [Applications](/domains/applications).
- **Web root** — The directory served by an App instance, with a relative path inherited from the App or overridden per instance. Production resolves it inside the selected release. See [Production release layout](/reference/deployments).
- **Legacy Instance** — The earlier runnable application record. Orbit retains it for existing Workspace and Doctor behavior. New development placements use App instance.
- **Workspace** — A Git worktree owned by a legacy Instance. App instance creation does not use or change Workspace source.
- **Route** — A hostname owned by an App. It sends traffic to App instances through one Node or active Cluster. Orbit records whether the hostname is generated or explicit. See [Routes](/reference/routes).
- **Development-server endpoint** — The reserved path `/__orbit/vite` on an App instance Route hostname that carries live frontend assets and hot module replacement through Cluster HTTPS to the owning Node. See [Routes](/reference/routes#development-server-endpoint).
- **Router** — The Node role that receives Routes with Cluster scope and selects their workload targets. Every Cluster with a Route needs one active Router.
- **Ingress** — The Node role that receives public HTTP and HTTPS traffic and forwards it to the Router. See [ADR 0011](/decisions/0011-clustered-production-ingress-and-app-prod-placement).
- **Database** — A Node role that installs and manages Docker for shared database processes. See [Database role](/reference/database-role).
- **Database connection** — Saved MySQL, PostgreSQL, or SQLite credentials. Attaching a connection populates the App instance's stored environment. See [Database connections](/reference/database-connections).
- **Doctor** — The check that compares what the Gateway expects with what is on a Node and reports every difference. Doctor never changes a machine. See [ADR 0004](/decisions/0004-verify-only-doctor-boundary).
- **Process** — A systemd service or Docker container that Orbit manages for an App instance or Node. See [App processes and schedules](/reference/app-processes-and-schedules).
- **Runtime hibernation** — Pausing idle development processes and removing rebuildable dependencies after longer idle periods. An HTTP request restores dependencies and wakes configured processes. Keep-alive workers stay running. See [App-dev runtime hibernation](/reference/app-dev-runtime-hibernation).
- **Herdr session** — A named headless Herdr server on a Node. View its panes through a private, read-only connection with temporary access. See [Herdr sessions](/reference/herdr-sessions).
- **Schedule** — A recurring command for a Node or App instance. A systemd timer runs it on the host Node. See [Schedules](/reference/schedules).
- **Tool** — A package that Orbit manages on a Node through a specific package manager. See [Tools](/reference/tools).
- **Tool Manager** — Orbit's adapter for a package manager on a Node. Orbit prepares it on demand, independently of Node roles. See [Tools](/reference/tools).
- **Proof topology** — Disposable Incus machines for one issue and exact commit. The harness captures an immutable result before it may retain the machines for interactive review. See [Incus topologies](/reference/incus-topologies) and [ADR 0056](/decisions/0056-retain-proof-topologies-for-interactive-review).
- **Documentation context** — The ordered list of pages that `composer docs-context` selects for a component or concept. A contributor or agent reads it before changing that part of Orbit.
