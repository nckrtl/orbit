#!/usr/bin/env bash
# Once the node answers again, the next convergence clears its degradation and
# its exporter is converged and reported as active.
source /var/lib/orbit-e2e/proof/lib.sh

orbit node:role:add app-dev app-dev --converge --json >/dev/null || fail "reconciling role add failed"
summary=$(assert_exporters \
  "app-prod=desired/active/role_default/none" \
  "app-dev=desired/active/metrics_node/none" \
  "gateway=desired/active/role_default/none")

echo "status-recovered: $summary"
