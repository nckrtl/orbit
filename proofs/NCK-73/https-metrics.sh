#!/usr/bin/env bash
# metrics.orbit resolves privately to the Gateway, presents an Orbit-CA certificate, and proxies Grafana.
source /var/lib/orbit-e2e/proof/lib.sh

gateway=$(gateway_address)
resolved=$(resolve_metrics)
[[ "$resolved" == "$gateway" ]] || fail "metrics.orbit resolved to $resolved, gateway is $gateway"

issuer=$(openssl s_client -connect "$resolved:443" -servername metrics.orbit -CAfile "$CA" </dev/null 2>/dev/null | openssl x509 -noout -issuer)
[[ "$issuer" == *"Orbit Root CA"* ]] || fail "unexpected issuer: $issuer"

health=$(metrics_curl https://metrics.orbit/api/health)
[[ "$(echo "$health" | json_get database)" == ok ]] || fail "Grafana health via gateway: $health"
echo "https: metrics.orbit -> $resolved (gateway), issuer=$issuer, grafana database=ok"
