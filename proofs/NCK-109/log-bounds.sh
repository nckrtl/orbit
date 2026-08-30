#!/usr/bin/env bash
# Both containers run with the configured rotating log driver.
source /var/lib/orbit-e2e/proof/lib.sh

expected="json-file 10m 3"

for container in orbit-metrics-prometheus orbit-metrics-grafana; do
  observed=$(log_config "$container")
  [[ "$observed" == "$expected" ]] \
    || fail "[$container] reports log config [$observed], expected [$expected]"
  echo "log-bounds: $container $observed"
done
