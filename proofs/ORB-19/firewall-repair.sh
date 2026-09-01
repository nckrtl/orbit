#!/usr/bin/env bash
source /var/lib/orbit-e2e/proof/lib.sh

orbit node:role:add app-dev metrics --converge --json >/dev/null
ufw_has_comment orbit:metrics-node-exporter || fail "explicit convergence did not restore exporter rule"
ufw_has_comment orbit:metrics-grafana-upstream || fail "explicit convergence did not restore Grafana upstream rule"

status=$(sudo ufw status numbered)
exporter=$(echo "$status" | grep -F '# orbit:metrics-node-exporter' || true)
publication=$(echo "$status" | grep -F '# orbit:metrics-grafana-upstream' || true)
metrics=$(wireguard_address)
gateway=$(gateway_address)
[[ "$exporter" == *"$metrics"* && "$exporter" == *'9100/tcp on orbit'* ]] \
  || fail "restored exporter rule has the wrong shape: $exporter"
[[ "$publication" == *"$metrics"* && "$publication" == *"$gateway"* && "$publication" == *'3000/tcp on orbit'* ]] \
  || fail "restored publication rule has the wrong shape: $publication"

report=$(orbit doctor --node="$(node_id app-dev)" --family=firewall --json)
[[ "$(echo "$report" | json_get healthy)" == true ]] || fail "firewall Doctor is not healthy after repair: $report"

echo "firewall-repair: explicit Metrics convergence restored exact rules and Doctor is healthy"
