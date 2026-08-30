#!/usr/bin/env bash
# Re-enabling is ordinary registration. The Gateway converges the fleet back to
# a healthy Metrics node and republishes the escape it had removed.
source /var/lib/orbit-e2e/proof/lib.sh

orbit metrics:enable app-dev --json >/dev/null || fail "metrics:enable did not converge"

status=$(orbit metrics:status --json)
[[ "$(echo "$status" | json_get enabled)" == true ]] || fail "metrics not enabled: $status"
[[ "$(echo "$status" | json_get assignment.status)" == active ]] || fail "assignment not active: $status"
[[ "$(echo "$status" | json_get prometheus)" == healthy ]] || fail "prometheus unhealthy: $status"
[[ "$(echo "$status" | json_get grafana)" == healthy ]] || fail "grafana unhealthy: $status"

container_exists orbit-metrics-prometheus || fail "the Prometheus container did not come back"
container_exists orbit-metrics-grafana || fail "the Grafana container did not come back"
volume_exists orbit-metrics-prometheus-data || fail "the Prometheus volume did not come back"
volume_exists orbit-metrics-grafana-data || fail "the Grafana volume did not come back"
sudo test -f /etc/orbit/metrics/.orbit-owner || fail "the ownership marker did not come back"
firewall_rule_exists orbit:metrics-node-exporter || fail "the 9100 rule did not come back"
firewall_rule_exists orbit:metrics-grafana-upstream || fail "the Grafana upstream rule did not come back"
sudo test -x "$ESCAPE" || fail "convergence did not republish the escape"

echo "reenable-healthy: $(echo "$status" | json_get url) is healthy again after the escape"
