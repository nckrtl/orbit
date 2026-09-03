#!/usr/bin/env bash
source /var/lib/orbit-e2e/proof/lib.sh

label=${1-}
[[ -n "$label" ]] || fail "success label is required"

orbit metrics:disable --force --json >/dev/null
status=$(orbit metrics:status --json)
[[ "$(json_get enabled <<<"$status")" == false ]] || fail "Metrics remained enabled in $label"

echo "success-disable: $label disabled Metrics through the normal product surface"
