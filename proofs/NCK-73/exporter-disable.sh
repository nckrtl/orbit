#!/usr/bin/env bash
# metrics:exporter:disable app-prod removes app-prod from the rendered Prometheus targets.
source /var/lib/orbit-e2e/proof/lib.sh

orbit metrics:exporter:disable app-prod --json >/dev/null
! grep -q 'node: "app-prod"' /etc/orbit/metrics/prometheus.yml || fail "app-prod still rendered"
grep -q 'node: "gateway"' /etc/orbit/metrics/prometheus.yml || fail "gateway target lost"
grep -q 'node: "app-dev"' /etc/orbit/metrics/prometheus.yml || fail "app-dev target lost"
refused=$(orbit metrics:exporter:disable app-dev --json || true)
echo "$refused" | grep -q '"code":"node.role_conflict"' || fail "metrics node exporter could be disabled: $refused"
echo "exporter-disable: app-prod removed from targets; metrics node exporter refused with node.role_conflict"
