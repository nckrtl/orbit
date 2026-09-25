---
title: "ADR 0148: Install the private DNS listener from the Gateway"
sidebarTitle: "0148 Install the private DNS listener from the Gateway"
description: "Proposed. The Gateway builds the private DNS listener as a small release from its own code and installs it on the vpn Node with every listener publication. A systemd socket unit holds the DNS address, so a listener restart never stops VPN DNS. The listener confirms each catalog it loads."
---

# ADR 0148: Install the private DNS listener from the Gateway

The Gateway builds the private DNS listener as a small release from its own code and installs it on the Node that holds `vpn` with every listener publication. `orbit-private-dns.socket` holds the DNS address, so restarting the listener never stops VPN DNS. The listener writes the digest of each catalog it loads, and the publication restarts a listener that does not confirm the published catalog.

## Status

Proposed.

This amends [ADR 0092](/decisions/0092-publish-private-dns-on-the-vpn-node-after-gateway-relocate). The publication target and the listen address stay. A remote publication now manages the listener unit too, because the unit no longer points at a Gateway checkout.

## Context

The listener ran `artisan orbit:private-dns-serve` from a checkout on the `vpn` Node. When `gateway` and `vpn` share a Node, that is the Gateway checkout. After a relocate, the `vpn` Node keeps its own checkout, which no Orbit operation updated. The remote publication wrote only the records and the catalog.

Production showed the cost. The `vpn` listener ran code from 2026-09-22, and that code checked the catalog through PHP's cached file status. It never reloaded, so it kept answering names that the catalog had removed. The fix for that bug was on main, but it never reached `vpn`. A restart would also have closed the DNS address for about 2.6 seconds while PHP and the framework booted, and every VPN lookup fails during that gap.

The listener needs no framework: it reads a JSON catalog and answers DNS messages. Only the artisan entry point tied it to a full checkout with Composer dependencies.

## Decision

- `PrivateDnsListenerRelease` builds the release from the Gateway's source. It contains `serve.php` and every `App\` class that the listener process reaches, and nothing from `vendor/`. Its id is a digest of those files, so it changes exactly when the listener code changes.
- Every publication that activates the listener sends the release, the service unit, and a socket unit to the Node that holds `vpn`. It uses the same script locally or over SSH. It installs a missing release in `/var/lib/orbit/private-dns/releases/<id>/`, and it installs only a release that passes `serve.php --self-test`. It keeps the previous release and removes older ones.
- `orbit-private-dns.socket` binds UDP and TCP port 53 on the VPN DNS address with `FreeBind=yes` and passes both sockets to `orbit-private-dns.service`. The service runs `serve.php` from the current release. A restart keeps the sockets open, so queries that arrive during it wait instead of failing. The listener finishes the query in hand when it gets `SIGTERM`.
- When the listener still binds the address itself, the publication stops it and starts the socket unit right after. This happens once per Node.
- The listener rereads the catalog on every query and once a second while idle. It compares file contents, not cached file status. After each load it writes the SHA-256 digest to `catalog.json.loaded`.
- The publication restarts the service when its unit changes, which includes a new release. It also restarts a running listener that does not confirm a changed catalog within 5 seconds, or that confirmed another catalog. Before each start or restart it removes the confirmation, so only the new listener can write it. A missing confirmation with an unchanged catalog counts as current, so a listener that never writes one is not restarted again.
- A failed start or restart restores the previous units, DNS files, and services.

## Rejected alternatives

- Sync the `vpn` checkout to the Gateway's commit with Git and Composer: rejected because every deploy would depend on GitHub and Packagist access from `vpn`, a full dependency install, and a rollback of both. The listener uses a handful of framework-free classes.
- Restart a self-binding listener on a change: rejected because each restart stops VPN DNS for seconds.
- Hand over with `SO_REUSEPORT` between two listener processes: rejected because systemd runs one main process per service. It would need two alternating units, and the kernel drops datagrams queued on the old socket when it closes.
- Keep the artisan entry point and install only the changed classes: rejected because the listener would still boot the framework and depend on a checkout.

## Consequences

- A Gateway deploy reaches the `vpn` listener with the next publication, with no manual step on `vpn`.
- Catalog changes never restart a current listener, and a restart never stops VPN DNS.
- The listener classes must stay free of framework code. Every `App\` class that a listener class names joins the release, so the database requester lookup moved to its own class. The release test runs the release without the Composer autoloader.
- Each listener publication carries about 70 KB of release files. It writes them only when that release id is missing.
- The first publication after the upgrade stops VPN DNS for a few milliseconds while the address moves to the socket unit.

## Affects

- Components: apps/gateway, apps/docs
- ADRs: amends [ADR 0092](/decisions/0092-publish-private-dns-on-the-vpn-node-after-gateway-relocate)
- Detail: [Private DNS](/reference/private-dns#listener-release-and-sockets)
- Verify: `PrivateDnsListenerReleaseTest`, `PrivateDnsListenerActivationTest`, `DnsmasqPrivateDnsManagerTargetingTest`, `FilePrivateDnsCatalogStoreTest`; an Incus proof with a continuous `dig` loop through catalog changes, a release change, and the move to the socket unit
