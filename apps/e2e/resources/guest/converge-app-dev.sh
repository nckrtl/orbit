#!/usr/bin/env bash
set -euo pipefail
umask 077
write_marker() {
  local value="$1" destination="$2" source
  source=$(mktemp)
  printf '%s\n' "$value" >"$source"
  if ! install -m 0644 "$source" "$destination"; then
    rm -f "$source"
    return 1
  fi
  rm -f "$source"
}
cd /home/orbit/orbit/apps/gateway
[[ $# -eq 3 && "$1" =~ ^[a-zA-Z0-9][a-zA-Z0-9.-]{0,62}$ && "$2" =~ ^([0-9]{1,3}\.){3}[0-9]{1,3}$ ]] || exit 64
wireguard_address=10.44.0.2
scan_host_key() {
  local deadline=$((SECONDS + 60)) keys
  until keys=$(ssh-keyscan -T 5 -t ed25519 -- "$1" 2>/dev/null) && [[ -n "$keys" ]]; do
    if (( SECONDS >= deadline )); then return 1; fi
    sleep 2
  done
  printf '%s\n' "$keys"
}
fingerprint=$(scan_host_key "$2" | ssh-keygen -lf - -E sha256 | awk 'NR == 1 { print $2 }')
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
    write_marker "$address" "$state.address"
    exit 0
  fi
fi
sudo -u orbit -- env HOME=/home/orbit ORBIT_HOME=/home/orbit/.orbit ORBIT_GATEWAY_CHECKOUT=/home/orbit/orbit/apps/gateway DB_DATABASE=/home/orbit/.orbit/gateway.sqlite php /home/orbit/orbit/apps/gateway/artisan orbit:node-provision "$1" "$2" \
  --role=app-dev --tld=beast --architecture="$3" --user=orbit \
  --wireguard-address="$wireguard_address" \
  --host-key-fingerprint="$fingerprint" --no-interaction
install -d -m 0755 "$(dirname "$state")"
write_marker "$prepared" "$state"
write_marker "$address" "$state.address"
