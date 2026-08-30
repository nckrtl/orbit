#!/usr/bin/env bash
# Metrics is active on app-dev and its managed configuration is re-rendered from this branch.
source /var/lib/orbit-e2e/proof/lib.sh

status=$(orbit metrics:status --json)
if [[ "$(echo "$status" | json_get enabled)" != true ]]; then
  orbit metrics:enable app-dev --json >/dev/null
  status=$(orbit metrics:status --json)
fi

[[ "$(echo "$status" | json_get assignment.status)" == active ]] || fail "metrics assignment not active: $status"
[[ "$(echo "$status" | json_get assignment.node_name)" == app-dev ]] || fail "metrics not on app-dev: $status"

# A fleet reconcile re-renders every managed file, so Grafana is provisioned with this branch's dashboard.
orbit metrics:exporter:enable gateway --json >/dev/null
grep -q 'node: "app-dev"' /etc/orbit/metrics/prometheus.yml || fail "prometheus targets were not rendered"

for _ in $(seq 1 60); do
  status=$(orbit metrics:status --json)
  if [[ "$(echo "$status" | json_get prometheus)" == healthy && "$(echo "$status" | json_get grafana)" == healthy ]]; then
    echo "converge: metrics active on app-dev, prometheus+grafana healthy, configuration re-rendered"
    exit 0
  fi
  sleep 5
done

fail "prometheus or grafana did not become healthy: $status"
