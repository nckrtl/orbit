#!/usr/bin/env bash
source "$(dirname "$0")/lib.sh"

assert_metrics_healthy
assert_expected_runtime
assert_boundary_unchanged

echo 'final-state: Metrics is active and healthy while orbit remains denied direct Docker access'
