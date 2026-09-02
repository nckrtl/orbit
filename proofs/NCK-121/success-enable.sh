#!/usr/bin/env bash
source /var/lib/orbit-e2e/proof/lib.sh

label=${1-}
[[ -n "$label" ]] || fail "success label is required"

if [[ "$(orbit metrics:status --json | json_get enabled)" == true ]]; then
  orbit metrics:disable --force --json >/dev/null
fi

orbit metrics:enable app-dev --json >/dev/null

status=$(orbit metrics:status --json)
[[ "$(json_get enabled <<<"$status")" == true ]] || fail "Metrics did not enable in $label"
[[ "$(json_get assignment.node_name <<<"$status")" == app-dev ]] || fail "Metrics moved from app-dev in $label"
[[ "$(json_get assignment.status <<<"$status")" == active ]] || fail "Metrics is not active in $label"
[[ "$(json_get prometheus <<<"$status")" == healthy ]] || fail "Prometheus is not healthy in $label"
[[ "$(json_get grafana <<<"$status")" == healthy ]] || fail "Grafana is not healthy in $label"

credentials=$(orbit metrics:credentials --json)
[[ "$(json_get username <<<"$credentials")" == admin ]] || fail "Metrics credentials cannot be inspected in $label"
[[ -n "$(json_get password <<<"$credentials")" ]] || fail "Metrics credential is empty in $label"

orbit metrics:exporter:disable app-prod --json >/dev/null
status=$(orbit metrics:status --json)
php -r '
  foreach (json_decode(stream_get_contents(STDIN), true)["exporters"] ?? [] as $exporter) {
    if (($exporter["name"] ?? null) === "app-prod") {
      exit(($exporter["desired"] ?? true) === false && ($exporter["actual"] ?? null) === "inactive" ? 0 : 1);
    }
  }
  exit(1);
' <<<"$status" || fail "app-prod exporter did not disable in $label"

orbit metrics:exporter:enable app-prod --json >/dev/null
status=$(orbit metrics:status --json)
php -r '
  foreach (json_decode(stream_get_contents(STDIN), true)["exporters"] ?? [] as $exporter) {
    if (($exporter["name"] ?? null) === "app-prod") {
      exit(($exporter["desired"] ?? false) === true && ($exporter["actual"] ?? null) === "active" ? 0 : 1);
    }
  }
  exit(1);
' <<<"$status" || fail "app-prod exporter did not reconverge in $label"

echo "success-enable: $label enabled Metrics, inspected it, and converged exporter preference"
