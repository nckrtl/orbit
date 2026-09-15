# Architecture

Orbit has one active Gateway. The command-line interface (CLI) sends it requests, and it coordinates changes on managed machines called Nodes.

This is how a command reaches a Node:

```text
Human or AI agent
        ↓
       CLI
        ↓
      HTTP
        ↓
     Gateway
        ↓
       SSH
        ↓
  Managed Nodes
```

Web traffic follows a separate path from CLI control traffic. [Routes](/reference/routes) explains how a domain reaches its App instance target through the Node, Router, and Ingress roles.

## CLI

The CLI lives in `apps/cli`. It sends HTTP requests to the Gateway and returns readable output for people or structured data for scripts and agents.

## Gateway

The Gateway lives in `apps/gateway`. It stores Orbit's records in SQLite, authorizes actions, and coordinates changes on Nodes. Nodes hold the files and run the services that apply those settings.

## Nodes and roles

A Node can stand alone or belong to one Cluster. Roles define its work: `app-dev` runs development applications, `database` installs Docker for shared database processes, and Router sends Cluster traffic to applications.

The Gateway manages Nodes over SSH. After setup, WireGuard provides the private network used for those connections. Orbit manages the files and services needed by each Node's assigned roles.

## Applications and traffic

An App stores shared source defaults and owns Routes. An App instance is one copy of that App on a Node, used for development or production. A Route gives it a domain. Related Nodes can share a Cluster, but this is optional.

A development App instance owns one Git checkout or worktree. A standalone production App instance has a dedicated user, home, deployment branch, and application steps. Orbit prepares and activates releases; the operator or agent starts deployments and chooses application commands. The Gateway selects any required PHP runtime and prepares one Route per active App instance.

When an App instance is idle on an active `app-dev` Node, it can [hibernate](/reference/app-dev-runtime-hibernation). Orbit stops processes configured to run unless they have keep-alive enabled. After a longer idle period, it removes dependencies that can be rebuilt from lockfiles. The next HTTP request restores those dependencies and starts the group configured to run.

See [Applications](/domains/applications) for source, branch, and setup details; [Routes](/reference/routes) for traffic and domain changes; and [PHP runtime](/reference/php-runtime) for runtime settings. [App instance removal](/reference/appinstance-removal) explains cleanup and retained content. These pages link to the governing architecture decisions.

App instance commands manage App instances and Routes. Runtime publication, Caddy, DNS, certificates, PHP-FPM, and firewall intent use App instances and Routes only. Doctor inspects App instances and Routes.

## Herdr sessions

A [Herdr session](/reference/herdr-sessions) runs a named headless Herdr server on a Node. Orbit can manage its process or adopt an existing server whose lifecycle stays external. Both modes let Commander view recorded panes through temporary, read-only `terminal.observe` access.

## Database connections

The Gateway stores named MySQL, PostgreSQL, and SQLite connections. Register a host or SQLite path, then attach the connection to an App instance to populate its stored environment. Registration needs no `database` role. Node processes manage database containers. See [Database connections](/reference/database-connections).

## Doctor

`orbit doctor` compares what the Gateway expects with what is actually on a Node. It reports problems without changing the machine. This behavior is described in [ADR 0004](/decisions/0004-verify-only-doctor-boundary).

## Testing on real Linux machines

Automated tests cover most Orbit behavior. When a change depends on Linux, systemd, file permissions, networking, or several machines, contributors also observe it in a disposable Incus environment. The selected [implementation flow](/reference/implementation-loop#incus-requirement) decides whether that environment is development discovery or a separate fresh proof topology. [ADR 0006](/decisions/0006-topology-led-feature-development) explains why Orbit uses Incus.

## Documentation tools

Mintlify publishes the pages in `docs/`. The `apps/docs` console project checks documentation and builds the index used by `composer docs-context`. [ADR 0014](/decisions/0014-maintain-verified-documentation-context) explains the index.

The `apps/e2e` project creates the temporary Incus machines used for these tests. Keeping it separate from the product code makes the test environment easier to trust.
