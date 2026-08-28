#!/usr/bin/env bash
set -euo pipefail
umask 077
orbit=/home/orbit/orbit/apps/cli/orbit
case ${1-} in
  configure-cli)
    [[ "$(id -u)" -eq 0 ]] && exec sudo -u orbit -- env HOME=/home/orbit ORBIT_HOME=/home/orbit/.orbit DB_DATABASE=/home/orbit/.orbit/gateway.sqlite bash "$0" "$@"
    [[ $# -eq 2 && "$2" =~ ^[a-zA-Z0-9][a-zA-Z0-9.-]{0,62}$ ]]
    ca=/home/orbit/.orbit/e2e-gateway-root-ca.pem
    install -d -m 0700 "$(dirname "$ca")"
    curl --fail --silent --show-error --insecure "https://$2/api/root-ca" -o "$ca.new"
    php -r '$v=json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR); file_put_contents($argv[2], $v["root_ca"]);' "$ca.new" "$ca"
    rm -f "$ca.new"
    "$orbit" gateway:add e2e "https://$2" --ca="$ca" --use --json
    ;;
  create-resources)
    [[ "$(id -u)" -eq 0 ]] && exec sudo -u orbit -- env HOME=/home/orbit ORBIT_HOME=/home/orbit/.orbit DB_DATABASE=/home/orbit/.orbit/gateway.sqlite bash "$0" "$@"
    [[ $# -eq 4 && "$2" =~ ^[a-zA-Z0-9][a-zA-Z0-9.-]{0,62}$ && "$3" =~ ^[a-zA-Z0-9][a-zA-Z0-9.-]{0,62}$ && "$4" =~ ^[0-9a-f]{40}$ ]]
    dev_id=$("$orbit" node:show "$2" --json | php -r '$v=json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR); echo $v["id"];')
    prod_id=$("$orbit" node:show "$3" --json | php -r '$v=json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR); echo $v["id"];')
    apps=$("$orbit" app:list --json)
    app_id=$(php -r '$v=json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR); $m=array_values(array_filter($v["apps"], fn($x) => ($x["slug"] ?? null)===$argv[1])); if(count($m)>1 || $m && (($m[0]["repository_url"] ?? null)!==$argv[2] || ($m[0]["name"] ?? null)!==$argv[3])) exit(65); echo $m[0]["id"] ?? "";' laravel https://github.com/laravel/laravel.git Laravel <<<"$apps")
    if [[ -z "$app_id" ]]; then
      app_id=$("$orbit" app:new laravel https://github.com/laravel/laravel.git --name=Laravel --json | php -r '$v=json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR); echo $v["id"];')
    fi
    instances=$("$orbit" instance:list --json)
    dev_instance_id=$(php -r '$v=json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR); $m=array_values(array_filter($v["instances"], fn($x) => ($x["name"] ?? null)===$argv[1])); if(count($m)>1 || $m && (($m[0]["app_id"] ?? null)!==(int)$argv[2] || ($m[0]["node_id"] ?? null)!==(int)$argv[3] || ($m[0]["environment"] ?? null)!==$argv[4])) exit(65); echo $m[0]["id"] ?? "";' e2e-dev "$app_id" "$dev_id" development <<<"$instances")
    if [[ -z "$dev_instance_id" ]]; then
      dev_instance_id=$("$orbit" instance:new "$app_id" "$dev_id" e2e-dev --environment=development --json | php -r '$v=json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR); echo $v["id"];')
    fi
    prod_instance_id=$(php -r '$v=json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR); $m=array_values(array_filter($v["instances"], fn($x) => ($x["name"] ?? null)===$argv[1])); if(count($m)>1 || $m && (($m[0]["app_id"] ?? null)!==(int)$argv[2] || ($m[0]["node_id"] ?? null)!==(int)$argv[3] || ($m[0]["environment"] ?? null)!==$argv[4] || ($m[0]["hostname"] ?? null)!==$argv[5])) exit(65); echo $m[0]["id"] ?? "";' e2e-prod "$app_id" "$prod_id" production laravel.internal <<<"$instances")
    if [[ -z "$prod_instance_id" ]]; then
      "$orbit" instance:new "$app_id" "$prod_id" e2e-prod --environment=production --hostname=laravel.internal --json >/dev/null
    fi
    workspaces=$("$orbit" workspace:list --json)
    workspace_id=$(php -r '$v=json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR); $m=array_values(array_filter($v["workspaces"], fn($x) => ($x["name"] ?? null)===$argv[1])); if(count($m)>1 || $m && (($m[0]["instance_id"] ?? null)!==(int)$argv[2] || ($m[0]["branch"] ?? null)!==$argv[3])) exit(65); echo $m[0]["id"] ?? "";' e2e "$dev_instance_id" "e2e-$4" <<<"$workspaces")
    if [[ -z "$workspace_id" ]]; then
      "$orbit" workspace:new "$dev_instance_id" e2e --branch="e2e-$4" --json >/dev/null
    fi
    ;;
  hydrate)
    [[ $# -eq 3 && "$2" =~ ^[0-9a-f]{40}$ ]]
    case "$3" in
      app-dev) runtime_user=orbit; runtime_home=/home/orbit; checkouts=(/home/orbit/apps/laravel /home/orbit/.orbit/worktrees/laravel/e2e) ;;
      app-prod) runtime_user=orbit-laravel; runtime_home=/var/www/laravel; checkouts=(/var/www/laravel/e2e-prod) ;;
      *) exit 64 ;;
    esac
    run_as_runtime() {
      if [[ "$(id -u)" -eq 0 ]]; then
        sudo -u "$runtime_user" -- env HOME="$runtime_home" "$@"
      else
        "$@"
      fi
    }
    for checkout in "${checkouts[@]}"; do
      if [[ ! -d "$checkout/.git" ]]; then run_as_runtime git clone --quiet https://github.com/laravel/laravel.git "$checkout"; fi
      [[ "$(run_as_runtime git -C "$checkout" remote get-url origin)" == https://github.com/laravel/laravel.git ]]
      run_as_runtime git -C "$checkout" fetch --quiet origin "$2"
      run_as_runtime git -C "$checkout" reset --hard --quiet "$2"
      [[ "$(run_as_runtime git -C "$checkout" rev-parse HEAD)" == "$2" ]]
      [[ -f "$checkout/.env" ]] || run_as_runtime cp "$checkout/.env.example" "$checkout/.env"
      run_as_runtime composer install --working-dir="$checkout" --no-interaction --no-progress
      run_as_runtime grep -q '^APP_KEY=base64:' "$checkout/.env" || run_as_runtime php "$checkout/artisan" key:generate --force --no-interaction
      run_as_runtime install -d -m 0775 "$checkout/storage" "$checkout/bootstrap/cache"
      run_as_runtime chmod -R ug+rwX "$checkout/storage" "$checkout/bootstrap/cache"
    done
    if [[ "$3" == app-prod ]]; then
      fragment=/etc/caddy/orbit-e2e-global.caddy
      rendered_state=/var/lib/orbit-e2e/caddy-rendered-path
      if [[ -s "$rendered_state" ]]; then
        rendered=$(cat "$rendered_state")
      else
        rendered=$(readlink -f /etc/caddy/Caddyfile)
        [[ "$rendered" != /etc/caddy/Caddyfile && "$rendered" != /etc/caddy/Caddyfile.orbit-e2e ]]
        printf '%s\n' "$rendered" >"$rendered_state"
      fi
      [[ -f "$fragment" && -f "$rendered" ]]
      candidate=$(mktemp /etc/caddy/Caddyfile.orbit-e2e.XXXXXX)
      printf 'import %s\nimport %s\n' "$fragment" "$rendered" >"$candidate"
      caddy validate --config "$candidate" --adapter caddyfile
      mv -f "$candidate" /etc/caddy/Caddyfile.orbit-e2e
      ln -sfn Caddyfile.orbit-e2e /etc/caddy/Caddyfile
      systemctl reload caddy
      ca=$(cat /var/lib/orbit-e2e/caddy-ca-path)
      [[ -s "$ca" ]]
      curl --fail --silent --show-error --cacert "$ca" https://laravel.internal/ >/dev/null
    fi
    ;;
  *) exit 64 ;;
esac
