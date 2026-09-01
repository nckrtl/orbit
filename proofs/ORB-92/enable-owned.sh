#!/usr/bin/env bash
source "$(dirname "$0")/lib.sh"

assert_direct_docker_denied
orbit metrics:enable app-dev --json >/dev/null
assert_metrics_healthy
assert_expected_runtime
assert_boundary_unchanged

echo 'enable-owned: Metrics enabled with exactly two owned healthy containers and volumes'
