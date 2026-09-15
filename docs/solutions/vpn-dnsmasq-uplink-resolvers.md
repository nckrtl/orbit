---
title: "VPN dnsmasq uplink resolvers"
description: "Why the VPN DNS backend needs independent uplink resolvers and how to verify that forwarding cannot loop back."
---

# VPN dnsmasq uplink resolvers

## Problem

VPN clients that use the Gateway WireGuard address as `DNS=` lose recursive
lookups when mesh dnsmasq forwards only to hardcoded public resolvers. A
default-deny stateless host firewall often permits only the uplink/DHCP
resolvers the node already received on its public NIC.

## Cause

The managed fragment set `no-resolv` and always emitted `server=1.1.1.1` and
`server=8.8.8.8`. That ignored the nameservers systemd-resolved and DHCP already
use on the uplink interface. Deleting `no-resolv` and pointing at `127.0.0.53`
or `127.0.0.54` would recurse through systemd-resolved's stub or proxy listener
and can loop once the mesh listener is bound.

## Solution

At converge, `UplinkDnsResolvers` in `apps/gateway` reads the systemd-resolved
uplink file, then the DHCP lease for the public NIC. The managed
fragment still sets `no-resolv` and emits `server=` lines for those IPv4
addresses. The fragment listens on `127.0.0.55` so the requester-aware listener
can own the Gateway WireGuard DNS port without colliding with systemd-resolved.
When no uplink resolvers are visible, the fragment keeps the documented public
recursive fallback (`1.1.1.1` and `8.8.8.8`).

## Limits

The reader accepts IPv4 nameservers only. It skips loopback and the WireGuard
`orbit` interface. It does not bake live-fleet addresses into the repository.

## Verification

Focused Pest coverage lives in
`apps/gateway/tests/Feature/Infrastructure/WireGuard/NativeGatewayVpnConvergerTest.php`
and
`apps/gateway/tests/Unit/Infrastructure/WireGuard/UplinkDnsResolversTest.php`.
