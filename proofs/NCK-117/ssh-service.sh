#!/usr/bin/env bash
# Takes this node's sshd down or brings it back, so the fleet sees it as an
# unreachable peer. The harness itself reaches the node over incus, not ssh.
# Usage: ssh-service.sh stop|start
set -euo pipefail

fail() { echo "FAIL: $*" >&2; exit 1; }
action=${1:?usage: ssh-service.sh stop|start}

for unit in ssh.socket ssh.service; do
  if systemctl cat "$unit" >/dev/null 2>&1; then
    sudo systemctl "$action" "$unit" || fail "could not $action $unit"
  fi
done

listening() { timeout 3 bash -c 'exec 3<>/dev/tcp/127.0.0.1/22' 2>/dev/null; }

case "$action" in
  stop)
    for _ in 1 2 3 4 5; do listening || break; sleep 1; done
    ! listening || fail "sshd still accepts connections on port 22"
    echo "ssh-service: sshd stopped, port 22 closed"
    ;;
  start)
    for _ in 1 2 3 4 5 6 7 8 9 10; do listening && break; sleep 1; done
    listening || fail "sshd did not come back on port 22"
    echo "ssh-service: sshd started, port 22 open"
    ;;
  *) fail "unknown action [$action]" ;;
esac
