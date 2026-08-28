#!/usr/bin/env bash
set -euo pipefail
umask 077
checkout=/home/orbit/orbit
gateway=$checkout/apps/gateway
case ${1-} in
  hydrate)
    [[ $# -eq 2 && "$2" =~ ^[0-9a-f]{40}$ ]]
    cd /home/orbit
    "$checkout/apps/e2e/resources/guest/hydrate-orbit.sh" orbit gateway
    git -C "$checkout" reset --hard --quiet "$2"
    composer install --working-dir="$gateway" --no-interaction --no-progress
    ;;
  bootstrap)
    [[ $# -eq 2 && "$2" =~ ^[a-zA-Z0-9][a-zA-Z0-9.-]{0,62}$ ]]
    install -d -m 0750 "$gateway/database"
    touch "$gateway/database/database.sqlite"
    php "$gateway/artisan" migrate --force
    php "$gateway/artisan" orbit:bootstrap "$2" --no-interaction
    ;;
  *) exit 64 ;;
esac
