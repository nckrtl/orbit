#!/usr/bin/env bash
set -euo pipefail

issue=ORB-171
mode=${1:-authoritative}

if [[ "$mode" != diagnostic && "$mode" != authoritative ]]; then
    echo "usage: bash .loop/proof/orb-171-host-rehearsal.sh [diagnostic|authoritative]" >&2
    exit 64
fi

worktree=$(git rev-parse --show-toplevel)
cd "$worktree"

plan=.loop/proof/ORB-171.json
guest_fixture=.loop/proof/retirement-preserved-resource-reference.php
host_fixture=.loop/proof/orb-171-host-rehearsal.sh
state_dir=.e2e
attempt_path="$state_dir/attempt.json"
topology_path="$state_dir/topology.json"
receipt="$state_dir/orb-171-host-rehearsal-$mode.json"
logs_dir="$state_dir/orb-171-host-rehearsal-$mode-logs"
scratch=$(mktemp -d "${TMPDIR:-/tmp}/orb-171-host-rehearsal.XXXXXX")
commands="$scratch/commands.ndjson"
assertions="$scratch/assertions.ndjson"
command_index=0
last_stdout=
last_stderr=

cleanup() {
    rm -rf "$scratch"
}
trap cleanup EXIT

fail() {
    echo "ORB-171 host rehearsal failed: $*" >&2
    exit 1
}

for executable in git jq sha256sum php incus flock; do
    command -v "$executable" >/dev/null || fail "$executable is required"
done

mkdir -p "$state_dir"
rm -rf "$logs_dir"
mkdir -p "$logs_dir"
: > "$commands"
: > "$assertions"

record_assertion() {
    local label=$1
    local detail=$2

    jq -cn \
        --arg label "$label" \
        --arg detail "$detail" \
        '{label: $label, exit_code: 0, detail: $detail}' >> "$assertions"
}

run_recorded() {
    local label=$1
    local expected_exit=$2
    shift 2
    local safe_label=${label//[^a-zA-Z0-9._-]/-}
    local actual_exit assertion_exit stdout_sha stderr_sha

    command_index=$((command_index + 1))
    last_stdout="$logs_dir/$(printf '%02d' "$command_index")-$safe_label.stdout"
    last_stderr="$logs_dir/$(printf '%02d' "$command_index")-$safe_label.stderr"

    set +e
    "$@" > "$last_stdout" 2> "$last_stderr"
    actual_exit=$?
    set -e

    assertion_exit=0
    if [[ "$actual_exit" -ne "$expected_exit" ]]; then
        assertion_exit=1
    fi
    stdout_sha=$(sha256sum "$last_stdout" | cut -d ' ' -f 1)
    stderr_sha=$(sha256sum "$last_stderr" | cut -d ' ' -f 1)
    jq -cn \
        --arg label "$label" \
        --argjson expected_exit "$expected_exit" \
        --argjson actual_exit "$actual_exit" \
        --argjson assertion_exit "$assertion_exit" \
        --arg stdout_path "${last_stdout#$worktree/}" \
        --arg stderr_path "${last_stderr#$worktree/}" \
        --arg stdout_sha256 "$stdout_sha" \
        --arg stderr_sha256 "$stderr_sha" \
        '{
            label: $label,
            argv: $ARGS.positional,
            expected_exit: $expected_exit,
            actual_exit: $actual_exit,
            assertion_exit: $assertion_exit,
            stdout_path: $stdout_path,
            stderr_path: $stderr_path,
            stdout_sha256: $stdout_sha256,
            stderr_sha256: $stderr_sha256
        }' \
        --args -- "$@" >> "$commands"

    echo "[$label] exit=$actual_exit expected=$expected_exit"
    if [[ "$assertion_exit" -ne 0 ]]; then
        cat "$last_stdout" >&2
        cat "$last_stderr" >&2
        fail "command [$label] returned $actual_exit; expected $expected_exit"
    fi
}

assert_same_file() {
    local label=$1
    local expected=$2
    local actual=$3

    cmp -s "$expected" "$actual" || fail "assertion [$label] found changed bytes"
    record_assertion "$label" "byte-identical sha256=$(sha256sum "$actual" | cut -d ' ' -f 1)"
}

assert_native_drift_detected() {
    local snapshot=$1
    local changed="$scratch/native-negative-changed.json"

    jq '.[0].envelope.metadata.name += "-changed"' "$snapshot" > "$changed"
    if cmp -s "$snapshot" "$changed"; then
        fail 'the native resource comparison accepted a changed instance name'
    fi
    record_assertion native-resource-negative \
        'the native comparison rejects a changed captured instance name'
}

normalized_plan_sha() {
    php -r \
        'require "apps/e2e/vendor/autoload.php"; echo App\E2E\Value\ProofPlan::fromFile($argv[1])->fingerprint();' \
        "$plan"
}

proof_evidence_manifest() {
    local destination=$1
    local proof_attempt=

    {
        find "$state_dir" -type f \
            \( -path "$state_dir/proof-attempt.json" \
            -o -path "$state_dir/proof-topology.json" \
            -o -path "$state_dir/proof.json" \
            -o -path "$state_dir/proof-inputs/*" \
            -o -path "$state_dir/equivalence.json" \
            -o -path "$state_dir/equivalence/*" \) \
            -print0 \
            | sort -z \
            | xargs -0 -r sha256sum
        if [[ -f "$state_dir/proof-attempt.json" ]]; then
            proof_attempt=$(jq -er '.attempt_id' "$state_dir/proof-attempt.json")
            printf 'proof-ref  %s\n' "$(git rev-parse "refs/orbit/e2e-proof/orb-171/$proof_attempt" 2>/dev/null || true)"
        fi
    } > "$destination"
}

capture_native() {
    local phase=$1
    local destination=$2
    local entries="$scratch/$phase-native.ndjson"
    local label command_label path name envelope
    : > "$entries"

    # Incus returns used_by, locations, and aliases as unordered sets. Sort only
    # those arrays; retain every other raw envelope field for byte comparison.
    while IFS= read -r name; do
        label="instance-$name"
        command_label="$phase-$label"
        path="$incus_remote:/1.0/instances/$name?project=$incus_project"
        run_recorded "$command_label" 0 "$real_incus" query --raw "$path"
        envelope="$scratch/$command_label.json"
        jq -Se '
            if .type == "sync" and .status_code == 200 and (.metadata.name | type == "string")
            then .
                | if (.metadata.used_by? | type) == "array" then .metadata.used_by |= sort else . end
                | if (.metadata.locations? | type) == "array" then .metadata.locations |= sort else . end
                | if (.metadata.aliases? | type) == "array"
                  then .metadata.aliases |= sort_by(.name, .description)
                  else . end
            else error("invalid raw instance envelope")
            end
        ' "$last_stdout" > "$envelope" \
            || fail "[$command_label] did not return a valid raw sync envelope"
        jq -cn --arg label "$label" --slurpfile envelope "$envelope" \
            '{label: $label, envelope: $envelope[0]}' >> "$entries"
    done < <(jq -r '.construction.nodes | to_entries | sort_by(.key)[] | .value.instance' "$topology_path")

    for specification in \
        "network|$incus_remote:/1.0/networks/$network?project=$incus_project" \
        "pool|$incus_remote:/1.0/storage-pools/$storage_pool?project=$incus_project" \
        "image|$incus_remote:/1.0/images/$image_fingerprint?project=$incus_project"; do
        label=${specification%%|*}
        path=${specification#*|}
        run_recorded "$phase-$label" 0 "$real_incus" query --raw "$path"
        envelope="$scratch/$phase-$label.json"
        jq -Se '
            if .type == "sync" and .status_code == 200 and (.metadata | type == "object")
            then .
                | if (.metadata.used_by? | type) == "array" then .metadata.used_by |= sort else . end
                | if (.metadata.locations? | type) == "array" then .metadata.locations |= sort else . end
                | if (.metadata.aliases? | type) == "array"
                  then .metadata.aliases |= sort_by(.name, .description)
                  else . end
            else error("invalid raw resource envelope")
            end
        ' "$last_stdout" > "$envelope" \
            || fail "[$phase-$label] did not return a valid raw sync envelope"
        jq -cn --arg label "$label" --slurpfile envelope "$envelope" \
            '{label: $label, envelope: $envelope[0]}' >> "$entries"
    done

    jq -s '.' "$entries" > "$destination"
}

assert_scenario_queries() {
    local scenario=$1
    local query_log=$2
    local expected_pool=$3
    local expected_image=$4

    jq -e \
        --arg pool "$incus_remote:/1.0/storage-pools/$storage_pool?project=$incus_project" \
        --arg image "$incus_remote:/1.0/images/$image_fingerprint?project=$incus_project" \
        --argjson expected_pool "$expected_pool" \
        --argjson expected_image "$expected_image" '
            all(.[]; .argv[0:2] == ["query", "--raw"])
            and ([.[] | select(.argv[2] == $pool)] | length) == $expected_pool
            and ([.[] | select(.argv[2] == $image)] | length) == $expected_image
            and length == ($expected_pool + $expected_image)
        ' "$query_log" >/dev/null \
        || fail "scenario [$scenario] did not use the exact production raw query set"
    record_assertion "$scenario-production-queries" \
        "pool=$expected_pool image=$expected_image remote=$incus_remote project=$incus_project"
}

run_scenario() {
    local scenario=$1
    local expected_exists=$2
    local expected_pool_queries=$3
    local expected_image_queries=$4
    local scenario_root="$scratch/scenario-$scenario"
    local query_ndjson="$scratch/$scenario-queries.ndjson"
    local query_json="$scratch/$scenario-queries.json"
    local output="$scratch/$scenario.json"
    local content="ORB-171 $scenario reviewed candidate"
    : > "$query_ndjson"

    run_recorded "scenario-$scenario" 0 env \
        PATH="$wrapper_dir:$PATH" \
        ORB171_REAL_INCUS="$real_incus" \
        ORB171_QUERY_LOG="$query_ndjson" \
        php "$guest_fixture" host "$scenario" "$scenario_root" \
        "$incus_remote" "$incus_project" "$storage_pool" "$image_fingerprint" "$content"
    cp "$last_stdout" "$output"
    jq -e \
        --arg scenario "$scenario" \
        --arg path "$scenario_root/safe/candidate.json" \
        --arg sha "$(printf '%s' "$content" | sha256sum | cut -d ' ' -f 1)" \
        --argjson exists "$expected_exists" '
            .scenario == $scenario
            and .status == "passed"
            and .candidate.path == $path
            and .candidate.before_sha256 == $sha
            and .candidate.exists_after == $exists
            and (if $exists then .candidate.after_sha256 == $sha and .refused == true
                 else .candidate.after_sha256 == null and .refused == false end)
        ' "$output" >/dev/null || fail "scenario [$scenario] returned invalid candidate evidence"
    jq -s '.' "$query_ndjson" > "$query_json"
    assert_scenario_queries "$scenario" "$query_json" "$expected_pool_queries" "$expected_image_queries"
    record_assertion "$scenario-candidate" \
        "path=$scenario_root/safe/candidate.json before_sha256=$(printf '%s' "$content" | sha256sum | cut -d ' ' -f 1) exists_after=$expected_exists"
}

candidate_sha=$(git rev-parse HEAD)
candidate_tree=$(git rev-parse 'HEAD^{tree}')
plan_raw_sha=$(sha256sum "$plan" | cut -d ' ' -f 1)
plan_sha=$(normalized_plan_sha)
guest_fixture_sha=$(sha256sum "$guest_fixture" | cut -d ' ' -f 1)
host_fixture_sha=$(sha256sum "$host_fixture" | cut -d ' ' -f 1)
started_at=$(date -u +%Y-%m-%dT%H:%M:%SZ)
real_incus=$(command -v incus)

[[ -z "$(git status --porcelain)" ]] || fail 'the candidate worktree must be clean'
[[ -f "$attempt_path" && -f "$topology_path" ]] || fail 'an existing complete discovery is required'
jq -e \
    --arg issue "$issue" '
        .issue == $issue and .purpose == "discovery" and .extension == null
        and (.attempt_id | test("^[a-f0-9]{32}$"))
    ' "$attempt_path" >/dev/null || fail 'the discovery lease is not the exact standard ORB-171 lease'
discovery_attempt=$(jq -er '.attempt_id' "$attempt_path")
jq -e \
    --arg issue "$issue" \
    --arg attempt "$discovery_attempt" '
        .purpose == "discovery"
        and .construction.issue == $issue
        and .construction.attempt_id == $attempt
        and .construction.extension == null
        and (.construction.nodes | keys | sort) == ["app-dev", "app-prod", "gateway"]
        and (.construction.nodes | length) == 3
    ' "$topology_path" >/dev/null || fail 'the discovery topology is not the exact standard three-node target'
network=$(jq -er '.network' "$topology_path")
cp "$attempt_path" "$scratch/attempt-before.json"
cp "$topology_path" "$scratch/topology-before.json"

run_recorded incus-configuration 0 php -r '
    require "apps/e2e/vendor/autoload.php";
    $app = require "apps/e2e/bootstrap/app.php";
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    echo json_encode([
        "remote" => config("e2e.incus.remote"),
        "project" => config("e2e.incus.project"),
        "storage_pool" => config("e2e.incus.storage_pool"),
    ], JSON_THROW_ON_ERROR), "\n";
'
configuration="$scratch/incus-configuration.json"
cp "$last_stdout" "$configuration"
incus_remote=$(jq -er '.remote' "$configuration")
incus_project=$(jq -er '.project' "$configuration")
storage_pool=$(jq -er '.storage_pool' "$configuration")

run_recorded base-image-alias 0 "$real_incus" --project "$incus_project" image list \
    "$incus_remote:orbit-base-ubuntu-26.04-runtime" --format=json
image_fingerprint=$(jq -er '
    [.[] | select(any(.aliases[]?; .name == "orbit-base-ubuntu-26.04-runtime"))]
    | select(length == 1)
    | .[0]
    | .fingerprint
    | select(test("^[a-f0-9]{64}$"))
' "$last_stdout") \
    || fail 'the configured base-image alias did not resolve to one exact fingerprint'

jq -e '.mutates == true and .observed_inputs == false and .extension == null
    and .acceptance == [{
        id: "retirement-preserved-resource-reference",
        node: "gateway",
        argv: ["/var/lib/orbit-e2e/proof/retirement-preserved-resource-reference.php"],
        timeout_seconds: 120
    }]' "$plan" >/dev/null || fail 'the proof plan does not declare the exact standard guest action'
record_assertion candidate-clean "HEAD=$candidate_sha tree=$candidate_tree"
record_assertion discovery-binding \
    "attempt=$discovery_attempt network=$network nodes=$(jq -c '.construction.nodes | map_values(.instance)' "$topology_path")"
record_assertion incus-reference \
    "remote=$incus_remote project=$incus_project pool=$storage_pool fingerprint=$image_fingerprint"
record_assertion fixture-bindings \
    "plan_raw=$plan_raw_sha plan_normalized=$plan_sha guest=$guest_fixture_sha host=$host_fixture_sha"

if [[ "$mode" == authoritative ]]; then
    [[ -f "$state_dir/proof.json" && -f "$state_dir/proof-attempt.json" && -f "$state_dir/proof-topology.json" ]] \
        || fail 'authoritative mode requires retained proof state'
    proof_attempt=$(jq -er '.attempt_id' "$state_dir/proof.json")
    jq -e \
        --arg candidate "$candidate_sha" \
        --arg plan "$plan_sha" \
        --arg attempt "$proof_attempt" '
            .status == "proved"
            and .candidate_sha == $candidate
            and .plan_sha256 == $plan
            and .attempt_id == $attempt
            and .actions == [{id:"retirement-preserved-resource-reference",node:"gateway",exit_code:0}]
        ' "$state_dir/proof.json" >/dev/null \
        || fail 'retained proof does not bind the exact candidate, plan, action, and zero exit'
    jq -e \
        --arg attempt "$proof_attempt" '
            .issue == "ORB-171" and .purpose == "proof" and .attempt_id == $attempt and .extension == null
        ' "$state_dir/proof-attempt.json" >/dev/null \
        || fail 'retained proof lease does not bind the exact standard proof attempt'
    jq -e \
        --arg attempt "$proof_attempt" '
            .purpose == "proof" and .construction.issue == "ORB-171"
            and .construction.attempt_id == $attempt and .construction.extension == null
        ' "$state_dir/proof-topology.json" >/dev/null \
        || fail 'retained proof topology does not bind the exact standard proof attempt'
    [[ "$(git rev-parse "refs/orbit/e2e-proof/orb-171/$proof_attempt")" == "$candidate_sha" ]] \
        || fail 'retained proof Git ref does not point to the exact candidate'
    manifest_sha=$(jq -er '.manifest_sha256' "$state_dir/proof.json")
    manifest_path="$state_dir/proof-inputs/$manifest_sha.json"
    [[ -f "$manifest_path" ]] || fail 'retained proof input manifest is absent'
    run_recorded authoritative-proof-manifest 0 php -r '
        require "apps/e2e/vendor/autoload.php";
        $value = json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);
        $manifest = App\E2E\Value\ProofInputManifest::fromArray($value);
        if (
            $manifest->fingerprint() !== $argv[2]
            || $manifest->provedSha !== $argv[3]
            || $manifest->proofPlanPath !== $argv[4]
            || $manifest->construction->target->issue !== "ORB-171"
            || $manifest->construction->target->requireAttempt()->value !== $argv[5]
            || $manifest->construction->extension !== null
        ) {
            exit(1);
        }
        echo "manifest binding valid\n";
    ' "$manifest_path" "$manifest_sha" "$candidate_sha" "$plan" "$proof_attempt"
    plan_blob=$(git hash-object "$plan")
    guest_fixture_blob=$(git hash-object "$guest_fixture")
    host_fixture_blob=$(git hash-object "$host_fixture")
    jq -e \
        --arg plan "$plan" --arg plan_blob "$plan_blob" \
        --arg guest "$guest_fixture" --arg guest_blob "$guest_fixture_blob" \
        --arg host "$host_fixture" --arg host_blob "$host_fixture_blob" '
            any(.static_inputs[]; .path == $plan and .classification == "proof-contract" and .blob == $plan_blob)
            and any(.static_inputs[]; .path == $guest and .classification == "proof-contract" and .blob == $guest_blob)
            and any(.static_inputs[]; .path == $host and .classification == "proof-contract" and .blob == $host_blob)
        ' "$manifest_path" >/dev/null \
        || fail 'retained proof manifest does not bind every current proof fixture blob'
    record_assertion authoritative-proof-binding \
        "proof_attempt=$proof_attempt candidate=$candidate_sha plan=$plan_sha manifest=$manifest_sha action_exit=0"
    record_assertion authoritative-fixture-blobs \
        "plan=$plan_blob guest=$guest_fixture_blob host=$host_fixture_blob"
fi

proof_evidence_manifest "$scratch/proof-before"
capture_native before "$scratch/native-before.json"
assert_native_drift_detected "$scratch/native-before.json"

wrapper_dir="$scratch/wrapper"
mkdir "$wrapper_dir"
cat > "$wrapper_dir/incus" <<'WRAPPER'
#!/usr/bin/env bash
set -euo pipefail
: "${ORB171_REAL_INCUS:?}"
: "${ORB171_QUERY_LOG:?}"
{
    flock 9
    jq -cn --args '{argv: $ARGS.positional}' -- "$@" >&9
} 9>> "$ORB171_QUERY_LOG"
exec "$ORB171_REAL_INCUS" "$@"
WRAPPER
chmod 0700 "$wrapper_dir/incus"

run_scenario matching false 1 1
run_scenario changed-project true 0 1
run_scenario changed-pool true 0 1
run_scenario changed-fingerprint true 1 0

capture_native after "$scratch/native-after.json"
assert_same_file native-resources-unchanged "$scratch/native-before.json" "$scratch/native-after.json"
assert_same_file discovery-lease-unchanged "$scratch/attempt-before.json" "$attempt_path"
assert_same_file discovery-topology-unchanged "$scratch/topology-before.json" "$topology_path"
proof_evidence_manifest "$scratch/proof-after"
assert_same_file retained-proof-unchanged "$scratch/proof-before" "$scratch/proof-after"

finished_at=$(date -u +%Y-%m-%dT%H:%M:%SZ)
jq -s '.' "$commands" > "$scratch/commands.json"
jq -s '.' "$assertions" > "$scratch/assertions.json"
for scenario in matching changed-project changed-pool changed-fingerprint; do
    jq -s '.' "$scratch/$scenario-queries.ndjson" > "$scratch/$scenario-queries.json"
done
jq -n \
    --argjson schema 1 \
    --arg issue "$issue" \
    --arg mode "$mode" \
    --arg started_at "$started_at" \
    --arg finished_at "$finished_at" \
    --arg candidate_sha "$candidate_sha" \
    --arg candidate_tree "$candidate_tree" \
    --arg plan_path "$plan" \
    --arg plan_raw_sha256 "$plan_raw_sha" \
    --arg plan_sha256 "$plan_sha" \
    --arg guest_fixture_path "$guest_fixture" \
    --arg guest_fixture_sha256 "$guest_fixture_sha" \
    --arg host_fixture_path "$host_fixture" \
    --arg host_fixture_sha256 "$host_fixture_sha" \
    --arg discovery_attempt "$discovery_attempt" \
    --arg network "$network" \
    --arg incus_remote "$incus_remote" \
    --arg incus_project "$incus_project" \
    --arg storage_pool "$storage_pool" \
    --arg image_fingerprint "$image_fingerprint" \
    --slurpfile topology "$topology_path" \
    --slurpfile native "$scratch/native-before.json" \
    --slurpfile matching "$scratch/matching.json" \
    --slurpfile changed_project "$scratch/changed-project.json" \
    --slurpfile changed_pool "$scratch/changed-pool.json" \
    --slurpfile changed_fingerprint "$scratch/changed-fingerprint.json" \
    --slurpfile matching_queries "$scratch/matching-queries.json" \
    --slurpfile changed_project_queries "$scratch/changed-project-queries.json" \
    --slurpfile changed_pool_queries "$scratch/changed-pool-queries.json" \
    --slurpfile changed_fingerprint_queries "$scratch/changed-fingerprint-queries.json" \
    --slurpfile commands "$scratch/commands.json" \
    --slurpfile assertions "$scratch/assertions.json" '
        {
            schema: $schema,
            issue: $issue,
            mode: $mode,
            status: "passed",
            started_at: $started_at,
            finished_at: $finished_at,
            candidate: {sha: $candidate_sha, tree: $candidate_tree},
            inputs: {
                plan: {path: $plan_path, raw_sha256: $plan_raw_sha256, normalized_sha256: $plan_sha256},
                guest_fixture: {path: $guest_fixture_path, sha256: $guest_fixture_sha256},
                host_fixture: {path: $host_fixture_path, sha256: $host_fixture_sha256}
            },
            discovery: {
                attempt_id: $discovery_attempt,
                network: $network,
                topology: $topology[0],
                nodes: ($topology[0].construction.nodes | map_values(.instance))
            },
            preserved_reference: {
                remote: $incus_remote,
                project: $incus_project,
                pool_name: $storage_pool,
                image_fingerprint: $image_fingerprint
            },
            native_resources_before_and_after: $native[0],
            scenarios: {
                matching: $matching[0],
                changed_project: $changed_project[0],
                changed_pool: $changed_pool[0],
                changed_fingerprint: $changed_fingerprint[0]
            },
            production_batch_queries: {
                matching: $matching_queries[0],
                changed_project: $changed_project_queries[0],
                changed_pool: $changed_pool_queries[0],
                changed_fingerprint: $changed_fingerprint_queries[0]
            },
            commands: $commands[0],
            assertions: $assertions[0]
        }
    ' > "$scratch/receipt.json"
mv "$scratch/receipt.json" "$receipt"
chmod 0600 "$receipt"

echo "ORB-171 $mode host rehearsal passed"
echo "receipt: $receipt"
echo "discovery retained: $discovery_attempt"
