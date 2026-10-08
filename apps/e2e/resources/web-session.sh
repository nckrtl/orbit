#!/usr/bin/env bash
set -euo pipefail
# This script runs with an empty environment, not the caller's live profiles.
cd /home/orbit/orbit/apps/web
ca=/home/orbit/.orbit/e2e-gateway-root-ca.pem
[[ -s "$ca" && -s /etc/wireguard/orbit.conf ]] || { echo 'The operator is missing its topology CA or WireGuard configuration.' >&2; exit 66; }
php -r '$c=json_decode(file_get_contents("/home/orbit/.orbit/config.json"), true, 512, JSON_THROW_ON_ERROR); if (($c["gateways"]["e2e"]["url"] ?? null) !== "https://10.44.0.1") { fwrite(STDERR, "The operator has no topology-pinned e2e profile.\n"); exit(66); }'
export ORBIT_GATEWAY_URL=https://10.44.0.1
export ORBIT_CA_PATH="$ca"
export ORBIT_HOME=/home/orbit/.orbit
# Fail closed on a missing access grant, tunnel, URL, or trusted CA.
curl --fail --silent --show-error --max-time 10 --cacert "$ca" https://10.44.0.1/api/v1/nodes >/dev/null
# Only the disposable Gateway supplies realtime and metrics endpoints.
unset ORBIT_REALTIME_URL ORBIT_GRAFANA_URL ORBIT_GATEWAY VITE_ORBIT_DEMO VITEST
vp install
exec vp dev --host 0.0.0.0 --port 5173 --strictPort
