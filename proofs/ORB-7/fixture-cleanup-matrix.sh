#!/usr/bin/env bash
set -euo pipefail

fail() { echo "FAIL: $*" >&2; exit 1; }

fixture=/var/lib/orbit-e2e/proof/cleanup-fixture.sh
record=/var/lib/orbit-e2e/proof-cleanup/orb-7-owned-fixture
target=/var/tmp/orbit-e2e-orb-7-reusable-state
stub=/usr/local/sbin/orbit-e2e-orb-7-stub

sudo test ! -e "$record" || fail "the cleanup record already exists"
sudo test ! -e "$target" || fail "the reusable-state fixture path already exists"
sudo test ! -e "$stub" || fail "the stub fixture path already exists"
printf 'baseline\n' | sudo tee "$target" >/dev/null

assert_restored() {
  [[ "$(sudo cat "$target")" == baseline ]] || fail "$1 did not restore reusable state"
  sudo test ! -e "$stub" || fail "$1 left its stub installed"
  sudo test ! -e "$record" || fail "$1 left its cleanup record"
}

bash "$fixture" normal
assert_restored normal

for signal in INT TERM; do
  python3 - "$fixture" "$signal" <<'PY' &
import os
import signal
import sys

os.setsid()
signal.signal(signal.SIGINT, signal.SIG_DFL)
signal.signal(signal.SIGTERM, signal.SIG_DFL)
os.execv('/usr/bin/bash', ['bash', sys.argv[1], sys.argv[2]])
PY
  pid=$!
  for _ in $(seq 1 300); do
    sudo test ! -f "$record/ready" || break
    kill -0 "$pid" 2>/dev/null || break
    sleep 0.1
  done
  sudo test -f "$record/ready" || fail "$signal fixture did not reach its checkpoint"
  kill -s "$signal" -- "-$pid"
  timeout 12s tail --pid="$pid" -f /dev/null || fail "$signal fixture did not finish cleanup"
  set +e
  wait "$pid"
  status=$?
  set -e
  expected=130
  [[ "$signal" == TERM ]] && expected=143
  [[ "$status" -eq "$expected" ]] || fail "$signal fixture exited $status, expected $expected"
  assert_restored "$signal"
done

sudo rm -f -- "$target"
echo "fixture cleanup: normal exit, INT, and TERM restored the exact owned state"
