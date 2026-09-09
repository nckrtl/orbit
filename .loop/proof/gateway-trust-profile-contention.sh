#!/usr/bin/env bash
set -euo pipefail

scenario=${1:-}
repository=/home/orbit/orbit
config=/home/orbit/.orbit/config.json
fixture_root=/var/lib/orbit-e2e/proof

if [[ ! -f "$fixture_root/gateway-profile-mutation.php" ]]; then
    fixture_root="$repository/.loop/proof"
fi

mutator="$fixture_root/gateway-profile-mutation.php"
ssh_key=/home/orbit/.orbit/ssh/id_ed25519
known_hosts=/home/orbit/.orbit/ssh/known_hosts

assert_status() {
    python3 -c '
import json
import sys

value = json.load(sys.stdin)
request_id = value.get("request_id")
if value.get("gateway") != "e2e" or value.get("status") != "ok":
    raise SystemExit(65)
if not isinstance(request_id, str) or not request_id:
    raise SystemExit(65)
'
}

probe_surfaces() {
    orbit gateway:status --json | assert_status
    ssh \
        -i "$ssh_key" \
        -o BatchMode=yes \
        -o IdentitiesOnly=yes \
        -o StrictHostKeyChecking=yes \
        -o "UserKnownHostsFile=$known_hosts" \
        orbit@10.44.0.1 \
        'env HOME=/home/orbit ORBIT_HOME=/home/orbit/.orbit DB_DATABASE=/home/orbit/.orbit/gateway.sqlite /usr/local/bin/orbit gateway:status --json' \
        | assert_status
}

if [[ "$scenario" == probe ]]; then
    probe_surfaces
    printf 'gateway-trust observation surfaces ready\n'
    exit 0
fi

[[ "$scenario" == contention ]]
[[ -s "$config" && -f "$mutator" ]]

label_hash=$(printf 'e2e' | sha256sum | cut -c1-16)
system_target="/usr/local/share/ca-certificates/orbit-gateway-ca-$label_hash.crt"
profile_pin=$(python3 - "$config" <<'PY'
import json
import sys

value = json.load(open(sys.argv[1]))
pin = value.get("gateways", {}).get("e2e", {}).get("ca_path")
if not isinstance(pin, str) or not pin.startswith("/"):
    raise SystemExit(65)
print(pin)
PY
)
original_config=$(mktemp)
original_target=$(mktemp)
wrapper_directory=$(mktemp -d)
cp -- "$config" "$original_config"
cp -- "$system_target" "$original_target"

cleanup() {
    /usr/bin/install -m 0600 -- "$original_config" "$config"
    /usr/bin/sudo install -m 0644 -- "$original_target" "$system_target"
    /usr/bin/sudo update-ca-certificates >/dev/null
    rm -rf -- "$wrapper_directory"
    rm -f -- "$original_config" "$original_target"
}
trap cleanup EXIT

cat > "$wrapper_directory/sudo" <<'WRAPPER'
#!/usr/bin/env bash
set -euo pipefail

/usr/bin/sudo "$@"

if [[ "$#" -eq 1 && "$1" == update-ca-certificates && ! -e "$ORB169_MARKER" ]]; then
    : > "$ORB169_MARKER"
    /usr/bin/php8.5 "$ORB169_MUTATOR" "$ORB169_MODE" "$ORB169_TARGET_PIN"
fi
WRAPPER
chmod 0755 "$wrapper_directory/sudo"

reset_case() {
    /usr/bin/install -m 0600 -- "$original_config" "$config"
    /usr/bin/sudo rm -f -- "$system_target"
    /usr/bin/sudo update-ca-certificates >/dev/null
    [[ ! -e "$system_target" ]]
}

assert_result() {
    local mode=$1 status=$2 output=$3 target_pin=$4
    python3 - "$mode" "$status" "$output" "$target_pin" <<'PY'
import json
import re
import sys

mode, status, raw, target_pin = sys.argv[1:]
value = json.loads(raw)
request_id = value.get("request_id") if mode in ("active", "identical") else value.get("error", {}).get("request_id")
if not isinstance(request_id, str) or re.fullmatch(r"[0-9a-f-]{36}", request_id, re.I) is None:
    raise SystemExit(65)
if "replacement-secret" in raw or "BEGIN CERTIFICATE" in raw:
    raise SystemExit(65)

if mode in ("url", "pin"):
    error = value.get("error")
    valid = (
        status != "0"
        and isinstance(error, dict)
        and error.get("code") == "gateway.ca_profile_update_failed"
        and error.get("message") == "The root CA was trusted, but the gateway profile could not be updated."
    )
else:
    valid = (
        status == "0"
        and value.get("gateway") == "e2e"
        and value.get("status") == "trusted"
        and value.get("ca_path") == target_pin
        and isinstance(value.get("sha256"), str)
        and len(value["sha256"]) == 64
    )
if not valid:
    raise SystemExit(65)
PY
}

assert_profile() {
    local mode=$1 target_pin=$2
    python3 - "$config" "$mode" "$target_pin" <<'PY'
import json
import sys

path, mode, target_pin = sys.argv[1:]
value = json.load(open(path))
profile = value.get("gateways", {}).get("e2e")
if not isinstance(profile, dict):
    raise SystemExit(65)

expected_url = "https://orb169-replacement-secret.example" if mode == "url" else "https://10.44.0.1"
expected_pin = "/tmp/orb169-replacement-secret-pin.pem" if mode == "pin" else target_pin
expected_active = "orb169-other" if mode == "active" else "e2e"
if profile != {"url": expected_url, "ca_path": expected_pin}:
    raise SystemExit(65)
if value.get("active_gateway") != expected_active:
    raise SystemExit(65)
PY
}

assert_recovery() {
    local output=$1 target_pin=$2
    python3 - "$output" "$target_pin" <<'PY'
import json
import re
import sys

value = json.loads(sys.argv[1])
valid = (
    value.get("gateway") == "e2e"
    and value.get("status") == "already_trusted"
    and value.get("ca_path") == sys.argv[2]
    and isinstance(value.get("sha256"), str)
    and len(value["sha256"]) == 64
    and isinstance(value.get("request_id"), str)
    and re.fullmatch(r"[0-9a-f-]{36}", value["request_id"], re.I) is not None
)
if not valid:
    raise SystemExit(65)
PY
}

run_case() {
    local mode=$1 marker output status recovery
    reset_case

    if [[ "$mode" == active ]]; then
        /usr/bin/php8.5 "$mutator" prepare-active "$profile_pin"
    fi

    marker="$wrapper_directory/$mode.applied"
    set +e
    output=$(
        env \
            "PATH=$wrapper_directory:$PATH" \
            "ORB169_MARKER=$marker" \
            "ORB169_MODE=$mode" \
            "ORB169_MUTATOR=$mutator" \
            "ORB169_TARGET_PIN=$profile_pin" \
            orbit gateway:trust --json 2>&1
    )
    status=$?
    set -e

    [[ -e "$marker" ]]
    assert_result "$mode" "$status" "$output" "$profile_pin"
    assert_profile "$mode" "$profile_pin"
    cmp -- "$original_target" "$system_target"
    openssl verify -CAfile /etc/ssl/certs/ca-certificates.crt "$system_target" >/dev/null

    if [[ "$mode" == url || "$mode" == pin ]]; then
        /usr/bin/install -m 0600 -- "$original_config" "$config"
        recovery=$(orbit gateway:trust --json)
        assert_recovery "$recovery" "$profile_pin"
    fi

    printf 'gateway-trust %s contention case passed\n' "$mode"
}

run_case url
run_case pin
run_case active
run_case identical
probe_surfaces
