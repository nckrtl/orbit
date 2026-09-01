#!/usr/bin/env bash
source "$(dirname "$0")/lib.sh"

cleanup_foreign() {
    sudo docker container rm --force -- orbit-metrics-prometheus >/dev/null 2>&1 || true
    sudo docker volume rm -- orbit-metrics-prometheus-data >/dev/null 2>&1 || true
}
trap cleanup_foreign EXIT INT TERM

orbit metrics:disable --force --json >/dev/null
[[ -z "$(sudo docker container ls --all --filter label=com.orbit.managed=metrics --format '{{.Names}}')" ]] \
    || fail 'normal disable left Metrics containers'
[[ "$(sudo docker volume ls --filter label=com.orbit.managed=metrics --format '{{.Name}}' | wc -l)" -eq 2 ]] \
    || fail 'normal disable did not preserve both Metrics volumes'

orbit metrics:enable app-dev --json >/dev/null
orbit metrics:disable --force --purge-data --json >/dev/null
[[ -z "$(sudo docker volume ls --filter label=com.orbit.managed=metrics --format '{{.Name}}')" ]] \
    || fail 'explicit purge left Metrics volumes'

sudo docker container create --name orbit-metrics-prometheus \
    --label com.orbit.managed=foreign prom/prometheus:v3.5.0 >/dev/null
container_attempt=$(orbit metrics:enable app-dev --json 2>&1 || true)
[[ "$(printf '%s' "$container_attempt" | json_get error.code)" == node_role.convergence_failed ]] \
    || fail "foreign container was not refused: $container_attempt"
[[ "$(sudo docker container inspect --format '{{index .Config.Labels "com.orbit.managed"}}' \
    orbit-metrics-prometheus)" == foreign ]] || fail 'foreign container was changed'
sudo docker container rm --force -- orbit-metrics-prometheus >/dev/null

sudo docker volume create --label com.orbit.managed=foreign orbit-metrics-prometheus-data >/dev/null
volume_attempt=$(orbit node:role:add app-dev metrics --converge --json 2>&1 || true)
[[ "$(printf '%s' "$volume_attempt" | json_get error.code)" == node_role.convergence_failed ]] \
    || fail "foreign volume did not fail convergence: $volume_attempt"
[[ "$(sudo docker volume inspect --format '{{index .Labels "com.orbit.managed"}}' \
    orbit-metrics-prometheus-data)" == foreign ]] || fail 'foreign volume was changed'
sudo docker volume rm -- orbit-metrics-prometheus-data >/dev/null

trap - EXIT INT TERM
orbit node:role:add app-dev metrics --converge --json >/dev/null
assert_metrics_healthy
assert_expected_runtime
assert_boundary_unchanged

echo 'disable-purge-ownership: preserved then purged volumes, refused foreign resources, and recovered healthy'
