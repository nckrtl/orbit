#!/usr/bin/env bash
set -euo pipefail
umask 077
cd /home/orbit/orbit/apps/gateway
[[ $# -eq 3 && "$1" =~ ^[a-zA-Z0-9][a-zA-Z0-9.-]{0,62}$ && "$2" =~ ^([0-9]{1,3}\.){3}[0-9]{1,3}$ ]] || exit 64
[[ "$3" =~ ^(x86_64|aarch64)$ ]] || exit 65
wireguard_ip=10.44.0.2
db=/home/orbit/.orbit/gateway.sqlite
# The Gateway store is the source of truth: an active node with an active
# role is already provisioned. Public SSH closes after provisioning, so a
# repeat provision cannot run against a converged node.
node_active() {
  [[ -r "$db" ]] || return 1
  [[ "$(php -r '$pdo = new PDO("sqlite:".$argv[1]); $statement = $pdo->prepare("SELECT COUNT(*) FROM nodes n INNER JOIN node_roles r ON r.node_id = n.id WHERE n.name = ? AND n.status = ? AND r.role = ? AND r.status = ?"); $statement->execute([$argv[2], "active", $argv[3], "active"]); echo $statement->fetchColumn();' -- "$db" "$1" "$2")" == 1 ]]
}
scan_host_key() {
  local deadline=$((SECONDS + 60)) keys
  until keys=$(ssh-keyscan -T 5 -t ed25519 -- "$1" 2>/dev/null) && [[ -n "$keys" ]]; do
    if (( SECONDS >= deadline )); then return 1; fi
    sleep 2
  done
  printf '%s\n' "$keys"
}
provision() {
  local fingerprint
  fingerprint=$(scan_host_key "$2" | ssh-keygen -lf - -E sha256 | awk 'NR == 1 { print $2 }')
  [[ "$fingerprint" =~ ^SHA256:[A-Za-z0-9+/]{43}$ ]]
  sudo -u orbit -- env HOME=/home/orbit ORBIT_HOME=/home/orbit/.orbit ORBIT_GATEWAY_CHECKOUT=/home/orbit/orbit/apps/gateway DB_DATABASE=/home/orbit/.orbit/gateway.sqlite php /home/orbit/orbit/apps/gateway/artisan orbit:node-provision "$1" "$2" \
    --role=app-dev --tld=beast --architecture="$3" --user=orbit \
    --wireguard-ip="$wireguard_ip" \
    --host-key-fingerprint="$fingerprint" --no-interaction
}
if ! node_active "$1" app-dev; then
  provision "$1" "$2" "$3"
fi
