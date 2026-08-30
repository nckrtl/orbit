#!/usr/bin/env bash
# From the Gateway: Grafana's upstream answers over WireGuard and Prometheus stays closed.
source /var/lib/orbit-e2e/proof/lib.sh

metrics=$(metrics_address)
health=$(curl --silent --fail --max-time 5 "http://$metrics:3000/api/health") || fail "Grafana upstream closed to the gateway"
[[ "$(echo "$health" | json_get database)" == ok ]] || fail "Grafana upstream unhealthy: $health"
if curl --silent --max-time 4 "http://$metrics:9090/-/ready" >/dev/null 2>&1; then
  fail "Prometheus reachable from the gateway"
fi
echo "gateway: grafana upstream $metrics:3000 open, prometheus closed"
