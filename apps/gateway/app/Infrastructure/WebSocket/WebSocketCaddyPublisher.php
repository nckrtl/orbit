<?php

declare(strict_types=1);

namespace App\Infrastructure\WebSocket;

use App\Infrastructure\Caddy\CaddyFragmentListeners;
use App\Infrastructure\Caddy\CaddyGlobalOptions;
use App\Infrastructure\Caddy\CaddyPublicationLock;
use App\Infrastructure\Caddy\OwnsCaddyGlobalOptions;
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
    use OwnsCaddyGlobalOptions;

    public function command(string $configuration, string $port, CaddyFragmentListeners $listeners): RemoteCommand
    {
        $lockScript = CaddyPublicationLock::script();
        $listenerScript = $listeners->script();
        $comparison = CaddyFragmentListeners::comparison();
        $encoded = base64_encode(str_replace(WebSocketFootprint::CaddyBindPlaceholder, $listeners->sharedBind(), $configuration));
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
                CaddyPublicationLock::Path,
            ],
            input: CaddyGlobalOptions::conflictGuard().<<<BASH
                version=\$1
                owned_fragment=\$2
                versions=\$3
                live_caddyfile=\$4
                caddy_service=\$5
                lock=\$6
                {$lockScript}
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
                {$listenerScript}
                {$comparison}
                printf '%s\n' '{$this->encodedGlobalOptions()}' | base64 --decode > "\$candidate/Caddyfile"
                printf 'import %s/fragments/*.caddy\n' "\$candidate" >> "\$candidate/Caddyfile"
                chown -R root:caddy "\$candidate"
                find "\$candidate" -type d -exec chmod 0750 {} +
                find "\$candidate" -type f -exec chmod 0640 {} +
                # An identical candidate needs no new version and no reload, which would drop open streams.
                if orbit_fragments_unchanged "\$candidate/fragments" "\$current_fragments" \\
                    && systemctl is-active --quiet "\$caddy_service"; then
                    rm -rf -- "\$candidate"
                    exit 0
                fi
                refuse_carried_global_options "\$candidate" "\$source_main"
                caddy validate --config "\$candidate/Caddyfile" --adapter caddyfile
                # Caddy validates syntax, not listeners; a missing address would fail the reload.
                orbit_require_listen_addresses
                printf '%s\n' '{$this->encodedGlobalOptions()}' | base64 --decode > "\$candidate/Caddyfile"
                printf 'import %s/%s/fragments/*.caddy\n' "\$versions" "\$version" >> "\$candidate/Caddyfile"
                mv -fT -- "\$candidate" "\$published"
                ln -s -- "\$published/Caddyfile" "\$candidate_link"
                previous_target=\$(readlink -- "\$live_caddyfile" || true)
                mv -fT -- "\$candidate_link" "\$live_caddyfile"
                systemctl enable "\$caddy_service"
                if ! systemctl reload-or-restart "\$caddy_service"; then
                    # Caddy validates syntax, not listeners; a rejected load must not stay live.
                    if [ -n "\$previous_target" ]; then
                        ln -s -- "\$previous_target" "\$candidate_link"
                        mv -fT -- "\$candidate_link" "\$live_caddyfile"
                        # A rejected load can leave its listeners open beside the live ones; only a restart drops them.
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
                WebSocketFootprint::CaddyFragment,
                WebSocketFootprint::CaddyVersionsDirectory,
                WebSocketFootprint::CaddyfilePath,
                WebSocketFootprint::CaddyServiceName,
                CaddyPublicationLock::Path,
            ],
            input: CaddyGlobalOptions::conflictGuard().<<<'BASH'
                version=$1
                owned_fragment=$2
                versions=$3
                live_caddyfile=$4
                caddy_service=$5
                lock=$6
                BASH.PHP_EOL.CaddyPublicationLock::script().PHP_EOL.<<<'BASH'
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
                printf '%s\n' 'ewogICAgYXV0b19odHRwcyBkaXNhYmxlX2NlcnRzCn0K' | base64 --decode > "$candidate/Caddyfile"
                printf 'import %s/fragments/*.caddy\n' "$candidate" >> "$candidate/Caddyfile"
                chown -R root:caddy "$candidate"
                find "$candidate" -type d -exec chmod 0750 {} +
                find "$candidate" -type f -exec chmod 0640 {} +
                refuse_carried_global_options "$candidate" "$source_main"
                caddy validate --config "$candidate/Caddyfile" --adapter caddyfile
                printf '%s\n' 'ewogICAgYXV0b19odHRwcyBkaXNhYmxlX2NlcnRzCn0K' | base64 --decode > "$candidate/Caddyfile"
                printf 'import %s/%s/fragments/*.caddy\n' "$versions" "$version" >> "$candidate/Caddyfile"
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
