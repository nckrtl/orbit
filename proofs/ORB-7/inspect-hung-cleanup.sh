#!/usr/bin/env bash
set -euo pipefail

pid_file=/tmp/orbit-e2e-orb-7-hung.pid
test -s "$pid_file"
pid=$(<"$pid_file")
! kill -0 "$pid" 2>/dev/null
rm -f -- "$pid_file"
echo "hung cleanup inspector: force-killed process is absent"
