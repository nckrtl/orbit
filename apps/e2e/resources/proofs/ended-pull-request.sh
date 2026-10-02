#!/usr/bin/env bash
# Prove one merged-mid-run group on a fresh allocated topology, with substituted
# GitHub state. All Gateway lifecycle operations and the Pi server/driver are real.
# Usage: bash apps/e2e/resources/proofs/ended-pull-request.sh TASK-776
# Injection: add --lose-spawn-response=model|pi (expected exit 97 after real spawn).
# Add --hold-cleanup-stop=pi with a Pi loss to prove metadata retention and --recover.
# Teardown injection: --lose-delete-response=app-dev or --crash-after-app-dev-delete.
# Recovery: rerun on the SAME lease with --recover; no new fixture is created.
# Acquire a fresh lease first. The proof refuses existing Tasks/App/Pi settings.
# It retains the lease, restores configuration, and audits rows, paths and units.
set -eEuo pipefail
root=$(cd "$(dirname "${BASH_SOURCE[0]}")/../../../.." && pwd)
cd "$root"
issue=${1:?usage: ended-pull-request.sh TASK-NUMBER}
[[ $issue =~ ^TASK-([1-9][0-9]*)$ ]] || exit 64
number=${BASH_REMATCH[1]}
[[ $(git branch --show-current) == *"$number"* ]] || { echo 'Issue does not match branch' >&2; exit 1; }
dir=/tmp/orbit-ended-pr-$issue
artifacts=$root/.orbit-artifacts/ended-pr
mkdir -p "$artifacts"
helper=/home/orbit/orbit/apps/e2e/resources/proofs/ended-pull-request.php
model=/home/orbit/orbit/apps/e2e/resources/proofs/ended-pull-request-model.py
pi=/home/orbit/orbit/apps/pi-server/src/main.ts
services=/home/orbit/orbit/apps/e2e/resources/proofs/ended-pull-request-services.py
slug=ended-pr-$number
model_attempted=0
pi_attempted=0
setup_attempted=0
owned_nodes=()
absent_nodes=()
deletion_authorized=0
recovery_ready=1
checkout=
group=
recover=0
lose_response=
hold_stop=
lose_delete=0
crash_delete=0
for option in "${@:2}"; do
    case "$option" in
        --recover) recover=1; recovery_ready=0 ;;
        --lose-delete-response=app-dev) lose_delete=1 ;;
        --crash-after-app-dev-delete) crash_delete=1 ;;
        --lose-spawn-response=model|--lose-spawn-response=pi) lose_response=${option#*=} ;;
        --hold-cleanup-stop=model|--hold-cleanup-stop=pi) hold_stop=${option#*=} ;;
        *) echo "Unknown proof option: $option" >&2; exit 64 ;;
    esac
done
[[ -z $hold_stop || $hold_stop == "$lose_response" ]] || exit 64
[[ $recover == 0 || ( -z $lose_response && -z $hold_stop && $lose_delete == 0 && $crash_delete == 0 ) ]] || exit 64
[[ ( $lose_delete == 0 && $crash_delete == 0 ) || ( -z $lose_response && -z $hold_stop ) ]] || exit 64
[[ $lose_delete == 0 || $crash_delete == 0 ]] || exit 64
lease=$(bin/e2e-topology status "$issue" --json | python3 -c 'import json,sys; v=json.load(sys.stdin); assert v["state"]=="discovery" and v["issue"]==sys.argv[1]; print(v["attempt_id"])' "$issue")
[[ $lease =~ ^[0-9a-f]{32}$ ]] || exit 1
run_id=$(python3 -c 'import uuid; print(uuid.uuid4().hex)')

on() {
    local node=$1 label=$2 timeout=$3
    shift 3
    bin/e2e-topology exec "$issue" "$node" --timeout="$timeout" --record="$label" \
        --argv="$(python3 -c 'import json,sys; print(json.dumps(sys.argv[1:]))' "$@")"
}
observe() { on gateway "ended-pr-$1" 180 php "$helper" "$1" "$issue"; }
json_field() {
    python3 -c 'import json,sys; lines=[x for x in sys.stdin.read().splitlines() if x.startswith("ENDED_PR_JSON=")]; assert len(lines)==1, lines; print(json.loads(lines[0].split("=",1)[1])[sys.argv[1]])' "$1"
}
service_state() {
    local mode=$1 name=${2:-}
    on app-dev "ended-pr-service-$mode${name:+-$name}" 60 python3 "$services" "$mode" "$dir" "$issue" "$lease" "$run_id" ${name:+"$name"}
}
spawn_owned() {
    local name=$1
    shift
    local -a argv=(/usr/bin/env "ORBIT_ENDED_PR_RUN=$run_id" "ORBIT_ENDED_PR_LEASE=$lease" "$@")
    # Reserve BOTH the local attempt and durable guest identity before dispatch.
    # A failure/ambiguous response never transfers ownership back to nobody.
    if [[ $name == pi ]]; then pi_attempted=1; else model_attempted=1; fi
    on app-dev "ended-pr-spawn-reserved-$name" 60 python3 "$services" reserve "$dir" "$issue" "$lease" "$run_id" "$name" "${argv[@]}"
    bin/e2e-topology spawn "$issue" app-dev "ended-pr-$name" --argv="$(python3 -c 'import json,sys; print(json.dumps(sys.argv[1:]))' "${argv[@]}")"
    if [[ $lose_response == "$name" ]]; then
        # Lose the response only AFTER asserting that systemd really accepted it.
        service_state running "$name"
        echo "INJECTED_LOST_SPAWN_RESPONSE $name" >&2
        return 97
    fi
}
spawn_pi() {
    spawn_owned pi /usr/local/bin/bun "$pi" serve --host=10.44.0.2 --port=13774 --token-file="$dir/token" \
        --agent-dir="$dir/agent" --session-dir="$dir/sessions" --workspace-root="/home/orbit/apps/$slug" --allow-provider=proof
}
stop_owned() {
    local name=$1
    if [[ $hold_stop == "$name" ]]; then
        service_state running "$name" || return 1
        echo "INJECTED_CLEANUP_STOP_FAILURE $name" >&2
        return 98
    fi
    service_state stop "$name"
}
copy_evidence() {
    python3 - "$root" "$artifacts" <<'PY'
import pathlib, runpy, shutil, sys
root = pathlib.Path(sys.argv[1])
bridge = runpy.run_path(str(root / 'bin/e2e-clone-bridge'))
primary = bridge['bridge_primary'](root)
if primary is None:
    worktree = root
else:
    base = bridge['git'](primary, 'config', '--path', '--get', 'orbit.worktreeRoot', check=False) or bridge['DEFAULT_WORKTREE_ROOT']
    worktree = pathlib.Path(base).expanduser() / (root.name + '-e2e')
shutil.copy2(worktree / '.e2e/evidence.log', pathlib.Path(sys.argv[2]) / 'evidence.log')
print('Recorded evidence source:', worktree / '.e2e/evidence.log')
PY
}
cleanup() {
    local original=$? failed=0
    trap - EXIT
    set +e
    if [[ $recover == 1 && $recovery_ready == 0 ]]; then
        copy_evidence
        echo 'Recovery validation failed; retained ownership/progress unchanged.' >&2
        exit 1
    fi
    # Attempt flags are set BEFORE dispatch; the durable reservations reconcile
    # a lost response against the lease/run and systemd's actual command identity.
    if [[ $pi_attempted == 1 ]]; then
        bin/e2e-topology logs "$issue" app-dev ended-pr-pi --record=ended-pr-pi-logs || failed=1
        stop_owned pi || failed=1
    fi
    if [[ $model_attempted == 1 ]]; then
        bin/e2e-topology logs "$issue" app-dev ended-pr-model --record=ended-pr-model-logs || failed=1
        stop_owned model || failed=1
    fi
    # Confirm ALL units stopped on ALL owned Nodes before changing DB fixtures
    # or removing ANY manifest. A failed/unreadable check preserves both Nodes.
    for node in "${owned_nodes[@]}"; do
        on "$node" ended-pr-services-before-cleanup 60 python3 "$services" all-stopped "$dir" "$issue" "$lease" "$run_id" || failed=1
    done
    for node in "${absent_nodes[@]}"; do
        on "$node" ended-pr-deleted-node-absence 60 python3 "$services" absent "$dir" "$issue" "$lease" "$run_id" || failed=1
    done
    if [[ $failed == 0 && $setup_attempted == 1 ]]; then
        if [[ $deletion_authorized == 0 ]]; then observe cleanup || failed=1; fi
        observe audit || failed=1
    fi
    if [[ $failed == 0 ]]; then
        for node in "${owned_nodes[@]}"; do
            on "$node" ended-pr-services-before-delete 60 python3 "$services" all-stopped "$dir" "$issue" "$lease" "$run_id" || failed=1
            on "$node" ended-pr-predelete-audit 60 bash -c '
                set -euo pipefail
                dir=$1; issue=$2; lease=$3; run=$4; slug=$5
                test "$(<"$dir/owner")" = "$issue"
                test "$(<"$dir/lease")" = "$lease" && test "$(<"$dir/run")" = "$run"
                if [[ -d /home/orbit/apps/$slug ]]; then
                    test -z "$(find /home/orbit/apps/"$slug" -mindepth 1 -maxdepth 1 -print -quit)"
                fi
                echo METADATA_INTACT_ALL_DELETION_PRECONDITIONS_MET
            ' -- "$dir" "$issue" "$lease" "$run_id" "$slug" || failed=1
        done
    fi
    for node in "${absent_nodes[@]}"; do
        on "$node" ended-pr-deleted-node-before-delete 60 python3 "$services" absent "$dir" "$issue" "$lease" "$run_id" || failed=1
    done
    if [[ $failed == 0 && " ${owned_nodes[*]} " == *" gateway "* ]]; then
        # Persist intent AFTER DB cleanup and both-node audits, BEFORE dispatch.
        # This survives deletion acceptance with a lost response or a host crash.
        on gateway ended-pr-deletion-authorized 60 python3 "$services" authorize-delete "$dir" "$issue" "$lease" "$run_id" || failed=1
    fi
    if [[ $failed == 0 ]]; then
        # Preserve the authoritative Gateway state.json until app-dev removal is confirmed.
        for node in app-dev gateway; do
            [[ " ${owned_nodes[*]} " == *" $node "* ]] || continue
            on "$node" ended-pr-filesystem-audit 60 bash -c '
                set -euo pipefail
                dir=$1; issue=$2; lease=$3; run=$4; slug=$5
                test "$(<"$dir/owner")" = "$issue"
                test "$(<"$dir/lease")" = "$lease" && test "$(<"$dir/run")" = "$run"
                if [[ -d /home/orbit/apps/$slug ]]; then rmdir /home/orbit/apps/"$slug"; fi
                rm -rf -- "$dir"
                test ! -e "$dir" && test ! -e /home/orbit/apps/"$slug"
                echo FILESYSTEM_AND_PROCESS_LEFTOVERS_ZERO
            ' -- "$dir" "$issue" "$lease" "$run_id" "$slug" || { failed=1; break; }
            if [[ $node == app-dev && ( $lose_delete == 1 || $crash_delete == 1 ) ]]; then
                on app-dev ended-pr-actual-appdev-deletion 60 python3 "$services" absent "$dir" "$issue" "$lease" "$run_id" || { failed=1; break; }
                if [[ $crash_delete == 1 ]]; then
                    echo 'INJECTED_HOST_CRASH_AFTER_ACTUAL_APPDEV_DELETION' >&2
                    kill -KILL "$$"
                fi
                echo 'INJECTED_LOST_APPDEV_DELETION_RESPONSE' >&2
                failed=1
                break
            fi
        done
    fi
    copy_evidence || failed=1
    if [[ $failed != 0 ]]; then
        echo "Proof cleanup/audit FAILED; ownership metadata retained. Recover on this lease: bash apps/e2e/resources/proofs/ended-pull-request.sh $issue --recover" >&2
        exit 1
    fi
    if [[ $recover == 1 ]]; then echo RECOVERY_COMPLETE_ZERO_LEFTOVERS; fi
    exit "$original"
}

# Refuse occupied paths and service names on BOTH Nodes before creating anything.
# No shared credentials or resources are borrowed from the promoted snapshot.
trap cleanup EXIT
if [[ $recover == 1 ]]; then
    run_id=$(on gateway ended-pr-recovery-owner 60 python3 -c '
import pathlib,sys
root=pathlib.Path(sys.argv[1]); assert (root/"owner").read_text().strip()==sys.argv[2]
assert (root/"lease").read_text().strip()==sys.argv[3], "Recovery must use the original lease"
print((root/"run").read_text().strip())
' "$dir" "$issue" "$lease" | grep -E '^[0-9a-f]{32}$')
    [[ $run_id =~ ^[0-9a-f]{32}$ ]] || exit 1
    owned_nodes=(gateway)
    progress=$(on gateway ended-pr-recovery-progress 60 python3 "$services" deletion-state "$dir" "$issue" "$lease" "$run_id")
    [[ $progress == *DELETION_AUTHORIZED ]] && deletion_authorized=1
    metadata=$(on app-dev ended-pr-recovery-appdev-metadata 60 python3 "$services" metadata-state "$dir" "$issue" "$lease" "$run_id")
    if [[ $metadata == *METADATA_PRESENT ]]; then
        owned_nodes+=(app-dev)
        pi_attempted=1
        model_attempted=1
    elif [[ $metadata == *METADATA_ABSENT && $deletion_authorized == 1 ]]; then
        # No local markers remain. Only durable Gateway intent plus independent
        # path AND unit absence can admit this node; no present unit is stopped.
        on app-dev ended-pr-recovery-appdev-absence 60 python3 "$services" absent "$dir" "$issue" "$lease" "$run_id"
        absent_nodes=(app-dev)
    else
        echo 'Missing app-dev metadata without retained deletion authorization.' >&2
        exit 1
    fi
    manifest=$(on gateway ended-pr-recovery-manifest 60 bash -c 'if [[ -f "$1/state.json" ]]; then echo present; else echo absent; fi' -- "$dir")
    [[ $manifest == *present ]] && setup_attempted=1
    recovery_ready=1
    exit 0
fi
for node in gateway app-dev; do
    on "$node" ended-pr-ownership-preflight 60 bash -c '
        set -euo pipefail
        test ! -e "$1" && test ! -L "$1" && test ! -e /home/orbit/apps/"$3"
    ' -- "$dir" "$issue" "$slug"
    on "$node" ended-pr-service-preflight 60 python3 "$services" preflight "$dir" "$issue" "$lease" "$run_id"
done
for node in gateway app-dev; do
    on "$node" ended-pr-owned-directory 60 bash -c '
        set -euo pipefail
        mkdir -m 700 "$1"
        printf "%s\n" "$2" > "$1/owner"
        printf "%s\n" "$3" > "$1/lease"
        printf "%s\n" "$4" > "$1/run"
        mkdir -m 700 "$1/services"
    ' -- "$dir" "$issue" "$lease" "$run_id"
    owned_nodes+=("$node")
done

git rev-parse HEAD > "$artifacts/candidate.txt"
on app-dev ended-pr-pi-dependencies 300 bash -c 'set -e; cd /home/orbit/orbit/apps/pi-server; bun install --frozen-lockfile'
on app-dev ended-pr-model-setup 60 python3 -c '
import hashlib,json,pathlib,sys
root=pathlib.Path(sys.argv[1]); issue=sys.argv[2]
(root/"agent").mkdir()
(root/"token").write_text(hashlib.sha256(("disposable-"+issue+"-pi").encode()).hexdigest())
(root/"agent"/"models.json").write_text(json.dumps({"providers":{"proof":{"baseUrl":"http://127.0.0.1:18080/v1","api":"openai-completions","apiKey":"disposable-model-key","models":[{"id":"proof-model","name":"Proof model","reasoning":False,"input":["text"],"contextWindow":32000,"maxTokens":1000}]}}}))
' "$dir" "$issue"
setup_attempted=1
setup=$(observe setup)
printf '%s\n' "$setup"
group=$(printf '%s\n' "$setup" | json_field group_id)
checkout=$(printf '%s\n' "$setup" | json_field checkout)
[[ $group =~ ^[1-9][0-9]*$ && $checkout == /home/orbit/apps/$slug/task-$group ]]
on app-dev ended-pr-workspace 60 bash -c '
    set -euo pipefail
    test ! -e "$1"
    mkdir -p "$1"
    cd "$1"
    git init -b "$2"
    git config user.email proof@orbit.invalid
    git config user.name "Disposable Tasks proof"
    git remote add origin "$3"
    printf "Disposable Tasks proof\n" > README.md
    git add README.md
    git commit -m "Earlier approved fixture work"
    test -d .git
' -- "$checkout" "task-$group" "https://github.com/orbit-e2e-proof/$slug.git"
commit=$(on app-dev ended-pr-workspace-commit 60 git -C "$checkout" rev-parse HEAD | grep -E '^[0-9a-f]{40}$')
on gateway ended-pr-source-evidence 60 php "$helper" workspace-ready "$issue" "$commit"
spawn_owned model /usr/bin/python3 "$model" "$dir"
spawn_pi
on app-dev ended-pr-pi-ready 60 bash -c 'set -e; for i in {1..100}; do if curl -fsS -H "Authorization: Bearer $(<"$1/token")" http://10.44.0.2:13774/capabilities >/dev/null; then exit 0; fi; sleep .2; done; exit 1' -- "$dir"
observe start-agent
on app-dev ended-pr-model-waiting 60 bash -c 'set -e; for i in {1..100}; do if test -f "$1/waiting"; then echo REAL_MODEL_REQUEST_IN_FLIGHT; exit 0; fi; sleep .2; done; exit 1' -- "$dir"
observe working
observe tick-pending
# Repeated ticks while the real turn is running must not interrupt or notify it.
observe tick-pending
on app-dev ended-pr-release-turn 60 touch "$dir/release"
on app-dev ended-pr-turn-settled 60 bash -c 'set -e; for i in {1..100}; do sleep .2; if grep -rq "Fixture turn finished" "$1/sessions"; then exit 0; fi; done; exit 1' -- "$dir"
observe idle
observe tick-delivered
# Wait for the notice turn, then tick twice to verify exactly one transcript notice.
sleep 1
observe tick-delivered
observe tick-delivered
stop_owned pi
observe authorize
spawn_pi
on app-dev ended-pr-pi-restarted 60 bash -c 'set -e; for i in {1..100}; do if curl -fsS -H "Authorization: Bearer $(<"$1/token")" http://10.44.0.2:13774/capabilities >/dev/null; then exit 0; fi; sleep .2; done; exit 1' -- "$dir"
on gateway ended-pr-tasks-complete 180 orbit tasks:complete "$group" --yes --json
observe completed
on app-dev ended-pr-workspace-removed 60 bash -c 'set -e; test ! -e "$1"; echo REAL_WORKSPACE_REMOVED' -- "$checkout"
on gateway ended-pr-limitations 60 printf '%s\n' \
    'Operator-requested limitation wording: "Live GitHub merge not exercised: the PR state came from an injected TaskPullRequestWatcher. Gateway feature tests cover the HTTP watcher"' \
    'Exact injection scope: TaskPullRequestWatcher is bound in-process. This branch reads GitHubApi directly for running-task branch discovery and completion authorization, so those inputs come from repository-scoped HTTP response fixtures, not the watcher binding. No live GitHub request or merge is exercised.' \
    'Pi server and driver are real, with a deterministic local model barrier. Completion commits a receipt during a real Pi outage, then the unmodified tasks:complete CLI resumes and removes the workspace.'
