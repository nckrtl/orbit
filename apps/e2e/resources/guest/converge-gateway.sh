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
    if ! "${orbit_env[@]}" php "$gateway/artisan" migrate --force; then
      exit 70
    fi
    if ! "${orbit_env[@]}" php "$gateway/artisan" orbit:bootstrap "$2" --no-interaction; then
      failure=$(sqlite3 -noheader -separator ' ' -cmd ".parameter set :public_host $2" \
        /home/orbit/.orbit/gateway.sqlite \
        "SELECT failed_step, error_code FROM nodes WHERE public_ssh_host = :public_host AND status = 'failed' LIMIT 1;" \
        2>/dev/null || true)
      if [[ "$failure" =~ ^([a-z0-9:-]+)\ ([a-z0-9._-]+)$ ]]; then
        printf 'orbit-e2e-failure step=%s error=%s\n' "${BASH_REMATCH[1]}" "${BASH_REMATCH[2]}" >&2
      fi
      exit 71
    fi
    ;;
  *) exit 64 ;;
esac
