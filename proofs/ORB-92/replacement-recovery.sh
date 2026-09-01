#!/usr/bin/env bash
source "$(dirname "$0")/lib.sh"

prometheus_before=$(sudo docker container inspect --format '{{.Id}}' orbit-metrics-prometheus)
grafana_before=$(sudo docker container inspect --format '{{.Id}}' orbit-metrics-grafana)

orbit metrics:exporter:disable app-prod --json >/dev/null

prometheus_after=$(sudo docker container inspect --format '{{.Id}}' orbit-metrics-prometheus)
grafana_after=$(sudo docker container inspect --format '{{.Id}}' orbit-metrics-grafana)
[[ "$prometheus_after" != "$prometheus_before" ]] || fail 'Prometheus was not replaced after its targets changed'
[[ "$grafana_after" == "$grafana_before" ]] || fail 'Grafana changed during Prometheus-only replacement'

orbit metrics:exporter:enable app-prod --json >/dev/null

restart_docker() {
    sudo systemctl start docker.socket docker.service >/dev/null 2>&1 || true
}
trap restart_docker EXIT INT TERM

sudo systemctl stop docker.socket docker.service
attempt=$(orbit node:role:add app-dev metrics --converge --json 2>&1 || true)
restart_docker
trap - EXIT INT TERM

[[ "$(printf '%s' "$attempt" | json_get error.code)" == node_role.convergence_failed ]] \
    || fail "Docker outage did not fail convergence as expected: $attempt"

status=$(orbit metrics:status --json)
[[ "$(printf '%s' "$status" | json_get assignment.status)" == failed ]] \
    || fail "Metrics assignment did not record the failed convergence: $status"

orbit node:role:add app-dev metrics --converge --json >/dev/null
assert_metrics_healthy
assert_expected_runtime
assert_boundary_unchanged

echo 'replacement-recovery: replaced Prometheus only, recorded failure, and recovered through generic convergence'
