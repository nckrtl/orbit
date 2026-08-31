#!/usr/bin/env bash
set -euo pipefail
umask 077
# incus exec starts in /root, which the orbit account cannot enter; child
# processes spawned by the CLI need a readable working directory.
cd /
orbit=/home/orbit/orbit/apps/cli/orbit
case ${1-} in
  grant-operator)
    [[ "$(id -u)" -eq 0 ]] && exec sudo -u orbit -- env HOME=/home/orbit ORBIT_HOME=/home/orbit/.orbit DB_DATABASE=/home/orbit/.orbit/gateway.sqlite bash "$0" "$@"
    [[ $# -eq 3 && "$2" =~ ^[a-zA-Z0-9][a-zA-Z0-9.-]{0,62}$ && "$3" =~ ^[a-zA-Z0-9][a-zA-Z0-9.-]{0,62}$ ]] || { echo "grant-operator: invalid arguments" >&2; exit 64; }
    ca=/home/orbit/.orbit/ca/root.pem
    [[ -s "$ca" ]] || { echo "grant-operator: missing root CA at $ca ($(id -un))" >&2; ls -ln /home/orbit/.orbit/ca >&2; exit 66; }
    if ! output=$("$orbit" gateway:add https://10.44.0.1 --name=e2e --ca="$ca" --use --json 2>&1); then
      printf 'gateway:add failed: %s\n' "$output" >&2
      exit 1
    fi
    if ! nodes=$("$orbit" node:list --json 2>&1); then
      printf 'node:list failed: %s\n' "$nodes" >&2
      exit 1
    fi
    operator_id=$(php -r '$v=json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR); $m=array_values(array_filter($v["nodes"], fn($x) => ($x["name"] ?? null)===$argv[1])); if(count($m)!==1 || !is_int($m[0]["id"] ?? null)) exit(65); echo $m[0]["id"];' "$2" <<<"$nodes")
    gateway_id=$(php -r '$v=json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR); $m=array_values(array_filter($v["nodes"], fn($x) => ($x["name"] ?? null)===$argv[1])); if(count($m)!==1 || !is_int($m[0]["id"] ?? null)) exit(65); echo $m[0]["id"];' "$3" <<<"$nodes")
    if ! output=$("$orbit" node:access:add "$operator_id" "$gateway_id" --json 2>&1); then
      printf 'node:access:add failed: %s\n' "$output" >&2
      exit 1
    fi
    ;;
  configure-cli)
    [[ "$(id -u)" -eq 0 ]] && exec sudo -u orbit -- env HOME=/home/orbit ORBIT_HOME=/home/orbit/.orbit DB_DATABASE=/home/orbit/.orbit/gateway.sqlite bash "$0" "$@"
    [[ $# -eq 2 && "$2" =~ ^[a-zA-Z0-9][a-zA-Z0-9.-]{0,62}$ ]]
    ca=/home/orbit/.orbit/e2e-gateway-root-ca.pem
    install -d -m 0700 "$(dirname "$ca")"
    curl --fail --silent --show-error --insecure "https://$2/api/v1/ca/root" -o "$ca.new"
    php -r '$v=json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR); file_put_contents($argv[2], $v["data"]["root_ca"]);' "$ca.new" "$ca"
    rm -f "$ca.new"
    "$orbit" gateway:add "https://$2" --name=e2e --ca="$ca" --use --json
    ;;
  create-resources)
    [[ "$(id -u)" -eq 0 ]] && exec sudo -u orbit -- env HOME=/home/orbit ORBIT_HOME=/home/orbit/.orbit DB_DATABASE=/home/orbit/.orbit/gateway.sqlite bash "$0" "$@"
    [[ $# -eq 4 && "$2" =~ ^[a-zA-Z0-9][a-zA-Z0-9.-]{0,62}$ && "$3" =~ ^[a-zA-Z0-9][a-zA-Z0-9.-]{0,62}$ && "$4" =~ ^[0-9a-f]{40}$ ]]
    nodes=$("$orbit" node:list --json)
    dev_id=$(php -r '$v=json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR); $m=array_values(array_filter($v["nodes"], fn($x) => ($x["name"] ?? null)===$argv[1])); if(count($m)!==1 || !is_int($m[0]["id"] ?? null)) exit(65); echo $m[0]["id"];' "$2" <<<"$nodes")
    prod_id=$(php -r '$v=json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR); $m=array_values(array_filter($v["nodes"], fn($x) => ($x["name"] ?? null)===$argv[1])); if(count($m)!==1 || !is_int($m[0]["id"] ?? null)) exit(65); echo $m[0]["id"];' "$3" <<<"$nodes")
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
    workspace_id=$(php -r '$v=json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR); $m=array_values(array_filter($v["workspaces"], fn($x) => ($x["name"] ?? null)===$argv[1])); if(count($m)>1 || $m && (($m[0]["instance_id"] ?? null)!==(int)$argv[2] || ($m[0]["branch"] ?? null)!==$argv[3])) exit(65); echo $m[0]["id"] ?? "";' e2e "$dev_instance_id" e2e <<<"$workspaces")
    if [[ -z "$workspace_id" ]]; then
      "$orbit" workspace:new "$dev_instance_id" e2e --branch=e2e --json >/dev/null
    fi
    ;;
  metrics)
    [[ "$(id -u)" -eq 0 ]] && exec sudo -u orbit -- env HOME=/home/orbit ORBIT_HOME=/home/orbit/.orbit DB_DATABASE=/home/orbit/.orbit/gateway.sqlite bash "$0" "$@"
    [[ $# -eq 1 ]] || exit 64
    status=$("$orbit" metrics:status --json)
    read -r action node_id < <(php -r '$v=json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR); $a=$v["assignment"] ?? null; if ($a === null) { echo "enable -\n"; exit; } if (($a["node_name"] ?? null) !== "app-dev" || !is_int($a["node_id"] ?? null)) exit(65); $status=$a["status"] ?? null; if ($status === "active") { echo "noop ", $a["node_id"], "\n"; exit; } if ($status === "failed") { echo "recover ", $a["node_id"], "\n"; exit; } exit(65);' <<<"$status")
    case "$action" in
      enable) mutation=$("$orbit" metrics:enable app-dev --json) ;;
      recover) mutation=$("$orbit" node:role:add "$node_id" metrics --converge --json) ;;
      noop) exit 0 ;;
      *) exit 65 ;;
    esac
    php -r '$v=json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR); $status=$v["assignment"]["status"] ?? $v["status"] ?? null; if (!in_array($status, ["active", "enabled"], true)) exit(1);' <<<"$mutation"
    ;;
  internal-tls)
    # Internal TLS for the sample production site lives inside the product's
    # own Caddy layout: the `local_certs` global block becomes an unmanaged
    # fragment of the managed version behind /etc/caddy/Caddyfile, and the
    # product publisher carries unmanaged fragments forward on every publish.
    # Runs before re-projection so the publisher validates a managed layout.
    [[ $# -eq 1 ]] || exit 64
    [[ "$(id -u)" -eq 0 ]] || exit 77
    source=/etc/caddy/orbit-e2e-global.caddy
    live=/etc/caddy/Caddyfile
    legacy_wrapper=/etc/caddy/Caddyfile.orbit-e2e
    target=$(readlink -f "$live")
    if [[ "$target" == "$legacy_wrapper" ]]; then
      # A promoted snapshot may still carry the retired e2e wrapper; resolve
      # the managed version it imported and restore the product symlink.
      target=$(sed -n 's#^import \(/etc/caddy/orbit-versions/[0-9a-f]\{16\}/Caddyfile\)$#\1#p' "$target" | tail -n 1)
    fi
    case "$target" in
      /etc/caddy/orbit-versions/*/Caddyfile) ;;
      *) printf 'internal-tls: unexpected Caddyfile target: %s\n' "$target" >&2; exit 65 ;;
    esac
    [[ -f "$target" && -s "$source" ]]
    fragment=$(dirname "$target")/fragments/00-orbit-e2e-global.caddy
    changed=0
    if ! cmp -s -- "$source" "$fragment"; then
      install -m 0640 -- "$source" "$fragment"
      chown --reference="$target" -- "$fragment"
      changed=1
    fi
    if [[ "$(readlink -f "$live")" != "$target" ]]; then
      ln -sfn "$target" "$live"
      changed=1
    fi
    rm -f -- "$legacy_wrapper" /var/lib/orbit-e2e/caddy-rendered-path /var/lib/orbit-e2e/caddy-config-sha256
    caddy validate --config "$live" --adapter caddyfile
    if [[ "$changed" -eq 1 ]]; then
      systemctl reload caddy
    fi
    ;;
  reproject)
    # Re-project every managed role and instance through the product so the
    # rendered PHP-FPM pools, Caddy fragments, firewall rules, and DNS records
    # match the Gateway code in the checkout. Roles first, then instances with
    # development last: the app-dev runtime converger publishes the Gateway
    # DNS records for every active site, so it must run after every other
    # instance is active again.
    [[ "$(id -u)" -eq 0 ]] && exec sudo -u orbit -- env HOME=/home/orbit ORBIT_HOME=/home/orbit/.orbit DB_DATABASE=/home/orbit/.orbit/gateway.sqlite bash "$0" "$@"
    [[ $# -eq 1 ]] || exit 64
    "$orbit" node:list --json | php -r '$v=json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR); foreach ($v["nodes"] as $n) { foreach ($n["roles"] ?? [] as $r) { if (in_array($r, ["app-dev", "app-prod"], true)) { printf("%d %s\n", $n["id"], $r); } } }' | while read -r node_id role; do
      "$orbit" node:role:add "$node_id" "$role" --converge --json | php -r '$v=json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR); if (($v["assignment"]["status"] ?? null) !== "active") { fwrite(STDERR, "role is not active after re-projection\n"); exit(1); } printf("reprojected role %s on node %d\n", $v["role"], $v["node_id"]);' || exit 1
    done
    "$orbit" instance:list --json | php -r '$v=json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR); $i=$v["instances"]; usort($i, fn($a, $b) => [$a["environment"] === "development", $a["id"]] <=> [$b["environment"] === "development", $b["id"]]); foreach ($i as $x) { printf("%d %s\n", $x["id"], $x["php_version"]); }' | while read -r id version; do
      "$orbit" instance:php "$id" "$version" --json | php -r '$v=json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR); if (($v["status"] ?? null) !== "active") { fwrite(STDERR, "instance is not active after re-projection\n"); exit(1); } printf("reprojected instance %d (%s) on node %d\n", $v["id"], $v["name"], $v["node_id"]);' || exit 1
    done
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
    hydrate_composer_dependencies() {
      local checkout=$1
      local lock_hash marker marker_tmp
      marker="$checkout/vendor/.orbit-e2e-composer-lock"
      if [[ -f "$checkout/composer.lock" ]]; then
        lock_hash=$(sha256sum "$checkout/composer.lock" | awk '{print $1}')
        if [[ -s "$checkout/vendor/autoload.php" && -f "$marker" && "$(<"$marker")" == "$lock_hash" ]]; then
          return
        fi
      fi
      run_as_runtime composer install --working-dir="$checkout" --no-interaction --no-progress
      [[ -s "$checkout/vendor/autoload.php" && -f "$checkout/composer.lock" ]]
      lock_hash=$(sha256sum "$checkout/composer.lock" | awk '{print $1}')
      marker_tmp=$(run_as_runtime mktemp "$checkout/vendor/.orbit-e2e-composer-lock.XXXXXX")
      printf '%s' "$lock_hash" | run_as_runtime tee "$marker_tmp" >/dev/null
      run_as_runtime mv -f "$marker_tmp" "$marker"
    }
    for checkout in "${checkouts[@]}"; do
      [[ -d "$checkout/.git" || -f "$checkout/.git" ]] || exit 66
      [[ "$(run_as_runtime git -C "$checkout" remote get-url origin)" == https://github.com/laravel/laravel.git ]]
      if ! run_as_runtime git -C "$checkout" cat-file -e "$2^{commit}"; then
        run_as_runtime git -C "$checkout" fetch --quiet origin "$2"
      fi
      run_as_runtime git -C "$checkout" reset --hard --quiet "$2"
      [[ "$(run_as_runtime git -C "$checkout" rev-parse HEAD)" == "$2" ]]
      [[ -f "$checkout/.env" ]] || run_as_runtime cp "$checkout/.env.example" "$checkout/.env"
      hydrate_composer_dependencies "$checkout"
      run_as_runtime grep -q '^APP_KEY=base64:' "$checkout/.env" || run_as_runtime php "$checkout/artisan" key:generate --force --no-interaction
      run_as_runtime install -d -m 0775 "$checkout/storage" "$checkout/bootstrap/cache"
      run_as_runtime chmod -R ug+rwX "$checkout/storage" "$checkout/bootstrap/cache"
      if run_as_runtime grep -q '^DB_CONNECTION=sqlite$' "$checkout/.env"; then
        run_as_runtime install -d -m 0775 "$checkout/database"
        [[ -f "$checkout/database/database.sqlite" ]] || run_as_runtime touch "$checkout/database/database.sqlite"
      fi
      run_as_runtime php "$checkout/artisan" migrate --force --no-interaction
    done
    if [[ "$3" == app-prod ]]; then
      # The product-managed Caddyfile serves the site with the internal CA that
      # `internal-tls` placed inside the managed version.
      ca=$(cat /var/lib/orbit-e2e/caddy-ca-path)
      [[ -s "$ca" ]]
      curl --fail --silent --show-error --retry 10 --retry-delay 2 --retry-connrefused --retry-all-errors --connect-timeout 10 --max-time 30 --cacert "$ca" --resolve laravel.internal:443:127.0.0.1 https://laravel.internal/ >/dev/null
    fi
    ;;
  *) exit 64 ;;
esac
