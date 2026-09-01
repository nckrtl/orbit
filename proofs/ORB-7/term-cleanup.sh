#!/usr/bin/env bash
set -euo pipefail

target=/var/tmp/orbit-e2e-orb-7-term-target
witness=/tmp/orbit-e2e-orb-7-term-witness

cleanup() {
  trap - TERM
  rm -f -- "$target"
  printf 'term-observed\n' >"$witness"
  exit 143
}

trap cleanup TERM
printf 'active\n' >"$target"
while true; do sleep 1; done
