#!/usr/bin/env bash
# metrics:status reports the skipped node as unknown with a stable reason,
# while every reachable node keeps a real exporter state.
source /var/lib/orbit-e2e/proof/lib.sh

summary=$(assert_exporters \
  "app-prod=desired/unknown/role_default/unreachable" \
  "app-dev=desired/active/metrics_node/none" \
  "gateway=desired/active/role_default/none")

echo "status-degraded: $summary"
