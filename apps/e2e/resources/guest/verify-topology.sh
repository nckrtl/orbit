#!/usr/bin/env bash
set -euo pipefail
umask 077

[[ $# -eq 3 || ( $# -eq 5 && "$1" == wireguard.reachability ) ]]
probe=$1
mode=$2
identity=$3
[[ "$mode" == readiness || "$mode" == proof ]]
[[ "$identity" =~ ^[0-9a-f]{40}$ ]]
if [[ "$probe" == wireguard.reachability ]]; then
  app_dev_name=$4
  app_prod_name=$5
  [[ "$app_dev_name" =~ ^[A-Za-z0-9][A-Za-z0-9-]{0,62}$ ]]
  [[ "$app_prod_name" =~ ^[A-Za-z0-9][A-Za-z0-9-]{0,62}$ ]]
  [[ "$app_dev_name" != "$app_prod_name" ]]
fi

case "$probe" in
  vm.gateway.running|vm.app-dev.running|vm.app-prod.running) state=$(systemctl is-system-running 2>/dev/null) || exit 1; [[ "$state" == running || "$state" == degraded ]] ;;
  role.gateway) [[ -f /home/orbit/orbit/apps/gateway/artisan && -f /home/orbit/orbit/apps/gateway/.env && -f /home/orbit/.orbit/gateway.app-key && -f /home/orbit/.orbit/gateway.sqlite && "$(stat -c '%U:%a' /home/orbit/.orbit/gateway.sqlite 2>/dev/null)" == orbit:600 && -f /etc/wireguard/orbit.conf && -f /etc/caddy/Caddyfile ]] ;;
  role.app-dev) [[ -d /home/orbit/apps/laravel && -d /home/orbit/.orbit/worktrees/laravel/e2e ]] ;;
  role.app-prod) [[ -d /var/www/laravel/e2e-prod ]] ;;
  service.gateway) systemctl is-active --quiet caddy 2>/dev/null && systemctl is-active --quiet php8.5-fpm 2>/dev/null ;;
  service.vpn) systemctl is-active --quiet wg-quick@orbit 2>/dev/null ;;
  wireguard.reachability)
    command -v wg >/dev/null
    command -v sqlite3 >/dev/null
    command -v ssh >/dev/null
    db=/home/orbit/.orbit/gateway.sqlite
    key=/home/orbit/.orbit/ssh/id_ed25519
    [[ -r "$db" && -r "$key" ]]
    app_dev_address=$(sqlite3 -cmd ".parameter set :name $app_dev_name" "$db" 'SELECT wireguard_address FROM nodes WHERE name = :name;')
    app_prod_address=$(sqlite3 -cmd ".parameter set :name $app_prod_name" "$db" 'SELECT wireguard_address FROM nodes WHERE name = :name;')
    [[ "$app_dev_address" =~ ^([0-9]{1,3}\.){3}[0-9]{1,3}$ ]]
    [[ "$app_prod_address" =~ ^([0-9]{1,3}\.){3}[0-9]{1,3}$ ]]
    wg show orbit allowed-ips | awk -v expected="$app_dev_address/32" '{ for (i = 1; i <= NF; i++) if ($i == expected) found = 1 } END { exit !found }'
    wg show orbit allowed-ips | awk -v expected="$app_prod_address/32" '{ for (i = 1; i <= NF; i++) if ($i == expected) found = 1 } END { exit !found }'
    sudo -u orbit -- env HOME=/home/orbit ssh -n -o BatchMode=yes -o ConnectTimeout=5 -o StrictHostKeyChecking=yes -o HostKeyAlias="$app_dev_name" -i "$key" "orbit@$app_dev_address" true
    sudo -u orbit -- env HOME=/home/orbit ssh -n -o BatchMode=yes -o ConnectTimeout=5 -o StrictHostKeyChecking=yes -o HostKeyAlias="$app_prod_name" -i "$key" "orbit@$app_prod_address" true
    ;;
  https.gateway-internal) curl --fail --silent --show-error --max-time 5 --cacert /home/orbit/.orbit/e2e-gateway-root-ca.pem https://gateway.orbit/ >/dev/null ;;
  php-fpm.app-dev|php-fpm.app-prod) systemctl is-active --quiet php8.5-fpm 2>/dev/null ;;
  caddy.app-dev|caddy.app-prod) systemctl is-active --quiet caddy 2>/dev/null && caddy validate --config /etc/caddy/Caddyfile --adapter caddyfile >/dev/null 2>&1 ;;
  laravel.dev) [[ -f /home/orbit/apps/laravel/artisan ]] && php /home/orbit/apps/laravel/artisan --version >/dev/null ;;
  laravel.prod) [[ -f /var/www/laravel/e2e-prod/artisan ]] && php /var/www/laravel/e2e-prod/artisan --version >/dev/null && curl --fail --silent --show-error --max-time 5 --cacert "$(cat /var/lib/orbit-e2e/caddy-ca-path)" https://laravel.internal/ >/dev/null ;;
  workspace.app-dev) [[ -d /home/orbit/.orbit/worktrees/laravel/e2e && -f /home/orbit/.orbit/worktrees/laravel/e2e/artisan ]] ;;
  source.gateway|source.app-dev) [[ "$(git -C /home/orbit/orbit rev-parse HEAD 2>/dev/null)" == "$identity" ]] ;;
  source.manifest) [[ -f /home/orbit/orbit/.git/orbit-overlay.paths ]] ;;
  operator.app-dev) sudo -u orbit -- env HOME=/home/orbit ORBIT_HOME=/home/orbit/.orbit DB_DATABASE=/home/orbit/.orbit/gateway.sqlite /home/orbit/orbit/apps/cli/orbit gateway:status --json >/dev/null ;;
  *) exit 64 ;;
esac

printf '{"probe":"%s","passed":true,"identity":"%s"}\n' "$probe" "$identity"
