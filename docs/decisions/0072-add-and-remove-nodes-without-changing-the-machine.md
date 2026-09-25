---
title: "ADR 0072: Add and remove Nodes without changing the machine"
sidebarTitle: "0072 Add and remove Nodes without changing the machine"
description: "Accepted on 2026-09-14. Extends ADR 0069 and ADR 0071."
---

# ADR 0072: Add and remove Nodes without changing the machine

In the context of Node lifecycle commands, facing a removal that stops Processes, retracts observers, and restores a firewall rule on the machine, we decided for node:add as provision or converge and node:remove as a registry and VPN operation guarded by owned state and against a removal that cleans the machine, to keep the machine's state owned by explicit commands, accepting that an operator destroys Processes and Herdr sessions before removal.

## Status

Accepted on 2026-09-14. Extends [ADR 0069](/decisions/0069-allow-node-process-targets) and [ADR 0071](/decisions/0071-use-one-verb-vocabulary-across-cli-routes-and-sdk). Supersedes [ADR 0069](/decisions/0069-allow-node-process-targets) for Process cleanup during Node decommissioning. Amended by [ADR 0146](/decisions/0146-retire-the-herdr-integration): Orbit has no Herdr sessions, so removal no longer refuses, retracts, or forgets them.

## Context

Node removal refuses a Node that owns AppInstances, roles, or firewall rules, then stops and deletes Node-owned Processes, retracts Herdr observers, restores the public SSH recovery rule over WireGuard, removes the WireGuard peer, and deletes the record. Role convergence closes public SSH, so a removed machine without the recovery rule is reachable only through its provider console. ADR 0069 made Process cleanup part of decommissioning. ADR 0071 names the Node pair add and remove and reserves create and destroy for machines at a hosting provider.

## Decision

- The Gateway must treat node:add as provisioning for a Node without a record and as convergence for a Node with one.
- The Gateway must refuse node:remove while the Node owns AppInstances, roles, firewall rules, Processes, or Herdr sessions.
- The Gateway must not stop, delete, or reconfigure a Process, Herdr session, role, or checkout on the machine during node:remove.
- The Gateway must restore the public SSH recovery rule on the machine before it removes the WireGuard peer, so a node:add after removal can connect.
- The Gateway must remove the WireGuard peer, private DNS records, Metrics exporter state, Grafana access, and the Node record during node:remove.
- The Gateway may drop the records of roles, Processes, and Herdr sessions without machine access when the operator confirms the Node is unreachable, and must report the state that remains on the machine.

## Rejected alternatives

- Keep Process and Herdr cleanup inside removal: rejected because removal then mutates the machine while its name says it does not, and a failed cleanup leaves a half-removed Node.
- Remove without restoring public SSH: rejected because role convergence closes public SSH and the removed machine is then reachable only through the provider console.
- Name the pair provision and remove: rejected because the same request converges an existing Node, and add names both cases under ADR 0071.

## Consequences

- Node removal is a registry and VPN change plus one firewall rule, so the machine holds exactly what the operator left on it.
- An operator destroys Processes and Herdr sessions before removal, or confirms the Node unreachable and accepts retained state on the machine.
- Provider-backed node:create and node:destroy remain undecided; this record defines add and remove only.

## Affects

- Components: apps/cli, apps/docs, apps/e2e, apps/gateway, packages/php-sdk
- ADRs: extends [ADR 0069](/decisions/0069-allow-node-process-targets) and [ADR 0071](/decisions/0071-use-one-verb-vocabulary-across-cli-routes-and-sdk); supersedes [ADR 0069](/decisions/0069-allow-node-process-targets) for Process cleanup during Node decommissioning
- Detail: [Node provisioning](/reference/node-provisioning)
- Verify: `composer docs-lint`; Gateway Node add and removal tests
