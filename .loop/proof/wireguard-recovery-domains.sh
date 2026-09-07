#!/usr/bin/env bash

set -euo pipefail

gateway=/home/orbit/orbit/apps/gateway
converger="$gateway/app/Infrastructure/WireGuard/NativeWireGuardPeerConverger.php"
converger_test="$gateway/tests/Feature/Infrastructure/WireGuard/NativeWireGuardPeerConvergerTest.php"

cd "$gateway"
env \
    APP_ENV=testing \
    APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA= \
    APP_CONFIG_CACHE=/tmp/orbit-proof-no-config-cache.php \
    CACHE_STORE=array \
    DB_CONNECTION=sqlite \
    DB_DATABASE=:memory: \
    DB_URL= \
    ORBIT_HOME=/tmp/orbit-gateway-proof \
    QUEUE_CONNECTION=sync \
    SESSION_DRIVER=array \
    vendor/bin/pest --no-tia --compact \
    tests/Feature/Infrastructure/WireGuard/NativeWireGuardPeerConvergerTest.php \
    --filter='restores every prior DNS domain|rolls back exact peer state when the recoverable completion fails'

grep -Fq 'old_dns_domains=("${old_dns_state[@]:2}")' "$converger"
grep -Fq 'old_resolvectl_domains+=("~$old_dns_domain")' "$converger"
grep -Fq 'dns_domains+=("$app_dev_tld")' "$converger"
grep -Fq 'printf '\''%s\n'\'' "$dns_link" "$dns_server" "${dns_domains[@]}" > "$dns_state_candidate"' "$converger"
grep -Fq "it('restores every prior DNS domain after a successive underlay convergence fails'" "$converger_test"
grep -Fq "'immediate rollback' => 'immediate'" "$converger_test"
grep -Fq "'retained rollback' => 'retained'" "$converger_test"

printf '%s\n' 'wireguard-recovery-domains: ok'
