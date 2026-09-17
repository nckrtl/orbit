---
title: "firewall:allow"
description: "Create or converge one named allow rule."
---

# firewall:allow

Allow traffic to one destination port or port range, optionally from one source.

```bash
orbit firewall:allow <name> --node=ID --port=PORT [--from=SOURCE] [--protocol=PROTO]
```

| Argument | Required | Meaning |
| --- | --- | --- |
| `name` | yes | Stable rule name, unique per Node. |

| Option | Default | Meaning |
| --- | --- | --- |
| `--node=ID` | required | Numeric target Node ID. |
| `--port=PORT` | required | Destination port from 1 through 65535, or an ordered range such as `8000:8100`. |
| `--from=SOURCE` | `any` | Source IPv4 or IPv6 address, a CIDR such as `10.6.0.0/24`, or `any`. |
| `--protocol=PROTO` | `tcp` | `tcp` or `udp`. |

```bash
orbit firewall:allow office-postgres --node=7 --port=5432 --from=10.6.0.0/24
orbit firewall:allow media-udp --node=7 --port=6000:6100 --protocol=udp
```

Repeating the same command with the same values converges the existing rule and returns it. The same name with different values returns `firewall.name_taken`; remove the rule and create it again to change it. Human output reports `Firewall rule [name] is active.` with the request ID.
