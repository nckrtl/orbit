---
title: "firewall:deny"
description: "Create or converge one named deny rule."
---

Deny traffic to one destination port or port range, optionally from one source. It accepts the same argument and options as `firewall:allow`.

```bash
orbit firewall:deny <name> --node=ID --port=PORT [--from=SOURCE] [--protocol=PROTO]
```

```bash
orbit firewall:deny block-scanner --node=7 --port=22 --from=203.0.113.9
```

<Warning>
The Gateway refuses a deny rule whose port contains the Node's public SSH port for any source that would cover it with `firewall.public_ssh_deny_forbidden`, because that port is the recovery path after a failed provisioning. A deny rule and an allow rule with the same source, protocol, and port cannot coexist on one Node; the second one returns `firewall.action_conflict`.
</Warning>
