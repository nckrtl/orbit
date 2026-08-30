#!/usr/bin/env bash
# Once the node answers again, the next convergence clears its degradation and
# converges its exporter.
source /var/lib/orbit-e2e/proof/lib.sh

orbit node:role:add app-dev app-dev --converge --json >/dev/null || fail "reconciling role add failed"
summary=$(assert_exporters \
  "app-prod=desired/active/explicit_enabled/none" \
  "app-dev=desired/active/metrics_node/none")

echo "status-recovered: $summary"
