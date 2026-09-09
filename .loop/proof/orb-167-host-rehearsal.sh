#!/usr/bin/env bash
set -euo pipefail

issue=ORB-167
mode=${1:-authoritative}
incus_remote=${ORBIT_E2E_INCUS_REMOTE:-local}
incus_project=${ORBIT_E2E_INCUS_PROJECT:-default}

if [[ "$mode" != diagnostic && "$mode" != authoritative ]]; then
    echo "usage: bash .loop/proof/orb-167-host-rehearsal.sh [diagnostic|authoritative]" >&2
    exit 64
fi

worktree=$(git rev-parse --show-toplevel)
cd "$worktree"

plan=.loop/proof/ORB-167.json
guest_fixture=.loop/proof/lease-target-contract.php
host_fixture=.loop/proof/orb-167-host-rehearsal.sh
state_dir=.e2e
receipt="$state_dir/orb-167-host-rehearsal-$mode.json"
logs_dir="$state_dir/orb-167-host-rehearsal-$mode-logs"
scratch=$(mktemp -d "${TMPDIR:-/tmp}/orb-167-host-rehearsal.XXXXXX")
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
    echo "ORB-167 host rehearsal failed: $*" >&2
    exit 1
}

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

assert_last_command_contains() {
    local label=$1
    local expected=$2

    if ! grep -Fq -- "$expected" "$last_stdout" && ! grep -Fq -- "$expected" "$last_stderr"; then
        cat "$last_stdout" >&2
        cat "$last_stderr" >&2
        fail "assertion [$label] did not find [$expected]"
    fi
    record_assertion "$label" "found exact command output text: $expected"
}

assert_same_file() {
    local label=$1
    local expected=$2
    local actual=$3

    cmp -s "$expected" "$actual" || fail "assertion [$label] found changed bytes"
    record_assertion "$label" "byte-identical sha256=$(sha256sum "$actual" | cut -d ' ' -f 1)"
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
            printf 'proof-ref  %s\n' "$(git rev-parse "refs/orbit/e2e-proof/orb-167/$proof_attempt" 2>/dev/null || true)"
        fi
    } > "$destination"
}

attempt_network() {
    printf 'oe-%s' "$(printf '%s' "$issue:$1" | sha256sum | cut -c 1-12)"
}

attempt_names_json() {
    local short=${1:0:8}

    jq -cn \
        --arg prefix "orbit-e2e-orb-167-$short-" \
        '["gateway", "app-dev", "app-prod", "app-prod-2"] | map($prefix + .)'
}

capture_target() {
    local attempt=$1
    local label=$2
    local destination=$3
    local instances_json="$scratch/$label-all-instances.json"
    local networks_json="$scratch/$label-all-networks.json"
    local names network

    names=$(attempt_names_json "$attempt")
    network=$(attempt_network "$attempt")
    run_recorded "$label-instances" 0 incus --project "$incus_project" list "$incus_remote:" --format=json
    cp "$last_stdout" "$instances_json"
    run_recorded "$label-networks" 0 incus --project "$incus_project" network list "$incus_remote:" --format=json
    cp "$last_stdout" "$networks_json"

    jq -n \
        --arg issue "$issue" \
        --arg attempt "$attempt" \
        --arg network "$network" \
        --argjson names "$names" \
        --slurpfile instances "$instances_json" \
        --slurpfile networks "$networks_json" \
        '{
            issue: $issue,
            attempt_id: $attempt,
            extension: "app-prod",
            expected_instances: ($names | sort),
            expected_network: $network,
            instances: [
                $instances[0][]
                | select(.name as $name | $names | index($name))
                | {
                    name,
                    type,
                    status,
                    owner: .config["user.orbit.e2e.owner"],
                    issue: .config["user.orbit.e2e.issue"],
                    attempt: .config["user.orbit.e2e.attempt"],
                    network: (.devices.eth0.network // .expanded_devices.eth0.network)
                }
            ] | sort_by(.name),
            networks: [
                $networks[0][]
                | select(.name == $network)
                | {
                    name,
                    owner: .config["user.orbit.e2e.owner"],
                    issue: .config["user.orbit.e2e.issue"],
                    attempt: .config["user.orbit.e2e.attempt"],
                    used_by: (.used_by | sort)
                }
            ] | sort_by(.name)
        }' > "$destination"
}

assert_target_present() {
    local label=$1
    local snapshot=$2

    jq -e '
        (.instances | length) == 4
        and ([.instances[].name] == .expected_instances)
        and all(.instances[];
            .type == "virtual-machine"
            and .owner == "orbit-e2e"
            and .issue == $issue
            and .attempt == $attempt
            and .network == $network
        )
        and (.networks | length) == 1
        and .networks[0].name == .expected_network
        and .networks[0].owner == "orbit-e2e"
        and .networks[0].issue == .issue
        and .networks[0].attempt == .attempt_id
    ' \
        --arg issue "$issue" \
        --arg attempt "$(jq -r '.attempt_id' "$snapshot")" \
        --arg network "$(jq -r '.expected_network' "$snapshot")" \
        "$snapshot" >/dev/null \
        || fail "assertion [$label] did not find the exact owned four-VM target"
    record_assertion "$label" "exact four VMs and network present; snapshot sha256=$(sha256sum "$snapshot" | cut -d ' ' -f 1)"
}

assert_target_absent() {
    local label=$1
    local snapshot=$2

    jq -e '(.instances | length) == 0 and (.networks | length) == 0' "$snapshot" >/dev/null \
        || fail "assertion [$label] found a retained target resource"
    record_assertion "$label" "all four exact VMs and network absent"
}

acquire_discovery() {
    local label=$1

    run_recorded "$label" 0 bin/e2e-topology acquire "$issue" "$worktree"
    [[ -f "$state_dir/attempt.json" && -f "$state_dir/topology.json" ]] \
        || fail "acquisition did not write the discovery lease and topology"
    jq -e \
        --arg issue "$issue" \
        '.issue == $issue and .purpose == "discovery" and .extension == "app-prod"' \
        "$state_dir/attempt.json" >/dev/null \
        || fail "acquisition did not persist the extended target in its initial lease"
    record_assertion "$label-lease" "discovery lease contains extension=app-prod and a complete topology record"
}

candidate_sha=$(git rev-parse HEAD)
candidate_tree=$(git rev-parse 'HEAD^{tree}')
plan_raw_sha=$(sha256sum "$plan" | cut -d ' ' -f 1)
plan_sha=$(normalized_plan_sha)
guest_fixture_sha=$(sha256sum "$guest_fixture" | cut -d ' ' -f 1)
host_fixture_sha=$(sha256sum "$host_fixture" | cut -d ' ' -f 1)
started_at=$(date -u +%Y-%m-%dT%H:%M:%SZ)

[[ -z "$(git status --porcelain)" ]] || fail 'the candidate worktree must be clean'
jq -e '.extension == "app-prod" and .mutates == true and .observed_inputs == false' "$plan" >/dev/null \
    || fail 'the current proof plan does not declare the required host boundary'
record_assertion candidate-clean "HEAD=$candidate_sha tree=$candidate_tree"
record_assertion incus-scope "remote=$incus_remote project=$incus_project"
record_assertion fixture-bindings \
    "plan_raw=$plan_raw_sha plan_normalized=$plan_sha guest=$guest_fixture_sha host=$host_fixture_sha"

if [[ "$mode" == authoritative ]]; then
    [[ -f "$state_dir/proof.json" && -f "$state_dir/proof-attempt.json" && -f "$state_dir/proof-topology.json" ]] \
        || fail 'authoritative mode requires retained proof state'
    proof_attempt=$(jq -er '.attempt_id' "$state_dir/proof.json")
    jq -e \
        --arg candidate "$candidate_sha" \
        --arg plan "$plan_sha" \
        --arg attempt "$proof_attempt" \
        '.status == "proved"
            and .candidate_sha == $candidate
            and .plan_sha256 == $plan
            and .attempt_id == $attempt
            and (.actions | length) == 1
            and .actions[0] == {id:"lease-target-contract",node:"gateway",exit_code:0}' \
        "$state_dir/proof.json" >/dev/null \
        || fail 'retained proof does not bind the exact candidate, plan, action, and zero exit'
    jq -e \
        --arg attempt "$proof_attempt" \
        '.issue == "ORB-167" and .purpose == "proof" and .attempt_id == $attempt and .extension == "app-prod"' \
        "$state_dir/proof-attempt.json" >/dev/null \
        || fail 'retained proof lease does not bind the exact extended proof attempt'
    jq -e \
        --arg attempt "$proof_attempt" \
        '.purpose == "proof" and .construction.issue == "ORB-167"
            and .construction.attempt_id == $attempt and .construction.extension == "app-prod"' \
        "$state_dir/proof-topology.json" >/dev/null \
        || fail 'retained proof topology does not bind the exact extended proof attempt'
    [[ "$(git rev-parse "refs/orbit/e2e-proof/orb-167/$proof_attempt")" == "$candidate_sha" ]] \
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
            || $manifest->construction->target->issue !== "ORB-167"
            || $manifest->construction->target->requireAttempt()->value !== $argv[5]
            || $manifest->construction->extension?->value !== "app-prod"
        ) {
            exit(1);
        }
        echo "manifest binding valid\n";
    ' "$manifest_path" "$manifest_sha" "$candidate_sha" "$plan" "$proof_attempt"
    plan_blob=$(git hash-object "$plan")
    guest_fixture_blob=$(git hash-object "$guest_fixture")
    host_fixture_blob=$(git hash-object "$host_fixture")
    jq -e \
        --arg plan "$plan" \
        --arg plan_blob "$plan_blob" \
        --arg guest "$guest_fixture" \
        --arg guest_blob "$guest_fixture_blob" \
        --arg host "$host_fixture" \
        --arg host_blob "$host_fixture_blob" \
        'any(.static_inputs[]; .path == $plan and .classification == "proof-contract" and .blob == $plan_blob)
            and any(.static_inputs[]; .path == $guest and .classification == "proof-contract" and .blob == $guest_blob)
            and any(.static_inputs[]; .path == $host and .classification == "proof-contract" and .blob == $host_blob)' \
        "$manifest_path" >/dev/null \
        || fail 'retained proof manifest does not bind every current proof fixture blob'
    record_assertion authoritative-proof-binding \
        "proof attempt=$proof_attempt candidate=$candidate_sha plan=$plan_sha manifest=$manifest_sha action exit=0"
    record_assertion authoritative-fixture-blobs \
        "plan=$plan_blob guest=$guest_fixture_blob host=$host_fixture_blob"
fi

proof_evidence_manifest "$scratch/proof-before"

if [[ -f "$state_dir/attempt.json" || -f "$state_dir/topology.json" ]]; then
    [[ -f "$state_dir/attempt.json" && -f "$state_dir/topology.json" ]] \
        || fail 'the initial discovery state is incomplete'
    jq -e '.purpose == "discovery" and .extension == "app-prod"' "$state_dir/attempt.json" >/dev/null \
        || fail 'the initial discovery lease is not a current extended lease'
    record_assertion initial-discovery "reused the complete current extended discovery"
else
    acquire_discovery acquire-current-lease-target
fi

lease_only_attempt=$(jq -er '.attempt_id' "$state_dir/attempt.json")
capture_target "$lease_only_attempt" lease-only-before "$scratch/lease-only-before.json"
assert_target_present lease-only-before "$scratch/lease-only-before.json"
rm "$state_dir/topology.json"
record_assertion lease-only-fixture 'removed only topology.json to simulate interruption after resource construction'
run_recorded release-current-lease-only 0 bin/e2e-topology release "$issue" --worktree="$worktree"
capture_target "$lease_only_attempt" lease-only-after "$scratch/lease-only-after.json"
assert_target_absent lease-only-after "$scratch/lease-only-after.json"
[[ ! -e "$state_dir/attempt.json" && ! -e "$state_dir/topology.json" ]] \
    || fail 'current lease-only release retained discovery state'
record_assertion lease-only-state-cleanup "attempt=$lease_only_attempt lease and topology absent"

acquire_discovery acquire-legacy-recovery-target
legacy_attempt=$(jq -er '.attempt_id' "$state_dir/attempt.json")
capture_target "$legacy_attempt" legacy-before "$scratch/legacy-before.json"
assert_target_present legacy-before "$scratch/legacy-before.json"
rm "$state_dir/topology.json"
jq 'del(.extension)' "$state_dir/attempt.json" > "$scratch/legacy-attempt.json"
chmod 0600 "$scratch/legacy-attempt.json"
mv "$scratch/legacy-attempt.json" "$state_dir/attempt.json"
cp "$state_dir/attempt.json" "$scratch/legacy-lease-before.json"
record_assertion legacy-fixture 'removed topology.json and only the extension key from the lease'

run_recorded refuse-ambiguous-legacy 1 bin/e2e-topology release "$issue" --worktree="$worktree"
assert_last_command_contains ambiguous-message 'extension target is ambiguous'
assert_same_file ambiguous-lease-unchanged "$scratch/legacy-lease-before.json" "$state_dir/attempt.json"
capture_target "$legacy_attempt" ambiguous-after "$scratch/ambiguous-after.json"
assert_same_file ambiguous-resources-unchanged "$scratch/legacy-before.json" "$scratch/ambiguous-after.json"

run_recorded refuse-conflicting-none 1 \
    bin/e2e-topology release "$issue" --worktree="$worktree" \
    --recover-extension=none --expected-attempt="$legacy_attempt"
assert_last_command_contains conflicting-none-message 'conflicts with the exact app-prod-2 VM'
assert_same_file conflicting-none-lease-unchanged "$scratch/legacy-lease-before.json" "$state_dir/attempt.json"
capture_target "$legacy_attempt" conflicting-none-after "$scratch/conflicting-none-after.json"
assert_same_file conflicting-none-resources-unchanged "$scratch/legacy-before.json" "$scratch/conflicting-none-after.json"

run_recorded recover-matching-app-prod 0 \
    bin/e2e-topology release "$issue" --worktree="$worktree" \
    --recover-extension=app-prod --expected-attempt="$legacy_attempt"
capture_target "$legacy_attempt" recovered-after "$scratch/recovered-after.json"
assert_target_absent recovered-after "$scratch/recovered-after.json"
[[ ! -e "$state_dir/attempt.json" && ! -e "$state_dir/topology.json" ]] \
    || fail 'matching recovery retained discovery state'
record_assertion recovered-state-cleanup "attempt=$legacy_attempt lease and topology absent"

acquire_discovery acquire-fresh-closeout-discovery
fresh_attempt=$(jq -er '.attempt_id' "$state_dir/attempt.json")
capture_target "$fresh_attempt" fresh-discovery "$scratch/fresh-discovery.json"
assert_target_present fresh-discovery "$scratch/fresh-discovery.json"

proof_evidence_manifest "$scratch/proof-after"
assert_same_file retained-proof-unchanged "$scratch/proof-before" "$scratch/proof-after"

finished_at=$(date -u +%Y-%m-%dT%H:%M:%SZ)
jq -s '.' "$commands" > "$scratch/commands.json"
jq -s '.' "$assertions" > "$scratch/assertions.json"
jq -n \
    --argjson schema 1 \
    --arg issue "$issue" \
    --arg mode "$mode" \
    --arg started_at "$started_at" \
    --arg finished_at "$finished_at" \
    --arg candidate_sha "$candidate_sha" \
    --arg candidate_tree "$candidate_tree" \
    --arg incus_remote "$incus_remote" \
    --arg incus_project "$incus_project" \
    --arg plan_path "$plan" \
    --arg plan_raw_sha256 "$plan_raw_sha" \
    --arg plan_sha256 "$plan_sha" \
    --arg guest_fixture_path "$guest_fixture" \
    --arg guest_fixture_sha256 "$guest_fixture_sha" \
    --arg host_fixture_path "$host_fixture" \
    --arg host_fixture_sha256 "$host_fixture_sha" \
    --arg lease_only_attempt "$lease_only_attempt" \
    --arg legacy_attempt "$legacy_attempt" \
    --arg fresh_attempt "$fresh_attempt" \
    --slurpfile lease_only "$scratch/lease-only-before.json" \
    --slurpfile legacy "$scratch/legacy-before.json" \
    --slurpfile fresh "$scratch/fresh-discovery.json" \
    --slurpfile commands "$scratch/commands.json" \
    --slurpfile assertions "$scratch/assertions.json" \
    '{
        schema: $schema,
        issue: $issue,
        mode: $mode,
        status: "passed",
        started_at: $started_at,
        finished_at: $finished_at,
        candidate: {sha: $candidate_sha, tree: $candidate_tree},
        incus_scope: {remote: $incus_remote, project: $incus_project},
        inputs: {
            plan: {path: $plan_path, raw_sha256: $plan_raw_sha256, normalized_sha256: $plan_sha256},
            guest_fixture: {path: $guest_fixture_path, sha256: $guest_fixture_sha256},
            host_fixture: {path: $host_fixture_path, sha256: $host_fixture_sha256}
        },
        scenarios: {
            current_lease_only_release: $lease_only[0],
            ambiguous_and_recovered_legacy: $legacy[0],
            retained_discovery: $fresh[0]
        },
        attempts: {
            current_lease_only: $lease_only_attempt,
            legacy_recovery: $legacy_attempt,
            retained_discovery: $fresh_attempt
        },
        commands: $commands[0],
        assertions: $assertions[0]
    }' > "$scratch/receipt.json"
mv "$scratch/receipt.json" "$receipt"
chmod 0600 "$receipt"

echo "ORB-167 $mode host rehearsal passed"
echo "receipt: $receipt"
echo "fresh discovery: $fresh_attempt"
