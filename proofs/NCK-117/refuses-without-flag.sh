#!/usr/bin/env bash
# Without the explicit claim, an unreachable node still refuses, and the
# refusal names the flag that would let the operator through. Neither command
# may remove anything.
source /var/lib/orbit-e2e/proof/lib.sh

prod_id=$(node_id app-prod)
[[ -n "$prod_id" ]] || fail "could not resolve the app-prod node id"

removal=$(orbit node:remove "$prod_id" --force --json) && fail "node:remove succeeded without --offline: $removal"
[[ "$(echo "$removal" | json_get error.code)" == node.has_roles ]] \
  || fail "node:remove did not refuse with node.has_roles: $removal"
[[ "$(echo "$removal" | json_get error.message)" == *--offline* ]] \
  || fail "node:remove refusal does not name --offline: $removal"

role=$(orbit node:role:remove "$prod_id" app-prod --force --json) \
  && fail "node:role:remove succeeded without --offline: $role"
[[ "$(echo "$role" | json_get error.message)" == *--offline* ]] \
  || fail "node:role:remove failure does not name --offline: $role"

[[ "$(node_present app-prod)" == yes ]] || fail "app-prod was removed by a refused command"
[[ "$(node_roles app-prod)" == app-prod ]] || fail "app-prod lost its role to a refused command"

echo "refuses-without-flag: both commands refused and named --offline, app-prod untouched"
