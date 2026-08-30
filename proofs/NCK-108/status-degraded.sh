#!/usr/bin/env bash
# metrics:status reports the skipped node as unknown with a stable reason,
# while the Metrics node keeps a real exporter state.
source /var/lib/orbit-e2e/proof/lib.sh

summary=$(assert_exporters \
  "app-prod=desired/unknown/explicit_enabled/unreachable" \
  "app-dev=desired/active/metrics_node/none")

echo "status-degraded: $summary"
