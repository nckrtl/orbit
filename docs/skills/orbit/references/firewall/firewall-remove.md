---
title: "firewall:remove"
description: "Remove one named rule."
---

# firewall:remove

Remove one named rule from UFW and delete its record. Before prompting, the CLI resolves the rule with a read-only lookup, then asks `Remove firewall rule [name] from Node #ID?` with No selected. Supply `--yes` for automation, including JSON; JSON never implies consent.

```bash
orbit firewall:remove <name> --node=ID [--yes] [--json]
```

| Argument | Required | Meaning |
| --- | --- | --- |
| `name` | yes | Stable rule name. |

| Option | Meaning |
| --- | --- |
| `--node=ID` | Numeric target Node ID. |
| `--yes` | Confirm rule removal without prompting. |

```bash
orbit firewall:remove office-postgres --node=7
orbit firewall:remove office-postgres --node=7 --yes --json
```

The Gateway marks the rule `removing`, deletes every UFW rule that carries its comment, verifies that none remains, and then deletes the record. A failure leaves the rule `failed` with the step that stopped; repeat the command to retry.

Declining, Ctrl-C, or end of input before removal exits 1 with `input.cancelled`. Noninteractive removal without `--yes` exits 1 with `input.confirmation_required` and an instruction to supply `--yes`. Refused confirmations send no removal request. Existing lookup, authorization, and safety errors retain their codes.
