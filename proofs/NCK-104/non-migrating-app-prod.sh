#!/usr/bin/env bash
# Later settings do not move existing checkouts; new instances use the configured root; app-prod stays /var/www.
source /var/lib/orbit-e2e/proof/lib.sh

[[ "$(instance_checkout e2e-dev)" == /home/orbit/apps/laravel ]] \
  || fail "app-dev instance checkout migrated"
[[ "$(instance_checkout e2e-prod)" == /var/www/laravel/e2e-prod ]] \
  || fail "app-prod instance checkout changed"
[[ "$(workspace_checkout e2e)" == /home/orbit/.orbit/worktrees/laravel/e2e ]] \
  || fail "legacy workspace checkout migrated"
[[ "$(workspace_checkout nck104-derived)" == /srv/orbit/worktrees/laravel/nck104-derived ]] \
  || fail "later derived workspace did not use the configured root"

app=$(orbit app:new nck104 https://github.com/laravel/laravel.git --json)
app_id=$(echo "$app" | json_get id)
[[ -n "$app_id" ]] || fail "failed to create nck104 app: $app"
created=$(orbit instance:new "$app_id" "$(node_id app-dev)" nck104-dev --json)
[[ "$(echo "$created" | json_get checkout_path)" == /srv/orbit/instances/nck104 ]] \
  || fail "new instance was not placed under the configured instance root: $created"
[[ "$(instance_checkout e2e-dev)" == /home/orbit/apps/laravel ]] \
  || fail "creating a later instance moved the original checkout"
[[ "$(instance_checkout e2e-prod)" == /var/www/laravel/e2e-prod ]] \
  || fail "creating a later instance changed app-prod"

echo "non-migrating: existing checkouts unchanged, later instance uses configured root, app-prod stays /var/www"
