#!/usr/bin/env bash
set -euo pipefail

root=$(mktemp -d /tmp/orbit-orb-114-hydration.XXXXXX)
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
    (string) getenv('ORB_114_ARTISAN_CALLS'),
    implode(' ', array_slice($argv, 1)).PHP_EOL,
    FILE_APPEND,
);
PHP

cat >"$root/orbit" <<'BASH'
#!/usr/bin/env bash
set -euo pipefail
[[ "$*" == 'instance:list --json' ]]
for mutation_log in \
  "$ORB_114_GIT_RESET_CALLS" \
  "$ORB_114_ARTISAN_CALLS" \
  "$ORB_114_MUTATION_CALLS"
do
  [[ ! -e "$mutation_log" ]] || {
    touch "$ORB_114_MUTATED_DURING_PREFLIGHT"
    exit 99
  }
done
if [[ "$ORB_114_MODE" == readiness ]]; then
  printf '%s' "$ORB_114_INSTANCE_RESPONSE"
  exit 0
fi
printf '%s\n' "$*" >>"$ORB_114_HYDRATION_PREFLIGHT_CALLS"
attempt=$(wc -l <"$ORB_114_HYDRATION_PREFLIGHT_CALLS")
if [[ "$attempt" -le 6 ]]; then
  printf '{"error":{"code":"gateway.unavailable","message":"Injected transient failure.","request_id":null}}'
  exit 69
fi
printf '%s' "$ORB_114_INSTANCE_RESPONSE"
BASH

cat >"$root/bin/git" <<'BASH'
#!/usr/bin/env bash
set -euo pipefail
printf '%s\n' "$*" >>"$ORB_114_GIT_CALLS"
case "$*" in
  *' remote get-url origin') printf 'https://github.com/laravel/laravel.git\n' ;;
  *' cat-file -e '*) ;;
  *' reset --hard --quiet '*) printf '%s\n' "$*" >>"$ORB_114_GIT_RESET_CALLS" ;;
  *' rev-parse HEAD') printf '%s\n' "$ORB_114_TARGET_COMMIT" ;;
  *) exit 1 ;;
esac
BASH

for command in chmod cp install touch; do
  cat >"$root/bin/$command" <<BASH
#!/usr/bin/env bash
set -euo pipefail
printf '%s %s\\n' '$command' "\$*" >>"\$ORB_114_MUTATION_CALLS"
exec /usr/bin/$command "\$@"
BASH
done

cat >"$root/bin/composer" <<'BASH'
#!/usr/bin/env bash
printf '%s\n' "composer $*" >>"$ORB_114_MUTATION_CALLS"
exit 99
BASH

cat >"$root/bin/sleep" <<'BASH'
#!/usr/bin/env bash
set -euo pipefail
for mutation_log in \
  "$ORB_114_GIT_RESET_CALLS" \
  "$ORB_114_ARTISAN_CALLS" \
  "$ORB_114_MUTATION_CALLS"
do
  [[ ! -e "$mutation_log" ]] || {
    touch "$ORB_114_MUTATED_DURING_PREFLIGHT"
    exit 99
  }
done
printf '%s\n' "$*" >>"$ORB_114_SLEEP_CALLS"
BASH

chmod 0700 "$root/orbit" "$root/bin/git" "$root/bin/composer" "$root/bin/sleep"
chmod 0700 "$root/bin/chmod" "$root/bin/cp" "$root/bin/install" "$root/bin/touch"
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
export ORB_114_ARTISAN_CALLS="$root/artisan-calls"
export ORB_114_GIT_CALLS="$root/git-calls"
export ORB_114_GIT_RESET_CALLS="$root/git-reset-calls"
export ORB_114_HYDRATION_PREFLIGHT_CALLS="$root/hydration-preflight-calls"
export ORB_114_INSTANCE_RESPONSE="$instance_response"
export ORB_114_MODE=readiness
export ORB_114_MUTATED_DURING_PREFLIGHT="$root/mutated-during-preflight"
export ORB_114_MUTATION_CALLS="$root/mutation-calls"
export ORB_114_SLEEP_CALLS="$root/sleep-calls"
export ORB_114_TARGET_COMMIT="$target_commit"

readiness=$("$root/converge-sample-app.sh" instance-api-readiness)
[[ "$readiness" == 'instance-api-readiness: instance:list --json validated app_instances envelope' ]]

export ORB_114_MODE=hydrate
"$root/converge-sample-app.sh" hydrate "$target_commit" app-dev "$checkout"

[[ "$(wc -l <"$root/hydration-preflight-calls")" -eq 7 ]]
[[ "$(wc -l <"$root/sleep-calls")" -eq 6 ]]
[[ "$(wc -l <"$root/git-reset-calls")" -eq 1 ]]
[[ "$(grep -c ' reset --hard --quiet ' "$root/git-calls")" -eq 1 ]]
[[ "$(grep -c ' clean ' "$root/git-calls" || true)" -eq 0 ]]
[[ "$(<"$root/artisan-calls")" == 'migrate --force --no-interaction' ]]
[[ "$(<"$root/mutation-calls")" == $'install -d -m 0775 '"$checkout"$'/storage '"$checkout"$'/bootstrap/cache\nchmod -R ug+rwX '"$checkout"$'/storage '"$checkout"'/bootstrap/cache' ]]
[[ ! -e "$root/mutated-during-preflight" ]]

printf 'extended-hydration-preflight-outage: readiness passed, six hydration preflight attempts failed, and mutation ran once after recovery\n'
