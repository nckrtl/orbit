#!/usr/bin/env bash

set -euo pipefail

gateway=/home/orbit/orbit/apps/gateway
issuer="$gateway/app/Infrastructure/Certificates/OpenSslGatewayCertificateIssuer.php"
issuer_test="$gateway/tests/Feature/Infrastructure/Gateway/OpenSslGatewayCertificateIssuerTest.php"

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
    tests/Feature/Infrastructure/Gateway/OpenSslGatewayCertificateIssuerTest.php \
    tests/Feature/Infrastructure/Gateway/GatewayCertificateRenewalTest.php \
    tests/Unit/Infrastructure/Metrics/MetricsLocalPublicationTest.php

if grep -Fq -- "-current/gateway." "$issuer"; then
    printf '%s\n' 'The issuer still returns certificate paths through a mutable current alias.' >&2
    exit 1
fi

grep -Fq '$resolvedDirectory = realpath($currentDirectory);' "$issuer"
test "$(grep -Fc 'realpath($currentDirectory)' "$issuer")" -eq 1
grep -Fq "preg_match('/\\A[a-f0-9]{16}\\z/', basename(\$resolvedDirectory)) === 1" "$issuer"
grep -Fq '$versionPaths = new GatewayCertificatePaths(' "$issuer"
grep -Fq "privateKeyPath: \$versionDirectory.'/gateway.key'," "$issuer"
grep -Fq "certificatePath: \$versionDirectory.'/gateway.pem'," "$issuer"
grep -Fq 'return $versionPaths;' "$issuer"
grep -Fq "it('keeps an issued generation stable while later certificates are published'" "$issuer_test"
grep -Fq 'new NativeGatewayCertificatePublisher($publications, $orbitHome)->publish($first);' "$issuer_test"
grep -Fq 'new MetricsCertificatePublisher($publications)->publish($first);' "$issuer_test"
grep -Fq "it('reuses one resolved generation when the current alias changes during validation'" "$issuer_test"

for scope in gateway metrics; do
    current="/home/orbit/.orbit/ca/${scope}-current"
    versions="/home/orbit/.orbit/ca/${scope}-versions"
    target=$(readlink -f "$current")

    case "$target" in
        "$versions"/[a-f0-9][a-f0-9][a-f0-9][a-f0-9][a-f0-9][a-f0-9][a-f0-9][a-f0-9][a-f0-9][a-f0-9][a-f0-9][a-f0-9][a-f0-9][a-f0-9][a-f0-9][a-f0-9]) ;;
        *) exit 1 ;;
    esac

    test -f "$target/gateway.pem"
    test -f "$target/gateway.key"
    certificate_public=$(openssl x509 -in "$target/gateway.pem" -pubkey -noout)
    private_public=$(openssl pkey -in "$target/gateway.key" -pubout)
    test "$certificate_public" = "$private_public"
done

printf '%s\n' 'immutable-certificate-paths: ok'
