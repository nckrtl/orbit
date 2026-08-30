#!/usr/bin/env bash
# The Gateway is reachable again, so the stale Metrics role comes off through
# Orbit. Everything it owns on the node is already gone, and disable copes.
source /var/lib/orbit-e2e/proof/lib.sh

result=$(orbit metrics:disable --force --json) || fail "metrics:disable failed: $result"
[[ "$(echo "$result" | json_get status)" == removed ]] || fail "unexpected disable result: $result"
[[ "$(echo "$result" | json_get publication)" == cleaned ]] || fail "publication not cleaned: $result"

echo "clear-stale-role: $result"
