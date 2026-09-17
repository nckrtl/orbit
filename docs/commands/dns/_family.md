---
title: "dns"
description: "Point one development TLD at an IP address from a macOS operator machine, or remove that override."
commands:
  - dns:resolve
---

The `dns` family changes resolver configuration on the operator machine only. It sends nothing to the Gateway. Use it when a Mac needs to reach `*.test` domains on a development Node directly instead of through Orbit VPN DNS.

[Private DNS](/reference/private-dns) owns resolver selection on managed Nodes and the Cluster Router addresses that Gateway DNS returns.

## Commands

| Command | Result |
| --- | --- |
| [`dns:resolve`](#orbit-dnsresolve) | Configure or remove a local resolver override for one TLD. |

The command accepts `--json` and requires macOS with Homebrew `dnsmasq` installed.

{/* commands */}

## Related

- [`node`](/cli/node) sets a Node TLD with `node:add --tld`. Generated Route domains use that TLD when no active Cluster TLD owns the Node.
- [`route`](/cli/route) lists the domains that resolve under a TLD.
