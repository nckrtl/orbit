#!/usr/bin/env bash
# The provisioned dashboard follows the scraped node set without replacing
# Grafana, which is what keeps it out of the Grafana configuration hash.
source /var/lib/orbit-e2e/proof/lib.sh

# Start from a known target set so the dashboard has to change.
orbit metrics:exporter:enable app-prod --json >/dev/null

before_grafana=$(container_id orbit-metrics-grafana)

orbit metrics:exporter:disable app-prod --json >/dev/null

if grep -q app-prod /etc/orbit/metrics/grafana/dashboards/orbit-node-resources.json; then
  fail "the rendered dashboard still names the removed target"
fi

served=1
for _ in $(seq 1 12); do
  sleep 10
  served=$(served_dashboard_mentions app-prod)

  if [[ "$served" == "0" ]]; then
    break
  fi
done

[[ "$served" == "0" ]] || fail "Grafana still serves a dashboard naming the removed target"
[[ "$(container_id orbit-metrics-grafana)" == "$before_grafana" ]] \
  || fail "Grafana was replaced while its dashboard reloaded"

orbit metrics:exporter:enable app-prod --json >/dev/null

echo "dashboard-reload: grafana stable on $before_grafana while the dashboard followed the fleet"
