#!/usr/bin/env bash
source "$(dirname "$0")/lib.sh"

before=$(sudo docker container inspect --format '{{.Id}}' \
    orbit-metrics-prometheus orbit-metrics-grafana)

orbit node:role:add app-dev metrics --converge --json >/dev/null

after=$(sudo docker container inspect --format '{{.Id}}' \
    orbit-metrics-prometheus orbit-metrics-grafana)
[[ "$after" == "$before" ]] || fail "healthy containers changed during repeated convergence: $before -> $after"

assert_metrics_healthy
assert_expected_runtime
assert_boundary_unchanged

echo 'idempotent: repeated convergence preserved both owned healthy containers'
