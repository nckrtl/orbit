---
title: "Architecture"
description: "Orbit's components, the boundaries between them, and the four paths that connect them."
---

# Architecture

Orbit has one Gateway that owns all fleet state. Clients ask the Gateway for changes, and the Gateway applies them to Nodes. Nodes run applications and report what they observe.

## Components

| Component | Code | Role |
| --- | --- | --- |
| Gateway | `apps/gateway` | The only authority. It stores fleet state in SQLite, authorizes every request, applies changes to Nodes over SSH, and runs scheduled work. It serves the HTTP API, the MCP endpoint, and the web app. |
| CLI | `apps/cli`, `packages/php-sdk` | A thin client for humans and agents. It calls the Gateway API and never connects to a Node. |
| Web app | `apps/web`, `apps/desktop` | A static single-page app that reads the API and shows record changes live. The desktop app is a native shell around it. |
| Node agent | `apps/agent` | A program on every Node that reports presence and Process state. It never changes a Node. |
| pi-server | `apps/pi-server` | Runs coding-agent sessions for the Tasks extension on `app-dev` Nodes. |

## Trust

Every Node joins one private WireGuard network. The Gateway identifies each caller by the WireGuard address its request comes from. A directed access grant decides which Nodes that caller may act on. Humans, agents, and Nodes use the same API with the same rules.

The Gateway is private and reachable only over WireGuard. Public traffic enters only through Nodes with the `ingress` role, and the Gateway never shares a Node with `ingress`.

## Nodes and roles

A Node is an Ubuntu 26.04 machine. Its roles decide its work, and Orbit installs and manages only what those roles need. Services run natively under systemd.

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

**Tasks.** With the Tasks extension enabled, the Gateway provisions a task workspace on an `app-dev` Node, runs coding agents in it one subtask at a time, checks each handoff with the Project's task check, and opens a pull request through the GitHub App. See [Tasks](/reference/tasks).

## Data

The Gateway's SQLite database holds all Orbit records. Applications keep their own data in their own databases. Orbit stores connection records for them.

See [Concepts](/concepts) for the terms, and each domain page for its details.
