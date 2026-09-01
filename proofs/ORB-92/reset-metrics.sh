#!/usr/bin/env bash
source "$(dirname "$0")/lib.sh"

assert_direct_docker_denied
orbit metrics:disable --force --purge-data --json >/dev/null

status=$(orbit metrics:status --json)
[[ "$(printf '%s' "$status" | json_get enabled)" == false ]] || fail "Metrics remains enabled: $status"
[[ -z "$(sudo docker container ls --all --filter label=com.orbit.managed=metrics --format '{{.Names}}')" ]] \
    || fail 'Metrics containers remain after setup purge'
[[ -z "$(sudo docker volume ls --filter label=com.orbit.managed=metrics --format '{{.Name}}')" ]] \
    || fail 'Metrics volumes remain after setup purge'

echo 'reset-metrics: disabled and purged the prepared Metrics state without direct Docker access'
