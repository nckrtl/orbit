#!/usr/bin/env bash
# Usage: bash apps/e2e/resources/proofs/orbit-worker.sh TASK-820
# Run from a task workspace on a fresh allocated lease. No live provider credentials.
# The model response is deterministic; Pi, systemd, SSH, ACLs and Gateway task components are real.
set -euo pipefail
root=$(cd "$(dirname "${BASH_SOURCE[0]}")/../../../.." && pwd)
cd "$root"
topology=${1:?usage: orbit-worker.sh TASK-NUMBER}
[[ $topology =~ ^TASK-([1-9][0-9]*)$ ]] || exit 64
[[ $(git branch --show-current) == *"${BASH_REMATCH[1]}"* ]] || exit 64
worker_created=0
provider_started=0
task_started=0
process_id=
process_attempted=0
checkout=
home_mode=

on() {
    local node=$1 seconds=$2 label=$3
    shift 3
    bin/e2e-topology exec "$topology" "$node" --timeout="$seconds" --record="$label" \
        --argv="$(python3 -c 'import json,sys; print(json.dumps(sys.argv[1:]))' "$@")"
}

cleanup() {
    local original=$?
    trap - EXIT
    set +e
    local failed=0
    if (( process_attempted )) && [[ -z $process_id ]]; then
        local processes
        processes=$(on gateway 60 worker-process-cleanup-discovery orbit process:list --node=app-dev --json) || failed=1
        if (( failed == 0 )); then
            process_id=$(printf '%s\n' "$processes" | python3 -c 'import json,sys; s=sys.stdin.read(); rows=json.loads(s[s.index("{"):])["processes"]; rows=[r for r in rows if r["name"] == "pi-server"]; assert len(rows) <= 1; assert not rows or (rows[0]["user"] == "orbit-worker" and rows[0]["runtime_config"]["command"][0] == "/home/orbit-worker/.local/bin/pi-server"); print(rows[0]["id"] if rows else "")') || failed=1
        fi
    fi
    if (( task_started )); then
        local removal
        removal=$(on gateway 240 worker-gateway-removal bash -lc 'if test -f /home/orbit/orbit-worker-proof.json; then php /home/orbit/orbit/apps/e2e/resources/proofs/orbit-worker.php cleanup; fi') || failed=1
        printf '%s\n' "$removal"
        [[ $removal == *GATEWAY_REMOVAL_AUDIT* ]] || failed=1
        if [[ -z $checkout && $removal == *GATEWAY_REMOVAL_AUDIT* ]]; then
            checkout=$(printf '%s\n' "$removal" | python3 -c 'import json,sys; line=next(l for l in sys.stdin if l.startswith("GATEWAY_REMOVAL_AUDIT ")); print(json.loads(line.removeprefix("GATEWAY_REMOVAL_AUDIT "))["checkout"] or "")') || failed=1
        fi
    fi
    # Do not remove identities or evidence when native workspace removal failed.
    if (( failed )); then
        echo 'Native cleanup failed; retain the proof state, account and Process for diagnosis.' >&2
        exit 1
    fi
    if [[ -n $checkout ]]; then
        on app-dev 60 worker-checkout-removal-audit bash -c '
set -euo pipefail
checkout=$1
[ ! -e "$checkout" ] && [ ! -L "$checkout" ]
config=$(sudo -n -u orbit-worker -H git config --global --list)
! printf "%s\\n" "$config" | grep -Fx "safe.directory=$checkout"
parent=$(dirname "$checkout")
if [ -d "$parent" ]; then
    [ -z "$(find "$parent" -mindepth 1 -maxdepth 1 -print -quit)" ]
    rmdir "$parent"
fi
incus_rows=$(sudo -n -u orbit-worker -H incus list --format=json)
[ "$incus_rows" = "[]" ]
echo WORKER_FILESYSTEM_AUDIT_PASSED
' -- "$checkout" || failed=1
    fi
    if [[ -n $process_id ]]; then
        on gateway 120 worker-process-destroy orbit process:destroy "$process_id" --yes --json || failed=1
    fi
    if (( provider_started )); then
        bin/e2e-topology logs "$topology" app-dev orbit-worker-provider --record=worker-provider-log || failed=1
        bin/e2e-topology kill "$topology" app-dev orbit-worker-provider || failed=1
    fi
    if (( worker_created )); then
        on app-dev 60 worker-account-cleanup bash -c '
set -euo pipefail
[ "$(stat -c %U /home/orbit-worker)" = orbit-worker ]
[ -z "$(pgrep -u orbit-worker || true)" ]
sudo userdel -r orbit-worker
rm /home/orbit/worker-proof-private
sudo chmod "$1" /home/orbit
[ ! -e /home/orbit-worker ]
! getent passwd orbit-worker
! systemctl list-units --all --no-legend | grep -F orbit-worker-provider
[ ! -e /tmp/orbit-worker-proof-pi-server ]
[ ! -e /home/orbit/worker-proof-private ]
echo WORKER_ACCOUNT_AUDIT_PASSED
' -- "$home_mode" || failed=1
    fi
    if (( failed == 0 && task_started )); then
        on gateway 60 worker-final-audit bash -lc 'set -euo pipefail; test -f /home/orbit/orbit-worker-proof.json; orbit process:list --node=app-dev --json | python3 -c '\''import json,sys; assert all(p["name"] != "pi-server" for p in json.load(sys.stdin)["processes"])'\''; rm /home/orbit/orbit-worker-proof.json; echo WORKER_FINAL_AUDIT_PASSED' || failed=1
    fi
    if (( failed )); then exit 1; fi
    exit "$original"
}
trap cleanup EXIT

# The script deliberately refuses existing fixture identities. Release and acquire
# a fresh lease for a repeat, or finish the recorded cleanup before another run.
on app-dev 60 worker-preflight bash -lc 'set -euo pipefail; ! getent passwd orbit-worker; test ! -e /home/orbit-worker; test ! -e /var/tmp/orbit-worker-proof; command -v setfacl; command -v bun'
on gateway 60 worker-gateway-preflight bash -lc 'set -euo pipefail; test ! -e /home/orbit/orbit-worker-proof.json; orbit process:list --node=app-dev --json | python3 -c '\''import json,sys; assert all(p["name"] != "pi-server" for p in json.load(sys.stdin)["processes"])'\'''
home_mode=$(on app-dev 60 worker-home-original stat -c %a /home/orbit | grep -E '^[0-7]{3,4}$')
[[ $home_mode =~ ^[0-7]{3,4}$ ]]
# Incus is a proof prerequisite, local to this disposable VM. Never connect this
# worker to the harness host's Incus socket or to another task's Incus project.
on app-dev 600 worker-incus-prerequisite bash -lc 'set -euo pipefail; sudo apt-get update -qq; sudo env DEBIAN_FRONTEND=noninteractive apt-get install -y incus; sudo incus admin init --minimal; test "$(sudo incus list --format=json)" = "[]"'
worker_created=1
on app-dev 60 worker-host-setup bash -lc 'set -euo pipefail; umask 077; printf "managed-private\\n" > /home/orbit/worker-proof-private; sudo useradd -m -s /bin/bash orbit-worker; sudo chmod 0700 /home/orbit-worker; sudo chmod 0711 /home/orbit; for private in /home/orbit/.ssh /home/orbit/.config /home/orbit/.pi; do if test -d "$private"; then sudo chmod 0700 "$private"; fi; done; sudo usermod -aG incus-admin orbit-worker; sudo -n -u orbit-worker -H true; id orbit-worker'
on app-dev 180 worker-pi-install bash -lc 'set -euo pipefail
sudo install -d -o orbit-worker -g orbit-worker -m 0700 /home/orbit-worker/.local/bin /home/orbit-worker/.pi/agent
cd /home/orbit/orbit/apps/pi-server
bun install --frozen-lockfile
bun build src/main.ts --compile --outfile /tmp/orbit-worker-proof-pi-server
sudo install -o orbit-worker -g orbit-worker -m 0755 /tmp/orbit-worker-proof-pi-server /home/orbit-worker/.local/bin/pi-server
rm /tmp/orbit-worker-proof-pi-server
sudo -n -u orbit-worker -H python3 -c '\''import json,pathlib; d=pathlib.Path("/home/orbit-worker/.pi/agent"); (d/"orbit-token").write_text("disposable-worker-proof-"*3); (d/"orbit-token").chmod(0o600); (d/"models.json").write_text(json.dumps({"providers":{"worker-proof":{"baseUrl":"http://127.0.0.1:18317/v1","api":"openai-completions","apiKey":"disposable-fixture-key","models":[{"id":"worker-proof","reasoning":False,"input":["text"],"contextWindow":128000,"maxTokens":4096,"cost":{"input":0,"output":0,"cacheRead":0,"cacheWrite":0}}]}}}))'\'''
bin/e2e-topology spawn "$topology" app-dev orbit-worker-provider --argv='["sudo","-n","-u","orbit-worker","-H","bun","/home/orbit/orbit/apps/e2e/resources/proofs/orbit-worker-provider.ts"]'
provider_started=1
on app-dev 60 worker-provider-ready sudo -n -u orbit-worker -H curl --fail --silent --retry 20 --retry-connrefused --retry-delay 1 http://127.0.0.1:18317/health
process_attempted=1
created=$(on gateway 120 worker-pi-process-create orbit process:create pi-server --node=app-dev --user=orbit-worker \
    --working-directory=/home/orbit-worker --command=/home/orbit-worker/.local/bin/pi-server --command=serve \
    --command=--host=10.44.0.2 --command=--token-file=/home/orbit-worker/.pi/agent/orbit-token \
    --command=--workspace-root=/home/orbit/apps --command=--allow-provider=worker-proof --restart=always --keep-alive --start --json)
printf '%s\n' "$created"
process_id=$(printf '%s\n' "$created" | python3 -c 'import json,sys; s=sys.stdin.read(); j=json.loads(s[s.index("{"):]); assert j["user"] == "orbit-worker"; print(j["id"])')
[[ $process_id =~ ^[1-9][0-9]*$ ]]
on app-dev 60 worker-pi-process-identity bash -lc 'set -euo pipefail; pids=$(pgrep -u orbit-worker -f "pi-server serve"); test -n "$pids"; for pid in $pids; do ps -o user=,args= -p "$pid"; done; sudo -n -u orbit-worker -H curl --fail --silent -H "Authorization: Bearer $(sudo cat /home/orbit-worker/.pi/agent/orbit-token)" http://10.44.0.2:3774/capabilities'
task_started=1
started=$(on gateway 240 worker-task-start php /home/orbit/orbit/apps/e2e/resources/proofs/orbit-worker.php start)
printf '%s\n' "$started"
checkout=$(printf '%s\n' "$started" | python3 -c 'import json,sys; line=next(l for l in sys.stdin if l.startswith("WORKER_STARTED ")); print(json.loads(line.removeprefix("WORKER_STARTED "))["checkout"])')
[[ $checkout == /home/orbit/apps/orbit-worker-proof/task-* ]]
on gateway 240 worker-agent-and-gateway-commit php /home/orbit/orbit/apps/e2e/resources/proofs/orbit-worker.php commit
on app-dev 60 worker-commit-readback sudo -n -u orbit-worker -H bash -c '
set -euo pipefail
cd "$1"
[ "$(stat -c %U .)" = orbit ]
[ "$(stat -c %U worker-proof.txt)" = orbit-worker ]
[ "$(git show HEAD:worker-proof.txt)" = "orbit-worker proof" ]
[ "$(git log -1 --format=%ae)" = tasks@orbit ]
[ -z "$(git status --porcelain)" ]
git log -1 --format="GATEWAY_COMMIT_READBACK %H %an <%ae>"
git show HEAD:worker-proof.txt
echo WORKER_COMMIT_READBACK_PASSED
' -- "$checkout"
echo 'WORKER_SCENARIO_PASSED; cleanup and leftover audits follow.'
