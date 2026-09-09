#!/usr/bin/env bash
set -euo pipefail
fixture=/var/lib/orbit-e2e/proof/loop-smoke.sh
test -r "$fixture"
test "$(stat -c %U "$fixture")" = root
if [[ "${1:?}" == checkout ]]; then
    cd /home/orbit/orbit
    test -z "$(git ls-files .loop)"
    test ! -e .loop
    test "$(git rev-parse HEAD)" = "${2:?}"
fi
