#!/usr/bin/env bash
source /var/lib/orbit-e2e/proof/lib.sh

for name in orbit-metrics-prometheus orbit-metrics-grafana; do
  contract=$(docker container inspect --format '{{.Config.Healthcheck.StartPeriod}}|{{.Config.Healthcheck.Interval}}|{{.Config.Healthcheck.Retries}}|{{.State.Health.Status}}' "$name")
  [[ "$contract" == '30s|5s|12|healthy' || "$contract" == '30000000000|5000000000|12|healthy' ]] \
    || fail "$name health contract is [$contract]"
done

prometheus=$(docker container inspect --format '{{json .Config.Healthcheck.Test}}' orbit-metrics-prometheus)
grafana=$(docker container inspect --format '{{json .Config.Healthcheck.Test}}' orbit-metrics-grafana)
[[ "$prometheus" == *'127.0.0.1:9090/-/ready'* ]] || fail "Prometheus health command changed: $prometheus"
[[ "$grafana" == *':3000/api/health'* ]] || fail "Grafana health command changed: $grafana"

echo "health-grace: both containers healthy with 30s start period, 5s interval, and 12 retries"
