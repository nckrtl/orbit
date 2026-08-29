#!/usr/bin/env bash
set -euo pipefail
umask 077
cd /home/orbit/orbit/apps/gateway
[[ $# -eq 3 && "$1" =~ ^[a-zA-Z0-9][a-zA-Z0-9.-]{0,62}$ && "$2" =~ ^([0-9]{1,3}\.){3}[0-9]{1,3}$ ]] || exit 64
wireguard_address=10.44.0.3
fingerprint=$(ssh-keyscan -t ed25519 -- "$2" 2>/dev/null | ssh-keygen -lf - -E sha256 | awk 'NR == 1 { print $2 }')
[[ "$fingerprint" =~ ^SHA256:[A-Za-z0-9+/]{43}$ ]]
[[ "$3" =~ ^(x86_64|aarch64)$ ]] || exit 65
state=/var/lib/orbit-e2e/node-provision-app-prod
prepared=$(printf '%s\0' 'contract-2' "$1" "$3" "$fingerprint" "$(sha256sum "$0" | awk '{print $1}')" | sha256sum | awk '{print $1}')
address=$(printf '%s\0' "$2" | sha256sum | awk '{print $1}')
provision=true
if [[ -e "$state" ]]; then
  current_prepared=$(cat "$state")
  [[ "$current_prepared" =~ ^[0-9a-f]{64}$ ]] || exit 66
  if [[ -e "$state.address" ]]; then
    current_address=$(cat "$state.address")
    [[ "$current_address" =~ ^[0-9a-f]{64}$ ]] || exit 66
  else
    current_address=''
  fi
  if [[ "$current_prepared" == "$prepared" && "$current_address" == "$address" ]]; then
    provision=false
  elif [[ "$current_prepared" == "$prepared" && -n "$current_address" ]]; then
    if ! sudo -u orbit -- env HOME=/home/orbit ORBIT_HOME=/home/orbit/.orbit ORBIT_GATEWAY_CHECKOUT=/home/orbit/orbit/apps/gateway DB_DATABASE=/home/orbit/.orbit/gateway.sqlite php /home/orbit/orbit/apps/gateway/artisan orbit:node-retarget "$1" "$2" --no-interaction; then
      rm -f "$state" "$state.address"
      exit 1
    fi
    printf '%s\n' "$address" | install -m 0644 /dev/stdin "$state.address"
    provision=false
  fi
fi
if [[ "$provision" == true ]]; then
  sudo -u orbit -- env HOME=/home/orbit ORBIT_HOME=/home/orbit/.orbit ORBIT_GATEWAY_CHECKOUT=/home/orbit/orbit/apps/gateway DB_DATABASE=/home/orbit/.orbit/gateway.sqlite php /home/orbit/orbit/apps/gateway/artisan orbit:node-provision "$1" "$2" \
    --role=app-prod --architecture="$3" --user=orbit \
    --wireguard-address="$wireguard_address" \
    --host-key-fingerprint="$fingerprint" --no-interaction
  install -d -m 0755 "$(dirname "$state")"
  printf '%s\n' "$prepared" | install -m 0644 /dev/stdin "$state"
  printf '%s\n' "$address" | install -m 0644 /dev/stdin "$state.address"
fi
sudo -u orbit -- env HOME=/home/orbit ssh -i /home/orbit/.orbit/ssh/id_ed25519 \
  -o UserKnownHostsFile=/home/orbit/.orbit/ssh/known_hosts \
  -o BatchMode=yes \
  -o StrictHostKeyChecking=yes \
  -- orbit@"$wireguard_address" 'bash -se' <<'GUEST'
set -euo pipefail
fragment=/etc/caddy/orbit-e2e-global.caddy
printf '%s\n' '{' '    local_certs' '}' | sudo install -m 0644 /dev/stdin "$fragment"
sudo caddy validate --config "$fragment" --adapter caddyfile
sudo install -d -m 0755 /var/lib/orbit-e2e
printf '%s\n' /var/lib/caddy/.local/share/caddy/pki/authorities/local/root.crt | sudo tee /var/lib/orbit-e2e/caddy-ca-path >/dev/null
GUEST
