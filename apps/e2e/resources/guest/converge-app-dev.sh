#!/usr/bin/env bash
set -euo pipefail
umask 077
[[ $# -eq 1 && "$1" =~ ^[a-zA-Z0-9][a-zA-Z0-9.-]{0,62}$ ]] || exit 64
fingerprint=$(ssh-keyscan -- "$1" 2>/dev/null | ssh-keygen -lf - -E sha256 | awk 'NR == 1 { print $2 }')
[[ "$fingerprint" =~ ^SHA256:[A-Za-z0-9+/]{43}$ ]]
php /home/orbit/orbit/apps/gateway/artisan orbit:node-provision "$1" "$1" \
  --role=app-dev --tld=beast --host-key-fingerprint="$fingerprint" --no-interaction
