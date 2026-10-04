---
title: "Architecture"
description: "Orbit's components, the boundaries between them, and the four paths that connect them."
---

# Architecture

Orbit has one Gateway that owns all fleet state. Clients ask the Gateway for changes, and the Gateway applies them to Nodes. Nodes run applications and report what they observe.

## Components

Orbit consists of these components. Each one lives in its own project in the repository.

| Component | Code | Role |
| --- | --- | --- |
| Gateway | `apps/gateway` | The only authority. It stores fleet state, authorizes requests, and applies changes to Nodes over SSH. It also serves the API, MCP, and the web app. |
| CLI | `apps/cli`, `packages/php-sdk` | A thin client for humans and agents. It calls the Gateway API and never connects to a Node. |
| Web app | `apps/web`, `apps/desktop` | A static single-page app that reads the API and shows record changes live. The desktop app is a native shell around it. |
| Node agent | `apps/agent` | A program on every Node that reports presence and Process state. It never changes a Node. |
| pi-server | `apps/pi-server` | Runs Pi sessions for task agents on `app-dev` Nodes. Implementers and reviewers use this driver only. T3 runs outside this repository and serves annotation threads, not task agents. |

## Trust

Every Node joins one private WireGuard network. The Gateway identifies each caller by the WireGuard address its request comes from. A directed access grant decides which Nodes that caller may act on. Humans and agents use the same API with the same rules. Node agents report through their own endpoints with a per-Node secret.

The Gateway is private and reachable only over WireGuard. Public traffic enters only through Nodes with the `ingress` role, and the Gateway never shares a Node with `ingress`.

## Nodes and roles

A Node is a machine in the WireGuard fleet. Ubuntu 26.04 Nodes run Orbit service roles. macOS Nodes support selected tools through their existing SSH account and package managers, with no service roles. Tools do not require a role. [Node provisioning](/reference/node-provisioning#macos-nodes) describes macOS enrollment.

On Ubuntu, services run natively under systemd. Container Processes and the `metrics` role's Prometheus and Grafana run in Docker. macOS tool support does not enable these runtimes.

| Role | Work |
| --- | --- |
| `gateway` | Runs the Gateway. One per fleet. |
| `vpn` | Runs the WireGuard hub. One per fleet. |
| `app-dev` | Runs development Instances and task workspaces. |
| `app-prod` | Runs production Instances. |
| `router` | Sends each Cluster Route to the Node that runs its Instance. |
| `ingress` | Accepts public HTTP and HTTPS traffic. |
| `database` | Runs shared database Processes in Docker. |
| `websocket` | Runs the realtime server. One per fleet. |
| `metrics` | Collects fleet metrics. One per fleet. |
| `analytics` | Collects visit analytics. One per fleet. |

A role refuses a Node that holds a conflicting role. For example, the `gateway` role never shares a Node with `app-dev`, `app-prod`, `database`, `analytics`, or `ingress`. [Node provisioning](/reference/node-provisioning) lists every conflict.

## Paths

**Control.** A human or agent runs a CLI command or an MCP tool. The request reaches the Gateway over WireGuard. The Gateway checks the caller, records the request, and applies the change to each Node over one shared SSH connection per Node.

**Traffic.** A Route gives an Instance a domain on one Node or one Cluster. Private requests arrive over WireGuard. Public requests enter through `ingress`. In a Cluster, `router` sends each request to the Node that runs the Instance. Caddy serves the Instance there. See [Routes](/reference/routes).

**Observation.** Node agents publish state to the `websocket` Node. The Gateway keeps a view of it, and the web app shows it live. [Doctor](/cli/doctor) compares the state the Gateway expects with each Node's actual state and reports every difference without changing anything.

**Tasks.** With the Tasks extension enabled, the Gateway provisions a task workspace on an `app-dev` Node. It runs coding agents there one subtask at a time and checks each handoff with the Project's task check. At the end, it opens a pull request through the GitHub App. See [Tasks](/reference/tasks).

## Data

The Gateway's SQLite database holds all Orbit records. [Project Documents](/reference/project-documents) keep hierarchy, versions, and encrypted storage credentials there, but their file bodies live in a dedicated private UpCloud S3-compatible bucket. Only the Gateway accesses that bucket. Applications keep their own data in their own databases. Orbit stores connection records for them.

See [Concepts](/concepts) for the terms, and each domain page for its details.
