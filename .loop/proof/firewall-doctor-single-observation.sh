#!/usr/bin/env bash
set -euo pipefail

scenario=${1:-}
repository=/home/orbit/orbit
gateway="$repository/apps/gateway"
fixture=/var/lib/orbit-e2e/proof/firewall-doctor-single-observation.php
ssh_key=/home/orbit/.orbit/ssh/id_ed25519
known_hosts=/home/orbit/.orbit/ssh/known_hosts
wrapper=/usr/local/sbin/ufw
mode_file=/var/lib/orbit-e2e/orb174-ufw-mode
log_file=/var/lib/orbit-e2e/orb174-ufw.log

if [[ ! -f "$fixture" ]]; then
    fixture="$repository/.loop/proof/firewall-doctor-single-observation.php"
fi

gateway_fixture() {
    (
        cd "$gateway"
        php "$fixture" "$@"
    )
}

json_field() {
    php -r '
        $value=json_decode(stream_get_contents(STDIN), true, 32, JSON_THROW_ON_ERROR);
        foreach(explode(".", $argv[1]) as $part) {
            if(!is_array($value) || !array_key_exists($part, $value)) exit(65);
            $value=$value[$part];
        }
        if(is_bool($value)) echo $value ? "true" : "false";
        elseif(is_int($value) || is_string($value)) echo $value;
        elseif($value===null) echo "null";
        else exit(65);
    ' "$1"
}

remote_command() {
    local node_ip=$1
    shift
    ssh \
        -i "$ssh_key" \
        -o BatchMode=yes \
        -o IdentitiesOnly=yes \
        -o StrictHostKeyChecking=yes \
        -o "UserKnownHostsFile=$known_hosts" \
        -- "orbit@$node_ip" \
        "$@"
}

remote_script() {
    local node_ip=$1
    shift
    remote_command "$node_ip" bash -seu -- "$@"
}

remove_comment() {
    local node_ip=$1
    local comment=$2
    remote_script "$node_ip" "$comment" <<'REMOTE'
comment=$1
while :; do
    number=$(sudo /usr/sbin/ufw status numbered | awk -v comment="$comment" '
        substr($0, length($0) - length(comment) + 1) == comment {
            value=$0
            sub(/^\[[[:space:]]*/, "", value)
            sub(/\].*$/, "", value)
            print value
        }
    ' | tail -n 1)
    [[ -n "$number" ]] || break
    sudo /usr/sbin/ufw --force delete "$number" >/dev/null
done
REMOTE
}

add_persisted_rule() {
    local node_ip=$1
    local comment=$2
    local port=$3
    remote_command "$node_ip" sudo /usr/sbin/ufw allow in proto tcp from any to any port "$port" comment "$comment" >/dev/null
}

restore_exporter_rule() {
    local node_ip=$1
    remove_comment "$node_ip" orbit:metrics-node-exporter
    remote_command "$node_ip" sudo /usr/sbin/ufw allow in on orbit proto tcp \
        from "$node_ip" to "$node_ip" port 9100 comment orbit:metrics-node-exporter >/dev/null
}

install_wrapper() {
    local node_ip=$1
    remote_script "$node_ip" "$wrapper" "$mode_file" "$log_file" <<'REMOTE'
wrapper=$1
mode_file=$2
log_file=$3
if sudo test -e "$wrapper" && ! sudo grep -Fq 'ORB-174 observation fixture' "$wrapper"; then
    exit 65
fi
temporary=$(mktemp)
cat > "$temporary" <<'WRAPPER'
#!/usr/bin/env bash
# ORB-174 observation fixture
set -euo pipefail
mode_file=/var/lib/orbit-e2e/orb174-ufw-mode
log_file=/var/lib/orbit-e2e/orb174-ufw.log
if [[ "$*" != 'status numbered' ]]; then
    exec /usr/sbin/ufw "$@"
fi
printf '%s\n' "$*" >> "$log_file"
mode=$(cat "$mode_file")
case "$mode" in
    normal)
        exec /usr/sbin/ufw "$@"
        ;;
    malformed)
        printf 'credential=orb174-secret-malformed\n'
        ;;
    truncated)
        head -c 70000 /dev/zero | tr '\0' x
        printf '\nStatus: active\ncredential=orb174-secret-truncated\n'
        ;;
    failure)
        printf 'credential=orb174-secret-failure\n' >&2
        exit 71
        ;;
    deadline)
        sleep 35
        printf 'Status: active\ncredential=orb174-secret-deadline\n'
        ;;
    *)
        exit 64
        ;;
esac
WRAPPER
sudo install -o root -g root -m 0755 "$temporary" "$wrapper"
rm -f -- "$temporary"
printf 'normal\n' | sudo tee "$mode_file" >/dev/null
sudo chmod 0644 "$mode_file"
sudo touch "$log_file"
sudo chmod 0644 "$log_file"
REMOTE
}

set_mode() {
    local node_ip=$1
    local mode=$2
    remote_script "$node_ip" "$mode" "$mode_file" <<'REMOTE'
mode=$1
mode_file=$2
printf '%s\n' "$mode" | sudo tee "$mode_file" >/dev/null
REMOTE
}

reset_observations() {
    local node_ip=$1
    remote_script "$node_ip" "$log_file" <<'REMOTE'
log_file=$1
sudo sh -c ': > "$1"' sh "$log_file"
REMOTE
}

observation_count() {
    local node_ip=$1
    remote_script "$node_ip" "$log_file" <<'REMOTE'
log_file=$1
sudo awk '$0 == "status numbered" { count++ } END { print count + 0 }' "$log_file"
REMOTE
}

run_doctor() {
    local node_ip=$1
    local node_id=$2
    local stderr_file=/tmp/orb174-doctor-stderr
    set +e
    doctor_output=$(remote_command "$node_ip" orbit doctor --node="$node_id" --family=firewall --json 2>"$stderr_file")
    doctor_status=$?
    set -e
    if grep -Fq orb174-secret "$stderr_file"; then
        exit 65
    fi
}

assert_report() {
    local output=$1
    local command_status=$2
    local node_id=$3
    local healthy=$4
    local family_status=$5
    local checked=$6
    local expected_codes=$7
    local expected_ids=$8
    php -r '
        $value=json_decode($argv[1], true, 64, JSON_THROW_ON_ERROR);
        $node=$value["nodes"][0] ?? null;
        $family=$node["families"][0] ?? null;
        $healthy=$argv[4]==="true";
        $codes=$argv[7]==="" ? [] : explode(",", $argv[7]);
        $ids=json_decode($argv[8], true, 16, JSON_THROW_ON_ERROR);
        $issues=$family["issues"] ?? null;
        if(!is_array($node) || count($value["nodes"] ?? [])!==1 || ($node["node_id"] ?? null)!==(int)$argv[3] || count($node["families"] ?? [])!==1 || !is_array($family) || ($family["family"] ?? null)!=="firewall" || ($family["status"] ?? null)!==$argv[5] || ($family["checked"] ?? null)!==(int)$argv[6] || ($value["healthy"] ?? null)!==$healthy || !is_array($issues) || array_column($issues,"code")!==$codes || array_column($issues,"resource_id")!==$ids || !is_string($value["request_id"] ?? null) || $value["request_id"]==="") exit(65);
        $expectedStatus=$healthy ? 0 : 1;
        if((int)$argv[2]!==$expectedStatus) exit(65);
    ' "$output" "$command_status" "$node_id" "$healthy" "$family_status" "$checked" "$expected_codes" "$expected_ids"
    [[ "$output" != *orb174-secret* ]]
}

assert_one_new_observation() {
    local node_ip=$1
    local before=$2
    local after
    after=$(observation_count "$node_ip")
    [[ "$after" -eq $((before + 1)) ]]
}

cleanup() {
    set +e
    set_mode "$node_ip" normal
    restore_exporter_rule "$node_ip"
    remove_comment "$node_ip" "$first_comment"
    remove_comment "$node_ip" "$second_comment"
    gateway_fixture cleanup >/dev/null
    remote_command "$node_ip" sudo rm -f -- "$wrapper" "$mode_file" "$log_file"
}

case "$scenario" in
    setup)
        [[ $# -eq 1 && "$(id -u)" -eq 1000 ]]
        (cd "$gateway" && php artisan migrate --force --no-interaction >/dev/null)
        state=$(gateway_fixture setup)
        node_id=$(json_field node.id <<<"$state")
        node_ip=$(json_field node.wireguard_ip <<<"$state")
        first_comment=$(json_field rules.orb174-first.comment <<<"$state")
        second_comment=$(json_field rules.orb174-second.comment <<<"$state")
        first_port=$(json_field rules.orb174-first.port <<<"$state")
        second_port=$(json_field rules.orb174-second.port <<<"$state")
        [[ "$node_id" =~ ^[1-9][0-9]*$ && "$node_ip" == 10.44.0.2 ]]
        php -r '$v=json_decode($argv[1],true,32,JSON_THROW_ON_ERROR); if(($v["synthetic"]??null)!==["orbit:metrics-node-exporter","orbit:metrics-grafana-upstream"]) exit(65);' "$state"
        remove_comment "$node_ip" "$first_comment"
        remove_comment "$node_ip" "$second_comment"
        add_persisted_rule "$node_ip" "$first_comment" "$first_port"
        add_persisted_rule "$node_ip" "$second_comment" "$second_port"
        install_wrapper "$node_ip"
        reset_observations "$node_ip"
        before=$(observation_count "$node_ip")
        run_doctor "$node_ip" "$node_id"
        assert_report "$doctor_output" "$doctor_status" "$node_id" true healthy 2 '' '[]'
        assert_one_new_observation "$node_ip" "$before"
        printf 'healthy stored and synthetic baseline used one UFW observation\n'
        ;;

    acceptance)
        [[ $# -eq 1 && "$(id -u)" -eq 1000 ]]
        state=$(gateway_fixture state)
        node_id=$(json_field node.id <<<"$state")
        node_ip=$(json_field node.wireguard_ip <<<"$state")
        first_id=$(json_field rules.orb174-first.id <<<"$state")
        second_id=$(json_field rules.orb174-second.id <<<"$state")
        first_comment=$(json_field rules.orb174-first.comment <<<"$state")
        second_comment=$(json_field rules.orb174-second.comment <<<"$state")
        first_port=$(json_field rules.orb174-first.port <<<"$state")
        second_port=$(json_field rules.orb174-second.port <<<"$state")
        trap cleanup EXIT
        reset_observations "$node_ip"

        lifecycle=$(gateway_fixture add-lifecycle)
        lifecycle_id=$(json_field rules.orb174-lifecycle.id <<<"$lifecycle")
        remove_comment "$node_ip" "$first_comment"
        remove_comment "$node_ip" orbit:metrics-node-exporter
        before=$(observation_count "$node_ip")
        run_doctor "$node_ip" "$node_id"
        assert_report \
            "$doctor_output" "$doctor_status" "$node_id" false drift 3 \
            'firewall.rule_missing,firewall.lifecycle_not_active,firewall.rule_missing' \
            "[$first_id,$lifecycle_id,\"orbit:metrics-node-exporter\"]"
        assert_one_new_observation "$node_ip" "$before"

        add_persisted_rule "$node_ip" "$first_comment" "$first_port"
        restore_exporter_rule "$node_ip"
        gateway_fixture remove-lifecycle >/dev/null
        before=$(observation_count "$node_ip")
        run_doctor "$node_ip" "$node_id"
        assert_report "$doctor_output" "$doctor_status" "$node_id" true healthy 2 '' '[]'
        assert_one_new_observation "$node_ip" "$before"

        failure_ids="[$first_id,$second_id,\"orbit:metrics-node-exporter\",\"orbit:metrics-grafana-upstream\"]"
        failure_codes='firewall.inspection_failed,firewall.inspection_failed,firewall.inspection_failed,firewall.inspection_failed'
        for mode in malformed truncated failure deadline; do
            set_mode "$node_ip" "$mode"
            before=$(observation_count "$node_ip")
            run_doctor "$node_ip" "$node_id"
            assert_report "$doctor_output" "$doctor_status" "$node_id" false unverifiable 2 "$failure_codes" "$failure_ids"
            assert_one_new_observation "$node_ip" "$before"
        done

        set_mode "$node_ip" normal
        before=$(observation_count "$node_ip")
        run_doctor "$node_ip" "$node_id"
        assert_report "$doctor_output" "$doctor_status" "$node_id" true healthy 2 '' '[]'
        assert_one_new_observation "$node_ip" "$before"
        [[ "$(observation_count "$node_ip")" -eq 7 ]]

        cleanup
        trap - EXIT
        printf 'single observations, ordering, lifecycle skips, failures, and fresh recovery passed\n'
        ;;

    *)
        exit 64
        ;;
esac
