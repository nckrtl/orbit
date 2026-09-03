#!/usr/bin/env bash
set -euo pipefail

readonly proof_root=/var/lib/orbit-e2e/proof
readonly pid_file=/tmp/orbit-e2e-orb-7-hung.pid
readonly mutation=/tmp/orbit-e2e-orb-7-hung.mutation
readonly witness=/tmp/orbit-e2e-orb-7-hung.witness

cleanup() {
  local status="$?"
  local pid=''
  trap - EXIT INT TERM
  if [[ -s "$pid_file" ]]; then
    pid=$(<"$pid_file")
    kill -KILL "$pid" 2>/dev/null || true
  fi
  rm -f -- "$pid_file" "$mutation" "$witness"
  exit "$status"
}
trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM

status=0
timeout --signal=TERM --kill-after=5s 2s bash "$proof_root/hung-cleanup.sh" || status=$?
[[ "$status" -eq 137 ]] || {
  echo "hung cleanup fixture exited $status, expected 137" >&2
  exit 1
}

bash "$proof_root/inspect-hung-cleanup.sh"
trap - EXIT INT TERM
echo "hung cleanup matrix: child exit 137 inspected; proof action exits zero"
