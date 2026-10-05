---
title: "Concepts"
description: "The core terms Orbit uses. Each domain page defines its own detailed terms."
---

# Concepts

These are the terms every part of Orbit shares. Each domain page defines the terms it adds.

## Fleet

- **Gateway**: The service that owns all fleet state. It authorizes every request and applies changes to Nodes. See [Architecture](/architecture).
- **Node**: A machine in the fleet. It joins the private WireGuard network. It may have service roles or only managed Tools.
- **Role**: One kind of work a Node does, such as `app-dev` or `ingress`. See [Architecture](/architecture#nodes-and-roles).
- **Cluster**: An optional group of Nodes that share routing.
- **Access grant**: A directed permission that lets one Node act on another through the Gateway. See [node](/cli/node).
- **Tool**: A package that Orbit installs or explicitly adopts, then updates on a Node. See [Tools](/reference/tools).

## Applications

- **Project**: One Git repository, its named apps and the defaults for running them. See [Projects](/reference/projects).
- **Named app**: An application within a Project, with a name, repository-relative path, relative web root and type. See [Application directory](/reference/projects#application-directory).
- **Instance**: One running copy of the whole Project on a Node, serving each configured app in development or the sole app in supported production. See [Applications](/domains/applications).
- **Route**: A domain that reaches one named app in an Instance, a pool for the same app, or a Node-local service. See [Routes](/reference/routes).
- **Process**: A long-running service that Orbit manages for an Instance or a Node, such as a queue worker. See [Processes and schedules](/reference/processes-and-schedules).
- **Schedule**: A command that runs on a timer for an Instance or a Node. See [Schedules](/reference/schedules).
- **Deployment**: One release that Orbit prepares and activates on a production Instance. See [Production release layout](/reference/deployments).

## Operations

- **Doctor**: The check that compares the state the Gateway expects with each Node's actual state. It reports every difference and changes nothing. See [Doctor](/cli/doctor).
- **Activity**: The Gateway's record of requests. It keeps every change and every failed request, plus a sample of successful reads. See [Activity](/cli/activity).
- **Node agent**: The program on managed Linux Nodes that reports presence and Process state. It never changes a Node. See [Node agent](/reference/node-agent).
- **Extension**: An optional Gateway feature with one switch at the Gateway. A disabled extension is invisible to every client. See [extension](/cli/extension).

## Tasks

- **Task**: One feature or bug fix, delivered as one pull request. The Tasks board shows this top-level `tasks` row, which has no `parent_id`. See [Tasks](/reference/tasks).
- **Task definition**: A Project's stored plan in the Gateway. Writing one does not start a task. See [Tasks](/reference/tasks#task-definitions).
- **Subtask**: A child task with `parent_id` set to its task and with no children of its own. See [Tasks](/reference/tasks).
- **Deliverable**: A checkable item that a subtask must produce, such as a file, a test, or a command that passes.
- **Task check**: The command that a Project runs to verify every subtask handoff.
- **Task workspace**: The Instance that Orbit provisions for a task, and its checkout on an `app-dev` Node.
- **Turn receipt**: The file `.git/orbit/receipt.json` that the command `.git/orbit/turn` writes. See [Tasks](/reference/tasks#turn-receipt).
