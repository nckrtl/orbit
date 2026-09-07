#!/usr/bin/env bash

set -euo pipefail

gateway=/home/orbit/orbit/apps/gateway
manager="$gateway/app/Infrastructure/Metrics/MetricsPublicationManager.php"
certificate="$gateway/app/Infrastructure/Metrics/MetricsCertificatePublisher.php"
caddy="$gateway/app/Infrastructure/Metrics/MetricsCaddyPublisher.php"
receipt="$gateway/app/Infrastructure/Metrics/MetricsPublicationReceipt.php"

cd "$gateway"
php artisan test --compact \
    tests/Unit/Infrastructure/Metrics/MetricsPublicationReceiptTest.php \
    tests/Unit/Infrastructure/Metrics/MetricsPublicationManagerTest.php \
    tests/Unit/Infrastructure/Metrics/MetricsLocalPublicationTest.php

if grep -Eq '\$(certificate|caddy)Changed' "$manager"; then
    printf '%s\n' 'Metrics publication still discards prior state through change booleans.' >&2
    exit 1
fi

caddy_restore_line=$(grep -nF '$this->caddy->restore($caddyReceipt);' "$manager" | cut -d: -f1)
firewall_restore_line=$(grep -nF '$this->firewall->remove($metrics, $gatewayAddress);' "$manager" | head -n 1 | cut -d: -f1)
certificate_restore_line=$(grep -nF '$this->certificatePublisher->restore($certificateReceipt);' "$manager" | cut -d: -f1)
test "$caddy_restore_line" -lt "$firewall_restore_line"
test "$firewall_restore_line" -lt "$certificate_restore_line"

grep -Fq 'MetricsPublicationReceipt::fromProcessOutput' "$certificate"
grep -Fq 'MetricsPublicationReceipt::fromProcessOutput' "$caddy"
grep -Fq 'previous_configuration=\$(base64 -w 0 -- "\$current_fragments/\$owned_fragment")' "$caddy"
grep -Fq 'for fragment in "$current_fragments"/*.caddy' "$caddy"
grep -Fq '$this->publish($receipt->previousPublication());' "$caddy"
grep -Fq 'if ! systemctl reload-or-restart caddy; then' "$certificate"
grep -Fq 'ln -s -- "$previous_target" "$link"' "$certificate"
grep -Fq 'ln -s -- "$published_target" "$link"' "$certificate"
grep -Fq 'rm -rf -- "$directory"' "$certificate"
if grep -Eq 'privateKey|metrics\.key' "$receipt"; then
    printf '%s\n' 'The Metrics receipt contains private-key state.' >&2
    exit 1
fi

certificate_target=$(sudo readlink -f /etc/caddy/orbit-metrics-cert-current)
case "$certificate_target" in
    /etc/caddy/orbit-metrics-cert-versions/*) ;;
    *) exit 1 ;;
esac
sudo test -f /etc/caddy/orbit-metrics-cert-versions/.orbit-owner
test "$(sudo cat /etc/caddy/orbit-metrics-cert-versions/.orbit-owner)" = metrics-certificate
certificate_public=$(sudo openssl x509 -in "$certificate_target/metrics.pem" -pubkey -noout)
private_public=$(sudo openssl pkey -in "$certificate_target/metrics.key" -pubout)
test "$certificate_public" = "$private_public"

caddyfile=$(sudo readlink -f /etc/caddy/Caddyfile)
case "$caddyfile" in
    /etc/caddy/orbit-versions/*/Caddyfile) ;;
    *) exit 1 ;;
esac
metrics_fragment="$(dirname "$caddyfile")/fragments/metrics.caddy"
sudo head -n 1 "$metrics_fragment" | grep -Fqx -- '# Managed by Orbit: metrics'
sudo caddy validate --config "$caddyfile" --adapter caddyfile >/dev/null
sudo systemctl is-active --quiet caddy

printf '%s\n' 'metrics-publication-receipts: ok'
