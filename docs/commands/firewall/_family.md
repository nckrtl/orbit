---
title: "firewall"
description: "Allow, deny, list, and remove named UFW rules on one Node through the Gateway."
commands:
  - firewall:allow
  - firewall:deny
  - firewall:list
  - firewall:remove
---

Every managed Node runs Uncomplicated Firewall (UFW). Orbit owns the rules it needs for public SSH recovery, WireGuard member trust, and each role; those rules carry an `orbit:` prefix. The `firewall` family adds operator rules beside them, each identified by a stable name on one Node.

The Gateway applies each rule over SSH, verifies it in the UFW status output, and records the result. Doctor checks the `firewall` family and reports a rule that is missing or different from its record without changing the machine.

## Commands

| Command | Result |
| --- | --- |
| [`firewall:allow`](#orbit-firewallallow) | Create or converge one named allow rule. |
| [`firewall:deny`](#orbit-firewalldeny) | Create or converge one named deny rule. |
| [`firewall:list`](#orbit-firewalllist) | List the named rules of one Node. |
| [`firewall:remove`](#orbit-firewallremove) | Remove one named rule. |

Every command accepts `--json` and requires `--node` with a numeric Node ID. A rule name is 1 through 63 characters of lowercase letters, digits, dots, underscores, and hyphens, starting and ending with a letter or digit; it cannot contain a colon, so an operator name never collides with an `orbit:` rule. The Gateway tags each UFW rule with the comment `orbit:node:<node-id>:firewall:<name>` and uses that comment for every later change.

All targets and rule values are explicit; these commands do not prompt for missing arguments or options. Human requests show an indeterminate progress tree while the Gateway works, then the verified result and request ID. Piped output has no animation or escape codes. JSON contains only the existing response or error.

{/* commands */}

## Error codes

The CLI validates the name, source, protocol, and port before it sends a request. The Gateway returns the remaining codes.

| Error code | Meaning |
| --- | --- |
| `firewall.node_id_invalid` | `--node` is not a positive integer. |
| `firewall.rule_name_required`, `firewall.rule_name_invalid` | The name is missing or outside the accepted form. |
| `firewall.source_invalid` | The source is not `any`, an IP address, or a valid CIDR. |
| `firewall.protocol_invalid` | The protocol is not `tcp` or `udp`. |
| `firewall.port_invalid` | The port is outside 1 through 65535 or the range is not ordered. |
| `firewall.name_taken` | The name exists on the Node with different values. |
| `firewall.action_conflict` | An opposite rule with the same source, protocol, and port exists. |
| `firewall.public_ssh_deny_forbidden` | The deny rule would cover the public recovery SSH port. |
| `firewall.rule_collision` | The rule name identifies conflicting UFW rules on the machine; Orbit adopts neither. |
| `firewall.backend_inactive` | UFW is inactive on the Node. |
| `firewall.platform_unsupported` | The Node is not a Linux Node. |
| `firewall.apply_failed`, `firewall.verify_failed` | UFW did not accept the rule or the verification did not find it. |
| `firewall.remove_failed`, `firewall.remove_verify_failed` | UFW did not remove the rule or the verification still found it. |
| `firewall.node_unreachable` | The Gateway could not reach the Node over SSH. |

## Related

- [`doctor`](/cli/doctor) inspects the `firewall` family with `--family=firewall`.
- [`node`](/cli/node) removal refuses a Node that still owns named firewall rules.
