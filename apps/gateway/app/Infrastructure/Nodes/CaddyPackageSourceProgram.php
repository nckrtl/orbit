<?php

declare(strict_types=1);

namespace App\Infrastructure\Nodes;

use App\Domain\Nodes\CaddyRelease;

/**
 * Publishes the Caddy project's own apt source on a Node and installs `caddy` from it, so the Node
 * runs a release Orbit can render against instead of the Ubuntu archive build. The signing key is
 * pinned by digest and fingerprint, the candidate must come from the pinned origin, and the
 * installed release must reach the floor. ADR 0100 records the decision.
 *
 * The program runs as root before the role installs the rest of its packages. It is idempotent: it
 * republishes nothing that already matches and upgrades an archive Caddy in place, keeping the
 * Orbit-owned Caddyfile through `--force-confold`.
 */
final class CaddyPackageSourceProgram
{
    public const string SOURCE_URI = 'https://dl.cloudsmith.io/public/caddy/stable/deb/debian';

    public const string SUITE = 'any-version';

    public const string COMPONENT = 'main';

    public const string KEY_URL = 'https://dl.cloudsmith.io/public/caddy/stable/gpg.key';

    public const string KEYRING_PATH = '/usr/share/keyrings/orbit-caddy.gpg';

    public const string SOURCE_PATH = '/etc/apt/sources.list.d/orbit-caddy.sources';

    public const string KEY_SHA256 = '783dfee04b19e851a928cd87b34710213ebbe7628f98d9f34595ab83be578c00';

    public const string KEY_FINGERPRINT = '65760C51EDEA2017CEA2CA15155B6D79CA56EA34';

    /**
     * The positional arguments the program consumes, in order.
     *
     * @return list<string>
     */
    public static function arguments(): array
    {
        return [
            self::SOURCE_URI,
            self::SUITE,
            self::COMPONENT,
            self::KEY_URL,
            self::KEYRING_PATH,
            self::SOURCE_PATH,
            self::KEY_SHA256,
            self::KEY_FINGERPRINT,
            CaddyRelease::MINIMUM,
        ];
    }

    public static function render(): string
    {
        return <<<'BASH'
            source_uri=$1
            suite=$2
            component=$3
            key_url=$4
            keyring_path=$5
            source_path=$6
            key_sha256=$7
            key_fingerprint=$8
            minimum_version=$9

            for managed_path in "$keyring_path" "$source_path"; do
                if [ ! -e "$managed_path" ] && [ ! -L "$managed_path" ]; then
                    continue
                fi

                if [ -L "$managed_path" ] \
                    || [ ! -f "$managed_path" ] \
                    || [ "$(stat -c '%U:%G' -- "$managed_path")" != root:root ] \
                    || [ "$(stat -c '%a' -- "$managed_path")" != 644 ]
                then
                    printf '%s\n' 'An Orbit Caddy package source file has unsafe ownership or mode.' >&2
                    exit 1
                fi
            done

            umask 022
            work_directory=$(mktemp -d)
            gnupg_home="$work_directory/gnupg"
            install -d -m 0700 -- "$gnupg_home"
            downloaded_key="$work_directory/gpg.key"
            keyring_body="$work_directory/orbit-caddy.gpg"
            source_body="$work_directory/orbit-caddy.sources"
            key_backup="$work_directory/keyring.backup"
            source_backup="$work_directory/source.backup"
            had_key=0
            had_source=0
            published=0

            if [ -f "$keyring_path" ]; then
                cp -- "$keyring_path" "$key_backup"
                had_key=1
            fi

            if [ -f "$source_path" ]; then
                cp -- "$source_path" "$source_backup"
                had_source=1
            fi

            restore_caddy_source() {
                status=$?
                trap - EXIT

                if [ "$status" -ne 0 ] && [ "$published" -eq 1 ]; then
                    if [ "$had_key" -eq 1 ]; then
                        install -m 0644 -o root -g root -- "$key_backup" "$keyring_path"
                    else
                        rm -f -- "$keyring_path"
                    fi

                    if [ "$had_source" -eq 1 ]; then
                        install -m 0644 -o root -g root -- "$source_backup" "$source_path"
                    else
                        rm -f -- "$source_path"
                    fi
                fi

                rm -rf -- "$work_directory"
                exit "$status"
            }
            trap restore_caddy_source EXIT

            curl --fail --silent --show-error --location --proto '=https' --tlsv1.2 \
                --output "$downloaded_key" \
                "$key_url"
            printf '%s  %s\n' "$key_sha256" "$downloaded_key" | sha256sum --check --status

            primary_fingerprint=$(GNUPGHOME="$gnupg_home" gpg --batch --with-colons --show-keys "$downloaded_key" \
                | awk -F: '$1 == "pub" { primary = 1; next } $1 == "fpr" && primary { print $10; exit }')
            if [ "$primary_fingerprint" != "$key_fingerprint" ]; then
                printf '%s\n' 'The Caddy signing key identity does not match the Orbit pin.' >&2
                exit 1
            fi

            GNUPGHOME="$gnupg_home" gpg --batch --dearmor --output "$keyring_body" -- "$downloaded_key"

            printf 'Types: deb\nURIs: %s\nSuites: %s\nComponents: %s\nSigned-By: %s\n' \
                "$source_uri" \
                "$suite" \
                "$component" \
                "$keyring_path" \
                > "$source_body"

            if [ ! -f "$keyring_path" ] || ! cmp -s -- "$keyring_body" "$keyring_path"; then
                published=1
                install -m 0644 -o root -g root -- "$keyring_body" "$keyring_path"
            fi

            if [ ! -f "$source_path" ] || ! cmp -s -- "$source_body" "$source_path"; then
                published=1
                install -m 0644 -o root -g root -- "$source_body" "$source_path"
            fi

            export DEBIAN_FRONTEND=noninteractive
            apt-get -o DPkg::Lock::Timeout=300 update

            candidate=$(apt-cache policy -- caddy | awk '$1 == "Candidate:" { print $2; exit }')
            if [ -z "$candidate" ] || [ "$candidate" = '(none)' ]; then
                printf '%s\n' 'The caddy package has no candidate on this Node.' >&2
                exit 1
            fi

            expected_origin="$source_uri $suite/$component $(dpkg --print-architecture) Packages"
            if ! apt-cache madison -- caddy | awk -F '|' -v candidate="$candidate" -v origin="$expected_origin" '
                function trim(value) { gsub(/^[[:space:]]+|[[:space:]]+$/, "", value); return value }
                NF == 3 && trim($1) == "caddy" && trim($2) == candidate && trim($3) == origin { found = 1 }
                END { exit found ? 0 : 1 }
            '; then
                printf '%s\n' 'The caddy candidate does not come from the pinned Orbit source.' >&2
                exit 1
            fi

            apt-get -o DPkg::Lock::Timeout=300 -o Dpkg::Options::=--force-confold \
                install --yes --no-install-recommends --no-remove -- caddy

            installed_version=$(caddy version 2>/dev/null | head -n 1 | awk '{ print $1 }')
            installed_version=${installed_version#v}
            if [ -z "$installed_version" ]; then
                printf '%s\n' 'Caddy reported no version.' >&2
                exit 1
            fi
            if ! dpkg --compare-versions "$installed_version" ge "$minimum_version"; then
                printf 'Caddy %s is older than the %s Orbit renders against.\n' \
                    "$installed_version" \
                    "$minimum_version" >&2
                exit 1
            fi
            BASH;
    }
}
