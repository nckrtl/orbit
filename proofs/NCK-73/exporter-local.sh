#!/usr/bin/env bash
# node-exporter runs under systemd on this node's WireGuard address with the Orbit UFW rule.
source /var/lib/orbit-e2e/proof/lib.sh

address=$(wireguard_address)
[[ -n "$address" ]] || fail "no WireGuard address on orbit"
[[ "$(systemctl is-active prometheus-node-exporter)" == active ]] || fail "exporter inactive"
grep -q '^# Managed by Orbit: metrics' /etc/systemd/system/prometheus-node-exporter.service.d/orbit.conf || fail "drop-in missing"
listeners=$(ss -ltn)
[[ "$listeners" == *" $address:9100 "* ]] || fail "exporter not bound to $address:9100"
[[ "$listeners" != *" 0.0.0.0:9100 "* ]] || fail "exporter listens on all interfaces"
metrics=$(curl --silent --fail --max-time 5 "http://$address:9100/metrics") || fail "exporter unreachable locally"
[[ "$metrics" == *node_exporter_build_info* ]] || fail "exporter answered without build info"
ufw_has_comment orbit:metrics-node-exporter || fail "UFW exporter rule missing"
rule=$(sudo ufw status | grep 'orbit:metrics-node-exporter' || true)
[[ "$rule" == *" on orbit "* ]] || fail "UFW rule not bound to the orbit interface: $rule"
echo "exporter: active on $address:9100 with Orbit UFW rule"
