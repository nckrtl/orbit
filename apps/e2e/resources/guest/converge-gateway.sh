#!/usr/bin/env bash
set -euo pipefail
umask 077
checkout=/home/orbit/orbit
gateway=$checkout/apps/gateway
orbit_env=(sudo -u orbit -- env HOME=/home/orbit ORBIT_HOME=/home/orbit/.orbit ORBIT_GATEWAY_CHECKOUT=/home/orbit/orbit/apps/gateway DB_DATABASE=/home/orbit/.orbit/gateway.sqlite)
gateway_prerequisites() {
  local package
  local -a missing=()
  for package in caddy dnsmasq php8.5-fpm php8.5-mysql php8.5-pgsql; do
    if [[ "$(dpkg-query -W -f='${Status}' "$package" 2>/dev/null || true)" != 'install ok installed' ]]; then
      missing+=("$package")
    fi
  done
  if ((${#missing[@]} > 0)); then
    DEBIAN_FRONTEND=noninteractive apt-get update
    DEBIAN_FRONTEND=noninteractive apt-get install --yes --no-install-recommends -- "${missing[@]}"
  fi
}
case ${1-} in
  prerequisites)
    [[ $# -eq 1 ]]
    gateway_prerequisites
    # A restored environment may still name the retired standalone checkout.
    if [[ -f "$gateway/.env" ]]; then
      sed -i '/^ORBIT_GATEWAY_CHECKOUT=/d' "$gateway/.env"
      printf 'ORBIT_GATEWAY_CHECKOUT=%s\n' "$gateway" >>"$gateway/.env"
    fi
    ;;
  private-dns)
    [[ $# -eq 3 && "$2" =~ ^[a-z][a-z0-9-]{0,22}$ && "$3" =~ ^[a-z][a-z0-9-]{0,22}$ ]] || exit 64
    cd "$gateway"
    systemctl reset-failed orbit-private-dns
    "${orbit_env[@]}" php -r 'require "vendor/autoload.php"; $app=require "bootstrap/app.php"; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); $app->make(App\Domain\AppDev\PrivateDnsManager::class)->converge();'
    systemctl restart orbit-private-dns
    "${orbit_env[@]}" php artisan orbit:node-dns-repair "$2" --no-interaction
    "${orbit_env[@]}" php artisan orbit:node-dns-repair "$3" --no-interaction
    ;;
  bootstrap)
    [[ $# -eq 2 && "$2" =~ ^[a-zA-Z0-9][a-zA-Z0-9.-]{0,62}$ ]]
    install -d -o orbit -g orbit -m 0700 /home/orbit/.orbit
    touch /home/orbit/.orbit/gateway.sqlite
    chown orbit:orbit /home/orbit/.orbit/gateway.sqlite
    chmod 0600 /home/orbit/.orbit/gateway.sqlite
    cd "$gateway"
    if ! "${orbit_env[@]}" php artisan migrate --force; then
      exit 70
    fi
    if ! "${orbit_env[@]}" php artisan orbit:bootstrap "$2" --no-interaction; then
      exit 71
    fi
    ;;
  *) exit 64 ;;
esac
