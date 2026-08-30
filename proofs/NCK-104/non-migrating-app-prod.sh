#!/usr/bin/env bash
# Later settings do not move existing checkouts; app-prod placement stays /var/www.
source /var/lib/orbit-e2e/proof/lib.sh

[[ "$(instance_checkout e2e-dev)" == /home/orbit/apps/laravel ]] \
  || fail "app-dev instance checkout migrated"
[[ "$(instance_checkout e2e-prod)" == /var/www/laravel/e2e-prod ]] \
  || fail "app-prod instance checkout changed"
[[ "$(workspace_checkout e2e)" == /home/orbit/.orbit/worktrees/laravel/e2e ]] \
  || fail "legacy workspace checkout migrated"
[[ "$(workspace_checkout nck104-derived)" == /srv/orbit/worktrees/laravel/nck104-derived ]] \
  || fail "later derived workspace did not use the configured root"

echo "non-migrating: existing checkouts unchanged, app-prod stays /var/www"
