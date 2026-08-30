#!/usr/bin/env bash
# Whatever Orbit could not clean on the node, the Gateway side it owns must be
# exactly consistent: no peer, no DNS record, no stale role row.
source /var/lib/orbit-e2e/proof/lib.sh

[[ "$(node_present app-prod)" == no ]] || fail "app-prod is still registered"
[[ "$(node_roles app-prod)" == absent ]] || fail "app-prod still carries role rows"

address=10.44.0.3

peers=$(sudo wg show orbit allowed-ips || fail "could not read the WireGuard peers")
[[ "$peers" != *"$address"* ]] || fail "the removed node still has a WireGuard peer: $peers"

records=$(cat /etc/dnsmasq.d/orbit-records.conf 2>/dev/null || true)
[[ "$records" != *"$address"* ]] || fail "the removed node still has a DNS record: $records"

echo "gateway-state-consistent: no WireGuard peer, no DNS record and no role rows for app-prod"
