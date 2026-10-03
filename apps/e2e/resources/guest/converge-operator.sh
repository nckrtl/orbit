#!/usr/bin/env bash
set -euo pipefail
umask 077
cd /home/orbit/orbit/apps/gateway
[[ $# -eq 3 && "$1" =~ ^([0-9]{1,3}\.){3}[0-9]{1,3}$ && "$2" =~ ^(x86_64|aarch64)$ && "$3" =~ ^10\.44\.0\.[1-9][0-9]{0,2}$ ]] || exit 64
db=/home/orbit/.orbit/gateway.sqlite
active=$(php -r '$pdo=new PDO("sqlite:".$argv[1]); echo $pdo->query("SELECT COUNT(*) FROM nodes WHERE name = '\''operator'\'' AND status = '\''active'\''")->fetchColumn();' -- "$db")
if [[ "$active" != 1 ]]; then
  keys=$(ssh-keyscan -T 10 -t ed25519 -- "$1" 2>/dev/null)
  fingerprint=$(ssh-keygen -lf - -E sha256 <<<"$keys" | awk 'NR == 1 { print $2 }')
  [[ "$fingerprint" =~ ^SHA256:[A-Za-z0-9+/]{43}$ ]]
  sudo -u orbit -- env HOME=/home/orbit ORBIT_HOME=/home/orbit/.orbit ORBIT_GATEWAY_CHECKOUT=/home/orbit/orbit/apps/gateway DB_DATABASE="$db" php artisan orbit:node-provision operator "$1" --architecture="$2" --user=orbit --wireguard-ip="$3" --host-key-fingerprint="$fingerprint" --no-interaction
fi
