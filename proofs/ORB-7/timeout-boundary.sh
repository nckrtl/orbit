#!/usr/bin/env bash
set -euo pipefail

fail() { echo "FAIL: $*" >&2; exit 1; }

term_target=/var/tmp/orbit-e2e-orb-7-term-target
term_witness=/tmp/orbit-e2e-orb-7-term-witness
hung_pid=/tmp/orbit-e2e-orb-7-hung.pid

test ! -e "$term_target" || fail "the TERM target already exists"
test ! -e "$term_witness" || fail "the TERM witness already exists"
test ! -e "$hung_pid" || fail "the hung-cleanup pid file already exists"

set +e
timeout --signal=TERM --kill-after=5s 1s bash /var/lib/orbit-e2e/proof/term-cleanup.sh
term_status=$?
set -e
[[ "$term_status" -eq 124 ]] || fail "catchable timeout exited $term_status, expected 124"
[[ "$(<"$term_witness")" == term-observed ]] || fail "the catchable timeout did not deliver TERM"
test ! -e "$term_target" || fail "the catchable timeout did not clean its owned target"
rm -f -- "$term_witness"

started=$(date +%s)
set +e
timeout --signal=TERM --kill-after=5s 1s bash /var/lib/orbit-e2e/proof/hung-cleanup.sh
hung_status=$?
set -e
elapsed=$(( $(date +%s) - started ))
[[ "$hung_status" -eq 137 ]] || fail "hung timeout exited $hung_status, expected 137"
[[ "$elapsed" -ge 5 && "$elapsed" -le 12 ]] || fail "hung timeout took ${elapsed}s, expected bounded force-kill"
test -s "$hung_pid" || fail "the hung fixture did not publish its pid"
pid=$(<"$hung_pid")
! kill -0 "$pid" 2>/dev/null || fail "the force-killed process is still active"
rm -f -- "$hung_pid"

echo "timeout boundary: TERM was catchable and unresponsive cleanup was force-killed after five seconds"
