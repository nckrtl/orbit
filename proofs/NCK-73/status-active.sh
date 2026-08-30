#!/usr/bin/env bash
# metrics:status --json reports the assignment, service health, and exporter states.
# Usage: status-active.sh <expected exporter JSON fragment e.g. app-prod=active>
source /var/lib/orbit-e2e/proof/lib.sh

status=$(orbit metrics:status --json)
[[ "$(echo "$status" | json_get enabled)" == true ]] || fail "not enabled: $status"
[[ "$(echo "$status" | json_get assignment.node_name)" == app-dev ]] || fail "assignment not on app-dev: $status"
[[ "$(echo "$status" | json_get assignment.status)" == active ]] || fail "assignment not active: $status"
[[ "$(echo "$status" | json_get prometheus)" == healthy ]] || fail "prometheus unhealthy: $status"
[[ "$(echo "$status" | json_get grafana)" == healthy ]] || fail "grafana unhealthy: $status"
summary=$(echo "$status" | php -r '
  $rows = [];
  foreach (json_decode(stream_get_contents(STDIN), true)["exporters"] as $exporter) {
    $rows[] = $exporter["name"] . "=" . ($exporter["desired"] ? "desired" : "excluded") . "/" . $exporter["actual"] . "/" . $exporter["reason"];
  }
  sort($rows);
  echo implode(" ", $rows);
')
for expectation in "$@"; do
  [[ "$summary" == *"$expectation"* ]] || fail "exporter expectation [$expectation] not met: $summary"
done
echo "status: active on app-dev, prometheus+grafana healthy, exporters: $summary"
