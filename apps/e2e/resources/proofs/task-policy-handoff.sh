#!/usr/bin/env bash
# Prove the documented helper install and teardown commands on disposable resources.
#
# Usage: bash apps/e2e/resources/proofs/task-policy-handoff.sh
#
# The install block comes from docs/reference/instance-setup.md and runs on the host,
# where the reviewed commit is present. The guest SSH port is not reachable from the
# host, so the proof's ssh command is a transport into the task's app-dev Node.
# It runs that same remote command as the disposable user orbit731.
# Teardown create, update, readback, and destroy use a Project created for this proof.
# Nothing here touches the live fleet or Project 46.
set -euo pipefail

root=$(cd "$(dirname "${BASH_SOURCE[0]}")/../../../.." && pwd)
cd "$root"
topology=TASK-155
slug=orb155-handoff
repo=https://github.com/github/gitignore.git
ssh_dir=$(mktemp -d)
trap 'rm -rf "$ssh_dir"' EXIT

on_node() {
  local node=$1 timeout=$2
  shift 2
  local label=()
  if [[ ${1:-} == rec:* ]]; then
    label=(--record="${1#rec:}")
    shift
  fi
  bin/e2e-topology exec "$topology" "$node" --timeout="$timeout" --argv="$(python3 -c 'import json,sys; print(json.dumps(sys.argv[1:]))' "$@")" "${label[@]}"
}

cat >"$ssh_dir/ssh" <<'EOF'
#!/usr/bin/env bash
# Transport stand-in: deliver stdin and the remote command to orbit731 on app-dev.
set -euo pipefail
while [[ $# -gt 0 ]]; do
  case "$1" in
    -*) shift ;;
    *) shift; break ;;
  esac
done
cmd="$*"
payload=$(mktemp)
cat >"$payload"
encoded=$(base64 -w0 "$payload")
rm -f "$payload"
inner="printf '%s' '$encoded' | base64 -d | $cmd"
exec bin/e2e-topology exec TASK-155 app-dev --timeout=60 --argv="$(REMOTE="$inner" python3 -c 'import json,os; print(json.dumps(["sudo","-u","orbit731","-H","bash","-c",os.environ["REMOTE"]]))')"
EOF
chmod 0755 "$ssh_dir/ssh"

echo "handoff proof candidate $(git rev-parse HEAD)"

on_node app-dev 40 rec:handoff-user bash -lc 'sudo useradd -m -s /bin/bash orbit731 2>/dev/null || true
sudo -u orbit731 mkdir -p /home/orbit731/.local/lib/orbit
printf "previous-helper\n" | sudo -u orbit731 tee /home/orbit731/.local/lib/orbit/e2e-task-cleanup >/dev/null
sudo -u orbit731 chmod 0755 /home/orbit731/.local/lib/orbit/e2e-task-cleanup
echo HANDOFF_USER_READY'

block=$(awk '
  /^```bash$/ { capture = 1; buf = ""; next }
  /^```$/ { if (capture && buf ~ /e2e-task-cleanup\.stage/) { printf "%s", buf; exit } capture = 0 }
  capture { buf = buf $0 "\n" }
' docs/reference/instance-setup.md)
test -n "$block"

run_install() {
  local rev=$1
  local script=${block/rev=REVIEWED_SHA/rev=$rev}
  PATH="$ssh_dir:$PATH" MANAGED_USER=orbit731 NODE=app-dev bash -c "$script"
}

if PATH="$ssh_dir:$PATH" run_install 0000000000000000000000000000000000000000; then
  echo "a missing blob was treated as success" >&2
  exit 1
fi
kept=$(on_node app-dev 20 sudo -u orbit731 cat /home/orbit731/.local/lib/orbit/e2e-task-cleanup)
kept=${kept##*$'\n'}
[[ $kept == previous-helper ]]
echo HANDOFF_PRODUCER_FAILURE_PRESERVED

run_install HEAD
remote=$(on_node app-dev 20 sudo -u orbit731 sha256sum /home/orbit731/.local/lib/orbit/e2e-task-cleanup)
remote=${remote##*$'\n'}
local=$(git show HEAD:bin/e2e-task-cleanup | sha256sum)
echo "HANDOFF_READBACK remote=$remote"
echo "HANDOFF_READBACK local=$local"
[[ ${remote%% *} == "${local%% *}" ]]
stage=$(on_node app-dev 20 bash -lc 'if [[ -e /home/orbit731/.local/lib/orbit/e2e-task-cleanup.stage ]]; then echo present; else echo absent; fi')
[[ $stage == *absent ]]
echo HANDOFF_INSTALL_OK

create=$(on_node gateway 90 rec:handoff-project-create orbit project:create "$slug" monorepo "$repo" --default-branch=main --name=ORB-155-handoff --json --no-interaction)
project=$(printf '%s' "$create" | python3 -c 'import json,sys; raw=sys.stdin.read(); data=json.loads(raw[raw.find("{"):raw.rfind("}")+1]); print(data["id"])')
echo "HANDOFF_PROJECT $project"
cleanup_project() {
  on_node gateway 60 orbit project:destroy "$project" --yes --json >/dev/null || true
  on_node app-dev 30 bash -lc 'sudo userdel -r orbit731 2>/dev/null || true' || true
  rm -rf "$ssh_dir"
}
trap cleanup_project EXIT

on_node gateway 40 rec:handoff-teardown-create orbit instance:teardown-step:create task-e2e-bridge --project="$project" --command='"$HOME/.local/lib/orbit/e2e-task-cleanup"' --json
list=$(on_node gateway 40 rec:handoff-teardown-readback orbit instance:teardown-step:list --project="$project" --json)
printf '%s\n' "$list" | python3 -c 'import json,sys
raw=sys.stdin.read(); data=json.loads(raw[raw.find("{"):raw.rfind("}")+1])
steps=data["steps"] if isinstance(data, dict) else data
row=next(step for step in steps if step["name"]=="task-e2e-bridge")
assert row["command"]=="\"$HOME/.local/lib/orbit/e2e-task-cleanup\""
assert int(row["timeout_seconds"])==240
print("HANDOFF_CREATE_READBACK", row["name"], row["timeout_seconds"], row["command"])'
on_node gateway 40 rec:handoff-teardown-delta orbit instance:teardown-step:update task-e2e-bridge --project="$project" --command='"$HOME/.local/lib/orbit/e2e-task-cleanup"' --timeout=180 --json
on_node gateway 40 rec:handoff-teardown-update orbit instance:teardown-step:update task-e2e-bridge --project="$project" --command='"$HOME/.local/lib/orbit/e2e-task-cleanup"' --timeout=240 --json
list=$(on_node gateway 40 rec:handoff-teardown-update-readback orbit instance:teardown-step:list --project="$project" --json)
printf '%s\n' "$list" | python3 -c 'import json,sys
raw=sys.stdin.read(); data=json.loads(raw[raw.find("{"):raw.rfind("}")+1])
steps=data["steps"] if isinstance(data, dict) else data
row=next(step for step in steps if step["name"]=="task-e2e-bridge")
assert row["command"]=="\"$HOME/.local/lib/orbit/e2e-task-cleanup\""
assert int(row["timeout_seconds"])==240
print("HANDOFF_UPDATE_READBACK", row["name"], row["timeout_seconds"], row["command"])'
on_node gateway 40 rec:handoff-teardown-destroy orbit instance:teardown-step:destroy task-e2e-bridge --project="$project" --yes --json
list=$(on_node gateway 40 rec:handoff-teardown-destroy-readback orbit instance:teardown-step:list --project="$project" --json)
printf '%s\n' "$list" | python3 -c 'import json,sys
raw=sys.stdin.read(); data=json.loads(raw[raw.find("{"):raw.rfind("}")+1])
steps=data["steps"] if isinstance(data, dict) else data
assert all(step["name"]!="task-e2e-bridge" for step in steps)
print("HANDOFF_DESTROY_READBACK empty")'
echo HANDOFF_TEARDOWN_OK
