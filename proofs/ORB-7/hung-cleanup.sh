#!/usr/bin/env bash
set -euo pipefail

pid_file=/tmp/orbit-e2e-orb-7-hung.pid
mutation=/tmp/orbit-e2e-orb-7-hung.mutation
witness=/tmp/orbit-e2e-orb-7-hung.witness
cleanup() {
  printf 'term\n' >>"$witness"
  rm -f -- "$mutation"
  printf 'restored\n' >>"$witness"
  trap '' TERM
  while true; do sleep 1; done
}
trap cleanup TERM
test ! -e "$pid_file" && test ! -e "$mutation" && test ! -e "$witness"
printf '%s\n' "$$" >"$pid_file"
printf 'mutation\n' >"$mutation"
printf 'installed\n' >"$witness"
while true; do sleep 1; done
