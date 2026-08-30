#!/usr/bin/env bash
# The Metrics node removes its whole footprint with no Gateway, and leaves
# every look-alike it cannot prove Orbit owns.
source /var/lib/orbit-e2e/proof/lib.sh

readonly DECOY_CONTAINER=orbit-metrics-prometheus-decoy
readonly DECOY_VOLUME=orbit-metrics-prometheus-backup
readonly DECOY_RULE=orbit:metrics-grafana-upstream-v2

address=$(this_address)
docker container create --name "$DECOY_CONTAINER" --label com.orbit.managed=other \
  prom/prometheus:v3.5.0 >/dev/null
docker volume create --label com.orbit.managed=other "$DECOY_VOLUME" >/dev/null
sudo ufw allow in on orbit proto tcp to "$address" port 3001 comment "$DECOY_RULE" >/dev/null

run_escape --force
[[ "$ESCAPE_STATUS" -eq 0 ]] || fail "escape exited $ESCAPE_STATUS: $ESCAPE_OUTPUT"
assert_reports 'metrics-node'
assert_reports 'Will remove:'
assert_reports '  - container orbit-metrics-prometheus'
assert_reports '  - volume orbit-metrics-grafana-data, and the data in it'
assert_reports '  - /etc/orbit/metrics/.orbit-owner'
assert_reports 'commented orbit:metrics-grafana-upstream'

for name in orbit-metrics-prometheus orbit-metrics-grafana; do
  ! container_exists "$name" || fail "container $name survived the escape"
done

for name in orbit-metrics-prometheus-data orbit-metrics-grafana-data; do
  ! volume_exists "$name" || fail "volume $name survived the escape"
done

sudo test ! -d /etc/orbit/metrics || fail "/etc/orbit/metrics survived the escape"
sudo test ! -e /etc/systemd/system/prometheus-node-exporter.service.d/orbit.conf \
  || fail "the exporter drop-in survived the escape"
! firewall_rule_exists orbit:metrics-grafana-upstream || fail "the Grafana upstream rule survived"
! firewall_rule_exists orbit:metrics-node-exporter || fail "the exporter rule survived"
sudo test -x "$ESCAPE" || fail "the escape removed itself"

container_exists "$DECOY_CONTAINER" || fail "the escape removed a container it does not own"
volume_exists "$DECOY_VOLUME" || fail "the escape removed a volume whose managed label reads another value"
firewall_rule_exists "$DECOY_RULE" || fail "the escape removed a firewall rule it does not own"
package_installed prometheus-node-exporter || fail "the escape removed a package it cannot prove it owns"

assert_reports 'sudo apt-get purge --yes prometheus-node-exporter'
assert_reports 'the metrics.orbit route, its certificate and its private DNS record'
assert_reports 'Every Orbit-owned Metrics resource on this node is gone.'

docker container rm --force --volumes "$DECOY_CONTAINER" >/dev/null
docker volume rm "$DECOY_VOLUME" >/dev/null
delete_firewall_rule "$DECOY_RULE"

echo "escape-metrics-node: the Metrics footprint is gone and three planted look-alikes are untouched"
