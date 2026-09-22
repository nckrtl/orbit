<?php

declare(strict_types=1);

namespace App\Infrastructure\ProxyCli;

use App\Infrastructure\Ssh\RemoteCommand;

/**
 * Publishes and removes the proxycli Caddy site on the Process Node, using the same
 * versioned-fragment Caddyfile convention as analytics and websocket.
 */
final readonly class ProxyCliCaddyPublisher
{
    public function command(string $configuration, string $port, string $wireguardIp): RemoteCommand
    {
        $encoded = base64_encode($configuration);
        $version = bin2hex(random_bytes(8));

        return new RemoteCommand(
            arguments: [
                'sudo',
                'bash',
                '-seu',
                '--',
                $version,
                ProxyCliFootprint::CaddyFragment,
                ProxyCliFootprint::CaddyVersionsDirectory,
                ProxyCliFootprint::CaddyfilePath,
                ProxyCliFootprint::CaddyServiceName,
                ProxyCliFootprint::CaddyLockPath,
                $wireguardIp,
                ProxyCliFootprint::CaddyBindPlaceholder,
            ],
            input: <<<BASH
                version=\$1
                owned_fragment=\$2
                versions=\$3
                live_caddyfile=\$4
                caddy_service=\$5
                lock=\$6
                wireguard_ip=\$7
                bind_placeholder=\$8
                exec 9>"\$lock"
                flock -w 30 9
                candidate="\$versions/\$version.candidate"
                published="\$versions/\$version"
                candidate_link="\$(dirname "\$live_caddyfile")/.Caddyfile.orbit-\$version"
                trap 'rm -rf -- "\$candidate"; rm -f -- "\$candidate_link"' EXIT
                install -d -o root -g caddy -m 0750 -- "\$versions" "\$candidate/fragments"
                source_main=\$(readlink -f "\$live_caddyfile")
                current_fragments=\$(dirname "\$source_main")/fragments
                if [ -d "\$current_fragments" ]; then
                    for fragment in "\$current_fragments"/*.caddy; do
                        if [ ! -e "\$fragment" ] || [ "\$(basename "\$fragment")" = "\$owned_fragment" ]; then
                            continue
                        fi
                        cp --preserve=mode,ownership -- "\$fragment" "\$candidate/fragments/"
                    done
                elif [ -f "\$source_main" ] && [ "\$source_main" != "\$live_caddyfile" ]; then
                    cp --preserve=mode,ownership -- "\$source_main" "\$candidate/fragments/unmanaged.caddy"
                fi
                bind_address=\$wireguard_ip
                if grep -qsE '^[[:space:]]*bind[[:space:]]+0\\.0\\.0\\.0' "\$candidate"/fragments/*.caddy; then
                    bind_address=0.0.0.0
                fi
                printf '%s' '{$encoded}' | base64 --decode \\
                    | sed "s/\$bind_placeholder/\$bind_address/" > "\$candidate/fragments/\$owned_fragment"
                printf 'import %s/fragments/*.caddy\n' "\$candidate" > "\$candidate/Caddyfile"
                chown -R root:caddy "\$candidate"
                find "\$candidate" -type d -exec chmod 0750 {} +
                find "\$candidate" -type f -exec chmod 0640 {} +
                if [ -d "\$current_fragments" ] \\
                    && [ -f "\$current_fragments/\$owned_fragment" ] \\
                    && cmp -s -- "\$candidate/fragments/\$owned_fragment" "\$current_fragments/\$owned_fragment" \\
                    && systemctl is-active --quiet "\$caddy_service"; then
                    rm -rf -- "\$candidate"
                    exit 0
                fi
                runuser -u caddy -- caddy validate --config "\$candidate/Caddyfile" --adapter caddyfile
                printf 'import %s/%s/fragments/*.caddy\n' "\$versions" "\$version" > "\$candidate/Caddyfile"
                mv -fT -- "\$candidate" "\$published"
                ln -s -- "\$published/Caddyfile" "\$candidate_link"
                previous_target=\$(readlink -- "\$live_caddyfile" || true)
                mv -fT -- "\$candidate_link" "\$live_caddyfile"
                systemctl enable "\$caddy_service"
                if ! systemctl reload-or-restart "\$caddy_service"; then
                    if [ -n "\$previous_target" ]; then
                        ln -s -- "\$previous_target" "\$candidate_link"
                        mv -fT -- "\$candidate_link" "\$live_caddyfile"
                        systemctl restart "\$caddy_service" || true
                    fi
                    rm -rf -- "\$published"
                    exit 1
                fi
                BASH,
        );
    }

    public function removeCommand(): RemoteCommand
    {
        $version = bin2hex(random_bytes(8));

        return new RemoteCommand(
            arguments: [
                'sudo',
                'bash',
                '-seu',
                '--',
                $version,
                ProxyCliFootprint::CaddyFragment,
                ProxyCliFootprint::CaddyVersionsDirectory,
                ProxyCliFootprint::CaddyfilePath,
                ProxyCliFootprint::CaddyServiceName,
                ProxyCliFootprint::CaddyLockPath,
            ],
            input: <<<'BASH'
                version=$1
                owned_fragment=$2
                versions=$3
                live_caddyfile=$4
                caddy_service=$5
                lock=$6
                exec 9>"$lock"
                flock -w 30 9
                source_main=$(readlink -f "$live_caddyfile")
                current_fragments=$(dirname "$source_main")/fragments
                if [ ! -d "$current_fragments" ] || [ ! -f "$current_fragments/$owned_fragment" ]; then
                    exit 0
                fi
                candidate="$versions/$version.candidate"
                published="$versions/$version"
                candidate_link="$(dirname "$live_caddyfile")/.Caddyfile.orbit-$version"
                trap 'rm -rf -- "$candidate"; rm -f -- "$candidate_link"' EXIT
                install -d -o root -g caddy -m 0750 -- "$versions" "$candidate/fragments"
                for fragment in "$current_fragments"/*.caddy; do
                    if [ ! -e "$fragment" ] || [ "$(basename "$fragment")" = "$owned_fragment" ]; then
                        continue
                    fi
                    cp --preserve=mode,ownership -- "$fragment" "$candidate/fragments/"
                done
                printf 'import %s/fragments/*.caddy\n' "$candidate" > "$candidate/Caddyfile"
                chown -R root:caddy "$candidate"
                find "$candidate" -type d -exec chmod 0750 {} +
                find "$candidate" -type f -exec chmod 0640 {} +
                runuser -u caddy -- caddy validate --config "$candidate/Caddyfile" --adapter caddyfile
                printf 'import %s/%s/fragments/*.caddy\n' "$versions" "$version" > "$candidate/Caddyfile"
                mv -fT -- "$candidate" "$published"
                ln -s -- "$published/Caddyfile" "$candidate_link"
                previous_target=$(readlink -- "$live_caddyfile" || true)
                mv -fT -- "$candidate_link" "$live_caddyfile"
                if ! systemctl reload-or-restart "$caddy_service"; then
                    if [ -n "$previous_target" ]; then
                        ln -s -- "$previous_target" "$candidate_link"
                        mv -fT -- "$candidate_link" "$live_caddyfile"
                        systemctl restart "$caddy_service" || true
                    fi
                    rm -rf -- "$published"
                    exit 1
                fi
                BASH,
        );
    }
}
