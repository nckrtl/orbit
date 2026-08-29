#!/usr/bin/env bash
set -euo pipefail
umask 077
cd /home/orbit/orbit/apps/gateway
[[ $# -eq 3 && "$1" =~ ^[a-zA-Z0-9][a-zA-Z0-9.-]{0,62}$ && "$2" =~ ^([0-9]{1,3}\.){3}[0-9]{1,3}$ ]] || exit 64
fingerprint=$(ssh-keyscan -t ed25519 -- "$2" 2>/dev/null | ssh-keygen -lf - -E sha256 | awk 'NR == 1 { print $2 }')
[[ "$fingerprint" =~ ^SHA256:[A-Za-z0-9+/]{43}$ ]]
[[ "$3" =~ ^(x86_64|aarch64)$ ]] || exit 65
state=/var/lib/orbit-e2e/node-provision-app-dev
prepared=$(printf '%s\0' 'contract-2' "$1" "$3" "$fingerprint" "$(sha256sum "$0" | awk '{print $1}')" | sha256sum | awk '{print $1}')
address=$(printf '%s\0' "$2" | sha256sum | awk '{print $1}')
if [[ -e "$state" ]]; then
  current_prepared=$(cat "$state")
  [[ "$current_prepared" =~ ^[0-9a-f]{64}$ ]] || exit 66
  if [[ -e "$state.address" ]]; then
    current_address=$(cat "$state.address")
    [[ "$current_address" =~ ^[0-9a-f]{64}$ ]] || exit 66
  else
    current_address=''
  fi
  if [[ "$current_prepared" == "$prepared" && "$current_address" == "$address" ]]; then exit 0; fi
  if [[ "$current_prepared" == "$prepared" && -n "$current_address" ]]; then
    if ! sudo -u orbit -- env HOME=/home/orbit ORBIT_HOME=/home/orbit/.orbit ORBIT_GATEWAY_CHECKOUT=/home/orbit/orbit/apps/gateway DB_DATABASE=/home/orbit/.orbit/gateway.sqlite php /home/orbit/orbit/apps/gateway/artisan orbit:node-retarget "$1" "$2" --no-interaction; then
      rm -f "$state" "$state.address"
      exit 1
    fi
    printf '%s\n' "$address" | install -m 0644 /dev/stdin "$state.address"
    exit 0
  fi
fi
sudo -u orbit -- env HOME=/home/orbit ORBIT_HOME=/home/orbit/.orbit ORBIT_GATEWAY_CHECKOUT=/home/orbit/orbit/apps/gateway DB_DATABASE=/home/orbit/.orbit/gateway.sqlite php /home/orbit/orbit/apps/gateway/artisan orbit:node-provision "$1" "$2" \
  --role=app-dev --tld=beast --architecture="$3" --user=orbit \
  --host-key-fingerprint="$fingerprint" --no-interaction
install -d -m 0755 "$(dirname "$state")"
printf '%s\n' "$prepared" | install -m 0644 /dev/stdin "$state"
printf '%s\n' "$address" | install -m 0644 /dev/stdin "$state.address"
