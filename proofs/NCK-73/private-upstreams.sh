#!/usr/bin/env bash
# From a non-Gateway node: Grafana's upstream port and Prometheus are not reachable.
# Usage: private-upstreams.sh <metrics node WireGuard address>
source /var/lib/orbit-e2e/proof/lib.sh

metrics=$1
if curl --silent --max-time 4 "http://$metrics:3000/api/health" >/dev/null 2>&1; then
  fail "Grafana upstream reachable from a non-Gateway node"
fi
if curl --silent --max-time 4 "http://$metrics:9090/-/ready" >/dev/null 2>&1; then
  fail "Prometheus reachable from the fleet"
fi
echo "private: $metrics:3000 and :9090 closed to this node"
