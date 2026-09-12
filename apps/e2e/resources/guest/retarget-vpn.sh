#!/usr/bin/env bash
set -euo pipefail
umask 077
# Point this node's WireGuard peer at the Gateway's current address.
# Cloned topologies live on a new subnet, and the Gateway closes public SSH
# after role provisioning, so the endpoint is repaired here as root instead
# of through orbit:node-retarget.
[[ ( $# -eq 1 || $# -eq 2 ) && "$1" =~ ^([0-9]{1,3}\.){3}[0-9]{1,3}$ ]] || exit 64
conf=/etc/wireguard/orbit.conf
if [[ ! -f "$conf" ]]; then
  [[ $# -eq 1 ]] && exit 0
  exit 65
fi
current=$(sed -n 's/^Endpoint *= *//p' "$conf" | head -n 1)
[[ "$current" =~ ^([0-9]{1,3}\.){3}[0-9]{1,3}:[0-9]{1,5}$ ]] || exit 65
port=${current##*:}
desired="$1:$port"
peer=$(sed -n 's/^PublicKey *= *//p' "$conf" | head -n 1)
[[ -n "$peer" ]] || exit 65
if [[ $# -eq 2 ]]; then
  [[ "$2" =~ ^([0-9]{1,3}\.){3}[0-9]{1,3}:[1-9][0-9]{0,4}$ && "$desired" == "$2" ]] || exit 65
  (( 10#$port >= 1 && 10#$port <= 65535 )) || exit 65
  [[ $(grep -c '^Endpoint *=' "$conf") -eq 1 && $(grep -c '^PublicKey *=' "$conf") -eq 1 ]] || exit 65
  [[ "$peer" =~ ^[a-zA-Z0-9+/]{43}=$ ]] || exit 65
  for address in "$1" "${current%:*}"; do
    IFS=. read -r -a octets <<< "$address"
    [[ ${#octets[@]} -eq 4 ]] || exit 65
    for octet in "${octets[@]}"; do
      [[ "$octet" =~ ^(0|[1-9][0-9]{0,2})$ ]] && (( 10#$octet <= 255 )) || exit 65
    done
  done
fi
if [[ "$current" != "$desired" ]]; then
  candidate=$(mktemp "$conf.XXXXXX")
  sed "s|^Endpoint *=.*|Endpoint = $desired|" "$conf" >"$candidate"
  chmod 0600 "$candidate"
  mv -f "$candidate" "$conf"
fi
if systemctl is-active --quiet wg-quick@orbit; then
  if [[ "$current" != "$desired" || $# -eq 2 ]]; then
    wg set orbit peer "$peer" endpoint "$desired"
  fi
elif ! systemctl restart wg-quick@orbit; then
  journalctl -u wg-quick@orbit --no-pager -n 30 >&2 || true
  exit 1
fi
deadline=$((SECONDS + 60))
until ping -c 1 -W 2 10.44.0.1 >/dev/null 2>&1; do
  if (( SECONDS >= deadline )); then exit 1; fi
  sleep 2
done
