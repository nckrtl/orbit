#!/usr/bin/env bash
# The escape refuses a drop-in and a configuration directory that carry no
# Orbit ownership proof, and reports both instead of guessing.
source /var/lib/orbit-e2e/proof/lib.sh

readonly FOREIGN_DROPIN=/etc/systemd/system/prometheus-node-exporter.service.d/orbit.conf
readonly FOREIGN_MARKER=/etc/orbit/metrics/.orbit-owner
readonly FOREIGN_FILE=/etc/orbit/metrics/prometheus.yml

sudo test ! -e "$FOREIGN_DROPIN" || fail "this node still holds an Orbit drop-in; plant on a cleaned node"

printf '# hand written by the platform team\n[Service]\nExecStart=\n' \
  | sudo install -D -m 0644 /dev/stdin "$FOREIGN_DROPIN"
sudo install -d /etc/orbit/metrics
printf 'someone-else\n' | sudo install -m 0640 /dev/stdin "$FOREIGN_MARKER"
printf 'scrape_configs: []\n' | sudo install -m 0644 /dev/stdin "$FOREIGN_FILE"

# A rule that kept the Orbit comment but not the Orbit shape. The Gateway
# refuses this as ownership drift, so the escape must refuse it too.
address=$(this_address)
sudo ufw allow in on orbit proto tcp to "$address" port 9100 \
  comment orbit:metrics-node-exporter >/dev/null

run_escape --force
[[ "$ESCAPE_STATUS" -eq 3 ]] || fail "escape exited $ESCAPE_STATUS, expected 3: $ESCAPE_OUTPUT"

assert_reports 'Refused, because ownership could not be proved:'
assert_reports "$FOREIGN_DROPIN (its first line is not '# Managed by Orbit: metrics')"
assert_reports "/etc/orbit/metrics ($FOREIGN_MARKER does not read 'metrics')"
assert_reports 'exporter UFW rule    drift'
assert_reports 'are not the rule Orbit writes'
assert_reports 'Nothing was removed without proof.'

firewall_rule_exists orbit:metrics-node-exporter \
  || fail "the escape deleted a firewall rule whose shape it could not prove"

[[ "$(sudo head -n 1 "$FOREIGN_DROPIN")" == '# hand written by the platform team' ]] \
  || fail "the escape modified a drop-in it cannot prove it owns"
[[ "$(sudo cat "$FOREIGN_MARKER")" == 'someone-else' ]] || fail "the escape modified a foreign marker"
[[ "$(sudo cat "$FOREIGN_FILE")" == 'scrape_configs: []' ]] || fail "the escape removed a foreign file"

sudo rm -rf /etc/systemd/system/prometheus-node-exporter.service.d /etc/orbit/metrics
sudo systemctl daemon-reload
delete_firewall_rule orbit:metrics-node-exporter

echo "refuses-without-proof: both unprovable resources were reported and left byte-identical"
