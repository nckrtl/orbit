#!/usr/bin/env bash
# Prove the documented helper install preserves a previous file.
# Success and producer failure run the docs block unchanged.
# Truncation and SIGTERM use that same remote command through the test ssh.
set -euo pipefail

root=$(cd "$(dirname "${BASH_SOURCE[0]}")/../../../.." && pwd)
docs=$root/docs/reference/instance-setup.md
block=$(awk '
  /^```bash$/ { capture = 1; buf = ""; next }
  /^```$/ { if (capture && buf ~ /e2e-task-cleanup\.stage/) { printf "%s", buf; exit } capture = 0 }
  capture { buf = buf $0 "\n" }
' "$docs")
if [[ $block != *'set -o pipefail'* || $block != *'test -s '* || $block != *'mv -f --'* ]]; then
  echo "documented install block is missing pipefail, the empty check, or the rename" >&2
  exit 1
fi

work=$(mktemp -d)
trap 'rm -rf "$work"' EXIT
mkdir -p "$work/bin" "$work/home/.local/lib/orbit"
cat >"$work/bin/ssh" <<'EOF'
#!/usr/bin/env bash
while [[ $# -gt 0 ]]; do
  case "$1" in
    -*) shift ;;
    *) shift; break ;;
  esac
done
cmd="$*"
export HOME="${ORBIT_TEST_HOME:?}"
mode=${ORBIT_SSH_MODE:-run}
if [[ $mode == truncate ]]; then
  head -c 20 | bash -c "$cmd"
  exit $?
fi
if [[ $mode == interrupt ]]; then
  # A hard kill while cat is blocked cannot run the trap. The stage may remain.
  # The destination must still be the previous helper.
  bash -c "$cmd" < <(printf 'partial'; sleep 30) &
  pid=$!
  sleep 0.2
  kill -KILL "$pid" 2>/dev/null || true
  kill -KILL -- -"$pid" 2>/dev/null || true
  pkill -KILL -P "$pid" 2>/dev/null || true
  wait "$pid" 2>/dev/null || true
  exit 1
fi
exec bash -c "$cmd"
EOF
chmod 0755 "$work/bin/ssh"

export PATH="$work/bin:$PATH"
export ORBIT_TEST_HOME=$work/home
previous='previous-helper'
printf '%s\n' "$previous" >"$work/home/.local/lib/orbit/e2e-task-cleanup"
chmod 0755 "$work/home/.local/lib/orbit/e2e-task-cleanup"

run_block() {
  local rev=$1
  local script=${block/rev=REVIEWED_SHA/rev=$rev}
  (
    cd "$root"
    MANAGED_USER=managed
    NODE=node
    eval "$script"
  )
}

assert_previous() {
  [[ $(cat "$work/home/.local/lib/orbit/e2e-task-cleanup") == "$previous" ]]
  [[ ! -e $work/home/.local/lib/orbit/e2e-task-cleanup.stage ]]
}

ORBIT_SSH_MODE=run
run_block HEAD
digest=$(sha256sum "$work/home/.local/lib/orbit/e2e-task-cleanup" | awk '{print $1}')
expected=$(git -C "$root" show "HEAD:bin/e2e-task-cleanup" | sha256sum | awk '{print $1}')
[[ $digest == "$expected" ]]
[[ ! -e $work/home/.local/lib/orbit/e2e-task-cleanup.stage ]]
echo "success digest matches and the stage is gone"

printf '%s\n' "$previous" >"$work/home/.local/lib/orbit/e2e-task-cleanup"
if ORBIT_SSH_MODE=run run_block 0000000000000000000000000000000000000000; then
  echo "a missing blob was treated as success" >&2
  exit 1
fi
assert_previous
echo "producer failure kept the previous helper"

printf '%s\n' "$previous" >"$work/home/.local/lib/orbit/e2e-task-cleanup"
if ORBIT_SSH_MODE=truncate run_block HEAD; then
  echo "a truncated transfer was treated as success" >&2
  exit 1
fi
assert_previous
echo "truncated transfer kept the previous helper"

printf '%s\n' "$previous" >"$work/home/.local/lib/orbit/e2e-task-cleanup"
if ORBIT_SSH_MODE=interrupt run_block HEAD; then
  echo "an interrupted transfer was treated as success" >&2
  exit 1
fi
[[ $(cat "$work/home/.local/lib/orbit/e2e-task-cleanup") == "$previous" ]]
echo "interrupted transfer kept the previous helper"
