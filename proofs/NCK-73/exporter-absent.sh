#!/usr/bin/env bash
# This node no longer runs an Orbit-managed exporter and carries no Metrics firewall rule.
source /var/lib/orbit-e2e/proof/lib.sh

[[ ! -e /etc/systemd/system/prometheus-node-exporter.service.d/orbit.conf ]] || fail "drop-in remains"
state=$(systemctl is-active prometheus-node-exporter || true)
[[ "$state" != active ]] || fail "exporter still active"
! ufw_has_comment orbit:metrics-node-exporter || fail "UFW exporter rule remains"
dpkg -s prometheus-node-exporter >/dev/null 2>&1 || fail "package removed"
echo "exporter-absent: service $state, drop-in gone, rule gone, package preserved"
