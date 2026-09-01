#!/usr/bin/env bash
source /var/lib/orbit-e2e/proof/lib.sh

password=$(cat "$STATE/grafana-password")
reports=$(cat "$STATE/exporter-report.json" "$STATE/both-report.json")
[[ -n "$password" ]] || fail "Grafana credential sentinel is unavailable"
[[ "$reports" != *"$password"* ]] || fail "Doctor output exposed the Grafana credential"

for forbidden in "sudo ufw" "ufw status" "ALLOW IN" "Status: active" stdout stderr; do
  [[ "$reports" != *"$forbidden"* ]] || fail "Doctor output exposed [$forbidden]"
done

echo "doctor-redaction: drift reports contain no credential, command, stdout, stderr, or raw UFW output"
