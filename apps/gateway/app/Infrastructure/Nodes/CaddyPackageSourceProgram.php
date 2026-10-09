<?php

declare(strict_types=1);

namespace App\Infrastructure\Nodes;

use App\Domain\Nodes\CaddyRelease;

/**
 * Installs the Caddy project's own `.deb` from its GitHub release on a Node, so the Node runs a
 * release Orbit can render against instead of the Ubuntu archive build. The package is pinned by
 * release and SHA-512 digest per architecture, and the installed release must reach the floor.
 *
 * The program also sets `net.ipv4.tcp_migrate_req`, so a Caddy reload hands the connections that
 * wait on the old listening socket to the new one instead of resetting them. ADR 0144 records it.
 *
 * Orbit used to publish the Caddy apt source on Cloudsmith. That source refused every request from
 * 2026-10-09 with `402 Payment Required`, and a failing source fails every `apt-get update` on the
 * Node. The program deletes the old source and keyring.
 *
 * The program runs as root before the role installs the rest of its packages. It is idempotent: it
 * installs nothing while Caddy already reaches the floor, so a converge never restarts a running
 * Caddy for a newer pin. It upgrades an archive Caddy in place and keeps the Orbit-owned Caddyfile
 * through `--force-confold`. It prints `orbit-caddy-package-result=changed` or `=unchanged`.
 */
final class CaddyPackageSourceProgram
{
    public const string RELEASE = '2.11.7';

    public const string RELEASE_URL = 'https://github.com/caddyserver/caddy/releases/download/v'.self::RELEASE;

    /** From `caddy_2.11.7_checksums.txt`, whose sigstore signature names Caddy's release workflow. */
    public const string AMD64_SHA512 = '47e8351c2317b427af14a103e763ca1118a3d2396a88b4c0669cdec9c4a68a957690194e2423a1633f53135741c33a41bdac2b55515b7d0f7adc8b733add50d9';

    public const string ARM64_SHA512 = 'ac32f03f0eea04021f2d4e5d89bfd01b84ead115f2eba9d2a0d06447e86d4af932899e56f090993a566d0925dbd1063002929b395541a8c5f5e51d7039f7375a';

    public const string KERNEL_SETTING_PATH = '/etc/sysctl.d/60-orbit-caddy.conf';

    public const string KERNEL_SETTING = 'net.ipv4.tcp_migrate_req = 1';

    public const string LEGACY_KEYRING_PATH = '/usr/share/keyrings/orbit-caddy.gpg';

    public const string LEGACY_SOURCE_PATH = '/etc/apt/sources.list.d/orbit-caddy.sources';

    public const string Changed = 'orbit-caddy-package-result=changed';

    /**
     * The positional arguments the program consumes, in order.
     *
     * @return list<string>
     */
    public static function arguments(): array
    {
        return [
            self::RELEASE,
            self::RELEASE_URL,
            self::AMD64_SHA512,
            self::ARM64_SHA512,
            CaddyRelease::MINIMUM,
            self::KERNEL_SETTING_PATH,
            self::KERNEL_SETTING,
            self::LEGACY_KEYRING_PATH,
            self::LEGACY_SOURCE_PATH,
        ];
    }

    public static function render(): string
    {
        return <<<'BASH'
            release=$1
            release_url=$2
            amd64_sha512=$3
            arm64_sha512=$4
            minimum_version=$5
            kernel_setting_path=$6
            kernel_setting=$7
            legacy_keyring_path=$8
            legacy_source_path=$9
            changed=0

            if [ -e "$kernel_setting_path" ] || [ -L "$kernel_setting_path" ]; then
                if [ -L "$kernel_setting_path" ] \
                    || [ ! -f "$kernel_setting_path" ] \
                    || [ "$(stat -c '%U:%G' -- "$kernel_setting_path")" != root:root ] \
                    || [ "$(stat -c '%a' -- "$kernel_setting_path")" != 644 ]
                then
                    printf '%s\n' 'An Orbit Caddy file has unsafe ownership or mode.' >&2
                    exit 1
                fi
            fi

            umask 022
            work_directory=$(mktemp -d)
            trap 'rm -rf -- "$work_directory"' EXIT

            kernel_setting_body="$work_directory/60-orbit-caddy.conf"
            printf '%s\n' "$kernel_setting" > "$kernel_setting_body"
            sysctl --quiet --load="$kernel_setting_body"
            if [ ! -f "$kernel_setting_path" ] || ! cmp -s -- "$kernel_setting_body" "$kernel_setting_path"; then
                install -m 0644 -o root -g root -- "$kernel_setting_body" "$kernel_setting_path"
                changed=1
            fi

            for legacy_path in "$legacy_source_path" "$legacy_keyring_path"; do
                if [ -e "$legacy_path" ] || [ -L "$legacy_path" ]; then
                    rm -f -- "$legacy_path"
                    changed=1
                fi
            done

            installed_version() {
                if ! command -v caddy >/dev/null 2>&1; then
                    return 0
                fi

                reported=$(caddy version 2>/dev/null | head -n 1 | awk '{ print $1 }' || true)
                printf '%s\n' "${reported#v}"
            }

            current_version=$(installed_version)
            if [ -z "$current_version" ] \
                || ! dpkg --compare-versions "$current_version" ge "$minimum_version"
            then
                architecture=$(dpkg --print-architecture)
                case "$architecture" in
                    amd64) package_sha512=$amd64_sha512 ;;
                    arm64) package_sha512=$arm64_sha512 ;;
                    *)
                        printf 'Orbit pins no Caddy package for the %s architecture.\n' "$architecture" >&2
                        exit 1
                        ;;
                esac

                chmod 0755 -- "$work_directory"
                package_path="$work_directory/caddy_${release}_linux_${architecture}.deb"
                curl --fail --silent --show-error --location --proto '=https' --tlsv1.2 \
                    --output "$package_path" \
                    "$release_url/caddy_${release}_linux_${architecture}.deb"
                if ! printf '%s  %s\n' "$package_sha512" "$package_path" | sha512sum --check --status; then
                    printf 'The Caddy %s package does not match the Orbit pin.\n' "$release" >&2
                    exit 1
                fi
                chmod 0644 -- "$package_path"

                export DEBIAN_FRONTEND=noninteractive
                apt-get -o DPkg::Lock::Timeout=300 -o Dpkg::Options::=--force-confold \
                    install --yes --no-install-recommends --no-remove -- "$package_path"
                changed=1

                current_version=$(installed_version)
            fi

            if [ -z "$current_version" ]; then
                printf '%s\n' 'Caddy reported no version.' >&2
                exit 1
            fi
            if ! dpkg --compare-versions "$current_version" ge "$minimum_version"; then
                printf 'Caddy %s is older than the %s Orbit renders against.\n' \
                    "$current_version" \
                    "$minimum_version" >&2
                exit 1
            fi

            if [ "$changed" -eq 1 ]; then
                printf '%s\n' 'orbit-caddy-package-result=changed'
            else
                printf '%s\n' 'orbit-caddy-package-result=unchanged'
            fi
            BASH;
    }
}
