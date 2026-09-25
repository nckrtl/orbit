<?php

declare(strict_types=1);

namespace App\Infrastructure\ProxyCli;

use App\Infrastructure\Caddy\CaddyFragmentListeners;
use App\Infrastructure\Caddy\CaddyGlobalOptions;
use App\Infrastructure\Caddy\CaddyPublicationLock;
use App\Infrastructure\Caddy\OwnsCaddyGlobalOptions;
use App\Infrastructure\Ssh\RemoteCommand;

/**
 * Publishes and removes the proxycli Caddy site on the Process Node, using the same
 * versioned-fragment Caddyfile convention as analytics and websocket.
 */
final readonly class ProxyCliCaddyPublisher
{
    use OwnsCaddyGlobalOptions;

    public const string AppDevFragment = 'app-dev.caddy';

    /**
     * @param  string|null  $appDevConfiguration  Replaces the `app-dev.caddy` fragment in the same reload, so a
     *                                            takeover withdraws the Route that served the collector hostname.
     */
    public function command(
        string $configuration,
        string $port,
        CaddyFragmentListeners $listeners,
        ?string $appDevConfiguration = null,
    ): RemoteCommand {
        $lockScript = CaddyPublicationLock::script();
        $listenerScript = $listeners->script();
        $comparison = CaddyFragmentListeners::comparison();
        $encoded = base64_encode(str_replace(ProxyCliFootprint::CaddyBindPlaceholder, $listeners->sharedBind(), $configuration));
        $replacedFragment = $appDevConfiguration === null ? '' : self::AppDevFragment;
        $replacementEncoded = base64_encode($appDevConfiguration ?? '');
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
                CaddyPublicationLock::Path,
                $replacedFragment,
            ],
            input: CaddyGlobalOptions::conflictGuard().<<<BASH
                version=\$1
                owned_fragment=\$2
                versions=\$3
                live_caddyfile=\$4
                caddy_service=\$5
                lock=\$6
                replaced_fragment=\$7
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
                        if [ ! -e "\$fragment" ] \\
                            || [ "\$(basename "\$fragment")" = "\$owned_fragment" ] \\
                            || [ "\$(basename "\$fragment")" = "\$replaced_fragment" ]; then
                            continue
                        fi
                        cp --preserve=mode,ownership -- "\$fragment" "\$candidate/fragments/"
                    done
                elif [ -f "\$source_main" ] && [ "\$source_main" != "\$live_caddyfile" ]; then
                    cp --preserve=mode,ownership -- "\$source_main" "\$candidate/fragments/unmanaged.caddy"
                fi
                if [ -n "\$replaced_fragment" ]; then
                    printf '%s' '{$replacementEncoded}' | base64 --decode > "\$candidate/fragments/\$replaced_fragment"
                fi
                printf '%s' '{$encoded}' | base64 --decode > "\$candidate/fragments/\$owned_fragment"
                {$listenerScript}
                {$comparison}
                printf '%s\n' '{$this->encodedGlobalOptions()}' | base64 --decode > "\$candidate/Caddyfile"
                printf 'import %s/fragments/*.caddy\n' "\$candidate" >> "\$candidate/Caddyfile"
                chown -R root:caddy "\$candidate"
                find "\$candidate" -type d -exec chmod 0750 {} +
                find "\$candidate" -type f -exec chmod 0640 {} +
                # An identical candidate needs no new version and no reload.
                if orbit_fragments_unchanged "\$candidate/fragments" "\$current_fragments" \\
                    && systemctl is-active --quiet "\$caddy_service"; then
                    rm -rf -- "\$candidate"
                    exit 0
                fi
                refuse_carried_global_options "\$candidate" "\$source_main"
                orbit_require_listen_addresses
                caddy validate --config "\$candidate/Caddyfile" --adapter caddyfile
                printf '%s\n' '{$this->encodedGlobalOptions()}' | base64 --decode > "\$candidate/Caddyfile"
                printf 'import %s/%s/fragments/*.caddy\n' "\$versions" "\$version" >> "\$candidate/Caddyfile"
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
                CaddyPublicationLock::Path,
                $this->encodedGlobalOptions(),
            ],
            input: CaddyGlobalOptions::conflictGuard().<<<'BASH'
                version=$1
                owned_fragment=$2
                versions=$3
                live_caddyfile=$4
                caddy_service=$5
                lock=$6
                global_options=$7
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
                printf '%s\n' "$global_options" | base64 --decode > "$candidate/Caddyfile"
                printf 'import %s/fragments/*.caddy\n' "$candidate" >> "$candidate/Caddyfile"
                chown -R root:caddy "$candidate"
                find "$candidate" -type d -exec chmod 0750 {} +
                find "$candidate" -type f -exec chmod 0640 {} +
                refuse_carried_global_options "$candidate" "$source_main"
                caddy validate --config "$candidate/Caddyfile" --adapter caddyfile
                printf '%s\n' "$global_options" | base64 --decode > "$candidate/Caddyfile"
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
