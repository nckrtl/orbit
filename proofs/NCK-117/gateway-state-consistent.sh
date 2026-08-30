#!/usr/bin/env bash
# Whatever Orbit could not clean on the node, the Gateway side it owns must be
# exactly consistent.
#
# This is the only assertion in the plan that a removed node's peer is *gone*:
# `wireguard.reachability` proves the declared peers still have routes, never
# that a withdrawn one lost its. So the counts matter as much as the contents.
# Every check below inspects at least one real record and says how many, and
# the peer count is pinned to the number of nodes Orbit still knows.
source /var/lib/orbit-e2e/proof/lib.sh

[[ "$(node_present app-prod)" == no ]] || fail "app-prod is still registered"
[[ "$(node_roles app-prod)" == absent ]] || fail "app-prod still carries role rows"

registered=$(orbit node:list --json | php -r '
  $addresses = [];
  foreach (json_decode(stream_get_contents(STDIN), true)["nodes"] ?? [] as $node) {
    if (is_string($node["wireguard_address"] ?? null)) { $addresses[] = $node["wireguard_address"]; }
  }
  sort($addresses);
  echo implode(" ", $addresses);
')
[[ -n "$registered" ]] || fail "could not read the registered node addresses"
registered_count=$(wc -w <<<"$registered")
(( registered_count >= 2 )) || fail "expected the gateway and at least one peer to remain, got: $registered"

# The Gateway carries one peer per node it still knows, itself excepted. An
# outlived peer shows up as a count mismatch even if its address looks valid.
routes=$(sudo wg show orbit allowed-ips) || fail "could not read the WireGuard peers"
peers=$(grep -oE '[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+/32' <<<"$routes" | sort -u)
peer_count=$(grep -c . <<<"$peers" || true)
(( peer_count == registered_count - 1 )) \
  || fail "expected $(( registered_count - 1 )) WireGuard peers for [$registered], found $peer_count: $peers"

for route in $peers; do
  address=${route%/32}
  [[ " $registered " == *" $address "* ]] \
    || fail "WireGuard peer [$address] outlived its node; registered: $registered"
done

# Same for every address the Orbit-managed record file resolves.
records=/etc/dnsmasq.d/orbit-records.conf
[[ -r "$records" ]] || fail "the Orbit DNS record file is missing at $records"
dns_addresses=$(grep -oE '[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+' "$records" | sort -u)
dns_count=$(grep -c . <<<"$dns_addresses" || true)
(( dns_count >= 1 )) || fail "no DNS records were inspected in $records"

for address in $dns_addresses; do
  [[ " $registered " == *" $address "* ]] \
    || fail "DNS record for [$address] outlived its node; registered: $registered"
done

echo "gateway-state-consistent: $peer_count peer(s) and $dns_count DNS address(es) inspected, all registered; app-prod has none"
