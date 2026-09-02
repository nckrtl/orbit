#!/usr/bin/env bash
set -euo pipefail

fail() { echo "FAIL: $*" >&2; exit 1; }

payload=$(awk 'BEGIN { for (line = 1; line <= 20000; line++) printf "orb-7-output-%05d:%064d\n", line, line }')
grep -Fqx 'orb-7-output-20000:0000000000000000000000000000000000000000000000000000000000020000' <<<"$payload" \
  || fail "captured producer output lost its final line"
if grep -Fqx 'orb-7-output-missing' <<<"$payload"; then
  fail "a missing assertion was inverted"
fi
first=$(awk 'NR == 1 { print }' <<<"$payload")
[[ "$first" == orb-7-output-00001:* ]] || fail "the first captured value was incorrect"

echo "pipefail assertions: full producer output was captured before early-exit consumers"
