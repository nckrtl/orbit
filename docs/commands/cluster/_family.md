---
title: "cluster"
description: "Group Nodes into a Cluster, give it a development TLD and one Router, and attach or detach member Nodes."
commands:
  - cluster:create
  - cluster:list
  - cluster:show
  - cluster:update
  - cluster:node:add
  - cluster:node:remove
  - cluster:router:set
  - cluster:router:unset
  - cluster:destroy
---

A Cluster is an optional group of Nodes with one name, at most one development TLD, and one Router. A Node outside a Cluster is standalone. Active membership gives a Node's Routes Cluster scope, so private traffic reaches them through the Router instead of the workload Node directly.

The [Routes reference](/reference/routes) owns domain generation, routing scope, and Router transition ownership. [Private DNS](/reference/private-dns#cluster-router-addresses) owns which Router address a requester receives.

## Commands

| Command | Result |
| --- | --- |
| [`cluster:create`](#orbit-clustercreate) | Create a Cluster, optionally with a TLD. |
| [`cluster:list`](#orbit-clusterlist) | List Clusters. |
| [`cluster:show`](#orbit-clustershow) | Show one Cluster with its members and Router. |
| [`cluster:update`](#orbit-clusterupdate) | Change the name, TLD, or state. |
| [`cluster:node:add`](#orbit-clusternodeadd) | Attach a Node. |
| [`cluster:node:remove`](#orbit-clusternoderemove) | Detach a Node. |
| [`cluster:router:set`](#orbit-clusterrouterset) | Set or replace the Router. |
| [`cluster:router:unset`](#orbit-clusterrouterunset) | Clear the Router from an inactive Cluster. |
| [`cluster:destroy`](#orbit-clusterdestroy) | Remove an empty Cluster. |

Every command accepts `--json`. Cluster and Node arguments are explicit numeric IDs. Human lists use uppercase table headers, and one Cluster uses a detail tree. TLDs display with a leading dot; missing values display an em dash. Requests show progress while waiting and retain their Gateway request ID.

Destructive Cluster commands keep `--force` as their existing consent option. The confirmation names the resolved Cluster and the affected Node when detaching a member. Interactive confirmation starts at No. Declining, Ctrl-C or EOF stops before mutation. A pipe or `--json` never supplies consent and requires `--force`.

A new Cluster is `inactive`. Membership, name, and TLD can be prepared while it is inactive without changing any traffic. Activating a Cluster that has a TLD, or giving an active Cluster a TLD, is the routing cutover: it requires exactly one active Router and reconciles every affected Route before the change becomes authoritative.

{/* commands */}

## Related

- [`node`](/cli/node) provisions the Nodes that join a Cluster and assigns the `router` and `ingress` roles.
- [`route`](/cli/route) creates explicit Routes with Cluster scope.
