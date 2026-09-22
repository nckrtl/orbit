<?php

declare(strict_types=1);

namespace App\Infrastructure\Nodes\Roles;

use App\Domain\Nodes\ManagedUserAccount;
use App\Domain\Nodes\RoleName;
use App\Domain\Nodes\UbuntuRelease;
use App\Infrastructure\Nodes\CaddyPackageSourceProgram;
use App\Infrastructure\Nodes\NodeBootstrapPackageCatalog;
use App\Infrastructure\Nodes\OsReleaseParserProgram;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Models\Node;

final readonly class NodeRolePrerequisiteCommandFactory
{
    public function __construct(
        private NodeBootstrapPackageCatalog $packages = new NodeBootstrapPackageCatalog,
    ) {}

    /**
     * Publishes the pinned Caddy apt source before the role installs its packages, so `caddy` comes
     * from the Caddy project rather than the Ubuntu archive. Null when the role needs no Caddy.
     * ADR 0100 records the decision.
     */
    public function caddySource(Node $node, RoleName $role): ?RemoteCommand
    {
        if (! in_array('caddy', $this->packages->forRole($node, $role), strict: true)) {
            return null;
        }

        return new RemoteCommand(
            arguments: ['sudo', 'bash', '-seu', '--', ...CaddyPackageSourceProgram::arguments()],
            input: CaddyPackageSourceProgram::render(),
        );
    }

    public function make(Node $node, RoleName $role, ManagedUserAccount $account): RemoteCommand
    {
        if ($role === RoleName::Gateway) {
            return new RemoteCommand(['true']);
        }

        $input = <<<'BASH'
            role=$1
            shift
            managed_user=$1
            managed_group=$2
            managed_home=$3
            shift 3
            BASH;
        $input .= "\n".OsReleaseParserProgram::render()."\n".<<<'BASH'
            docker_ce_healthy=false
            if [ "$(dpkg-query -W -f='${Status}' docker-ce 2>/dev/null)" = 'install ok installed' ] \
                && [ "$(dpkg-query -W -f='${Status}' docker-ce-cli 2>/dev/null)" = 'install ok installed' ] \
                && [ "$(dpkg-query -W -f='${Status}' containerd.io 2>/dev/null)" = 'install ok installed' ] \
                && test -x /usr/bin/docker \
                && systemctl is-active --quiet docker; then
                docker_ce_healthy=true
            fi
            if [ "$docker_ce_healthy" = true ]; then
                prerequisite_packages=()
                for prerequisite_package in "$@"; do
                    [ "$prerequisite_package" = docker.io ] || prerequisite_packages+=("$prerequisite_package")
                done
                set -- "${prerequisite_packages[@]}"
            fi

            export DEBIAN_FRONTEND=noninteractive
            apt-get update
            apt-get install --yes --no-install-recommends --no-remove -- "$@"

            __APP_DEV_SETUP__

            __APP_HOST_RUNTIME__
            BASH;
        $appDevSetup = <<<'BASH'
                install -d -m 0755 -o "$managed_user" -g "$managed_group" "$managed_home/apps" "$managed_home/.orbit/worktrees"
            BASH;
        $runtime = <<<'BASH'
                if { [ -e /opt/orbit ] || [ -L /opt/orbit ]; } \
                    && { [ -L /opt/orbit ] || [ ! -d /opt/orbit ] || [ "$(stat -c '%U:%G' /opt/orbit)" != 'root:root' ]; }; then
                    printf 'Orbit JavaScript runtime directory conflict: %s\n' /opt/orbit >&2
                    exit 1
                fi

                directory=/opt/orbit/bun
                if { [ -e "$directory" ] || [ -L "$directory" ]; } \
                    && { [ -L "$directory" ] || [ ! -d "$directory" ] || [ "$(stat -c '%U:%G' "$directory")" != "$managed_user:$managed_group" ]; }; then
                    printf 'Orbit JavaScript runtime directory conflict: %s\n' "$directory" >&2
                    exit 1
                fi

                install -d -m 0755 /opt/orbit
                install -d -m 0755 -o "$managed_user" -g "$managed_group" /opt/orbit/bun
                chown -R --no-dereference "$managed_user:$managed_group" /opt/orbit/bun

                sudo -u "$managed_user" -H env BUN_INSTALL=/opt/orbit/bun bash -o pipefail -c 'curl -fsSL https://bun.com/install | bash'
                bun_binary=/opt/orbit/bun/bin/bun
                test -x "$bun_binary"

                chmod -R a+rX /opt/orbit/bun

                if { [ -e /usr/local/bin/bun ] || [ -L /usr/local/bin/bun ]; } \
                    && { [ ! -L /usr/local/bin/bun ] \
                        || [ "$(stat -c '%U:%G' /usr/local/bin/bun)" != 'root:root' ] \
                        || [ "$(readlink /usr/local/bin/bun)" != "$bun_binary" ]; }; then
                    printf 'Orbit JavaScript runtime link conflict: %s\n' /usr/local/bin/bun >&2
                    exit 1
                fi

                bun_published=false
                rollback_bun_runtime() {
                    runtime_status=$?
                    if [ "$runtime_status" -ne 0 ] && [ "$bun_published" = true ]; then
                        rm -f -- /usr/local/bin/bun
                    fi
                    return "$runtime_status"
                }
                trap rollback_bun_runtime EXIT

                if ! { [ -e /usr/local/bin/bun ] || [ -L /usr/local/bin/bun ]; }; then
                    ln -s "$bun_binary" /usr/local/bin/bun
                    bun_published=true
                fi

                sudo -u "$managed_user" -H env BUN_INSTALL=/opt/orbit/bun /usr/local/bin/bun --version

                bun_published=false
                trap - EXIT
            BASH;

        $input = str_replace('__APP_DEV_SETUP__', $role === RoleName::AppDev ? $appDevSetup : '', $input);
        $input = str_replace(
            '__APP_HOST_RUNTIME__',
            in_array($role, [RoleName::AppDev, RoleName::AppProd], strict: true) ? $runtime : '',
            $input,
        );

        return new RemoteCommand(
            arguments: [
                'sudo',
                'bash',
                '-seu',
                '--',
                $role->value,
                $account->user,
                $account->group,
                $account->home,
                'ubuntu',
                UbuntuRelease::unsupportedText(),
                (string) count(UbuntuRelease::forRole($role)),
                ...array_map(
                    static fn (UbuntuRelease $release): string => $release->value,
                    UbuntuRelease::forRole($role),
                ),
                ...$this->packages->forRole($node, $role),
            ],
            input: $input,
        );
    }
}
