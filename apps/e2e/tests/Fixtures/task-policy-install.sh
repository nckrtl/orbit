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
if [[ $block != *'set -o pipefail'* || $block != *'test -s '* || $block != *'mv -f --'* || $block != *'stage.XXXXXX'* ]]; then
  echo "documented install block is missing pipefail, the empty check, the rename, or a unique stage" >&2
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
cat >"$work/bin/chmod" <<'EOF'
#!/usr/bin/env bash
if [[ ${ORBIT_PAUSE_CHMOD:-} == 1 ]]; then
  printf 'paused\n' > "${ORBIT_PAUSE_FLAG:?}"
  read -r _ < "${ORBIT_PAUSE_FIFO:?}"
fi
exec /usr/bin/chmod "$@"
EOF
cat >"$work/bin/cat" <<'EOF'
#!/usr/bin/env bash
if [[ ${ORBIT_SLOW_CAT:-} == 1 && $# -eq 0 ]]; then
  dd bs=1 count=8 status=none
  printf 'slow\n' > "${ORBIT_SLOW_FLAG:?}"
  read -r _ < "${ORBIT_SLOW_FIFO:?}"
fi
exec /bin/cat "$@"
EOF
chmod 0755 "$work/bin/chmod" "$work/bin/cat"

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

stages() {
  shopt -s nullglob
  local files=("$work/home/.local/lib/orbit"/e2e-task-cleanup.stage.*)
  printf '%s\n' "${files[@]}"
}

assert_previous() {
  [[ $(cat "$work/home/.local/lib/orbit/e2e-task-cleanup") == "$previous" ]]
  [[ -z $(stages) ]]
}

ORBIT_SSH_MODE=run
run_block HEAD
digest=$(sha256sum "$work/home/.local/lib/orbit/e2e-task-cleanup" | awk '{print $1}')
expected=$(git -C "$root" show "HEAD:bin/e2e-task-cleanup" | sha256sum | awk '{print $1}')
[[ $digest == "$expected" ]]
[[ -z $(stages) ]]
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

# Pause the first install after it has verified its stage, then start a retry
# that has written only part of its own stage. The first install must publish
# the file it verified, not the retry's incomplete bytes.
printf '%s\n' "$previous" >"$work/home/.local/lib/orbit/e2e-task-cleanup"
rm -f "$work/home/.local/lib/orbit"/e2e-task-cleanup.stage.*
mkfifo "$work/pause.fifo" "$work/slow.fifo"
ORBIT_PAUSE_CHMOD=1 ORBIT_PAUSE_FLAG=$work/paused ORBIT_PAUSE_FIFO=$work/pause.fifo \
  ORBIT_SSH_MODE=run run_block HEAD &
pid_a=$!
for _ in $(seq 1 50); do
  [[ -f $work/paused ]] && break
  sleep 0.1
done
[[ -f $work/paused ]]
verified=$(stages)
[[ -n $verified ]]
verified_digest=$(sha256sum $verified | awk '{print $1}')
[[ $verified_digest == "$expected" ]]
ORBIT_SLOW_CAT=1 ORBIT_SLOW_FLAG=$work/slow ORBIT_SLOW_FIFO=$work/slow.fifo \
  ORBIT_PAUSE_CHMOD=0 ORBIT_SSH_MODE=run run_block HEAD &
pid_b=$!
for _ in $(seq 1 50); do
  [[ -f $work/slow ]] && break
  sleep 0.1
done
[[ -f $work/slow ]]
[[ $(sha256sum $verified | awk '{print $1}') == "$verified_digest" ]]
printf 'go\n' >"$work/pause.fifo"
wait "$pid_a"
[[ $(sha256sum "$work/home/.local/lib/orbit/e2e-task-cleanup" | awk '{print $1}') == "$expected" ]]
kill_tree() {
  local pid=$1 child
  for child in $(ps -o pid= --ppid "$pid"); do
    kill_tree "$child"
  done
  kill -KILL "$pid" 2>/dev/null || true
}
kill_tree "$pid_b"
wait "$pid_b" || true
[[ $(sha256sum "$work/home/.local/lib/orbit/e2e-task-cleanup" | awk '{print $1}') == "$expected" ]]
echo "overlapping retry kept the verified helper"
