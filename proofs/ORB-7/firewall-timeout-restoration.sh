#!/usr/bin/env bash
set -euo pipefail
source /var/lib/orbit-e2e/proof/firewall-lib.sh

fixture=/var/lib/orbit-e2e/proof/firewall-fixture.sh
record=/var/lib/orbit-e2e/proof-cleanup/orb-7-firewall-fixture
stub=/usr/local/sbin/ufw
foreign=ORB7-FOREIGN-KEEP
lookalike=ORB7-LOOKALIKE-KEEP
exporter=ORB7-OWNED-EXPORTER
transient=ORB7-OWNED-TRANSIENT
shifted=ORB7-OWNED-SHIFTED
address=$(wireguard_address)
baseline=$(mktemp)
capture_firewall_shapes "$baseline"
pid=

driver_cleanup() {
  local status="$1"
  trap - EXIT INT TERM
  set +e
  if [[ -n "$pid" ]] && kill -0 "$pid" 2>/dev/null; then
    kill -s TERM -- "-$pid" 2>/dev/null
    timeout 12s tail --pid="$pid" -f /dev/null
    if kill -0 "$pid" 2>/dev/null; then
      kill -s KILL -- "-$pid" 2>/dev/null
    fi
    wait "$pid" 2>/dev/null
  fi
  delete_firewall_rule "$shifted"
  delete_firewall_rule "$transient"
  delete_firewall_rule "$exporter"
  delete_firewall_rule "$lookalike"
  delete_firewall_rule "$foreign"
  sudo rm -f -- "$stub"
  sudo rm -rf -- "$record"
  if ! assert_firewall_shapes "$baseline"; then
    status=1
  fi
  rm -f -- "$baseline"
  exit "$status"
}

for comment in "$foreign" "$lookalike" "$exporter" "$transient" "$shifted"; do
  ! firewall_rule_exists "$comment" || fail "the firewall fixture comment already exists: $comment"
done
sudo test ! -e "$stub" || fail "the fake ufw path already exists"

trap 'driver_cleanup "$?"' EXIT INT TERM
sudo /usr/sbin/ufw allow in on orbit proto tcp from 10.44.0.1 to "$address" port 5433 comment "$foreign" >/dev/null
sudo /usr/sbin/ufw allow in on orbit proto tcp from 10.44.0.1 to "$address" port 9101 comment "$lookalike" >/dev/null

python3 - "$fixture" <<'PY' &
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
  sudo test ! -f "$record/ready" || break
  kill -0 "$pid" 2>/dev/null || break
  sleep 0.1
done
sudo test -f "$record/ready" || fail "the firewall fixture did not reach its checkpoint"
sudo test -x "$stub" || fail "the firewall fixture did not install its stub"
firewall_rule_exists "$exporter" || fail "the firewall fixture did not install its exporter rule"
firewall_rule_exists "$transient" || fail "the firewall fixture did not install its transient rule"
firewall_rule_exists "$shifted" || fail "the firewall fixture did not install its shifted rule"

kill -s TERM -- "-$pid"
timeout 12s tail --pid="$pid" -f /dev/null || fail "the firewall fixture did not finish TERM cleanup"
set +e
wait "$pid"
status=$?
set -e
[[ "$status" -eq 143 ]] || fail "the firewall fixture exited $status after TERM, expected 143"

sudo test ! -e "$stub" || fail "the terminated firewall fixture left its stub"
sudo test ! -e "$record" || fail "the terminated firewall fixture left its cleanup record"
! firewall_rule_exists "$exporter" || fail "the terminated firewall fixture left its exporter rule"
! firewall_rule_exists "$transient" || fail "the terminated firewall fixture left its transient rule"
! firewall_rule_exists "$shifted" || fail "the terminated firewall fixture left its shifted rule"
firewall_rule_exists "$foreign" || fail "the terminated firewall fixture removed the foreign rule"
firewall_rule_exists "$lookalike" || fail "the terminated firewall fixture removed the look-alike rule"

delete_firewall_rule "$lookalike"
delete_firewall_rule "$foreign"
assert_firewall_shapes "$baseline"
rm -f -- "$baseline"
trap - EXIT INT TERM

echo "firewall timeout: installed stub and owned rules were witnessed before TERM, then restored"
