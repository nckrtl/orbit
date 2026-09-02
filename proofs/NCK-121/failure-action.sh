#!/usr/bin/env bash
source /var/lib/orbit-e2e/proof/lib.sh

case_name=${1-}
output=''
exit_code=0

case "$case_name" in
  invalid)
    output=$(orbit metrics:disable --force --json 2>&1) || exit_code=$?
    [[ "$exit_code" -ne 0 ]] || fail "invalid configured identity unexpectedly disabled Metrics"
    grep -Fq 'node_role.remove_failed' <<<"$output" \
      || fail "invalid identity did not return the existing lifecycle failure: $output"
    ;;
  missing|unusable)
    output=$(orbit metrics:exporter:disable app-prod --json 2>&1) || exit_code=$?
    [[ "$exit_code" -ne 0 ]] || fail "$case_name configured identity unexpectedly converged the exporter"
    grep -Fq 'metrics.exporter_configuration_inspection_failed' <<<"$output" \
      || fail "$case_name identity did not return the existing structured failure: $output"
    ;;
  *)
    fail "unknown failure action [$case_name]"
    ;;
esac

echo "failure-action: $case_name returned its existing structured failure"
