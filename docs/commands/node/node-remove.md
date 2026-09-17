---
title: "node:remove"
description: "Remove a Node and restore its public SSH recovery rule."
---

Remove a Node record and its Gateway-side projections. The Gateway restores the public SSH recovery rule and leaves the machine reachable on its recorded public SSH target so you can provision it again.

```bash
orbit node:remove <node> [--force] [--offline] [--json]
```

| Argument | Required | Meaning |
| --- | --- | --- |
| `node` | yes | Numeric Node ID. |

| Option | Meaning |
| --- | --- |
| `--force` | Skip the confirmation prompt. Required with `--offline`. |
| `--offline` | Shed roles on the Gateway side and remove a Node that the Gateway cannot reach. The Gateway probes the Node first; a Node that answers keeps every ordinary guard. |

The Gateway refuses the request while the Node still owns App instances, legacy instances, firewall rules, roles, Processes, or Herdr sessions. It never removes the Gateway or VPN Node, or the Node that sends the request.

```bash
orbit node:remove 7
orbit node:remove 7 --offline --force
```

<Warning>
`--offline --force` changes nothing on the machine. Caddy sites, checkouts, containers, units, and UFW rules stay in place, public SSH stays closed, and the response lists what remains under `retained_on_node`.
</Warning>

The [Node provisioning reference](/reference/node-provisioning#remove-a-node) lists each removal step and failure code.
