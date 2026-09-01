#!/usr/bin/env bash
source /var/lib/orbit-e2e/proof/lib.sh

mkdir -p "$STATE"
grafana_password > "$STATE/grafana-password"

remove_ufw_comment orbit:metrics-node-exporter
exporter_report=$(doctor_firewall)
echo "$exporter_report" | doctor_has_issue orbit:metrics-node-exporter \
  || fail "Doctor did not report the missing Metrics exporter rule: $exporter_report"
! ufw_has_comment orbit:metrics-node-exporter || fail "Doctor restored the missing exporter rule"

remove_ufw_comment orbit:metrics-grafana-upstream
both_report=$(doctor_firewall)
echo "$both_report" | doctor_has_issue orbit:metrics-node-exporter \
  || fail "Doctor lost the exporter drift finding: $both_report"
echo "$both_report" | doctor_has_issue orbit:metrics-grafana-upstream \
  || fail "Doctor did not report the missing Grafana upstream rule: $both_report"
! ufw_has_comment orbit:metrics-node-exporter || fail "Doctor restored the exporter rule"
! ufw_has_comment orbit:metrics-grafana-upstream || fail "Doctor restored the Grafana upstream rule"

printf '%s' "$exporter_report" > "$STATE/exporter-report.json"
printf '%s' "$both_report" > "$STATE/both-report.json"
echo "firewall-drift: Doctor observed each missing Metrics rule and left both absent"
