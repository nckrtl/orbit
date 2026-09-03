#!/usr/bin/env bash
set -euo pipefail

readonly proof_root=/var/lib/orbit-e2e/proof
readonly fixture_root="$proof_root/NCK-116"
readonly inspector="$fixture_root/inspect-orb-7-timeout.sh"
seeded=0

cleanup() {
  local status="$?"
  trap - EXIT INT TERM
  if [[ "$seeded" -eq 1 ]]; then
    env ORBIT_E2E_PROOF_ROOT="$fixture_root" bash "$inspector" >/dev/null 2>&1 || true
  fi
  exit "$status"
}
trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM

env ORBIT_E2E_PROOF_ROOT="$fixture_root" bash "$fixture_root/seed-orb-7-timeout.sh"
seeded=1

status=0
timeout --signal=TERM --kill-after=5s 30s \
  env ORBIT_E2E_PROOF_ROOT="$fixture_root" \
  ORBIT_E2E_ORB7_MODE=timeout \
  ORBIT_E2E_ORB7_CASE=refuses-a-shifted-rule-number \
  bash "$fixture_root/refuses-a-shifted-rule-number.sh" \
  || status=$?
[[ "$status" -eq 124 ]] || {
  echo "firewall timeout fixture exited $status, expected 124" >&2
  exit 1
}

env ORBIT_E2E_PROOF_ROOT="$fixture_root" bash "$inspector"
seeded=0
trap - EXIT INT TERM
echo "firewall timeout matrix: child exit 124 inspected; proof action exits zero"
