#!/usr/bin/env bash
# An exporter-only node removes the drop-in, the service and the 9100 rule,
# and nothing else. It keeps the package it cannot prove Orbit owns.
source /var/lib/orbit-e2e/proof/lib.sh

readonly DECOY_RULE=orbit:metrics-node-exporter-v2

orb7_arm escape-exporter-node
orb7_traps escape-exporter-node
address=$(this_address)
sudo ufw allow in on orbit proto tcp to "$address" port 9101 comment "$DECOY_RULE" >/dev/null
orb7_record_ufw_delta escape-exporter-node decoy
orb7_mark_active escape-exporter-node
orb7_checkpoint escape-exporter-node

run_escape --force
[[ "$ESCAPE_STATUS" -eq 0 ]] || fail "escape exited $ESCAPE_STATUS: $ESCAPE_OUTPUT"
assert_reports 'footprint on'
assert_reports ': exporter'

sudo test ! -e /etc/systemd/system/prometheus-node-exporter.service.d/orbit.conf \
  || fail "the exporter drop-in survived the escape"
[[ "$(systemctl is-active prometheus-node-exporter || true)" != active ]] \
  || fail "the exporter service is still active"
! firewall_rule_exists orbit:metrics-node-exporter || fail "the 9100 rule survived the escape"

firewall_rule_exists "$DECOY_RULE" || fail "the escape removed a firewall rule it does not own"
package_installed prometheus-node-exporter || fail "the escape removed a package it cannot prove it owns"
sudo test ! -e /etc/orbit/metrics || fail "an exporter-only node grew Metrics configuration"

assert_reports 'sudo apt-get purge --yes prometheus-node-exporter'
assert_reports 'Every Orbit-owned Metrics resource on this node is gone.'
[[ "$ESCAPE_OUTPUT" != *'metrics.orbit route'* ]] \
  || fail "an exporter-only node reported Gateway-side Metrics-node leftovers"

orb7_restore_owned escape-exporter-node
trap - EXIT INT TERM

echo "escape-exporter-node: the exporter footprint is gone, the package and a look-alike rule remain"
