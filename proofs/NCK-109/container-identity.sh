#!/usr/bin/env bash
# A change to the scraped node set replaces Prometheus and leaves Grafana alone.
source /var/lib/orbit-e2e/proof/lib.sh

# Start from a known target set so the measured change is the only one.
orbit metrics:exporter:enable app-prod --json >/dev/null

before_prometheus=$(container_id orbit-metrics-prometheus)
before_grafana=$(container_id orbit-metrics-grafana)

orbit metrics:exporter:disable app-prod --json >/dev/null

removed_prometheus=$(container_id orbit-metrics-prometheus)
removed_grafana=$(container_id orbit-metrics-grafana)

[[ "$removed_prometheus" != "$before_prometheus" ]] \
  || fail "removing a target left Prometheus on [$before_prometheus]"
[[ "$removed_grafana" == "$before_grafana" ]] \
  || fail "removing a target replaced Grafana [$before_grafana] with [$removed_grafana]"

orbit metrics:exporter:enable app-prod --json >/dev/null

added_prometheus=$(container_id orbit-metrics-prometheus)
added_grafana=$(container_id orbit-metrics-grafana)

[[ "$added_prometheus" != "$removed_prometheus" ]] \
  || fail "adding a target left Prometheus on [$removed_prometheus]"
[[ "$added_grafana" == "$before_grafana" ]] \
  || fail "adding a target replaced Grafana [$before_grafana] with [$added_grafana]"

echo "container-identity: grafana stable on $before_grafana across two target changes"
echo "container-identity: prometheus $before_prometheus -> $removed_prometheus -> $added_prometheus"
