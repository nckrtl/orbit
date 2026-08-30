#!/usr/bin/env bash
# metrics:exporter:enable app-prod restores the target.
source /var/lib/orbit-e2e/proof/lib.sh

orbit metrics:exporter:enable app-prod --json >/dev/null
grep -q 'node: "app-prod"' /etc/orbit/metrics/prometheus.yml || fail "app-prod not rendered"
echo "exporter-enable: app-prod rendered again"
