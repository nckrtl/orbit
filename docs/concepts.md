---
title: "Concepts"
description: "The core terms Orbit uses. Each domain page defines its own detailed terms."
---

# Concepts

These are the terms every part of Orbit shares. Each domain page defines the terms it adds.

## Fleet

- **Gateway**: The service that owns all fleet state. It authorizes every request and applies changes to Nodes. It is Orbit's control plane and runs only on Linux. See [Architecture](/architecture).
- **Node**: A machine in the fleet. It joins the private WireGuard network. It may have service roles or only managed Tools.
- **Roleless Node**: A Node with no roles, such as an operator's laptop. It joins the network and may have managed Tools. The web app lists it apart from Nodes with roles.
- **Role**: One kind of work a Node does, such as `app-dev` or `ingress`. See [Architecture](/architecture#nodes-and-roles).
- **Cluster**: An optional group of Nodes that share routing.
- **Access grant**: A directed permission that lets one Node act on another through the Gateway. See [node](/cli/node).
- **Tool**: A package that Orbit installs or explicitly adopts, then updates on a Node. See [Tools](/reference/tools).

## Applications

- **Project**: One Git repository and the defaults for running it. Its type decides what its Instances can do. See [Projects](/reference/projects).
- **Project Documents**: Folders and versioned files owned by a Project, independent of Git branches and Instances. See [Project Documents](/reference/project-documents).
- **Instance**: One running copy of a Project on a Node, for development or production. See [Applications](/domains/applications).
- **Placement**: The Node where an Instance runs. That Node's `app-dev` or `app-prod` role makes it a development or production Instance. Process and Schedule definitions name this kind `environments`.
- **Route**: A domain that reaches an Instance or a Node-local service. See [Routes](/reference/routes).
- **Process**: A long-running service that Orbit manages for an Instance or a Node, such as a queue worker. See [Processes and schedules](/reference/processes-and-schedules).
- **Schedule**: A command that runs on a timer for an Instance or a Node. See [Schedules](/reference/schedules).
- **Database server**: A MySQL or Valkey server that Orbit runs as a Docker Process on a Node and that holds Database connections. See [Database servers](/reference/database-servers).
- **Deployment**: One release that Orbit prepares and activates on a production Instance. See [Production release layout](/reference/deployments).

## Operations

- **Doctor**: The check that compares the state the Gateway expects with each Node's actual state. It reports every difference and changes nothing. See [Doctor](/cli/doctor).
- **Activity**: The Gateway's record of requests. It keeps every change and every failed request, plus a sample of successful reads. See [Activity](/cli/activity).
- **Pi server**: The service on a Node that runs Pi coding-agent sessions for the Tasks `pi` driver. See [Pi server](/reference/pi-server). Server alone names neither it nor a Database server.
- **Node agent**: The program on managed Linux Nodes that reports presence and Process state. It never changes a Node. See [Node agent](/reference/node-agent).
- **Extension**: An optional Gateway feature with one switch. A disabled extension is invisible to every client. Core reaches its code only through plug-in points. See [extension](/cli/extension) and [Extensions](/reference/extensions).

## Tasks

- **Task**: One feature or bug fix, delivered as one pull request. The Tasks board shows this top-level `tasks` row, which has no `parent_id`. See [Tasks](/reference/tasks).
- **Subtask**: A child task with `parent_id` set to its task and with no children of its own. See [Tasks](/reference/tasks).
- **Deliverable**: A checkable item that a subtask must produce, such as a file, a test, or a command that passes.
- **Task check**: The command that a Project runs to verify every subtask handoff.
- **Task workspace**: The Instance that Orbit provisions for a task, and its checkout on an `app-dev` Node.
- **Turn receipt**: The file `.git/orbit/receipt.json` that the command `.git/orbit/turn` writes. See [Tasks](/reference/tasks#turn-receipt).
