#!/usr/bin/env bash
set -euo pipefail
umask 077
[[ $# -eq 1 && "$1" =~ ^[a-zA-Z0-9][a-zA-Z0-9.-]{0,62}$ ]] || exit 64
fingerprint=$(ssh-keyscan -- "$1" 2>/dev/null | ssh-keygen -lf - -E sha256 | awk 'NR == 1 { print $2 }')
[[ "$fingerprint" =~ ^SHA256:[A-Za-z0-9+/]{43}$ ]]
sudo -u orbit -- env HOME=/home/orbit ORBIT_HOME=/home/orbit/.orbit ORBIT_GATEWAY_CHECKOUT=/home/orbit/orbit/apps/gateway DB_DATABASE=/home/orbit/.orbit/gateway.sqlite php /home/orbit/orbit/apps/gateway/artisan orbit:node-provision "$1" "$1" \
  --role=app-prod --host-key-fingerprint="$fingerprint" --no-interaction
sudo -u orbit -- env HOME=/home/orbit ssh -i /home/orbit/.orbit/ssh/id_ed25519 \
  -o UserKnownHostsFile=/home/orbit/.orbit/ssh/known_hosts \
  -o BatchMode=yes \
  -o StrictHostKeyChecking=yes \
  -- orbit@"$1" 'bash -se' <<'GUEST'
set -euo pipefail
fragment=/etc/caddy/orbit-e2e-global.caddy
printf '%s\n' '{' '    local_certs' '}' | sudo install -m 0644 /dev/stdin "$fragment"
sudo caddy validate --config "$fragment" --adapter caddyfile
if [[ -e /etc/caddy/Caddyfile ]]; then
  sudo caddy validate --config /etc/caddy/Caddyfile --adapter caddyfile
  sudo systemctl reload caddy
fi
sudo install -d -m 0755 /var/lib/orbit-e2e
printf '%s\n' /var/lib/caddy/.local/share/caddy/pki/authorities/local/root.crt | sudo tee /var/lib/orbit-e2e/caddy-ca-path >/dev/null
GUEST
