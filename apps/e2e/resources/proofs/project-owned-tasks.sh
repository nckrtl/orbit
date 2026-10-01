#!/usr/bin/env bash
# Prove the neutral Tasks lifecycle on the allocated Gateway/app-dev topology.
#
# Usage: bash apps/e2e/resources/proofs/project-owned-tasks.sh ORB-155
#
# The argument is the proof label. bin/e2e-topology leases the issue whose id is
# in the current branch (task-155 -> TASK-155). ORB-155 does not match that branch,
# so the harness issue is TASK-<number>, not the label.
#
# The script is safe to repeat. It reuses disposable orb155-* Projects, removes only
# their Instances, and does not release the topology. Sample Projects stay as they are.
set -eEuo pipefail

label=${1:-}
if [[ ! $label =~ ^[A-Z][A-Z0-9]{1,9}-[1-9][0-9]{0,8}$ ]]; then
    echo "usage: project-owned-tasks.sh ISSUE" >&2
    exit 64
fi

root=$(cd "$(dirname "${BASH_SOURCE[0]}")/../../../.." && pwd)
cd "$root"
branch=$(git rev-parse --abbrev-ref HEAD)
number=${label##*-}
if [[ $branch != *"$number"* ]]; then
    echo "branch ${branch} does not contain issue number ${number}" >&2
    exit 1
fi
topology=TASK-${number}
candidate=$(git rev-parse HEAD)
helper_src=$root/bin/e2e-task-cleanup
installed=${HOME}/.local/lib/orbit/e2e-task-cleanup
parser=$(mktemp)
helper_tmp=
work=

nc_check='test -f orb155-plain-setup -a ! -d vendor -a ! -d node_modules'
nc_check_next='test -d orb155-plain-dir'
null_setup='touch orb155-null-setup'
routed_check='test -d public -a ! -d vendor'
hello_repo=https://github.com/octocat/Hello-World.git
null_repo=https://github.com/octocat/Spoon-Knife.git
routed_repo=https://github.com/laravel/quickstart-basic.git

cleanup_local() {
    [[ -n $parser && -f $parser ]] && rm -f "$parser"
    [[ -n $helper_tmp && -d $helper_tmp ]] && rm -rf "$helper_tmp"
    [[ -n $work && -d $work ]] && rm -rf "$work"
}
trap cleanup_local EXIT

cat >"$parser" <<'PHP'
<?php
declare(strict_types=1);
$raw = stream_get_contents(STDIN);
$start = strpos($raw, '{');
$end = strrpos($raw, '}');
if ($start === false || $end === false || $end < $start) {
    fwrite(STDERR, "no json in command output:\n{$raw}\n");
    exit(1);
}
$data = json_decode(substr($raw, $start, $end - $start + 1), true, 512, JSON_THROW_ON_ERROR);
$path = json_decode($argv[1] ?? '[]', true, 512, JSON_THROW_ON_ERROR);
$value = $data;
foreach ($path as $key) {
    if (!is_array($value) || !array_key_exists($key, $value)) {
        fwrite(STDERR, 'missing '.json_encode($path)." in:\n{$raw}\n");
        exit(1);
    }
    $value = $value[$key];
}
if (is_bool($value)) {
    echo $value ? 'true' : 'false';
} elseif ($value === null) {
    echo 'null';
} elseif (is_scalar($value)) {
    echo $value;
} else {
    echo json_encode($value, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
}
echo "\n";
PHP

at() {
    local path_json=$1
    shift
    php "$parser" "$path_json"
}

argv_of() {
    python3 -c 'import json,sys; print(json.dumps(sys.argv[1:]))' "$@"
}

# Run one argv on a Node. Optional record label is the first argument when it starts with rec:.
on_node() {
    local node=$1 timeout=$2
    shift 2
    local label=()
    if [[ ${1:-} == rec:* ]]; then
        label=(--record="${1#rec:}")
        shift
    fi
    bin/e2e-topology exec "$topology" "$node" --timeout="$timeout" --argv="$(argv_of "$@")" "${label[@]}"
}

marker_line() {
    local output=$1
    printf '%s\n' "$output" | awk -F= '/^ORB155_JSON=/{print substr($0, 13); exit}'
}

observe() {
    local cmd=$1 id=${2:-}
    local output
    output=$(on_node gateway 120 bash -lc "cd /home/orbit/orbit/apps/gateway && ORB155_CMD=$(printf %q "$cmd") ORB155_ID=$(printf %q "$id") php artisan tinker --execute=\"\$(cat /tmp/orb155-observe.php)\"" 2>&1) || {
        echo "observer ${cmd} ${id} failed: ${output}" >&2
        return 1
    }
    local marker
    marker=$(marker_line "$output")
    if [[ -z $marker ]]; then
        echo "observer ${cmd} ${id} returned no marker: ${output}" >&2
        return 1
    fi
    printf '%s' "$marker"
}

require_marker() {
    local json=$1
    if [[ -z $json ]]; then
        echo "observer returned no ORB155_JSON marker" >&2
        exit 1
    fi
    printf '%s' "$json"
}

install_observer() {
    work=$(mktemp -d)
    cat >"$work/observe.php" <<'PHP'
$cmd = getenv("ORB155_CMD") ?: "";
$id = (int) (getenv("ORB155_ID") ?: 0);
$emit = function (array $payload): void {
    echo "ORB155_JSON=".json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n";
};
$enum = function ($value): string {
    if ($value instanceof BackedEnum) {
        return (string) $value->value;
    }
    return (string) $value;
};
if ($cmd === "baseline") {
    $task = App\Models\Task::query()->find($id);
    if (!$task instanceof App\Models\Task) {
        throw new RuntimeException("subtask {$id} is missing");
    }
    $check = App\Models\TaskCheck::query()->where("task_id", $task->id)->orderByDesc("id")->first();
    $group = $task->parent()->with("taskable")->first();
    $instance = $group?->taskable;
    $payload = [
        "state" => $check instanceof App\Models\TaskCheck ? $enum($check->status) : "missing",
        "kind" => $check instanceof App\Models\TaskCheck ? $enum($check->kind) : null,
        "exit_code" => $check?->exit_code,
        "failed_step" => $check?->failed_step,
        "output" => $check?->output,
        "group_id" => $group?->id,
        "group_status" => $group instanceof App\Models\Task ? $enum($group->status) : null,
        "instance_id" => $instance?->id,
        "instance_status" => $instance instanceof App\Models\Instance ? $enum($instance->status) : null,
        "checkout_path" => $instance?->checkout_path,
        "root" => $instance?->root,
        "task_workspace_routed" => $instance?->task_workspace_routed,
        "route_count" => $instance instanceof App\Models\Instance ? $instance->routes()->count() : 0,
    ];
    $emit($payload);
    return;
}
if ($cmd === "instance") {
    $instance = App\Models\Instance::query()->find($id);
    if (!$instance instanceof App\Models\Instance) {
        throw new RuntimeException("instance {$id} is missing");
    }
    $emit([
        "id" => $instance->id,
        "name" => $instance->name,
        "status" => $enum($instance->status),
        "root" => $instance->root,
        "checkout_path" => $instance->checkout_path,
        "task_workspace_routed" => $instance->task_workspace_routed,
        "route_count" => $instance->routes()->count(),
    ]);
    return;
}
if ($cmd === "settle") {
    $group = App\Models\Task::topLevel()->find($id);
    if (!$group instanceof App\Models\Task) {
        throw new RuntimeException("group {$id} is missing");
    }
    $group->status = App\Domain\Tasks\TaskGroupStatus::Settling;
    $group->pr_url = "https://github.com/orbit-e2e-proof/does-not-exist/pull/1";
    $group->assistance_requested = false;
    $group->assistance_reason = null;
    $group->save();
    $emit(["group_id" => $group->id, "status" => $enum($group->fresh()->status)]);
    return;
}
if ($cmd === "append-conflict" || $cmd === "append-lint") {
    $group = App\Models\Task::topLevel()->with("project")->find($id);
    if (!$group instanceof App\Models\Task) {
        throw new RuntimeException("group {$id} is missing");
    }
    $conflicts = $cmd === "append-conflict";
    $failed = $conflicts ? [] : [new App\Domain\Tasks\TaskPullRequestCheck("custom-lint", null)];
    $plans = App\Domain\Tasks\TaskSettlingFixup::plans($group->project->taskCheckCommand(), $conflicts, "master", $failed);
    if ($plans === []) {
        throw new RuntimeException("no fixup plan for {$cmd}");
    }
    $method = new ReflectionMethod(App\Domain\Tasks\TaskScheduler::class, "appendFixup");
    $created = $method->invoke(app(App\Domain\Tasks\TaskScheduler::class), $group, $plans[0], str_repeat("a", 40));
    if (!$created instanceof App\Models\Task) {
        $rows = App\Models\Task::query()->where("parent_id", $group->id)->get(["id", "status", "fixup_problem"])->toJson();
        throw new RuntimeException("appendFixup returned null for group {$group->id} status {$enum($group->fresh()->status)} tasks {$rows}");
    }
    $emit([
        "id" => $created->id,
        "title" => $created->title,
        "fixup_problem" => $created->fixup_problem,
        "deliverables" => $created->deliverables,
        "task_check" => $group->project->fresh()->taskCheckCommand(),
    ]);
    return;
}
if ($cmd === "complete") {
    $task = App\Models\Task::query()->find($id);
    if (!$task instanceof App\Models\Task) {
        throw new RuntimeException("subtask {$id} is missing");
    }
    $task->status = App\Domain\Tasks\TaskStatus::Completed;
    $task->save();
    $emit(["id" => $task->id, "status" => $enum($task->fresh()->status)]);
    return;
}
if ($cmd === "clear-pr") {
    $group = App\Models\Task::topLevel()->find($id);
    if (!$group instanceof App\Models\Task) {
        throw new RuntimeException("group {$id} is missing");
    }
    $group->pr_url = null;
    $group->save();
    $emit(["group_id" => $group->id, "pr_url" => $group->fresh()->pr_url]);
    return;
}
if ($cmd === "prepare-cleanup") {
    $projects = App\Models\Project::query()->where("slug", "like", "orb155-%")->get();
    $groups = [];
    foreach ($projects as $project) {
        foreach (App\Models\Task::topLevel()->where("project_id", $project->id)->get() as $group) {
            $status = $enum($group->status);
            if (in_array($status, ["cancelled", "completed"], true)) {
                continue;
            }
            $group->pr_url = null;
            $group->assistance_requested = false;
            if ($status === "settling") {
                $group->status = App\Domain\Tasks\TaskGroupStatus::Running;
            }
            $group->save();
            $groups[] = $group->id;
        }
    }
    $emit(["groups" => $groups]);
    return;
}
throw new RuntimeException("unknown observer command {$cmd}");
PHP
    local encoded
    encoded=$(base64 -w0 "$work/observe.php")
    on_node gateway 60 bash -lc "printf %s ${encoded} | base64 -d > /tmp/orb155-observe.php"
}

assert_eq() {
    local actual=$1 expected=$2 message=$3
    if [[ "$actual" != "$expected" ]]; then
        echo "${message}: expected [${expected}] got [${actual}]" >&2
        exit 1
    fi
}

assert_contains() {
    local haystack=$1 needle=$2 message=$3
    if [[ "$haystack" != *"$needle"* ]]; then
        echo "${message}: missing [${needle}] in [${haystack}]" >&2
        exit 1
    fi
}

assert_not_contains() {
    local haystack=$1 needle=$2 message=$3
    if [[ "$haystack" == *"$needle"* ]]; then
        echo "${message}: unexpectedly found [${needle}]" >&2
        exit 1
    fi
}

project_list() {
    on_node gateway 90 rec:"project list" orbit project:list --json
}

project_id_for() {
    local slug=$1 json=$2
    printf '%s' "$json" | php -r '
        $raw=stream_get_contents(STDIN);
        $data=json_decode(substr($raw, strpos($raw, "{")), true, 512, JSON_THROW_ON_ERROR);
        foreach ($data["projects"] as $project) {
            if ($project["slug"] === $argv[1]) { echo $project["id"], "\n"; exit; }
        }
        exit(2);
    ' "$slug" || true
}

ensure_project() {
    local slug=$1 type=$2 repo=$3 branch_name=$4 root_path=$5 routed=$6 check_mode=$7
    local json id
    json=$(project_list)
    id=$(project_id_for "$slug" "$json")
    if [[ -z $id ]]; then
        local -a check_args=()
        if [[ $check_mode == clear ]]; then
            :
        else
            check_args=(--task-check="$check_mode")
        fi
        json=$(on_node gateway 120 orbit project:create "$slug" "$type" "$repo" \
            --name="$slug" --default-branch="$branch_name" --root="$root_path" \
            --task-workspace-routed="$routed" "${check_args[@]}" --json)
        id=$(printf '%s' "$json" | at '["id"]')
    else
        if [[ $check_mode == clear ]]; then
            on_node gateway 60 orbit project:update "$id" --task-workspace-routed="$routed" --root="$root_path" --clear-task-check --json >/dev/null
        else
            on_node gateway 60 orbit project:update "$id" --task-workspace-routed="$routed" --root="$root_path" --task-check="$check_mode" --json >/dev/null
        fi
    fi
    printf '%s' "$id"
}

ensure_setup() {
    local project=$1 name=$2 command=$3
    local json
    json=$(on_node gateway 60 orbit instance:setup-step:list --project="$project" --json)
    if printf '%s' "$json" | php -r '
        $raw=stream_get_contents(STDIN);
        $data=json_decode(substr($raw, strpos($raw, "{")), true, 512, JSON_THROW_ON_ERROR);
        $steps=$data["steps"] ?? $data["setup_steps"] ?? $data["data"] ?? null;
        if (!is_array($steps)) { fwrite(STDERR, $raw); exit(1); }
        foreach ($steps as $step) {
            $name=$step["name"] ?? null;
            if ($name === $argv[1]) { exit(0); }
        }
        exit(2);
    ' "$name"; then
        on_node gateway 60 orbit instance:setup-step:update "$name" --project="$project" --command="$command" --json >/dev/null
        return 0
    fi
    on_node gateway 60 orbit instance:setup-step:create "$name" --project="$project" --command="$command" --json >/dev/null
}

drop_teardown_steps() {
    local project=$1 json
    json=$(on_node gateway 60 orbit instance:teardown-step:list --project="$project" --json || true)
    [[ -n $json ]] || return 0
    local names
    names=$(printf '%s' "$json" | php -r '
        $raw=stream_get_contents(STDIN);
        $start=strpos($raw, "{");
        if ($start === false) { exit(0); }
        $data=json_decode(substr($raw, $start), true, 512, JSON_THROW_ON_ERROR);
        $steps=$data["steps"] ?? $data["teardown_steps"] ?? [];
        if (!is_array($steps)) { exit(0); }
        foreach ($steps as $step) {
            if (is_string($step["name"] ?? null)) { echo $step["name"], "\n"; }
        }
    ' || true)
    local name
    while IFS= read -r name; do
        [[ -n $name ]] || continue
        on_node gateway 60 orbit instance:teardown-step:destroy "$name" --project="$project" --yes --json >/dev/null || true
    done <<<"$names"
}

prepare_fixture_projects() {
    local json ids
    json=$(observe prepare-cleanup)
    require_marker "$json" >/dev/null
    ids=$(printf '%s' "$json" | at '["groups"]' | php -r '$v=json_decode(stream_get_contents(STDIN), true); foreach ($v as $id) echo $id, "\n";')
    local id
    while IFS= read -r id; do
        [[ -n $id ]] || continue
        on_node gateway 180 orbit tasks:cancel "$id" --yes --json >/dev/null || true
    done <<<"$ids"
    json=$(project_list)
    local slug project instances
    for slug in orb155-noncomposer orb155-null orb155-routed; do
        project=$(project_id_for "$slug" "$json" || true)
        [[ -n $project ]] || continue
        drop_teardown_steps "$project"
        instances=$(on_node gateway 90 orbit instance:list --json)
        printf '%s' "$instances" | php -r '
            $raw=stream_get_contents(STDIN);
            $data=json_decode(substr($raw, strpos($raw, "{")), true, 512, JSON_THROW_ON_ERROR);
            foreach ($data["instances"] as $instance) {
                if ((int) $instance["project_id"] === (int) $argv[1]) { echo $instance["id"], "\n"; }
            }
        ' "$project" | while IFS= read -r instance_id; do
            [[ -n $instance_id ]] || continue
            on_node gateway 180 orbit instance:destroy "$instance_id" --force --yes --json >/dev/null || true
        done
    done
}

ensure_t3_eligibility() {
    local json
    json=$(on_node gateway 60 orbit process:list --node=app-dev --json)
    if printf '%s' "$json" | php -r '
        $raw=stream_get_contents(STDIN);
        $data=json_decode(substr($raw, strpos($raw, "{")), true, 512, JSON_THROW_ON_ERROR);
        foreach ($data["processes"] as $process) {
            if (($process["name"] ?? "") === "t3-code" && ($process["runtime"] ?? "") === "systemd" && ($process["status"] ?? "") === "active" && ($process["desired_state"] ?? "") === "running") {
                exit(0);
            }
        }
        exit(2);
    '; then
        return 0
    fi
    on_node gateway 180 rec:"deterministic t3-code eligibility process" orbit process:create t3-code --node=app-dev --runtime=systemd \
        --command=/usr/bin/sleep --command=infinity --restart=always --start --json >/dev/null
}

write_subtasks() {
    local payload encoded
    payload='[{"title":"Record the baseline","brief":"Leave the fresh workspace for the Project check.","deliverables":[{"id":"baseline-review","type":"review","description":"Confirm the baseline evidence."}]}]' 
    encoded=$(printf '%s' "$payload" | base64 -w0)
    on_node gateway 30 bash -lc "printf %s ${encoded} | base64 -d > /tmp/orb155-subtasks.json"
}

create_task() {
    local project=$1 title=$2
    local json
    json=$(on_node gateway 900 orbit tasks:create "$title" --project="$project" --brief="Prove the neutral task lifecycle for ${label}." \
        --status=todo --subtasks=/tmp/orb155-subtasks.json --json)
    printf '%s' "$json"
}

wait_baseline() {
    local subtask=$1 label_text=$2
    local attempt json state
    for attempt in $(seq 1 30); do
        on_node gateway 180 php /home/orbit/orbit/apps/gateway/artisan tasks:tick >/dev/null || true
        json=$(observe baseline "$subtask")
        require_marker "$json" >/dev/null
        state=$(printf '%s' "$json" | at '["state"]')
        if [[ $state == passed ]]; then
            echo "baseline ${label_text} passed"
            printf '%s\n' "$json"
            return 0
        fi
        if [[ $state != running && $state != missing ]]; then
            echo "baseline for subtask ${subtask} ended as ${state}: ${json}" >&2
            exit 1
        fi
        sleep 2
    done
    echo "baseline for subtask ${subtask} did not pass: ${json:-}" >&2
    exit 1
}

read_workspace_file() {
    local checkout=$1 relative=$2
    on_node app-dev 60 bash -lc "gitdir=\$(git -C $(printf %q "$checkout") rev-parse --absolute-git-dir) && cat \"\$gitdir/orbit/${relative}\""
}

assert_workspace_file() {
    local checkout=$1 relative=$2
    local body
    body=$(read_workspace_file "$checkout" "$relative")
    printf '%s' "$body"
}

prove_helper() {
    helper_tmp=$(mktemp -d)
    local state=$helper_tmp/state primary=$helper_tmp/primary origin=$helper_tmp/origin.git
    local worktrees=$helper_tmp/worktrees clone=$helper_tmp/task-729
    local bridge=$worktrees/task-729-e2e other=$worktrees/task-730-e2e
    install -d "${installed%/*}"
    install -m 0755 "$helper_src" "$installed"
    cmp -s "$helper_src" "$installed"
    git init -q -b main "$primary"
    git -C "$primary" config user.email proof@example.test
    git -C "$primary" config user.name proof
    echo readme >"$primary/README"
    git -C "$primary" add README
    git -C "$primary" commit -q -m init
    mkdir -p "$primary/.e2e/topology-snapshot"
    echo '{}' >"$primary/.e2e/topology-snapshot/promoted.json"
    git -C "$primary" config orbit.worktreeRoot "$worktrees"
    git init -q --bare "$origin"
    git -C "$primary" remote add origin "$origin"
    git -C "$primary" push -q origin main
    local url key
    url=$(git -C "$primary" remote get-url origin)
    key=$(php -r '
        $url = $argv[1];
        $pattern = "#\\A(?:[a-z][a-z0-9+.-]*://)?(?:[^@/]+@)?([^:/]+)[:/](.+?)(?:\\.git)?/*\\z#i";
        if (preg_match($pattern, $url, $matches) === 1) {
            echo hash("sha256", strtolower($matches[1])."/".$matches[2]);
            exit;
        }
        echo hash("sha256", $url);
    ' "$url")
    mkdir -p "$state/orbit/e2e-primary-checkouts"
    ln -s "$primary" "$state/orbit/e2e-primary-checkouts/$key"
    git clone -q "$origin" "$clone"
    git -C "$clone" remote set-url origin "$url"
    [[ ! -e $clone/bin/e2e-task-cleanup ]]
    mkdir -p "$worktrees"
    git -C "$primary" worktree add -q -b task-729-e2e "$bridge" HEAD
    echo dirt >"$bridge/dirt.txt"
    git -C "$primary" update-ref refs/orbit/e2e-bridge/task-729 HEAD
    git -C "$primary" branch task-730-e2e
    git -C "$primary" worktree add -q "$other" task-730-e2e
    echo keep >"$other/keep.txt"
    git -C "$primary" update-ref refs/orbit/e2e-bridge/task-730 HEAD
    local before_status locked_output failed
    before_status=$(bin/e2e-topology status "$topology")
    git -C "$primary" worktree lock "$bridge"
    set +e
    locked_output=$(cd "$clone" && XDG_STATE_HOME="$state" "$installed" 2>&1)
    failed=$?
    set -e
    if [[ $failed -eq 0 ]]; then
        echo "installed helper cleanup succeeded while the bridge was locked: ${locked_output}" >&2
        exit 1
    fi
    assert_contains "$locked_output" "locked" "locked bridge cleanup did not report the lock"
    [[ -d $bridge && -f $bridge/dirt.txt && -d $other && -f $clone/README ]]
    git -C "$primary" show-ref --verify --quiet refs/heads/task-729-e2e
    git -C "$primary" show-ref --verify --quiet refs/orbit/e2e-bridge/task-729
    git -C "$primary" worktree unlock "$bridge"
    (cd "$clone" && XDG_STATE_HOME="$state" "$installed")
    [[ ! -d $bridge && -d $other && -f $other/keep.txt && -d $clone && -f $clone/README ]]
    if git -C "$primary" show-ref --verify --quiet refs/heads/task-729-e2e; then
        echo "helper left the owned bridge branch" >&2
        exit 1
    fi
    if git -C "$primary" show-ref --verify --quiet refs/orbit/e2e-bridge/task-729; then
        echo "helper left the owned staging ref" >&2
        exit 1
    fi
    git -C "$primary" show-ref --verify --quiet refs/heads/task-730-e2e
    git -C "$primary" show-ref --verify --quiet refs/orbit/e2e-bridge/task-730
    (cd "$clone" && XDG_STATE_HOME="$state" "$installed")
    local after_status
    after_status=$(bin/e2e-topology status "$topology")
    assert_eq "$before_status" "$after_status" "helper cleanup changed the allocated topology status"
    rm -rf "$helper_tmp"
    helper_tmp=
    echo "installed helper removed only the owned bridge from a clone without bin/e2e-task-cleanup; topology ${topology} stayed leased"
}

echo "candidate ${candidate}"
echo "label ${label}"
echo "topology ${topology} on branch ${branch}"
status_text=$(bin/e2e-topology status "$topology")
echo "$status_text"
if [[ $status_text != *discovery* ]]; then
    echo "acquiring ${topology}"
    bin/e2e-topology acquire "$topology" .
    status_text=$(bin/e2e-topology status "$topology")
    echo "$status_text"
fi
assert_contains "$status_text" "discovery " "topology was not acquired"
attempt=${status_text##*discovery }
attempt=${attempt%%$'\n'*}

prove_helper

on_node gateway 60 rec:"enable tasks extension" orbit extension:enable tasks --json >/dev/null
install_observer
ensure_t3_eligibility
before_projects=$(project_list)
before_instances=$(on_node gateway 90 orbit instance:list --json)
sample_snapshot=$(printf '%s' "$before_projects" | php -r '
    $raw=stream_get_contents(STDIN);
    $data=json_decode(substr($raw, strpos($raw, "{")), true, 512, JSON_THROW_ON_ERROR);
    $rows=[];
    foreach ($data["projects"] as $project) {
        if (str_starts_with($project["slug"], "orb155-")) { continue; }
        $rows[]=["id"=>$project["id"],"slug"=>$project["slug"],"task_check"=>$project["task_check"],"task_workspace_routed"=>$project["task_workspace_routed"]];
    }
    echo json_encode($rows, JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR), "\n";
')
sample_instances=$(printf '%s' "$before_instances" | php -r '
    $raw=stream_get_contents(STDIN);
    $data=json_decode(substr($raw, strpos($raw, "{")), true, 512, JSON_THROW_ON_ERROR);
    $ids=[];
    foreach ($data["instances"] as $instance) {
        $slug=$instance["project"]["slug"] ?? "";
        if (!str_starts_with((string) $slug, "orb155-")) { $ids[]=$instance["id"]; }
    }
    echo json_encode($ids), "\n";
')

prepare_fixture_projects
write_subtasks
noncomposer=$(ensure_project orb155-noncomposer node-package "$hello_repo" master . false "$nc_check")
null_project=$(ensure_project orb155-null node-package "$null_repo" main . false clear)
routed_project=$(ensure_project orb155-routed laravel-app "$routed_repo" master public true "$routed_check")
ensure_setup "$noncomposer" mark 'touch orb155-plain-setup'
ensure_setup "$null_project" mark "$null_setup"
noncomposer_steps=$(on_node gateway 60 orbit instance:setup-step:list --project="$noncomposer" --json)
null_steps=$(on_node gateway 60 orbit instance:setup-step:list --project="$null_project" --json)
routed_steps=$(on_node gateway 60 orbit instance:setup-step:list --project="$routed_project" --json)
assert_eq "$(printf '%s' "$noncomposer_steps" | php -r '
    $raw=stream_get_contents(STDIN); $data=json_decode(substr($raw, strpos($raw,"{")), true, 512, JSON_THROW_ON_ERROR);
    $steps=$data["steps"] ?? $data["setup_steps"] ?? []; echo count($steps);
')" 1 "noncomposer setup inferred an extra step"
assert_eq "$(printf '%s' "$null_steps" | php -r '
    $raw=stream_get_contents(STDIN); $data=json_decode(substr($raw, strpos($raw,"{")), true, 512, JSON_THROW_ON_ERROR);
    $steps=$data["steps"] ?? $data["setup_steps"] ?? []; echo count($steps);
')" 1 "null-check setup inferred an extra step"
assert_eq "$(printf '%s' "$routed_steps" | php -r '
    $raw=stream_get_contents(STDIN); $data=json_decode(substr($raw, strpos($raw,"{")), true, 512, JSON_THROW_ON_ERROR);
    $steps=$data["steps"] ?? $data["setup_steps"] ?? []; echo count($steps);
')" 0 "routed project stored a setup step"

noncomposer_group=$(create_task "$noncomposer" "ORB-155 noncomposer baseline")
null_group=$(create_task "$null_project" "ORB-155 null-check baseline")
noncomposer_subtask=$(printf '%s' "$noncomposer_group" | at '["tasks",0,"id"]')
null_subtask=$(printf '%s' "$null_group" | at '["tasks",0,"id"]')
noncomposer_group_id=$(printf '%s' "$noncomposer_group" | at '["id"]')
null_group_id=$(printf '%s' "$null_group" | at '["id"]')

nc_json=$(wait_baseline "$noncomposer_subtask" "noncomposer baseline")
null_json=$(wait_baseline "$null_subtask" "null-check baseline")
assert_eq "$(printf '%s' "$nc_json" | at '["kind"]')" baseline "noncomposer check kind"
assert_eq "$(printf '%s' "$nc_json" | at '["exit_code"]')" 0 "noncomposer exit"
assert_eq "$(printf '%s' "$nc_json" | at '["failed_step"]')" null "noncomposer failed step"
assert_eq "$(printf '%s' "$nc_json" | at '["task_workspace_routed"]')" false "noncomposer routing"
assert_eq "$(printf '%s' "$nc_json" | at '["route_count"]')" 0 "noncomposer routes"
assert_contains "$(printf '%s' "$nc_json" | at '["output"]')" '$ touch orb155-plain-setup' "noncomposer setup output"
assert_contains "$(printf '%s' "$nc_json" | at '["output"]')" "$nc_check" "noncomposer check output"
assert_not_contains "$(printf '%s' "$nc_json" | at '["output"]')" 'composer install' "noncomposer baseline inferred composer install"
assert_not_contains "$(printf '%s' "$nc_json" | at '["output"]')" 'composer check' "noncomposer baseline inferred composer check"
assert_not_contains "$(printf '%s' "$nc_json" | at '["output"]')" '[Orbit internal]' "noncomposer baseline inferred an internal step"
nc_checkout=$(printf '%s' "$nc_json" | at '["checkout_path"]')
nc_setup_file=$(assert_workspace_file "$nc_checkout" setup.json)
assert_contains "$nc_setup_file" 'touch orb155-plain-setup' "workspace setup.json"
assert_not_contains "$nc_setup_file" 'composer' "workspace setup.json inferred composer"
nc_command=$(assert_workspace_file "$nc_checkout" check-command)
assert_eq "$nc_command" "$nc_check" "workspace check command"
on_node app-dev 30 bash -lc "test -f $(printf %q "$nc_checkout")/orb155-plain-setup && test ! -d $(printf %q "$nc_checkout")/vendor"

assert_eq "$(printf '%s' "$null_json" | at '["kind"]')" baseline "null check kind"
assert_eq "$(printf '%s' "$null_json" | at '["exit_code"]')" 0 "null exit"
assert_eq "$(printf '%s' "$null_json" | at '["failed_step"]')" null "null failed step"
assert_contains "$(printf '%s' "$null_json" | at '["output"]')" "$null_setup" "null setup output"
assert_not_contains "$(printf '%s' "$null_json" | at '["output"]')" 'composer' "null baseline mentioned composer"
assert_not_contains "$(printf '%s' "$null_json" | at '["output"]')" '[Orbit internal]' "null baseline inferred an internal step"
null_checkout=$(printf '%s' "$null_json" | at '["checkout_path"]')
null_command=$(assert_workspace_file "$null_checkout" check-command)
assert_eq "$null_command" "" "null check still ran a command"
on_node app-dev 30 bash -lc "test -f $(printf %q "$null_checkout")/orb155-null-setup && test ! -s \$(git -C $(printf %q "$null_checkout") rev-parse --absolute-git-dir)/orbit/check-command"

routed_group=$(create_task "$routed_project" "ORB-155 routed workspace")
routed_subtask=$(printf '%s' "$routed_group" | at '["tasks",0,"id"]')
routed_group_id=$(printf '%s' "$routed_group" | at '["id"]')
routed_json=$(wait_baseline "$routed_subtask" "routed cold baseline")
assert_eq "$(printf '%s' "$routed_json" | at '["task_workspace_routed"]')" true "routed mode was not recorded"
assert_eq "$(printf '%s' "$routed_json" | at '["instance_status"]')" active "routed workspace did not become active"
assert_eq "$(printf '%s' "$routed_json" | at '["root"]')" public "routed workspace root"
if [[ $(printf '%s' "$routed_json" | at '["route_count"]') -lt 1 ]]; then
    echo "routed workspace has no route: ${routed_json}" >&2
    exit 1
fi
assert_not_contains "$(printf '%s' "$routed_json" | at '["output"]')" 'composer' "routed cold baseline mentioned composer"
routed_checkout=$(printf '%s' "$routed_json" | at '["checkout_path"]')
routed_instance=$(printf '%s' "$routed_json" | at '["instance_id"]')
on_node app-dev 30 bash -lc "test -d $(printf %q "$routed_checkout")/public && test ! -d $(printf %q "$routed_checkout")/vendor"
routed_setup_file=$(assert_workspace_file "$routed_checkout" setup.json)
assert_eq "$routed_setup_file" "[]" "routed baseline received setup steps"

on_node gateway 60 rec:"turn routing off for future workspaces" orbit project:update "$routed_project" --task-workspace-routed=false --json >/dev/null
stable=$(observe instance "$routed_instance")
require_marker "$stable" >/dev/null
assert_eq "$(printf '%s' "$stable" | at '["task_workspace_routed"]')" true "existing workspace followed the new routing setting"
assert_eq "$(printf '%s' "$stable" | at '["status"]')" active "existing routed workspace changed status"
if [[ $(printf '%s' "$stable" | at '["route_count"]') -lt 1 ]]; then
    echo "existing route disappeared after the setting change" >&2
    exit 1
fi
unrouted_group=$(create_task "$routed_project" "ORB-155 unrouted workspace")
unrouted_subtask=$(printf '%s' "$unrouted_group" | at '["tasks",0,"id"]')
unrouted_json=$(wait_baseline "$unrouted_subtask" "unrouted workspace after setting change")
assert_eq "$(printf '%s' "$unrouted_json" | at '["task_workspace_routed"]')" false "new workspace ignored the routing setting"
assert_eq "$(printf '%s' "$unrouted_json" | at '["instance_status"]')" source_resolved "unrouted workspace was activated"
assert_eq "$(printf '%s' "$unrouted_json" | at '["route_count"]')" 0 "unrouted workspace gained a route"
assert_eq "$(printf '%s' "$unrouted_json" | at '["root"]')" null "unrouted workspace stored a root"
stable_again=$(observe instance "$routed_instance")
require_marker "$stable_again" >/dev/null
assert_eq "$(printf '%s' "$stable_again" | at '["task_workspace_routed"]')" true "first workspace changed when the second was created"
assert_eq "$(printf '%s' "$stable_again" | at '["route_count"]')" "$(printf '%s' "$stable" | at '["route_count"]')" "first workspace route count changed"

require_marker "$(observe settle "$noncomposer_group_id")" >/dev/null
conflict=$(observe append-conflict "$noncomposer_group_id")
require_marker "$conflict" >/dev/null
assert_eq "$(printf '%s' "$conflict" | at '["fixup_problem"]')" "conflict:master" "conflict fixup identity"
assert_eq "$(printf '%s' "$conflict" | at '["deliverables",0,"id"]')" project-check "conflict fixup deliverable"
assert_eq "$(printf '%s' "$conflict" | at '["deliverables",0,"type"]')" command "conflict fixup type"
assert_eq "$(printf '%s' "$conflict" | at '["deliverables",0,"command"]')" "$nc_check" "conflict fixup command"
assert_eq "$(printf '%s' "$conflict" | at '["deliverables",0,"directory"]')" . "conflict fixup directory"
require_marker "$(observe complete "$(printf '%s' "$conflict" | at '["id"]')")" >/dev/null
on_node gateway 60 orbit project:update "$noncomposer" --task-check="$nc_check_next" --json >/dev/null
lint=$(observe append-lint "$noncomposer_group_id")
require_marker "$lint" >/dev/null
assert_eq "$(printf '%s' "$lint" | at '["fixup_problem"]')" "check:custom-lint" "lint fixup identity"
assert_eq "$(printf '%s' "$lint" | at '["deliverables",0,"command"]')" "$nc_check_next" "new fixup did not snapshot the updated check"
shown=$(on_node gateway 60 rec:"generic fixups" orbit tasks:show "$noncomposer_group_id" --json)
assert_contains "$shown" "$nc_check" "tasks:show lost the original fixup command"
assert_contains "$shown" "$nc_check_next" "tasks:show lost the updated fixup command"
require_marker "$(observe settle "$null_group_id")" >/dev/null
review=$(observe append-conflict "$null_group_id")
require_marker "$review" >/dev/null
assert_eq "$(printf '%s' "$review" | at '["deliverables",0,"id"]')" fixup-review "null-check fixup id"
assert_eq "$(printf '%s' "$review" | at '["deliverables",0,"type"]')" review "null-check fixup type"
assert_eq "$(printf '%s' "$review" | at '["deliverables",0,"command"]' 2>/dev/null || true)" "" "null-check fixup stored a command"
if printf '%s' "$review" | php -r '
    $raw=stream_get_contents(STDIN);
    $data=json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    exit(array_key_exists("command", $data["deliverables"][0]) ? 1 : 0);
'; then
    :
else
    echo "null-check fixup deliverable included a command: ${review}" >&2
    exit 1
fi

on_node app-dev 30 bash -lc 'install -d -- "$HOME/.local/lib/orbit" && install -m 0755 /home/orbit/orbit/bin/e2e-task-cleanup "$HOME/.local/lib/orbit/e2e-task-cleanup"'
on_node gateway 60 orbit instance:teardown-step:create orb155-block --project="$null_project" --command='exit 1' --json >/dev/null
on_node gateway 60 orbit instance:teardown-step:create task-e2e-bridge --project="$null_project" --command='"$HOME/.local/lib/orbit/e2e-task-cleanup"' --json >/dev/null
require_marker "$(observe clear-pr "$null_group_id")" >/dev/null
require_marker "$(observe clear-pr "$noncomposer_group_id")" >/dev/null
set +e
teardown_failure=$(on_node gateway 180 rec:"teardown failure keeps the workspace" orbit tasks:cancel "$null_group_id" --yes --json 2>&1)
teardown_code=$?
set -e
if [[ $teardown_code -eq 0 ]]; then
    echo "cancel succeeded while the teardown step failed: ${teardown_failure}" >&2
    exit 1
fi
failed_group=$(on_node gateway 60 orbit tasks:show "$null_group_id" --json)
assert_contains "$failed_group" "Workspace removal failed:" "teardown failure was not retained"
assert_contains "$failed_group" "Teardown step failed." "teardown failure reason"
null_instance=$(printf '%s' "$null_json" | at '["instance_id"]')
on_node gateway 60 orbit instance:show "$null_instance" --json >/dev/null
on_node app-dev 30 bash -lc "test -d $(printf %q "$null_checkout")"
on_node gateway 60 orbit instance:teardown-step:update orb155-block --project="$null_project" --command='exit 0' --json >/dev/null
on_node gateway 180 rec:"teardown retry removes only the owned workspace" orbit tasks:cancel "$null_group_id" --yes --json >/dev/null
set +e
missing=$(on_node gateway 60 orbit instance:show "$null_instance" --json 2>&1)
missing_code=$?
set -e
if [[ $missing_code -eq 0 ]]; then
    echo "retried teardown left instance ${null_instance}: ${missing}" >&2
    exit 1
fi
on_node app-dev 30 bash -lc "test ! -e $(printf %q "$null_checkout")"
on_node gateway 60 orbit instance:show "$routed_instance" --json >/dev/null
nc_instance=$(printf '%s' "$nc_json" | at '["instance_id"]')
on_node gateway 60 orbit instance:show "$nc_instance" --json >/dev/null

after_projects=$(project_list)
after_snapshot=$(printf '%s' "$after_projects" | php -r '
    $raw=stream_get_contents(STDIN);
    $data=json_decode(substr($raw, strpos($raw, "{")), true, 512, JSON_THROW_ON_ERROR);
    $rows=[];
    foreach ($data["projects"] as $project) {
        if (str_starts_with($project["slug"], "orb155-")) { continue; }
        $rows[]=["id"=>$project["id"],"slug"=>$project["slug"],"task_check"=>$project["task_check"],"task_workspace_routed"=>$project["task_workspace_routed"]];
    }
    echo json_encode($rows, JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR), "\n";
')
assert_eq "$after_snapshot" "$sample_snapshot" "sample projects changed task check or routing"
after_instances=$(on_node gateway 90 orbit instance:list --json)
missing_sample=$(php -r '
    $expected=json_decode($argv[1], true, 512, JSON_THROW_ON_ERROR);
    $raw=$argv[2];
    $data=json_decode(substr($raw, strpos($raw, "{")), true, 512, JSON_THROW_ON_ERROR);
    $have=[];
    foreach ($data["instances"] as $instance) { $have[(int) $instance["id"]]=true; }
    $missing=[];
    foreach ($expected as $id) { if (!isset($have[(int) $id])) { $missing[]=$id; } }
    echo implode(",", $missing);
' "$sample_instances" "$after_instances")
if [[ -n $missing_sample ]]; then
    echo "sample instances disappeared: ${missing_sample}" >&2
    exit 1
fi

prepare_fixture_projects
final_status=$(bin/e2e-topology status "$topology")
assert_contains "$final_status" "discovery ${attempt}" "proof released or replaced the topology"
echo "proved ${label} at ${candidate} on ${topology} attempt ${attempt}"
echo "limitations: no GitHub App is configured, so the pull-request watcher cannot append a fixup from a live pull request; fixups were appended by TaskScheduler::appendFixup on the Gateway and read back with tasks:show. No T3 token or server is configured; a sleep t3-code process only makes app-dev eligible, and the implementer spawn fails after the baseline. The proof does not release ${topology}."
