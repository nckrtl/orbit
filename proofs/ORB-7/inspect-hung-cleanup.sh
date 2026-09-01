#!/usr/bin/env bash
set -euo pipefail

pid_file=/tmp/orbit-e2e-orb-7-hung.pid
mutation=/tmp/orbit-e2e-orb-7-hung.mutation
witness=/tmp/orbit-e2e-orb-7-hung.witness
test -s "$pid_file"
pid=$(<"$pid_file")
! kill -0 "$pid" 2>/dev/null
test ! -e "$mutation"
test "$(<"$witness")" == $'installed\nterm\nrestored'
rm -f -- "$pid_file" "$witness"
echo "hung cleanup inspector: TERM restored the mutation and the hung cleanup was force-killed"
