#!/usr/bin/env bash
# Convergence puts the escape on a node that runs no Orbit code, so it is
# reachable exactly where the CLI is not.
source /var/lib/orbit-e2e/proof/lib.sh

command -v orbit >/dev/null 2>&1 && fail "this node runs Orbit code; it is the wrong node for this claim"

sudo test -x "$ESCAPE" || fail "$ESCAPE was not published by convergence"
[[ "$(sudo stat -c '%U:%G %a' "$ESCAPE")" == "root:root 755" ]] \
  || fail "unexpected escape ownership: $(sudo stat -c '%U:%G %a' "$ESCAPE")"

run_escape --dry-run >/dev/null
[[ "$ESCAPE_STATUS" -eq 0 ]] || fail "dry run exited $ESCAPE_STATUS: $ESCAPE_OUTPUT"
assert_reports 'footprint on'
assert_reports 'exporter drop-in     owned'
assert_reports 'Dry run: nothing was changed.'

sudo test -e /etc/systemd/system/prometheus-node-exporter.service.d/orbit.conf \
  || fail "the dry run removed the exporter drop-in"

echo "escape-published: $ESCAPE is in place on a node with no Orbit CLI"
