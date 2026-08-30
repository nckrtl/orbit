#!/usr/bin/env bash
# Pinned official images, deterministic names, Orbit labels, named volumes, health, retention.
source /var/lib/orbit-e2e/proof/lib.sh

for name in orbit-metrics-prometheus orbit-metrics-grafana; do
  state=$(docker container inspect --format '{{.State.Status}} {{.State.Health.Status}}' "$name")
  [[ "$state" == "running healthy" ]] || fail "$name is $state"
  label=$(docker container inspect --format '{{index .Config.Labels "com.orbit.managed"}}' "$name")
  [[ "$label" == metrics ]] || fail "$name is not Orbit-owned: $label"
  network=$(docker container inspect --format '{{.HostConfig.NetworkMode}}' "$name")
  [[ "$network" == host ]] || fail "$name uses network $network"
done

images=$(docker container inspect --format '{{.Config.Image}}' orbit-metrics-prometheus orbit-metrics-grafana | sort | tr '\n' ' ')
[[ "$images" == "grafana/grafana:12.1.1 prom/prometheus:v3.5.0 " ]] || fail "unexpected images: $images"

args=$(docker container inspect --format '{{json .Args}}' orbit-metrics-prometheus)
[[ "$args" == *'--storage.tsdb.retention.time=15d'* ]] || fail "retention missing: $args"
[[ "$args" == *'--web.listen-address=127.0.0.1:9090'* ]] || fail "prometheus not loopback-only: $args"

volumes=$(docker volume ls --format '{{.Name}}' | grep -c '^orbit-metrics-\(prometheus\|grafana\)-data$')
[[ "$volumes" == 2 ]] || fail "expected two named volumes, found $volumes"

grep -q 'node: "app-dev"' /etc/orbit/metrics/prometheus.yml || fail "stable node label missing"
echo "containers: prometheus+grafana healthy, pinned images, host networking, 15d retention, 2 volumes"
