#!/usr/bin/env bash
set -euo pipefail
umask 077
checkout=/home/orbit/orbit
gateway=$checkout/apps/gateway
orbit_env=(sudo -u orbit -- env HOME=/home/orbit ORBIT_HOME=/home/orbit/.orbit ORBIT_GATEWAY_CHECKOUT=/home/orbit/orbit/apps/gateway DB_DATABASE=/home/orbit/.orbit/gateway.sqlite)
case ${1-} in
  bootstrap)
    [[ $# -eq 2 && "$2" =~ ^[a-zA-Z0-9][a-zA-Z0-9.-]{0,62}$ ]]
    install -d -o orbit -g orbit -m 0700 /home/orbit/.orbit
    touch /home/orbit/.orbit/gateway.sqlite
    chown orbit:orbit /home/orbit/.orbit/gateway.sqlite
    chmod 0600 /home/orbit/.orbit/gateway.sqlite
    "${orbit_env[@]}" php "$gateway/artisan" migrate --force
    "${orbit_env[@]}" php "$gateway/artisan" orbit:bootstrap "$2" --no-interaction
    ;;
  *) exit 64 ;;
esac
