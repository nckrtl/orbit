#!/usr/bin/env bash
set -euo pipefail
umask 077

scenario=${1:-}
repository=/home/orbit/orbit
gateway="$repository/apps/gateway"
fixture=/var/lib/orbit-e2e/proof/source-profile-checkpoint-drift.php
ssh_key=/home/orbit/.orbit/ssh/id_ed25519
known_hosts=/home/orbit/.orbit/ssh/known_hosts
app_dev_ip=10.44.0.2
source_state=/home/orbit/.orbit/orb168-source-profile
canonical_url=https://orb168-proof.orbit

if [ ! -f "$fixture" ]; then
    fixture="$repository/.loop/proof/source-profile-checkpoint-drift.php"
fi

gateway_fixture() {
    (
        cd "$gateway"
        php "$fixture" "$@"
    )
}

remote_command() {
    ssh \
        -i "$ssh_key" \
        -o BatchMode=yes \
        -o IdentitiesOnly=yes \
        -o StrictHostKeyChecking=yes \
        -o "UserKnownHostsFile=$known_hosts" \
        "orbit@$app_dev_ip" \
        "$@"
}

remote_script() {
    remote_command bash -seu -- "$@"
}

set_source_profile() {
    remote_script "$1" "$source_state" <<'BASH'
profile=$1
state=$2
checkout=$(cat "$state/checkout")
if [ "$profile" = plain ]; then
    printf '%s\n' '{"require":{"php":"^8.5"}}' > "$checkout/composer.json"
else
    install -m 0644 "$state/composer.json" "$checkout/composer.json"
fi
install -m 0755 "$state/artisan" "$checkout/artisan"
case "$profile" in
    laravel) ;;
    plain) rm -f -- "$checkout/artisan" ;;
    none) rm -f -- "$checkout/artisan" "$checkout/composer.json" ;;
    *) exit 64 ;;
esac
BASH
}

set_app_url() {
    remote_script "$1" "$source_state" <<'BASH'
value=$1
state=$2
checkout=$(cat "$state/checkout")
printf 'APP_URL=%s\n' "$value" > "$checkout/.env"
BASH
}

remove_app_url() {
    remote_script "$source_state" <<'BASH'
state=$1
checkout=$(cat "$state/checkout")
rm -f -- "$checkout/.env"
BASH
}

assert_app_url() {
    remote_script "$1" "$source_state" <<'BASH'
expected=$1
state=$2
checkout=$(cat "$state/checkout")
test "$(sed -n 's/^APP_URL=//p' "$checkout/.env")" = "$expected"
BASH
}

expect_create_failure() {
    local expected_code=$1
    shift
    local output status
    set +e
    output=$(remote_command orbit instance:new "$app_id" "$node_id" orb168-proof "--hostname=$hostname" --json "$@" 2>&1)
    status=$?
    set -e
    test "$status" -ne 0
    php -r '$v=json_decode($argv[1], true, 512, JSON_THROW_ON_ERROR); $e=$v["error"] ?? null; if(!is_array($e) || ($e["code"] ?? null)!==$argv[2] || !is_string($e["request_id"] ?? null) || $e["request_id"]==="") exit(65);' "$output" "$expected_code"
}

expect_create_success() {
    local output
    output=$(remote_command orbit instance:new "$app_id" "$node_id" orb168-proof "--hostname=$hostname" --json "$@")
    php -r '$v=json_decode($argv[1], true, 512, JSON_THROW_ON_ERROR); if(($v["id"] ?? null)!==(int)$argv[2] || ($v["status"] ?? null)!=="active" || ($v["hostname"] ?? null)!==$argv[3] || ($v["url"] ?? null)!==$argv[4] || ($v["route"]["id"] ?? null)!==(int)$argv[5] || !is_string($v["request_id"] ?? null)) exit(65);' "$output" "$instance_id" "$hostname" "$canonical_url" "$route_id"
}

load_identity() {
    local identity
    identity=$(gateway_fixture identity)
    read -r app_id node_id instance_id route_id checkout_path hostname < <(php -r '$v=json_decode(stream_get_contents(STDIN), true, 16, JSON_THROW_ON_ERROR); foreach(["app_id","node_id","instance_id","route_id","checkout_path","hostname"] as $k) if(!isset($v[$k])) exit(65); echo $v["app_id"], " ", $v["node_id"], " ", $v["instance_id"], " ", $v["route_id"], " ", $v["checkout_path"], " ", $v["hostname"], "\n";' <<<"$identity")
    test "$checkout_path" = "$(remote_command cat "$source_state/checkout")"
}

run_drift_scenarios() {
    for checkpoint in php-selected url-configured; do
        set_source_profile plain
        set_app_url "unchanged-laravel-to-plain-$checkpoint"
        gateway_fixture checkpoint "$checkpoint" 8.5 true
        expect_create_failure app-dev.source_evidence_changed
        gateway_fixture assert-refusal "$checkpoint" 8.5 true
        assert_app_url "unchanged-laravel-to-plain-$checkpoint"

        set_source_profile laravel
        set_app_url "unchanged-plain-to-laravel-$checkpoint"
        gateway_fixture checkpoint "$checkpoint" 8.5 false
        expect_create_failure app-dev.source_evidence_changed --recover-source-profile
        gateway_fixture assert-refusal "$checkpoint" 8.5 false
        assert_app_url "unchanged-plain-to-laravel-$checkpoint"

        set_source_profile none
        set_app_url "unchanged-php-to-none-$checkpoint"
        gateway_fixture checkpoint "$checkpoint" 8.5 false
        expect_create_failure app-dev.source_evidence_changed
        gateway_fixture assert-refusal "$checkpoint" 8.5 false
        assert_app_url "unchanged-php-to-none-$checkpoint"
    done
}

run_legacy_scenarios() {
    set_source_profile laravel
    for checkpoint in php-selected url-configured; do
        set_app_url "unchanged-legacy-$checkpoint"
        gateway_fixture checkpoint "$checkpoint" 8.5 legacy
        expect_create_failure app-dev.source_evidence_changed
        gateway_fixture assert-refusal "$checkpoint" 8.5 legacy
        assert_app_url "unchanged-legacy-$checkpoint"
    done
}

run_unchanged_retries() {
    set_source_profile laravel
    set_app_url before-complete-retry
    gateway_fixture checkpoint php-selected 8.5 true
    expect_create_success
    gateway_fixture assert-active 8.5 true
    assert_app_url "$canonical_url"

    gateway_fixture checkpoint url-configured 8.5 true
    expect_create_success
    gateway_fixture assert-active 8.5 true
    assert_app_url "$canonical_url"
}

run_recovery_scenario() {
    set_source_profile laravel
    remove_app_url
    gateway_fixture checkpoint url-configured 8.4 legacy
    gateway_fixture configure-url
    gateway_fixture assert-checkpoint url-configured 8.4 legacy
    assert_app_url "$canonical_url"
    expect_create_success --recover-source-profile
    gateway_fixture assert-active 8.5 true
    assert_app_url "$canonical_url"
}

run_active_and_removal() {
    set_source_profile none
    expect_create_success --recover-source-profile
    gateway_fixture assert-active 8.5 true

    set_source_profile laravel
    local removal
    removal=$(remote_command orbit instance:remove "$instance_id" --force --json)
    php -r '$v=json_decode($argv[1], true, 512, JSON_THROW_ON_ERROR); if(($v["id"] ?? null)!==(int)$argv[2] || ($v["status"] ?? null)!=="completed" || ($v["force"] ?? null)!==true || ($v["remaining"] ?? null)!==0 || !is_string($v["request_id"] ?? null)) exit(65);' "$removal" "$instance_id"
    gateway_fixture assert-removed
    remote_command test ! -e "$checkout_path"
    remote_command rm -rf -- "$source_state"
    rm -f -- /home/orbit/.orbit/orb168-source-profile-state.json
}

case "$scenario" in
    setup-app-dev)
        test "$(id -u)" -eq 1000
        rm -rf -- "$source_state"
        install -d -m 0700 "$source_state"
        instances=$(orbit instance:list --json)
        read -r sample_checkout starting_commit < <(php -r '$v=json_decode(stream_get_contents(STDIN), true, 512, JSON_THROW_ON_ERROR); $m=array_values(array_filter($v["app_instances"] ?? [], fn($x) => is_array($x) && ($x["name"] ?? null)==="e2e-dev")); if(count($m)!==1 || ($m[0]["status"] ?? null)!=="active" || !is_string($m[0]["checkout_path"] ?? null) || !is_string($m[0]["starting_commit"] ?? null)) exit(65); echo $m[0]["checkout_path"], " ", $m[0]["starting_commit"], "\n";' <<<"$instances")
        checkout="$(dirname "$sample_checkout")/orb168-proof"
        test ! -e "$checkout"
        git clone --local --no-checkout -- "$sample_checkout" "$checkout" >/dev/null
        git -C "$checkout" remote set-url origin https://github.com/laravel/laravel.git
        git -C "$checkout" checkout --quiet -b orb168-proof "$starting_commit"
        test "$(git -C "$checkout" rev-parse HEAD)" = "$starting_commit"
        test -f "$checkout/composer.json"
        test -f "$checkout/artisan"
        printf '%s\n' "$checkout" > "$source_state/checkout"
        cp -- "$checkout/composer.json" "$source_state/composer.json"
        cp -- "$checkout/artisan" "$source_state/artisan"
        ;;

    setup-gateway)
        test "$(id -u)" -eq 1000
        (
            cd "$gateway"
            php artisan migrate --force --no-interaction >/dev/null
        )
        gateway_fixture setup
        ;;

    drift|legacy|unchanged-retries|recovery|active-removal|acceptance)
        test "$(id -u)" -eq 1000
        load_identity
        case "$scenario" in
            drift) run_drift_scenarios ;;
            legacy) run_legacy_scenarios ;;
            unchanged-retries) run_unchanged_retries ;;
            recovery) run_recovery_scenario ;;
            active-removal) run_active_and_removal ;;
            acceptance)
                run_drift_scenarios
                run_legacy_scenarios
                run_unchanged_retries
                run_recovery_scenario
                run_active_and_removal
                ;;
        esac
        ;;

    *)
        exit 64
        ;;
esac
