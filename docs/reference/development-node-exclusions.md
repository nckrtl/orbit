---
title: "Development node exclusions"
description: "How a Project skips selected app-dev Nodes for development placement, from the Project or from the Node, including MCP."
covers:
  - apps/gateway/app/Domain/Projects/DevelopmentNodeExclusion.php
  - apps/gateway/app/Models/ProjectNodeExclusion.php
  - apps/gateway/app/Data/Projects/**
  - apps/gateway/app/Http/Controllers/Api/{ProjectExcludedNodesController,NodeExcludedProjectsController}.php
  - apps/cli/app/Commands/Projects/{AddExcludedNode,ListExcludedNodes,RemoveExcludedNode}Command.php
  - apps/cli/app/Commands/Nodes/{AddExcludedProjectCommand,ListExcludedProjectsCommand,RemoveExcludedProjectCommand}.php
  - packages/php-sdk/src/Requests/Projects/{AddProjectExcludedNode,ListProjectExcludedNodes,RemoveProjectExcludedNode}Request.php
---

# Development node exclusions

An exclusion keeps a Project off one `app-dev` Node for development. One row records one Project and one Node. The Project commands and the Node commands read and write the same row. With no rows, a Project can use every eligible `app-dev` Node. The [task workspace routing setting](/reference/projects#task-workspace-routing) changes whether a new workspace gets a Route; it does not change placement or exclusions.

## Record an exclusion

The Node must have an active `app-dev` role. The Node argument is an ID or a name. The Project argument is a numeric Project ID.

```bash
orbit project:excluded-node:add sabre --project=4
orbit node:excluded-project:add 4 --node=sabre
```

Both commands store the same row. A second `add` of the same pair returns the stored row.

| Command | Result |
| --- | --- |
| `project:excluded-node:add NODE --project=ID` | Exclude the Node for the Project. |
| `project:excluded-node:list --project=ID` | List the Nodes the Project cannot use. |
| `project:excluded-node:remove NODE --project=ID` | Delete the row. |
| `node:excluded-project:add PROJECT --node=NODE` | Exclude the Node for the Project. |
| `node:excluded-project:list --node=NODE` | List the Projects that cannot use the Node. |
| `node:excluded-project:remove PROJECT --node=NODE` | Delete the row. |

In a terminal, an omitted Project or Node opens a searchable selector. JSON and noninteractive calls must name both for `add` and `remove`, and the owner for `list`. `remove` asks for no confirmation, because it moves and stops nothing.

`project:show` includes `excluded_nodes`, and `node:show` includes `excluded_projects`. Each entry has the Project ID and slug, the Node ID and name, and `development_instance_count`: the number of development Instances of the Project already on the Node. Those Instances stay where they are. Use [transfer](/reference/instance-transfer) to move one.

Removing the `app-dev` role from a Node deletes its rows. Adding the role again does not restore them. Deleting the Node or the Project deletes its rows.

## Where Orbit applies the list

Orbit checks the list for every new development placement:

- The task scheduler skips excluded Nodes.
- Among the other Nodes, it picks the one with the fewest active tasks.
- When no Node remains, the task waits without a workspace.
- `instance:create`, `instance:register`, and `instance:transfer` to an excluded Node return `instance.node_excluded` before they change anything.

Production placement ignores the list. A Node that also has `app-prod` can still host a production Instance of the Project.

## MCP

The operations are Gateway API routes, so the [MCP server](/reference/mcp) offers them as tools with the same validation and errors.

| Tool | Command |
| --- | --- |
| `project-excluded-node-add` | `project:excluded-node:add` |
| `project-excluded-node-list` | `project:excluded-node:list` |
| `project-excluded-node-remove` | `project:excluded-node:remove` |
| `node-excluded-project-add` | `node:excluded-project:add` |
| `node-excluded-project-list` | `node:excluded-project:list` |
| `node-excluded-project-remove` | `node:excluded-project:remove` |

A tool takes the route's path parameters as arguments. `project` is the numeric Project ID, and `node` is the numeric Node ID.

## Errors

The Gateway returns these codes for exclusions and for refused placement.

| Code | HTTP | Cause |
| --- | --- | --- |
| `project.excluded_node_not_app_dev` | 422 | `add` names a Node without an active `app-dev` role. |
| `project.excluded_node_missing` | 404 | `remove` names a pair that is not stored. |
| `instance.node_excluded` | 409 | A development create, register, or transfer targets an excluded Node. |

## Why it works this way

These reasons explain the design. Check them before you propose a change.

### An exclusion list, not an allowlist

Most Projects can use every `app-dev` Node, and a new Node should get work at once. An empty list keeps that default. An allowlist was rejected because every new Node would need an entry for every Project. A Node capability flag, such as "runs Incus", was rejected because Orbit does not record capabilities and the operator picks specific Nodes.

### Two command families, one row

An operator starts from a Project or from a Node, and both see the same pairs. One family with a flag for the starting side was rejected.

### Existing Instances stay

The list applies to new placement only. Moving Instances when a row is added was rejected. The add response shows how many Instances are affected, and the operator decides.

### The row leaves with the role

Only `app-dev` Nodes take development work, so a row without the role means nothing. Adding the role again makes the Node eligible for every Project until someone excludes it again.
