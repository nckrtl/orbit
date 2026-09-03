#!/usr/bin/env bash
set -euo pipefail

root=$(mktemp -d /tmp/orbit-orb-110-handoff.XXXXXX)
trap 'rm -rf -- "$root"' EXIT
checkout=$root/checkout
target_commit=$(printf 'b%.0s' {1..40})
mkdir -p "$root/bin" "$checkout/.git" "$checkout/vendor" "$checkout/storage" "$checkout/bootstrap/cache"
printf 'locked dependencies\n' >"$checkout/composer.lock"
printf 'autoloaded\n' >"$checkout/vendor/autoload.php"
sha256sum "$checkout/composer.lock" | awk '{print $1}' >"$checkout/vendor/.orbit-e2e-composer-lock"
printf 'APP_KEY=base64:fixture\n' >"$checkout/.env"
cat >"$checkout/artisan" <<'PHP'
<?php
file_put_contents(
    (string) getenv('ORB_110_ARTISAN_CALLS'),
    implode(' ', array_slice($argv, 1)).PHP_EOL,
    FILE_APPEND,
);
PHP

cat >"$root/orbit" <<'BASH'
#!/usr/bin/env bash
set -euo pipefail
[[ "$*" == 'instance:list --json' ]]
[[ ! -e "$ORB_110_GIT_RESET_CALLED" ]] || {
  touch "$ORB_110_MUTATED_DURING_PREFLIGHT"
  exit 99
}
if [[ "$ORB_110_MODE" == readiness ]]; then
  printf '%s' "$ORB_110_INSTANCE_RESPONSE"
  exit 0
fi
printf '%s\n' "$*" >>"$ORB_110_HYDRATION_PREFLIGHT_CALLS"
attempt=$(wc -l <"$ORB_110_HYDRATION_PREFLIGHT_CALLS")
if [[ "$attempt" -eq 1 ]]; then
  printf '{"error":{"code":"gateway.unavailable","message":"Injected transient failure.","request_id":null}}'
  exit 69
fi
printf '%s' "$ORB_110_INSTANCE_RESPONSE"
BASH

cat >"$root/bin/git" <<'BASH'
#!/usr/bin/env bash
set -euo pipefail
printf '%s\n' "$*" >>"$ORB_110_GIT_CALLS"
case "$*" in
  *' remote get-url origin') printf 'https://github.com/laravel/laravel.git\n' ;;
  *' cat-file -e '*) ;;
  *' reset --hard --quiet '*) printf '%s\n' "$*" >>"$ORB_110_GIT_RESET_CALLED" ;;
  *' rev-parse HEAD') printf '%s\n' "$ORB_110_TARGET_COMMIT" ;;
  *) exit 1 ;;
esac
BASH

cat >"$root/bin/composer" <<'BASH'
#!/usr/bin/env bash
touch "$ORB_110_UNEXPECTED_COMPOSER_CALL"
exit 99
BASH

chmod 0700 "$root/orbit" "$root/bin/git" "$root/bin/composer"
sed \
  -e "s#orbit=/home/orbit/orbit/apps/cli/orbit#orbit=$root/orbit#" \
  -e "s#sample_state=/home/orbit/.orbit/e2e-sample-app-state.json#sample_state=$root/sample-app-state.json#" \
  /usr/local/bin/converge-sample-app.sh >"$root/converge-sample-app.sh"
chmod 0700 "$root/converge-sample-app.sh"

cat >"$root/sample-app-state.json" <<JSON
{"shape":"app_instances","app_id":1,"node_id":2,"name":"e2e-dev","checkout_path":"$checkout","effective_root":"public"}
JSON
instance_response=$(printf '{"app_instances":[{"id":4,"app_id":1,"node_id":2,"name":"e2e-dev","status":"active","checkout_path":"%s","selected_branch":"main","starting_commit":"%s","effective_root":"public"}],"request_id":"0198e15c-bf97-7c23-8f1f-61b8fe67a844"}' "$checkout" "$(printf 'a%.0s' {1..40})")

export PATH="$root/bin:$PATH"
export ORB_110_ARTISAN_CALLS="$root/artisan-calls"
export ORB_110_GIT_CALLS="$root/git-calls"
export ORB_110_GIT_RESET_CALLED="$root/git-reset-calls"
export ORB_110_HYDRATION_PREFLIGHT_CALLS="$root/hydration-preflight-calls"
export ORB_110_INSTANCE_RESPONSE="$instance_response"
export ORB_110_MODE=readiness
export ORB_110_MUTATED_DURING_PREFLIGHT="$root/mutated-during-preflight"
export ORB_110_TARGET_COMMIT="$target_commit"
export ORB_110_UNEXPECTED_COMPOSER_CALL="$root/unexpected-composer-call"

readiness=$("$root/converge-sample-app.sh" instance-api-readiness)
[[ "$readiness" == 'instance-api-readiness: instance:list --json validated app_instances envelope' ]]

export ORB_110_MODE=hydrate
"$root/converge-sample-app.sh" hydrate "$target_commit" app-dev "$checkout"

[[ "$(wc -l <"$root/hydration-preflight-calls")" -eq 2 ]]
[[ "$(wc -l <"$root/git-reset-calls")" -eq 1 ]]
[[ "$(<"$root/artisan-calls")" == 'migrate --force --no-interaction' ]]
[[ ! -e "$root/mutated-during-preflight" ]]
[[ ! -e "$root/unexpected-composer-call" ]]

printf 'transient-hydration-handoff: readiness passed, the first hydration preflight failed, and mutation ran once after retry\n'
