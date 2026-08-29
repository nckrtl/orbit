<?php

declare(strict_types=1);

namespace App\Infrastructure\Metrics;

use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Processes\ProcessInvocation;
use App\Infrastructure\Processes\ProcessRunner;

final readonly class MetricsCaddyPublisher
{
    private const string Fragment = 'metrics.caddy';

    public function __construct(
        private ProcessRunner $processes,
    ) {}

    public function publish(string $configuration): bool
    {
        $version = bin2hex(random_bytes(8));
        $encoded = base64_encode($configuration);
        $result = $this->run(new ProcessInvocation(
            arguments: [
                'sudo',
                'bash',
                '-seu',
                '--',
                $version,
                self::Fragment,
                '/etc/caddy/orbit-versions',
                '/etc/caddy/Caddyfile',
                'caddy',
                '/run/lock/orbit-caddy.lock',
            ],
            timeout: 60.0,
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
                rollback_link="\$(dirname "\$live_caddyfile")/.Caddyfile.orbit-rollback-\$version"
                rollback_file="\$(dirname "\$live_caddyfile")/.Caddyfile.orbit-rollback-file-\$version"
                previous_main="\$versions/.previous-main.\$version"
                trap 'rm -rf -- "\$candidate"; rm -f -- "\$candidate_link" "\$rollback_link" "\$rollback_file" "\$previous_main"' EXIT
                install -d -o root -g caddy -m 0750 -- "\$versions" "\$candidate/fragments"
                source_main=\$(readlink -f "\$live_caddyfile")
                test -f "\$source_main"
                cp -a -- "\$source_main" "\$previous_main"
                current_fragments=\$(dirname "\$source_main")/fragments
                if [ -f "\$current_fragments/\$owned_fragment" ]; then
                    head -n 1 -- "\$current_fragments/\$owned_fragment" | grep -Fqx -- "# Managed by Orbit: metrics"
                fi
                previous_target=
                if [ -L "\$live_caddyfile" ]; then
                    previous_target=\$(readlink "\$live_caddyfile")
                fi
                preserve_source_main=1
                case "\$source_main" in
                    "\$versions"/*/Caddyfile)
                        for fragment in "\$current_fragments"/*.caddy; do
                            if [ ! -e "\$fragment" ] || [ "\$(basename "\$fragment")" = "\$owned_fragment" ]; then
                                continue
                            fi
                            cp --preserve=mode,ownership -- "\$fragment" "\$candidate/fragments/"
                        done
                        ;;
                    *)
                        if [ "\$source_main" = "\$live_caddyfile" ]; then
                            current_md5=\$(md5sum -- "\$source_main" | awk '{print \$1}')
                            default_md5=\$(dpkg-query -W -f='\${Conffiles}\n' "\$caddy_service" | awk -v live_caddyfile="\$live_caddyfile" '\$1 == live_caddyfile { print \$2; exit }')
                            if [ -n "\$default_md5" ] && [ "\$current_md5" = "\$default_md5" ]; then
                                preserve_source_main=0
                            fi
                        fi
                        if [ "\$preserve_source_main" = 1 ]; then
                            cp --preserve=mode,ownership -- "\$source_main" "\$candidate/fragments/unmanaged.caddy"
                        fi
                        ;;
                esac
                printf '%s' '{$encoded}' | base64 --decode > "\$candidate/fragments/\$owned_fragment"
                printf 'import %s/fragments/*.caddy\n' "\$candidate" > "\$candidate/Caddyfile"
                chown -R root:caddy "\$candidate"
                find "\$candidate" -type d -exec chmod 0750 {} +
                find "\$candidate" -type f -exec chmod 0640 {} +
                if [ -f "\$current_fragments/\$owned_fragment" ] && cmp -s -- "\$candidate/fragments/\$owned_fragment" "\$current_fragments/\$owned_fragment"; then
                    exit 0
                fi
                caddy validate --config "\$candidate/Caddyfile" --adapter caddyfile
                printf 'import %s/%s/fragments/*.caddy\n' "\$versions" "\$version" > "\$candidate/Caddyfile"
                mv -fT -- "\$candidate" "\$published"
                ln -s -- "\$published/Caddyfile" "\$candidate_link"
                mv -fT -- "\$candidate_link" "\$live_caddyfile"
                if ! systemctl enable "\$caddy_service" || ! systemctl reload-or-restart "\$caddy_service"; then
                    if [ -n "\$previous_target" ]; then
                        ln -s -- "\$previous_target" "\$rollback_link"
                        mv -fT -- "\$rollback_link" "\$live_caddyfile"
                    else
                        cp -a -- "\$previous_main" "\$rollback_file"
                        mv -fT -- "\$rollback_file" "\$live_caddyfile"
                    fi
                    systemctl reload-or-restart "\$caddy_service" || true
                    rm -rf -- "\$published"
                    exit 1
                fi
                printf 'changed\n'
                BASH,
        ));

        return in_array('changed', preg_split('/\R/', trim($result->stdout)) ?: [], strict: true);
    }

    public function remove(): void
    {
        $version = bin2hex(random_bytes(8));
        $this->run(new ProcessInvocation(
            arguments: [
                'sudo',
                'bash',
                '-seu',
                '--',
                $version,
                self::Fragment,
                '/etc/caddy/orbit-versions',
                '/etc/caddy/Caddyfile',
                'caddy',
                '/run/lock/orbit-caddy.lock',
            ],
            timeout: 60.0,
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
                test -f "$source_main"
                current_fragments=$(dirname "$source_main")/fragments
                test ! -f "$current_fragments/$owned_fragment" && exit 0
                head -n 1 -- "$current_fragments/$owned_fragment" | grep -Fqx -- "# Managed by Orbit: metrics"
                candidate="$versions/$version.candidate"
                published="$versions/$version"
                candidate_link="$(dirname "$live_caddyfile")/.Caddyfile.orbit-$version"
                rollback_link="$(dirname "$live_caddyfile")/.Caddyfile.orbit-rollback-$version"
                rollback_file="$(dirname "$live_caddyfile")/.Caddyfile.orbit-rollback-file-$version"
                previous_main="$versions/.previous-main.$version"
                previous_target=
                if [ -L "$live_caddyfile" ]; then
                    previous_target=$(readlink "$live_caddyfile")
                fi
                trap 'rm -rf -- "$candidate"; rm -f -- "$candidate_link" "$rollback_link" "$rollback_file" "$previous_main"' EXIT
                install -d -o root -g caddy -m 0750 -- "$versions" "$candidate/fragments"
                cp -a -- "$source_main" "$previous_main"
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
                if ! systemctl reload-or-restart "$caddy_service"; then
                    if [ -n "$previous_target" ]; then
                        ln -s -- "$previous_target" "$rollback_link"
                        mv -fT -- "$rollback_link" "$live_caddyfile"
                    else
                        cp -a -- "$previous_main" "$rollback_file"
                        mv -fT -- "$rollback_file" "$live_caddyfile"
                    fi
                    systemctl reload-or-restart "$caddy_service" || true
                    rm -rf -- "$published"
                    exit 1
                fi
                BASH,
        ));
    }

    private function run(ProcessInvocation $invocation): \App\Infrastructure\Processes\CommandResult
    {
        $result = $this->processes->run($invocation);

        if (! $result->succeeded()) {
            throw new ResourceOperationException(
                'metrics.caddy_publication_failed',
                'Metrics Caddy publication did not complete.',
                502,
            );
        }

        return $result;
    }
}
