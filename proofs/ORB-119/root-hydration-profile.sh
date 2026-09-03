#!/usr/bin/env bash
set -euo pipefail

root=$(mktemp -d /home/orbit/orb-119-hydration.XXXXXX)
trap 'rm -rf -- "$root"' EXIT
checkout=$root/checkout
state=$root/sample-app-state.json
script=$root/converge-sample-app.sh
target_commit=$(printf 'b%.0s' {1..40})
mkdir -p "$checkout"
sed "s#sample_state=/home/orbit/.orbit/e2e-sample-app-state.json#sample_state=$state#" \
  /usr/local/bin/converge-sample-app.sh >"$script"
chmod 0700 "$script"
printf '{"shape":"app_instances","app_id":1,"node_id":2,"name":"e2e-dev","checkout_path":"%s","effective_root":"public"}\n' "$checkout" >"$state"

set +e
output=$(sudo "$script" hydrate "$target_commit" app-dev "$checkout" 2>&1)
status=$?
set -e

[[ "$status" -eq 65 ]]
[[ "$output" != *'failed after 30 attempts'* ]]
[[ ! -e "$checkout/.env" ]]
[[ ! -e "$checkout/vendor/.orbit-e2e-composer-lock" ]]
[[ ! -e "$checkout/database/database.sqlite" ]]

printf 'root-hydration-profile: root invocation reached Gateway through the Orbit profile, rejected the legacy envelope with exit 65, and did not mutate source\n'
