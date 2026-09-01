#!/usr/bin/env bash
source "$(dirname "$0")/lib.sh"

assert_boundary_unchanged
assert_metrics_healthy

echo 'boundary-unchanged: Docker socket, groups, effective sudo, and direct denial remain unchanged'
