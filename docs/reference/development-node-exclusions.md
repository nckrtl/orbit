---
title: "Development node exclusions"
description: "How a Project excludes app-dev Nodes from development placement, from the Project or from the Node, including MCP."
---

# Development node exclusions

This page tells an operator how to keep a Project off particular app-dev Nodes during development. [ADR 0118](/decisions/0118-exclude-app-dev-nodes-per-project) owns the decision. [Tasks](/reference/tasks) owns task scheduling. [Instance transfer](/reference/appinstance-transfer) owns moving an Instance that is already placed.

One row records one Project and one Node. The Project commands and the Node commands read and write that same row. An empty list means the Project can use any eligible app-dev Node.

## Record an exclusion

The Node must have an active `app-dev` role. The Node argument is an ID or a name. The Project argument is a numeric Project ID. In an interactive terminal, an omitted Project or Node opens a searchable selector. Canceling or finding no records stops the command before any change. JSON and noninteractive calls must supply both selectors for add and remove, or the owning selector for list.

```bash
orbit project:excluded-node:add sabre --project=4
orbit node:excluded-project:add 4 --node=sabre
```

Both commands store the same row. A second add of that pair returns the row that is already stored.

| Command | Result |
| --- | --- |
| `project:excluded-node:add NODE --project=ID` | Excludes that app-dev Node for the Project. |
| `project:excluded-node:list --project=ID` | Lists the Nodes that Project cannot use for development. |
| `project:excluded-node:remove NODE --project=ID` | Deletes that Project's row for the Node. |
| `node:excluded-project:add PROJECT --node=ID` | Excludes that Node for the Project. `NODE` may be a name. |
| `node:excluded-project:list --node=ID` | Lists the Projects that cannot use that Node for development. |
| `node:excluded-project:remove PROJECT --node=ID` | Deletes that Node's row for the Project. |

`remove` does not ask for confirmation. It does not move or stop an Instance.

`project:show` includes `excluded_nodes`. `node:show` includes `excluded_projects`. Each entry has the Project id and slug, the Node id and name, and `development_instance_count`. That count is how many development Instances of the Project are already on the Node. `add` returns the same count. Those Instances stay on the Node.

Removing the Node's `app-dev` role deletes every exclusion row for that Node. Adding `app-dev` again does not restore them. Deleting the Node or the Project deletes its rows.

## Where Orbit applies the list

Orbit checks the list for new development placement:

- The task scheduler skips an excluded Node before it counts load.
- It places the workspace on the app-dev Node with the fewest active groups.
- When every candidate is excluded or full, the group stays without a workspace.
- `instance:create`, `instance:register`, and a development `instance:transfer` onto an excluded Node stop before any change and return `instance.node_excluded`.

Production placement does not read the list. A Node that also has `app-prod` can host a production Instance of the same Project.

## MCP

The [MCP server](/reference/mcp) publishes these operations as tools because they are Gateway API routes. A tool call uses the same validation and error codes as the CLI.

| Tool | Command |
| --- | --- |
| `project-excluded-node-add` | `project:excluded-node:add` |
| `project-excluded-node-list` | `project:excluded-node:list` |
| `project-excluded-node-remove` | `project:excluded-node:remove` |
| `node-excluded-project-add` | `node:excluded-project:add` |
| `node-excluded-project-list` | `node:excluded-project:list` |
| `node-excluded-project-remove` | `node:excluded-project:remove` |

The tool arguments are the route's path parameters and JSON body in one object. `project-excluded-node-add` takes `app` and `node`. `node-excluded-project-add` takes `node` and `app`. Here, `app` is the numeric Project ID.

## Errors

The Gateway uses these codes when an exclusion change or a development placement is refused.

| Code | HTTP | When the Gateway returns it |
| --- | --- | --- |
| `project.excluded_node_not_app_dev` | 422 | `add` names a Node that has no active `app-dev` role. |
| `project.excluded_node_missing` | 404 | `remove` names a Project and Node pair that is not stored. |
| `instance.node_excluded` | 409 | Development create, register, or transfer targets an excluded Node for that Project. |
