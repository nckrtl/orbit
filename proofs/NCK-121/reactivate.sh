#!/usr/bin/env bash
source /var/lib/orbit-e2e/proof/lib.sh

orbit metrics:enable app-dev --json >/dev/null
status=$(orbit metrics:status --json)
[[ "$(json_get assignment.status <<<"$status")" == active ]] || fail "Metrics did not reactivate for failure fixtures"

echo "reactivate: active orbit Metrics fixture restored before failure evidence"
