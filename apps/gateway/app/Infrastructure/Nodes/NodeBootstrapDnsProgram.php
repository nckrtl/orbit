<?php

declare(strict_types=1);

namespace App\Infrastructure\Nodes;

final class NodeBootstrapDnsProgram
{
    public static function render(): string
    {
        return <<<'BOOTSTRAP_DNS'
            install_bootstrap_packages() (
                set -euo pipefail
                export LC_ALL=C
                dns_state=/etc/wireguard/orbit.dns-link
                dns_suspended=0
                old_dns_server=

                exec 9>/run/lock/orbit-wireguard-peer.lock
                flock -w 30 9 || {
                    printf '%s\n' 'Could not lock Orbit DNS for package bootstrap.' >&2
                    exit 1
                }

                restore_bootstrap_dns() {
                    status=$?
                    trap - EXIT HUP INT TERM
                    if [ "$dns_suspended" = 1 ]; then
                        if ! timeout --kill-after=5s 10s resolvectl dns orbit "$old_dns_server"; then
                            printf '%s\n' 'Could not restore Orbit bootstrap DNS server.' >&2
                            status=1
                        fi
                        if ! timeout --kill-after=5s 10s resolvectl domain orbit '~.'; then
                            printf '%s\n' 'Could not restore Orbit bootstrap DNS domain.' >&2
                            status=1
                        fi
                    fi
                    exit "$status"
                }
                trap restore_bootstrap_dns EXIT
                trap 'exit 129' HUP
                trap 'exit 130' INT
                trap 'exit 143' TERM

                dns_failure() {
                    printf '%s\n' "$1" >&2
                    exit 1
                }

                # APT owns source transports and proxy resolution. Try it before
                # diagnosing local DNS, which a working proxy may not need.
                package_indexes_ready=0
                if timeout --kill-after=5s 30s apt-get -o APT::Update::Error-Mode=any -o Acquire::Retries=0 -o Acquire::http::Timeout=15 -o Acquire::https::Timeout=15 update; then
                    package_indexes_ready=1
                fi

                if [ "$package_indexes_ready" = 0 ]; then
                    # Ask APT for the configured source URLs without contacting them.
                    source_uris=$(timeout --kill-after=5s 20s apt-get --print-uris update) ||
                        dns_failure 'Could not inspect package sources for bootstrap DNS.'
                    package_hosts=()
                    while IFS= read -r source_line; do
                        [[ "$source_line" = \'* ]] || continue
                        source_uri=${source_line#\'}
                        source_uri=${source_uri%%\'*}
                        case "$source_uri" in
                            http://*|https://*|ftp://*)
                                authority=${source_uri#*://}
                                authority=${authority%%/*}
                                authority=${authority##*@}
                                if [[ "$authority" = \[* ]]; then
                                    package_host=${authority#\[}
                                    package_host=${package_host%%\]*}
                                else
                                    package_host=${authority%%:*}
                                fi
                                [[ "$package_host" =~ ^[A-Za-z0-9][A-Za-z0-9.:-]*$ ]] ||
                                    dns_failure 'Unsupported package-source host for bootstrap DNS.'
                                if [[ " ${package_hosts[*]} " != *" $package_host "* ]]; then
                                    package_hosts+=("$package_host")
                                fi
                                ;;
                            file:*|copy:*|cdrom:*) ;;
                            *) dns_failure 'Unsupported package-source transport for bootstrap DNS.' ;;
                        esac
                    done <<< "$source_uris"

                    package_dns_works() {
                        for package_host in "${package_hosts[@]}"; do
                            if ! timeout --kill-after=2s 5s getent ahosts "$package_host" >/dev/null 2>&1; then
                                return 1
                            fi
                        done
                    }

                    if ! package_dns_works; then
                        [ "$bootstrap_dns_policy" = managed ] ||
                            dns_failure 'Package-source DNS is unavailable; operator DNS was preserved.'
                        [ ! -L /etc/wireguard ] && [ ! -L "$dns_state" ] && [ -f "$dns_state" ] ||
                            dns_failure 'Package-source DNS is unavailable; no trusted Orbit DNS ownership record exists.'
                        [ "$(stat -c '%u:%a' -- "$dns_state")" = 0:600 ] ||
                            dns_failure 'Package-source DNS is unavailable; Orbit DNS ownership permissions differ.'
                        mapfile -t owned_dns < "$dns_state"
                        [ "${#owned_dns[@]}" = 3 ] && [ "${owned_dns[0]}" = orbit ] && [ "${owned_dns[2]}" = . ] ||
                            dns_failure 'Package-source DNS is unavailable; retained DNS is not Orbit route-all DNS.'
                        old_dns_server=${owned_dns[1]}
                        [[ "$old_dns_server" =~ ^[A-Fa-f0-9:.]+$ ]] ||
                            dns_failure 'Package-source DNS is unavailable; Orbit DNS ownership is malformed.'
                        live_dns=$(timeout --kill-after=5s 10s resolvectl dns orbit) ||
                            dns_failure 'Could not inspect retained Orbit DNS server.'
                        live_domain=$(timeout --kill-after=5s 10s resolvectl domain orbit) ||
                            dns_failure 'Could not inspect retained Orbit DNS domain.'
                        [[ "$live_dns" =~ ^Link\ [0-9]+\ \(orbit\):\ (.*)$ ]] && [ "${BASH_REMATCH[1]}" = "$old_dns_server" ] ||
                            dns_failure 'Package-source DNS is unavailable; live DNS differs from Orbit ownership.'
                        [[ "$live_domain" =~ ^Link\ [0-9]+\ \(orbit\):\ (.*)$ ]] && [ "${BASH_REMATCH[1]}" = '~.' ] ||
                            dns_failure 'Package-source DNS is unavailable; live domains differ from Orbit ownership.'
                        dns_suspended=1
                        timeout --kill-after=5s 10s resolvectl dns orbit ''
                        timeout --kill-after=5s 10s resolvectl domain orbit ''
                        package_dns_works ||
                            dns_failure 'Package-source DNS is unavailable through the existing network resolver.'
                    fi

                    timeout --kill-after=15s 180s apt-get -o APT::Update::Error-Mode=any -o Acquire::Retries=0 -o Acquire::http::Timeout=15 -o Acquire::https::Timeout=15 update
                fi
                timeout --kill-after=15s 300s apt-get -o Acquire::Retries=0 -o Acquire::http::Timeout=15 -o Acquire::https::Timeout=15 install --yes --no-install-recommends -- "$@"
            )
            BOOTSTRAP_DNS;
    }
}
