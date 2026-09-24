<?php

declare(strict_types=1);

namespace App\Infrastructure\Caddy\Build;

use App\Domain\Nodes\CaddyRelease;
use App\Infrastructure\Caddy\CaddyPublicationLock;
use App\Infrastructure\Ssh\RemoteCommand;
use InvalidArgumentException;

/**
 * The one root script that pushes a rendered Node Caddyfile (ADR 0141). It takes the Node lock,
 * checks the Caddy release floor, writes and validates a new version, backs up a live Caddyfile
 * that no build wrote, swaps the live symlink, reloads Caddy, restores the previous target when
 * the reload fails, and keeps the live version plus the nine newest others.
 *
 * It reports its last stage on stderr as `orbit-caddy-build-stage=<stage>` and its result on
 * stdout as `orbit-caddy-build-result=<published|unchanged>`.
 */
final readonly class NodeCaddyPushScript
{
    public const int Retained = 9;

    public function __construct(
        private string $caddyDirectory = '/etc/caddy',
        private string $caddyExecutable = '/usr/bin/caddy',
        private string $caddyServiceName = 'caddy',
        private string $lockPath = CaddyPublicationLock::Path,
        private string $minimumRelease = CaddyRelease::MINIMUM,
    ) {}

    public function command(NodeCaddyfile $caddyfile): RemoteCommand
    {
        if (! $caddyfile->buildable()) {
            throw new InvalidArgumentException('A Node Caddyfile with render problems cannot be pushed.');
        }

        if (preg_match('/\A[0-9a-f]{32}\z/D', $caddyfile->version) !== 1
            || NodeCaddyfileRenderer::version($caddyfile->content) !== $caddyfile->version) {
            throw new InvalidArgumentException('The Node Caddyfile version does not match its content.');
        }

        return new RemoteCommand(
            arguments: [
                'sudo',
                'bash',
                '-seu',
                '--',
                $caddyfile->version,
                $this->caddyDirectory,
                $this->caddyExecutable,
                $this->caddyServiceName,
                $this->lockPath,
                $this->minimumRelease,
                NodeCaddyfileRenderer::Marker,
                (string) self::Retained,
            ],
            input: $this->script(base64_encode($caddyfile->content)),
            timeout: 120.0,
        );
    }

    private function script(string $encoded): string
    {
        $lock = CaddyPublicationLock::script();

        return <<<BASH
            version=\$1
            caddy_directory=\$2
            caddy_bin=\$3
            caddy_service=\$4
            lock=\$5
            minimum=\$6
            marker=\$7
            retained=\$8
            versions="\$caddy_directory/orbit-versions"
            backups="\$caddy_directory/orbit-backups"
            live="\$caddy_directory/Caddyfile"
            published="\$versions/\$version"
            candidate="\$versions/.\$version.candidate"
            link="\$caddy_directory/.Caddyfile.orbit-build-\$version"
            previous_file="\$caddy_directory/.Caddyfile.orbit-previous-\$version"
            stage=lock
            report() {
                status=\$?
                rm -rf -- "\$candidate"
                rm -f -- "\$link" "\$previous_file"
                if [ "\$status" != 0 ]; then
                    printf 'orbit-caddy-build-stage=%s\\n' "\$stage" >&2
                fi
                exit "\$status"
            }
            trap report EXIT
            umask 0077
            {$lock}

            stage=release
            reported=\$("\$caddy_bin" version 2>/dev/null | head -n 1 | awk '{ print \$1 }' || true)
            installed=\${reported#v}
            if ! printf '%s\\n' "\$installed" | grep -Eq '^[0-9]+[.][0-9]+[.][0-9]+'; then
                printf 'Caddy at %s reported no release.\\n' "\$caddy_bin" >&2
                exit 1
            fi
            lowest=\$(printf '%s\\n%s\\n' "\$minimum" "\${installed%%[-+ ]*}" | sort -V | head -n 1)
            if [ "\$lowest" != "\$minimum" ]; then
                printf 'Caddy %s at %s is below the Orbit release floor %s.\\n' "\$installed" "\$caddy_bin" "\$minimum" >&2
                exit 1
            fi

            stage=unchanged
            live_main=
            if [ -e "\$live" ] || [ -L "\$live" ]; then
                live_main=\$(readlink -f -- "\$live" || true)
            fi
            if [ -L "\$live" ] && [ "\$live_main" = "\$published/Caddyfile" ] && [ -f "\$published/Caddyfile" ]; then
                printf '%s' '{$encoded}' | base64 --decode | cmp -s -- - "\$published/Caddyfile" && {
                    printf 'orbit-caddy-build-result=unchanged\\n'
                    exit 0
                }
                printf 'The live version %s was changed after its build.\\n' "\$version" >&2
                exit 1
            fi

            stage=write
            install -d -o root -g caddy -m 0750 -- "\$versions"
            rm -rf -- "\$candidate"
            install -d -o root -g caddy -m 0750 -- "\$candidate"
            printf '%s' '{$encoded}' | base64 --decode > "\$candidate/Caddyfile"
            chown root:caddy -- "\$candidate/Caddyfile"
            chmod 0640 -- "\$candidate/Caddyfile"
            written=\$(sha256sum -- "\$candidate/Caddyfile" | awk '{ print substr(\$1, 1, 32) }')
            test "\$written" = "\$version"

            stage=validate
            if ! validation=\$(runuser -u caddy -- "\$caddy_bin" validate --config "\$candidate/Caddyfile" --adapter caddyfile 2>&1); then
                printf '%s\n' "\$validation" | grep -v '"level":"info"' | tail -n 5 >&2
                exit 1
            fi

            stage=backup
            backup_source=
            backup_kind=
            if [ -L "\$live" ]; then
                if [ -z "\$live_main" ] || [ ! -f "\$live_main" ] || [ "\$(head -n 1 -- "\$live_main")" = "\$marker" ]; then
                    :
                elif [ "\${live_main#"\$versions"/}" != "\$live_main" ] && [ -d "\$(dirname -- "\$live_main")/fragments" ]; then
                    backup_source=\$(dirname -- "\$live_main")
                    backup_kind=directory
                else
                    backup_source=\$live_main
                    backup_kind=file
                fi
            elif [ -f "\$live" ] && [ "\$(head -n 1 -- "\$live")" != "\$marker" ]; then
                package_md5=\$(dpkg-query -W -f='\${Conffiles}\\n' "\$caddy_service" 2>/dev/null | awk -v live="\$live" '\$1 == live { print \$2; exit }' || true)
                live_md5=\$(md5sum -- "\$live" | awk '{ print \$1 }')
                if [ -z "\$package_md5" ] || [ "\$live_md5" != "\$package_md5" ]; then
                    backup_source=\$live
                    backup_kind=file
                fi
            fi
            if [ -n "\$backup_kind" ]; then
                install -d -o root -g root -m 0700 -- "\$backups"
                stamp=\$(date -u +%Y%m%dT%H%M%SZ)
                backup="\$backups/\$stamp"
                suffix=1
                while ! mkdir -m 0700 -- "\$backup" 2>/dev/null; do
                    suffix=\$((suffix + 1))
                    test "\$suffix" -le 100
                    backup="\$backups/\$stamp-\$suffix"
                done
                if [ "\$backup_kind" = directory ]; then
                    cp -a -- "\$backup_source" "\$backup/\$(basename -- "\$backup_source")"
                else
                    cp -p -- "\$backup_source" "\$backup/Caddyfile"
                fi
                printf 'Backed up the replaced Caddy configuration to %s.\\n' "\$backup" >&2
            fi

            stage=swap
            previous_target=
            had_live=0
            if [ -L "\$live" ]; then
                previous_target=\$(readlink -- "\$live")
                had_live=1
            elif [ -e "\$live" ]; then
                cp -a -- "\$live" "\$previous_file"
                had_live=1
            fi
            rm -rf -- "\$published"
            mv -T -- "\$candidate" "\$published"
            ln -s -- "\$published/Caddyfile" "\$link"
            mv -fT -- "\$link" "\$live"

            stage=reload
            if ! systemctl enable --quiet "\$caddy_service" || ! systemctl reload-or-restart "\$caddy_service"; then
                if [ -n "\$previous_target" ]; then
                    ln -s -- "\$previous_target" "\$link"
                    mv -fT -- "\$link" "\$live"
                elif [ "\$had_live" = 1 ]; then
                    mv -fT -- "\$previous_file" "\$live"
                else
                    rm -f -- "\$live"
                fi
                if [ "\$had_live" = 1 ]; then
                    systemctl reload-or-restart "\$caddy_service" || systemctl restart "\$caddy_service" || true
                fi
                rm -rf -- "\$published"
                journalctl -u "\$caddy_service" -n 20 --no-pager -o cat 2>/dev/null | grep '^Error:' | tail -n 1 >&2 || true
                printf 'Caddy did not reload the new version; the previous configuration is live again.\\n' >&2
                exit 1
            fi

            stage=prune
            kept=0
            for directory in \$(ls -1dt -- "\$versions"/*/ 2>/dev/null); do
                directory=\${directory%/}
                name=\$(basename -- "\$directory")
                case "\$name" in
                    staged|.*) continue ;;
                esac
                if [ "\$directory" = "\$published" ] || [ ! -f "\$directory/Caddyfile" ]; then
                    continue
                fi
                if [ "\$kept" -lt "\$retained" ]; then
                    kept=\$((kept + 1))
                    continue
                fi
                rm -rf -- "\$directory" || printf 'Could not remove the old version %s.\\n' "\$name" >&2
            done
            printf 'orbit-caddy-build-result=published\\n'
            BASH;
    }
}
