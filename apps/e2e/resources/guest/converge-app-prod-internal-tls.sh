#!/usr/bin/env bash
set -euo pipefail
umask 077
[[ $# -eq 1 && "$1" =~ ^[a-zA-Z0-9][a-zA-Z0-9.-]{0,62}$ ]] || exit 64
fingerprint=$(ssh-keyscan -- "$1" 2>/dev/null | ssh-keygen -lf - -E sha256 | awk 'NR == 1 { print $2 }')
[[ "$fingerprint" =~ ^SHA256:[A-Za-z0-9+/]{43}$ ]]
php /home/orbit/orbit/apps/gateway/artisan orbit:node-provision "$1" "$1" \
  --role=app-prod --host-key-fingerprint="$fingerprint" --no-interaction
ssh -o BatchMode=yes -- "$1" 'bash -se' <<'GUEST'
set -euo pipefail
fragment=/etc/caddy/orbit-e2e-global.caddy
printf '%s\n' '{' '    local_certs' '}' | install -m 0644 /dev/stdin "$fragment"
caddy validate --config "$fragment" --adapter caddyfile
if [[ -e /etc/caddy/Caddyfile ]]; then
  caddy validate --config /etc/caddy/Caddyfile --adapter caddyfile
  systemctl reload caddy
fi
install -d -m 0755 /var/lib/orbit-e2e
printf '%s\n' /var/lib/caddy/.local/share/caddy/pki/authorities/local/root.crt > /var/lib/orbit-e2e/caddy-ca-path
GUEST
