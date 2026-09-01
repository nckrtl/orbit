#!/usr/bin/env bash
set -euo pipefail

fail() { echo "FAIL: $*" >&2; exit 1; }

addresses=$(ip -4 -o addr show dev orbit)
address=$(awk 'NR == 1 { split($4, value, "/"); print value[1] }' <<<"$addresses")
[[ -n "$address" ]] || fail "the Metrics node has no WireGuard address"
[[ "$(systemctl is-active prometheus-node-exporter)" == active ]] || fail "the historical exporter is inactive"
grep -q '^# Managed by Orbit: metrics' /etc/systemd/system/prometheus-node-exporter.service.d/orbit.conf \
  || fail "the historical exporter drop-in is absent"
listeners=$(ss -ltn)
[[ "$listeners" == *" $address:9100 "* ]] || fail "the historical exporter is not bound to $address:9100"
metrics=$(curl --silent --fail --max-time 5 "http://$address:9100/metrics") \
  || fail "the historical exporter is unreachable"
[[ "$metrics" == *node_exporter_build_info* ]] || fail "the historical exporter omitted build information"
firewall=$(sudo /usr/sbin/ufw status)
grep -q 'orbit:metrics-node-exporter' <<<"$firewall" || fail "the historical exporter firewall rule is absent"

echo "historical Metrics proof: exporter service, endpoint, drop-in, and firewall rule remain valid"
