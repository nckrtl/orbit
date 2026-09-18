<?php

declare(strict_types=1);

namespace App\Infrastructure\WebSocket;

use App\Infrastructure\Ssh\RemoteCommand;

/**
 * Publishes and removes the websocket role's Caddy site on its node,
 * following the same versioned-fragment Caddyfile convention every other
 * managed role's Caddy publication uses on a node: every publish collects
 * whatever other fragments are already live, replaces only the one this
 * role owns, validates the whole result, and atomically swaps it in.
 */
final readonly class WebSocketCaddyPublisher
{
    public function command(string $configuration, string $port): RemoteCommand
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
                WebSocketFootprint::CaddyFragment,
                WebSocketFootprint::CaddyVersionsDirectory,
                WebSocketFootprint::CaddyfilePath,
                WebSocketFootprint::CaddyServiceName,
                WebSocketFootprint::CaddyLockPath,
            ],
            input: <<<BASH
                version=\$1
                owned_fragment=\$2
                versions=\$3
                live_caddyfile=\$4
                caddy_service=\$5
                lock=\$6
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
                printf '%s' '{$encoded}' | base64 --decode > "\$candidate/fragments/\$owned_fragment"
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
                caddy validate --config "\$candidate/Caddyfile" --adapter caddyfile
                printf 'import %s/%s/fragments/*.caddy\n' "\$versions" "\$version" > "\$candidate/Caddyfile"
                mv -fT -- "\$candidate" "\$published"
                ln -s -- "\$published/Caddyfile" "\$candidate_link"
                mv -fT -- "\$candidate_link" "\$live_caddyfile"
                systemctl enable "\$caddy_service"
                systemctl reload-or-restart "\$caddy_service"
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
                WebSocketFootprint::CaddyFragment,
                WebSocketFootprint::CaddyVersionsDirectory,
                WebSocketFootprint::CaddyfilePath,
                WebSocketFootprint::CaddyServiceName,
                WebSocketFootprint::CaddyLockPath,
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
                caddy validate --config "$candidate/Caddyfile" --adapter caddyfile
                printf 'import %s/%s/fragments/*.caddy\n' "$versions" "$version" > "$candidate/Caddyfile"
                mv -fT -- "$candidate" "$published"
                ln -s -- "$published/Caddyfile" "$candidate_link"
                mv -fT -- "$candidate_link" "$live_caddyfile"
                systemctl reload-or-restart "$caddy_service"
                BASH,
        );
    }
}
