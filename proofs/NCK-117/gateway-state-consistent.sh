#!/usr/bin/env bash
# Whatever Orbit could not clean on the node, the Gateway side it owns must be
# exactly consistent. Rather than naming the removed address, this asserts the
# invariant: every WireGuard peer and every managed DNS address belongs to a
# node Orbit still knows.
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

# Every peer route the Gateway still carries must belong to a registered node.
for route in $(sudo wg show orbit allowed-ips | tr '\t' '\n' | grep -oE '^[0-9.]+/32' || true); do
  address=${route%/32}
  [[ " $registered " == *" $address "* ]] || fail "WireGuard peer [$address] outlived its node: peers=$route registered=$registered"
done

# Same for every address dnsmasq resolves from the Orbit-managed record file.
for address in $(grep -oE '[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+' /etc/dnsmasq.d/orbit-records.conf | sort -u); do
  [[ " $registered " == *" $address "* ]] || fail "DNS record for [$address] outlived its node: registered=$registered"
done

echo "gateway-state-consistent: every peer and DNS record belongs to a registered node; app-prod has none"
