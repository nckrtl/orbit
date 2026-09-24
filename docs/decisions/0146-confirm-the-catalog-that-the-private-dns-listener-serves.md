---
title: "ADR 0146: Confirm the catalog that the private DNS listener serves"
sidebarTitle: "0146 Confirm the catalog that the private DNS listener serves"
description: "Proposed. The private DNS listener writes the digest of each catalog it loads. Every publication checks that digest and restarts a running listener that does not confirm the published catalog, including a listener on a separate vpn node."
---

# ADR 0146: Confirm the catalog that the private DNS listener serves

The private DNS listener writes the SHA-256 digest of each catalog it loads next to the catalog. Every private DNS publication checks that digest while the listener runs. It restarts a listener that does not confirm the published catalog. This also applies on a separate `vpn` node, where the publication otherwise leaves the listener unit alone.

## Status

Proposed.

This extends [ADR 0092](/decisions/0092-publish-private-dns-on-the-vpn-node-after-gateway-relocate). The publication target, the listen address, and the rule that a remote publication does not rewrite the listener unit stay.

## Context

A publication replaces `catalog.json` and trusts the running listener to reread it. Nothing checked that it did. A long-running listener keeps the code it started with, so a Gateway deploy does not reach it until it restarts.

On production the roles split: the Gateway runs on `gateway` and the listener runs on `vpn` from that node's own checkout. No Orbit operation updates that checkout, and the remote publication writes only records and the catalog. The listener ran code whose reload check used PHP's cached file status, so it never reloaded after its first load. It kept answering names that the catalog had removed, including `collector.proxycli.orbit`. A restart or reboot would have switched every answer to the current catalog at once, without warning.

The operator needs the answers to follow the published catalog, whichever listener code runs.

## Decision

- `FilePrivateDnsCatalogStore` writes the SHA-256 digest of each catalog it loads to `catalog.json.loaded`, next to the catalog. A failed write never stops the listener.
- The listener rereads the catalog once a second while it is idle, so a quiet listener confirms a new catalog within a second.
- Every publication checks the confirmation when the listener unit is active and the publication did not start it.
  - A changed catalog must be confirmed within 5 seconds. Otherwise the publication restarts the listener and waits until it owns the DNS address again.
  - An unchanged catalog with a confirmation for another catalog also restarts the listener.
  - An unchanged catalog without any confirmation keeps the listener. Code that predates this decision never writes one.
- A failed restart restores the previous DNS files and services where the publication manages the listener. On a separate `vpn` node it fails the publication and keeps the new catalog.

## Rejected alternatives

- Restart the listener on every catalog change: rejected because each restart drops DNS on the VPN for a moment, and a current listener reloads without it.
- Update the `vpn` node's checkout from the Gateway on each publication: rejected because it needs Git and Composer access, dependency installs, and a rollback path on that node. That is a larger ownership change than keeping answers current.
- Probe changed names over DNS and compare the answers: rejected because an added name can come from the dnsmasq backend, so an answer cannot prove which catalog the listener loaded.
- Tell operators to restart the listener after each deploy: rejected because a missed step leaves answers stale with no signal.

## Consequences

- Private DNS answers follow the published catalog within one publication, even when the listener runs older code.
- A listener older than this decision restarts on every catalog change until its code is updated. Operators update the `vpn` checkout to the Gateway's commit to stop those restarts.
- A publication can take up to 5 seconds longer while it waits for a confirmation.
- The `vpn` checkout still does not follow Gateway deploys. Listener fixes other than catalog freshness reach that node only when an operator updates it.

## Affects

- Components: apps/gateway, apps/docs
- ADRs: extends [ADR 0092](/decisions/0092-publish-private-dns-on-the-vpn-node-after-gateway-relocate)
- Detail: [Private DNS](/reference/private-dns#catalog-confirmation)
- Verify: `FilePrivateDnsCatalogStoreTest`, `PrivateDnsListenerActivationTest`, `DnsmasqPrivateDnsManagerTargetingTest`; Incus proof that a stale listener is restarted and a current one is kept
