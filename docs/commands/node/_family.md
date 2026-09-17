---
title: "node"
description: "Add machines to the fleet, assign roles, grant Node-to-Node access, set storage settings, and remove Nodes."
commands:
  - node:add
  - node:list
  - node:show
  - node:remove
  - node:role:add
  - node:role:list
  - node:role:remove
  - node:access:add
  - node:access:remove
  - node:settings
---

A Node is a machine that the Gateway manages over SSH and reaches over WireGuard. The `node` family provisions a machine, converges it again after a change, assigns and removes roles, records which Node may call which, and sets the apps root.

The [Node provisioning](/reference/node-provisioning), [Node settings](/reference/node-settings), and [Node retarget](/reference/node-retarget) references own the bootstrap identity, the storage contract, and the public SSH boundaries that these commands follow.

## Commands

| Command | Result |
| --- | --- |
| [`node:add`](#orbit-nodeadd) | Provision a new machine or converge an existing Node. |
| [`node:list`](#orbit-nodelist) | List Nodes registered with the active Gateway. |
| [`node:show`](#orbit-nodeshow) | Show one Node. |
| [`node:remove`](#orbit-noderemove) | Remove a Node and restore its public SSH recovery rule. |
| [`node:role:add`](#orbit-noderoleadd) | Add or converge one role assignment. |
| [`node:role:list`](#orbit-noderolelist) | List the role assignments of one Node. |
| [`node:role:remove`](#orbit-noderoleremove) | Remove one role assignment and its dependent state. |
| [`node:access:add`](#orbit-nodeaccessadd) | Allow one Node to run commands on another Node. |
| [`node:access:remove`](#orbit-nodeaccessremove) | Remove one access edge. |
| [`node:settings`](#orbit-nodesettings) | Update the typed storage settings of one Node. |

Every command accepts `--json`. Commands that take a `node` argument or `--node` option accept the numeric Node ID; `node:role:*` and `node:settings` also accept the registered Node name.

Node and role lists use read-only tables with every column retained at narrow widths. Below the minimum table width, each row becomes a labeled record. Empty lists say `No nodes.` or `No roles.`. Node details use an aligned tree; absent human values use an em dash, and TLDs include their leading dot. JSON retains the existing fields and null values.

Requests show an indeterminate progress tree while the Gateway works. The CLI does not receive individual provisioning steps. It settles the operation before showing its result or error and request ID. Piped output keeps readable waiting and settled results without animation; JSON contains only the response.

Arguments and settings remain explicit in every mode. Removal confirmations default to No and identify the target and effect. Declining, cancelling, or reaching end of input exits 1 before removal. Automation, including JSON, requires the existing `--force` consent option. `--offline` and `--purge-data` do not supply consent.

{/* commands */}
