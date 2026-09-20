---
title: "ADR 0093: Show live UFW and desired rules that match converge"
sidebarTitle: "0093 Show live UFW and desired rules that match converge"
description: "Proposed. The managed firewall catalog matches role converge. The Node page lists live UFW and marks drift in red."
---

# ADR 0093: Show live UFW and desired rules that match converge

The managed firewall catalog is the desired set role converge keeps, not a static list of every baseline comment. The Node firewall page lists live UFW rules, paints live rules that are not an Orbit managed or operator rule in red, and flags desired rules that are missing from live.

## Status

Proposed.

This extends the read-only managed catalog introduced for the Node page. It does not change how converge mutates UFW.

## Context

`NodeFirewallRuleCatalog::forNode()` returns both `orbit:public-ssh-recovery` and `orbit:wireguard-members`. Bootstrap opens public SSH. The first active role converge removes that rule and keeps WireGuard member trust. The Node page listed `forNode()` as locked intent, so a Node with roles still advertised public port 22 after UFW no longer had it.

Operators need the rules that are on the machine, not only the catalog. A leftover public SSH rule, an extra allow, or a missing role rule must be visible without running Doctor.

## Decision

- The desired managed set omits `orbit:public-ssh-recovery` when the Node has at least one active role. A Node with no active role still intends public SSH recovery. Role-owned and Metrics-owned `orbit:` rules stay in that set.
- `GET /api/v1/nodes/{node}/managed-firewall-rules` returns that desired set. It does not SSH. No request adds, changes, or removes a managed rule.
- `GET /api/v1/nodes/{node}/live-firewall-rules` reads `ufw status numbered` over WireGuard and classifies each parsed IPv4 rule against the desired managed set plus operator `firewall` records. Live rules that do not match are drift. Desired or operator rules absent from live are missing. An inactive, absent, or unreachable backend returns no live rows and does not invent missing rows.
- The Node page lists live UFW first. Drift rows are red. Missing desired rows sit below a divider. When live UFW cannot be read, the page falls back to operator rules and the desired catalog.

## Rejected alternatives

- Hide `orbit:public-ssh-recovery` only in the web table: rejected because the catalog would still advertise a closed path to every other client.
- Change `forNode()` into the desired set and keep converge indexing `[0]` and `[1]`: rejected because restore and bootstrap still need the public SSH rule after roles exist.
- Treat Doctor as the only live view: rejected because the Node page is where operators already look, and Doctor is a separate verify-only run.
- Mutate fleet UFW from this read path: rejected. This decision is catalog honesty and display.

## Consequences

- A Node with an active role no longer lists public SSH recovery as intended.
- A leftover `orbit:public-ssh-recovery` rule on that Node appears as red drift.
- The live list SSHes the Node. An unreachable Node does not fail the catalog read.
- The PHP SDK does not add these two read routes. The web client and MCP consume them.

## Affects

- Components: apps/docs, apps/gateway
- ADRs: none
- Detail: `/cli/firewall`
- Verify: `ManagedFirewallRulesTest`, `LiveFirewallRulesTest`, `FirewallLiveDriftClassifierTest`, `RoleCatalogContractsTest`, and the Node page browser snapshot
