#!/usr/bin/env bash
set -euo pipefail

pid_file=/tmp/orbit-e2e-orb-7-hung.pid
printf '%s\n' "$$" >"$pid_file"
cleanup() {
  trap '' TERM
  while true; do sleep 1; done
}
trap cleanup TERM
while true; do sleep 1; done
