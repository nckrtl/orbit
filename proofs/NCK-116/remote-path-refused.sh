#!/usr/bin/env bash
# With the Gateway unreachable, the remote Metrics removal path still refuses.
# That is the state the node-local escape exists for.
source /var/lib/orbit-e2e/proof/lib.sh

set +e
status=$(orbit metrics:status --json 2>&1)
status_code=$?
disable=$(orbit metrics:disable --force --json 2>&1)
disable_code=$?
set -e

[[ "$status_code" -ne 0 ]] || fail "metrics:status answered with no reachable Gateway: $status"
[[ "$disable_code" -ne 0 ]] || fail "metrics:disable succeeded with no reachable Gateway: $disable"

sudo test -d /etc/orbit/metrics || fail "the Metrics footprint is already gone before the escape ran"
sudo test -x "$ESCAPE" || fail "$ESCAPE is missing on the Metrics node"

echo "remote-path-refused: metrics:status exited $status_code, metrics:disable exited $disable_code"
