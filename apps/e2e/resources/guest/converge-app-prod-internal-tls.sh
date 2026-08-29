#!/usr/bin/env bash
set -euo pipefail
umask 077
[[ $# -eq 3 && "$1" =~ ^[a-zA-Z0-9][a-zA-Z0-9.-]{0,62}$ && "$2" =~ ^([0-9]{1,3}\.){3}[0-9]{1,3}$ ]] || exit 64
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
    --host-key-fingerprint="$fingerprint" --no-interaction
  install -d -m 0755 "$(dirname "$state")"
  printf '%s\n' "$prepared" | install -m 0644 /dev/stdin "$state"
  printf '%s\n' "$address" | install -m 0644 /dev/stdin "$state.address"
fi
sudo -u orbit -- env HOME=/home/orbit ssh -i /home/orbit/.orbit/ssh/id_ed25519 \
  -o UserKnownHostsFile=/home/orbit/.orbit/ssh/known_hosts \
  -o BatchMode=yes \
  -o StrictHostKeyChecking=yes \
  -- orbit@"$2" 'bash -se' <<'GUEST'
set -euo pipefail
fragment=/etc/caddy/orbit-e2e-global.caddy
state=/var/lib/orbit-e2e/caddy-config-sha256
rendered=/etc/caddy/Caddyfile
if [[ -L "$rendered" ]]; then rendered=$(readlink -f "$rendered"); fi
printf '%s\n' '{' '    local_certs' '}' | sudo install -m 0644 /dev/stdin "$fragment"
sudo caddy validate --config "$fragment" --adapter caddyfile
if [[ -e /etc/caddy/Caddyfile ]]; then
  sudo caddy validate --config /etc/caddy/Caddyfile --adapter caddyfile
fi
sudo install -d -m 0755 /var/lib/orbit-e2e
candidate=$(mktemp /etc/caddy/Caddyfile.orbit-e2e.XXXXXX)
printf 'import %s\nimport %s\n' "$fragment" "$rendered" >"$candidate"
hash=$(sha256sum "$candidate" | awk '{print $1}')
if [[ -e "$state" ]]; then
  [[ "$(cat "$state")" =~ ^[0-9a-f]{64}$ ]] || exit 66
  current=$(sha256sum /etc/caddy/Caddyfile 2>/dev/null | awk '{print $1}' || true)
  if [[ "$current" == "$hash" && "$(cat "$state")" == "$hash" ]]; then
    rm -f "$candidate"
  else
    mv -f "$candidate" /etc/caddy/Caddyfile.orbit-e2e
    ln -sfn Caddyfile.orbit-e2e /etc/caddy/Caddyfile
    printf '%s\n' "$hash" | sudo install -m 0644 /dev/stdin "$state"
    sudo systemctl reload caddy
  fi
else
  mv -f "$candidate" /etc/caddy/Caddyfile.orbit-e2e
  ln -sfn Caddyfile.orbit-e2e /etc/caddy/Caddyfile
  printf '%s\n' "$hash" | sudo install -m 0644 /dev/stdin "$state"
  sudo systemctl reload caddy
fi
printf '%s\n' /var/lib/caddy/.local/share/caddy/pki/authorities/local/root.crt | sudo tee /var/lib/orbit-e2e/caddy-ca-path >/dev/null
GUEST
