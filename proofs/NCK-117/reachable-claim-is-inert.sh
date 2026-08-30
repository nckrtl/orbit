#!/usr/bin/env bash
# Only genuine unreachability may proceed. app-dev answers, so the offline
# claim is checked and discarded: removal falls back to the ordinary refusal
# and nothing on the node is shed.
source /var/lib/orbit-e2e/proof/lib.sh

dev_id=$(node_id app-dev)
[[ -n "$dev_id" ]] || fail "could not resolve the app-dev node id"
before=$(node_roles app-dev)

removal=$(orbit node:remove "$dev_id" --force --offline --json) \
  && fail "node:remove removed a reachable node under an offline claim: $removal"
[[ "$(echo "$removal" | json_get error.code)" == node.has_roles ]] \
  || fail "reachable node did not keep the ordinary refusal: $removal"

[[ "$(node_present app-dev)" == yes ]] || fail "a reachable node was removed"
[[ "$(node_roles app-dev)" == "$before" ]] || fail "roles changed on a reachable node: $before -> $(node_roles app-dev)"

echo "reachable-claim-is-inert: app-dev answered, kept every role, and removal still refused"
