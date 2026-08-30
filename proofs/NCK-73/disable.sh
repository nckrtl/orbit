#!/usr/bin/env bash
# metrics:disable --force removes containers, configuration, and firewall rules; keeps volumes and packages.
source /var/lib/orbit-e2e/proof/lib.sh

orbit metrics:disable --force --json >/dev/null
[[ -z "$(docker ps -a --format '{{.Names}}' | grep '^orbit-metrics-' || true)" ]] || fail "containers remain"
[[ ! -e /etc/orbit/metrics ]] || fail "generated configuration remains"
! ufw_has_comment orbit:metrics-grafana-upstream || fail "Grafana upstream rule remains"
! ufw_has_comment orbit:metrics-node-exporter || fail "exporter rule remains"
volumes=$(docker volume ls --format '{{.Name}}' | grep -c '^orbit-metrics-\(prometheus\|grafana\)-data$')
[[ "$volumes" == 2 ]] || fail "volumes not preserved: $volumes"
docker image inspect prom/prometheus:v3.5.0 >/dev/null || fail "image removed"
[[ "$(orbit metrics:status --json | json_get enabled)" == false ]] || fail "status still enabled"
echo "disable: containers, configuration, rules removed; 2 volumes preserved"
