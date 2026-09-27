---
title: "Concepts"
description: "The core terms Orbit uses. Each domain page defines its own detailed terms."
---

# Concepts

These are the terms every part of Orbit shares. Each domain page defines the terms it adds.

## Fleet

- **Gateway**: The service that owns all fleet state. It authorizes every request and applies changes to Nodes. See [Architecture](/architecture).
- **Node**: A machine in the fleet. It joins the private WireGuard network and does the work of its roles.
- **Role**: One kind of work a Node does, such as `app-dev` or `ingress`. See [Architecture](/architecture#nodes-and-roles).
- **Cluster**: An optional group of Nodes that share routing.
- **Access grant**: A directed permission that lets one Node act on another through the Gateway. See [node](/cli/node).
- **Tool**: A package that Orbit installs and updates on a Node. See [Tools](/reference/tools).

## Applications

- **Project**: One Git repository and the defaults for running it. Its type decides what its Instances can do. See [Projects](/reference/apps).
- **Instance**: One running copy of a Project on a Node, for development or production. See [Applications](/domains/applications).
- **Route**: A domain that reaches an Instance or a Node-local service. See [Routes](/reference/routes).
- **Process**: A long-running service that Orbit manages for an Instance or a Node, such as a queue worker. See [Processes and schedules](/reference/app-processes-and-schedules).
- **Schedule**: A command that runs on a timer for an Instance or a Node. See [Schedules](/reference/schedules).
- **Deployment**: One release that Orbit prepares and activates on a production Instance. See [Deployments](/reference/deployments).

## Operations

- **Doctor**: The check that compares the state the Gateway expects with each Node's actual state. It reports every difference and changes nothing. See [Doctor](/cli/doctor).
- **Activity**: The record the Gateway keeps of each request. It shows who asked, what changed, and the result. See [Activity](/cli/activity).
- **Node agent**: The program on every Node that reports presence and Process state. It never changes a Node. See [Node agent](/reference/node-agent).
- **Extension**: An optional feature that you enable explicitly, such as Tasks.

## Tasks

- **Task group**: One feature or bug fix, delivered as one pull request. See [Tasks](/reference/tasks).
- **Task**: One ordered unit of work in a task group. A fresh coding agent implements it. A reviewer approves it. The CLI and web app call it a subtask because it has a parent group.
- **Deliverable**: A checkable item that a task must produce, such as a file, a test, or a command that passes.
- **Task check**: The command that a Project runs to verify every task handoff.
- **Task workspace**: The Instance that Orbit provisions for a task group, and its checkout on an `app-dev` Node.
