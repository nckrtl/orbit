#!/usr/bin/env bash
set -euo pipefail

fail() { echo "FAIL: $*" >&2; exit 1; }

fixture=/var/lib/orbit-e2e/proof/representative-cleanup-fixture.sh
record=/var/lib/orbit-e2e/proof-cleanup/orb-7-representative
target=/var/tmp/orbit-e2e-orb-7-representative-state
stub=/usr/local/sbin/orbit-e2e-orb-7-representative-stub
checkpoint=

matrix_cleanup() {
  bash "$fixture" cleanup || true
  [[ -z "$checkpoint" ]] || sudo rm -f -- "$checkpoint"
  sudo rm -f -- "$target"
}
trap matrix_cleanup EXIT INT TERM

sudo test ! -e "$record" || fail "the representative cleanup record already exists"
sudo test ! -e "$target" || fail "the representative state already exists"
sudo test ! -e "$stub" || fail "the representative stub already exists"
printf 'baseline\n' | sudo install -o root -g root -m 0640 /dev/stdin "$target"
baseline=$(sudo stat -c '%U:%G %a' "$target"; sudo sha256sum "$target")

assert_restored() {
  local label="$1"
  [[ "$(sudo stat -c '%U:%G %a' "$target"; sudo sha256sum "$target")" == "$baseline" ]] \
    || fail "$label did not restore the exact representative state"
  sudo test ! -e "$stub" || fail "$label left the representative stub installed"
  sudo test ! -e "$record" || fail "$label left its cleanup record"
}

for window in post-record post-mutation; do
  for event in EXIT INT TERM; do
    checkpoint="/var/tmp/orbit-e2e-orb-7-representative-${window}-${event}.ready"
    sudo test ! -e "$checkpoint" || fail "checkpoint already exists: $checkpoint"
    env ORBIT_E2E_ORB7_EVENT="$event" ORBIT_E2E_ORB7_WINDOW="$window" \
      ORBIT_E2E_ORB7_CHECKPOINT="$checkpoint" python3 - "$fixture" <<'PY' &
import os
import signal
import sys

os.setsid()
signal.signal(signal.SIGINT, signal.SIG_DFL)
signal.signal(signal.SIGTERM, signal.SIG_DFL)
os.execv('/usr/bin/bash', ['bash', sys.argv[1]])
PY
    pid=$!
    for _ in $(seq 1 300); do
      sudo test ! -f "$checkpoint" || break
      kill -0 "$pid" 2>/dev/null || break
      sleep 0.1
    done
    sudo test -f "$checkpoint" || fail "$event fixture did not reach $window"
    sudo test -e "$record" || fail "$event fixture did not install its cleanup record"
    if [[ "$window" == post-mutation ]]; then
      sudo test -x "$stub" || fail "$event fixture did not install its representative stub"
      [[ "$(sudo cat "$target")" == mutated ]] || fail "$event fixture did not create its mutation"
    else
      sudo test ! -e "$stub" || fail "$event fixture mutated before the post-record checkpoint"
      [[ "$(sudo cat "$target")" == baseline ]] || fail "$event fixture changed state before mutation"
    fi
    if [[ "$event" != EXIT ]]; then
      kill -s "$event" -- "-$pid"
    fi
    timeout 12s tail --pid="$pid" -f /dev/null || fail "$event fixture did not finish cleanup"
    set +e
    wait "$pid"
    status=$?
    set -e
    expected=130
    [[ "$event" == EXIT ]] && expected=0
    [[ "$event" == TERM ]] && expected=143
    [[ "$status" -eq "$expected" ]] || fail "$event fixture exited $status, expected $expected"
    assert_restored "$event at $window"
    bash "$fixture" cleanup
    assert_restored "$event at $window idempotent cleanup"
    sudo rm -f -- "$checkpoint"
    checkpoint=
  done
done

echo "representative shared cleanup primitive: EXIT, INT, and TERM restored exact state at post-record and post-mutation"
