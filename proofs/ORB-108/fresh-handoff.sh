#!/usr/bin/env bash
set -euo pipefail

/usr/local/bin/converge-sample-app.sh gateway-readiness

sample_state=$(/usr/local/bin/converge-sample-app.sh inspect-state)
php -r '$v=json_decode(stream_get_contents(STDIN), true, 8, JSON_THROW_ON_ERROR); if($v!==["shape"=>"instances"]) exit(65);' <<<"$sample_state"

declare -a checkouts=(
  /home/orbit/apps/laravel
  /home/orbit/.orbit/worktrees/laravel/e2e
)

hydrated_commit=
for checkout in "${checkouts[@]}"; do
  [[ -d "$checkout/.git" || -f "$checkout/.git" ]]
  [[ "$(git -C "$checkout" remote get-url origin)" == https://github.com/laravel/laravel.git ]]
  commit=$(git -C "$checkout" rev-parse HEAD)
  [[ "$commit" =~ ^[0-9a-f]{40}$ ]]
  [[ -z "$hydrated_commit" || "$commit" == "$hydrated_commit" ]]
  hydrated_commit=$commit
  [[ -s "$checkout/vendor/autoload.php" ]]
  [[ -f "$checkout/.env" ]]
  grep -q '^APP_KEY=base64:' "$checkout/.env"
  [[ -f "$checkout/database/database.sqlite" ]]
done

printf 'fresh-handoff: Gateway is ready and both legacy development checkouts are hydrated at %s\n' "$hydrated_commit"
