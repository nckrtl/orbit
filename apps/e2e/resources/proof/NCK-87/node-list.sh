#!/usr/bin/env bash
# NCK-87 proof fixture. Runs as the orbit user on app-dev and proves that the
# harness plan's discovery command, `orbit node:list --json`, answers with the
# three active topology nodes. The same command opens the ACC-1 harness plan
# that bin/e2e-live feeds to the lifecycle suite.
set -euo pipefail

orbit node:list --json | python3 -c '
import json, sys
nodes = {n["name"]: n["status"] for n in json.load(sys.stdin)["nodes"]}
expected = {"gateway": "active", "app-dev": "active", "app-prod": "active"}
missing = {k: v for k, v in expected.items() if nodes.get(k) != v}
if missing:
    sys.exit(f"node:list did not report {missing}; got {nodes}")
print(f"node:list reports {sorted(nodes)} active")
'
