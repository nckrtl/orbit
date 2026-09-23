---
title: "ADR 0118: Skip selected Nodes for a Project"
sidebarTitle: "0118 Skip selected Nodes for a Project"
description: "Proposed. A Project and an app-dev Node share one exclusion row. Development placement skips that Node. The same routes are MCP tools."
---

# ADR 0118: Skip selected Nodes for a Project

A Project can exclude app-dev Nodes from its development placement. The Project commands and the Node commands write the same row. An empty list leaves placement on any eligible app-dev Node. The six operations are ordinary API routes, so the generated MCP catalogue exposes them as tools.

## Status

Proposed.

This extends the task placement rule in [ADR 0103](/decisions/0103-absorb-commander-tasks-as-a-gateway-extension), the development transfer rule in [ADR 0066](/decisions/0066-transfer-development-appinstances-between-nodes), the association vocabulary in [ADR 0071](/decisions/0071-use-one-verb-vocabulary-across-cli-routes-and-sdk), and the generated MCP catalogue in [ADR 0086](/decisions/0086-offer-the-api-as-mcp-tools).

## Context

Task workspaces, development Instance creation, registration, and development transfer already require an active `app-dev` role. They then accept every such Node that meets the existing driver and capacity checks. Orbit, for example, needs Nodes that can run Incus ephemeral VMs during development. Shark and Beast can. Sabre cannot. Another Project can still use Sabre.

The operator chooses Nodes. The active `app-dev` role is what makes a Node eligible. Most Projects can use every Node with that role. A Node that receives the role remains available to every Project until an exclusion names it.

Agents create task groups through MCP. The exclusion commands have to be on that same surface. [ADR 0086](/decisions/0086-offer-the-api-as-mcp-tools) generates one tool per API operation and forbids a second hand-written tool layer.

## Decision

- The Gateway stores one exclusion row per Project and Node. `project:excluded-node` and `node:excluded-project` add, list, and remove that same row. `add` from either family returns the existing row when the pair is already stored.
- `add` accepts a Node only while that Node has an active `app-dev` role. The Node argument is an ID or name. The Project argument is a numeric Project ID. A Node without that role returns `project.excluded_node_not_app_dev` (HTTP 422) from either family.
- `remove` from either family deletes the row. A pair that is not stored returns `project.excluded_node_missing` (HTTP 404). `remove` does not ask for confirmation, because it does not change a Node or an Instance.
- Deleting the `app-dev` role deletes every exclusion row for that Node in the same operation. A role record that remains keeps its rows. Putting `app-dev` back does not restore them. Deleting the Node or the Project deletes the rows through the foreign keys.
- `project:show` includes `excluded_nodes`. `node:show` includes `excluded_projects`. Each entry names the Project, the Node, and how many development Instances of that Project are already on that Node. `add` reports the same count. Those Instances stay where they are.
- Development placement consults the list. The task scheduler skips an excluded Node before the driver check and the per-Node ceiling, then chooses the least-loaded remaining Node. When no Node remains, the group stays without a workspace. `instance:create`, `instance:register`, and development `instance:transfer` stop before mutation with `instance.node_excluded` (HTTP 409) when the destination is excluded for that Project.
- Production placement does not consult the list. A Node that also has `app-prod` can still host a production Instance of the Project.
- The CLI, Gateway, and PHP SDK expose both families. The Gateway routes are the MCP tools `project-excluded-node-add`, `project-excluded-node-list`, `project-excluded-node-remove`, `node-excluded-project-add`, `node-excluded-project-list`, and `node-excluded-project-remove`. The feature regenerates `docs/openapi.json` and `apps/gateway/resources/mcp/tools.json`. It does not add a hand-written MCP tool.

## Rejected alternatives

- An allowlist of Nodes: rejected because an empty list must keep today's placement, and a new app-dev Node must stay eligible until it is excluded.
- A Node capability such as Incus: rejected because Orbit does not record that capability, and the operator's choice is a specific Node.
- One command family with a flag for the starting side: rejected because each door is an association between records that already exist. Two families write one row.
- Hand-written MCP tools: rejected because [ADR 0086](/decisions/0086-offer-the-api-as-mcp-tools) generates the catalogue from the API.
- Moving Instances that are already on an excluded Node: rejected because the list applies to new development placement. The operator moves an existing Instance with transfer.
- Applying the list to production placement: rejected because production placement uses the `app-prod` role.
- Keeping the row after the `app-dev` role is removed: rejected because the row leaves with the role. Putting the role back makes the Node eligible until it is excluded again.

## Consequences

- Orbit can exclude Sabre and still land task workspaces on Shark or Beast. Another Project with no row for Sabre can use Sabre.
- An operator can start from the Project or from the Node and see the same pairs.
- An agent can add, list, and remove exclusions through MCP with the API's validation and error codes.
- A new app-dev Node receives development work until a Project excludes it.
- Instances already on an excluded Node keep running. The add response names how many, and the operator transfers or removes them separately.
- Removing `app-dev` clears the Node from every Project list. Adding the role again does not put those exclusions back.

## Affects

- Components: apps/cli, apps/docs, apps/gateway, packages/php-sdk
- ADRs: extends [ADR 0066](/decisions/0066-transfer-development-appinstances-between-nodes), [ADR 0071](/decisions/0071-use-one-verb-vocabulary-across-cli-routes-and-sdk), [ADR 0086](/decisions/0086-offer-the-api-as-mcp-tools), and [ADR 0103](/decisions/0103-absorb-commander-tasks-as-a-gateway-extension)
- Detail: [Development node exclusions](/reference/development-node-exclusions)
- Verify: `composer docs-lint`; Gateway, PHP SDK, and CLI tests for both families, for placement refusal, and for role removal clearing the rows; `bin/mcp-tools --check`
