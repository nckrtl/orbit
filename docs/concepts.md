---
title: "Concepts"
description: "Definitions of Gateway, Node, Cluster, Project, Instance, Route, Router, Doctor, Process, Schedule, Tool, and the other terms Orbit uses."
---

# Concepts

These terms describe the parts of Orbit. Follow the links for commands and details.

- **Gateway** — The central Orbit service. It stores every machine and application record, authorizes each action, and applies changes to machines over SSH. See [Architecture](/architecture#gateway).
- **Node** — A machine connected to Orbit. It runs one or more roles and reaches the Gateway over WireGuard. A Node outside a Cluster is standalone.
- **Cluster** — An optional group of Nodes that share routing. It has a name and may have a development top-level domain (TLD), such as `test`. See [Routes](/reference/routes).
- **Project** — One Git repository plus shared source defaults and a `type` that decides routing and PHP-FPM capability. Use Project update to change those defaults. The HTTP compatibility path `/api/v1/apps` still serves the same records. See [Projects](/reference/apps#update-a-project).
- **Project type** — Closed enum `monorepo`, `laravel-app`, or `laravel-package`. `laravel-app` is the web-serving type. See [ADR 0106](/decisions/0106-derive-instance-capabilities-from-project-type).
- **Repository identity** — The Git host and path, without a trailing `.git`. Equivalent SSH and HTTPS URLs identify the same repository and belong to one Project. See [Projects](/reference/apps#keep-one-repository-owner).
- **Instance** — One managed copy of a Project on a Node. Placement on app-dev uses a checkout or worktree. Placement on app-prod requires a candidate. See [ADR 0047](/decisions/0047-create-production-appinstances-from-candidates) and [Applications](/domains/applications).
- **Development node exclusion** — One Project and one app-dev Node. Development placement for that Project skips that Node. See [Development node exclusions](/reference/development-node-exclusions).
- **Setup step** — One named setup command stored for a Project. See [Instance setup and teardown](/reference/instance-setup).
- **Teardown step** — One named teardown command stored for a Project. See [Instance setup and teardown](/reference/instance-setup).
- **Instance routing** — An active `laravel-app` Instance has one Route. A `monorepo` or `laravel-package` Instance has no Route by default. See [ADR 0106](/decisions/0106-derive-instance-capabilities-from-project-type).
- **Web root** — The directory served by a web-serving Instance, with a relative path inherited from the Project or overridden per Instance. app-prod resolves it inside the selected release. See [Production release layout](/reference/deployments).
- **Route** — A domain the Gateway publishes on the private network. See [ADR 0064](/decisions/0064-name-application-endpoints-as-domains), [ADR 0080](/decisions/0080-add-node-owned-custom-proxy-routes), and [Routes](/reference/routes).
- **Route replacement** — The Route Orbit creates for a domain change. See [ADR 0065](/decisions/0065-replace-routes-when-domains-change).
- **Development-server endpoint** — The reserved path `/__orbit/vite` on an Instance Route domain that carries live frontend assets and hot module replacement through Cluster HTTPS to the owning Node. See [Routes](/reference/routes#development-server-endpoint).
- **Agentation endpoint** — The reserved path `/__orbit/agentation` on an Instance Route domain that reverse-proxies the per-app Agentation HTTP Process. Orbit projects that origin as `AGENTATION_URL` and hibernates the Antigravity watcher with the Instance. See [Agentation](/reference/agentation).
- **Router** — The Node role that receives Routes with Cluster scope and selects their workload targets. Every Cluster with a Route needs one active Router.
- **Ingress** — The Node role that receives public HTTP and HTTPS traffic and forwards it to the Router. See [ADR 0011](/decisions/0011-clustered-production-ingress-and-app-prod-placement).
- **Database** — A Node role that installs and manages Docker for shared database processes. See [Database role](/reference/database-role).
- **Database connection** — A registered MySQL, PostgreSQL, SQLite, or Redis credential record. See [Database connections](/reference/database-connections).
- **proxycli** — An optional fleet extension that collects CLIProxyAPI quota into shared Valkey and publishes provider pools at `collector.proxycli.orbit`. See [proxycli](/reference/proxycli).
- **Doctor** — The check that compares what the Gateway expects with what is on a Node and reports every difference. Doctor never changes a machine. See [ADR 0004](/decisions/0004-verify-only-doctor-boundary).
- **Node agent** — `orbit-agent`, a visibility-only program on every managed Linux Node. It publishes presence and Process runtime state for the web app and never runs commands. See [Node agent](/reference/node-agent).
- **Process** — A systemd service or Docker container that Orbit manages for an Instance or Node. See [Project processes and schedules](/reference/app-processes-and-schedules).
- **Runtime hibernation** — Pausing idle development processes and removing rebuildable dependencies after longer idle periods. An HTTP request restores dependencies and wakes configured processes. Keep-alive workers stay running. See [App-dev runtime hibernation](/reference/app-dev-runtime-hibernation).
- **Herdr session** — A named headless Herdr server on a Node. View its panes through a private, read-only connection with temporary access. See [Herdr sessions](/reference/herdr-sessions).
- **Task group** — One parent feature stored by the Gateway `tasks` extension. See [Tasks](/reference/tasks).
- **Task** — An ordered subtask of a Task group. See [Tasks](/reference/tasks).
- **Schedule** — A recurring command for a Node or Instance. A systemd timer runs it on the host Node. See [Schedules](/reference/schedules).
- **Tool** — A package that Orbit manages on a Node through a specific package manager. See [Tools](/reference/tools).
- **Tool Manager** — Orbit's adapter for a package manager on a Node. Orbit prepares it on demand, independently of Node roles. See [Tools](/reference/tools).
- **Proof topology** — Disposable Incus machines for one issue and exact commit. The harness captures an immutable result before it may retain the machines for interactive review. See [Incus topologies](/reference/incus-topologies) and [ADR 0056](/decisions/0056-retain-proof-topologies-for-interactive-review).
- **Firewall rule** — A named UFW rule on one Node. See [firewall](/cli/firewall) and [ADR 0093](/decisions/0093-show-live-ufw-and-desired-rules-that-match-converge).
- **Documentation context** — The ordered list of pages that `composer docs-context` selects for a component or concept. A contributor or agent reads it before changing that part of Orbit.
- **Analytics driver** — The Gateway contract that reads visits for an Instance from the fleet analytics service. The first driver calls the Plausible Community Edition Stats API. See [Instance analytics stats](/reference/instance-analytics-stats).
