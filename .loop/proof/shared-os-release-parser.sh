#!/usr/bin/env bash

set -euo pipefail

worktree=/home/orbit/orbit
gateway="$worktree/apps/gateway"
temporary_directory=$(mktemp -d)
trap 'rm -rf -- "$temporary_directory"' EXIT

consumers=(
    "$gateway/app/Infrastructure/Nodes/NodeBootstrapCommandFactory.php"
    "$gateway/app/Infrastructure/Nodes/Roles/NodeRoleOperatingSystemGuard.php"
    "$gateway/app/Infrastructure/Nodes/Roles/NodeRolePrerequisiteCommandFactory.php"
    "$gateway/app/Infrastructure/Nodes/RemotePhpPackageManager.php"
)

render_calls=$(grep -hoF 'OsReleaseParserProgram::render()' "${consumers[@]}" | wc -l)
test "$render_calls" -eq 5
if grep -qF 'fail_os()' "${consumers[@]}"; then
    printf '%s\n' 'An OS release parser copy remains in a consumer.' >&2
    exit 1
fi

parser="$temporary_directory/parser.sh"
php -r \
    "require '$gateway/vendor/autoload.php'; echo App\\Infrastructure\\Nodes\\OsReleaseParserProgram::render();" \
    > "$parser"
bash -n "$parser"

unsupported_text=$(php -r \
    "require '$gateway/vendor/autoload.php'; echo App\\Domain\\Nodes\\UbuntuRelease::unsupportedText();")

run_parser() {
    local os_release=$1
    shift
    local program="$temporary_directory/program.sh"

    sed "s#/etc/os-release#$os_release#g" "$parser" > "$program"
    printf '\n%s\n' 'printf '\''%s\n'\'' "$selected_codename"' >> "$program"
    printf '%s\n' 'printf '\''%s\n'\'' "$@"' >> "$program"

    bash -seu -- ubuntu "$unsupported_text" 1 resolute "$@" < "$program"
}

actual_output=$(run_parser /etc/os-release remaining-argument)
test "$actual_output" = $'resolute\nremaining-argument'

payload_marker="$temporary_directory/payload-executed"
unsafe_release="$temporary_directory/unsafe-os-release"
printf 'ID=ubuntu\nVERSION_CODENAME=$(touch %s)\n' "$payload_marker" > "$unsafe_release"
if run_parser "$unsafe_release" >/dev/null 2> "$temporary_directory/unsafe-error"; then
    printf '%s\n' 'Unsafe OS metadata was accepted.' >&2
    exit 1
fi
test ! -e "$payload_marker"
test "$(cat "$temporary_directory/unsafe-error")" = "$unsupported_text"

duplicate_release="$temporary_directory/duplicate-os-release"
printf 'ID=ubuntu\nID=ubuntu\nVERSION_CODENAME=resolute\n' > "$duplicate_release"
if run_parser "$duplicate_release" >/dev/null 2> "$temporary_directory/duplicate-error"; then
    printf '%s\n' 'Duplicate OS metadata was accepted.' >&2
    exit 1
fi
test "$(cat "$temporary_directory/duplicate-error")" = "$unsupported_text"
