#!/usr/bin/env bash
set -euo pipefail

awk 'BEGIN { for (line = 1; line <= 20000; line++) printf "orb-7-output-%05d:%064d\n", line, line }'
echo "large output: proof action drained output beyond the pipe buffer"
