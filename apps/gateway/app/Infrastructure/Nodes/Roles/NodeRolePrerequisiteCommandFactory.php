<?php

declare(strict_types=1);

namespace App\Infrastructure\Nodes\Roles;

use App\Domain\Nodes\ManagedUserAccount;
use App\Domain\Nodes\RoleName;
use App\Domain\Nodes\UbuntuRelease;
use App\Infrastructure\Nodes\NodeBootstrapPackageCatalog;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Models\Node;

final readonly class NodeRolePrerequisiteCommandFactory
{
    public function __construct(
        private NodeBootstrapPackageCatalog $packages = new NodeBootstrapPackageCatalog,
    ) {}

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
            release_count=$1
            shift
            unsupported_text=$1
            shift

            fail_os() {
                if [ "$#" -eq 2 ] && [ -n "$1" ] && [ -n "$2" ]; then
                    printf 'Node operating system [%s/%s] is not supported.\n' "$1" "$2" >&2
                else
                    printf '%s\n' "$unsupported_text" >&2
                fi
                exit 1
            }

            if ! [ -r /etc/os-release ]; then
                fail_os
            fi

            os_id=
            os_codename=
            found_id=false
            found_codename=false
            while IFS= read -r line || [ -n "$line" ]; do
                case "$line" in
                    ID=*)
                        [ "$found_id" = false ] || fail_os
                        os_id=${line#ID=}
                        if [[ "$os_id" =~ ^\"[A-Za-z0-9._-]+\"$ ]]; then os_id=${os_id:1:${#os_id}-2}; elif [[ "$os_id" =~ ^\'[A-Za-z0-9._-]+\'$ ]]; then os_id=${os_id:1:${#os_id}-2}; elif [[ ! "$os_id" =~ ^[A-Za-z0-9._-]+$ ]]; then fail_os; fi
                        found_id=true
                        ;;
                    VERSION_CODENAME=*)
                        [ "$found_codename" = false ] || fail_os
                        os_codename=${line#VERSION_CODENAME=}
                        if [[ "$os_codename" =~ ^\"[A-Za-z0-9._-]+\"$ ]]; then os_codename=${os_codename:1:${#os_codename}-2}; elif [[ "$os_codename" =~ ^\'[A-Za-z0-9._-]+\'$ ]]; then os_codename=${os_codename:1:${#os_codename}-2}; elif [[ ! "$os_codename" =~ ^[A-Za-z0-9._-]+$ ]]; then fail_os; fi
                        found_codename=true
                        ;;
                esac
            done < /etc/os-release

            if [ "$found_id" != true ] || [ "$found_codename" != true ]; then
                fail_os
            fi

            supported_release=false
            for _ in $(seq 1 "$release_count"); do
                supported_codename=$1
                shift
                if [ "$os_codename" = "$supported_codename" ]; then
                    supported_release=true
                fi
            done

            if [ "$os_id" != 'ubuntu' ] || [ "$supported_release" != 'true' ]; then
                fail_os "$os_id" "$os_codename"
            fi

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

            __APP_COMPOSER_SETUP__

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

                vp_home=
                vp_environment=
                launcher_environment=
                for candidate in /opt/orbit/vite-plus "$managed_home/.vite-plus" "$managed_home/.local/share/vite-plus"; do
                    if [ -e "$candidate" ] || [ -L "$candidate" ]; then
                        vp_home="$candidate"
                        break
                    fi
                done
                if [ -z "$vp_home" ]; then
                    vp_home="$managed_home/.local/share/vite-plus"
                fi
                if [ "$vp_home" = /opt/orbit/vite-plus ]; then
                    vp_environment='VP_HOME=/opt/orbit/vite-plus'
                    launcher_environment='export VP_HOME=/opt/orbit/vite-plus'
                fi
                if { [ -e "$vp_home" ] || [ -L "$vp_home" ]; } \
                    && { [ -L "$vp_home" ] || [ ! -d "$vp_home" ]; }; then
                    printf 'Orbit Vite Plus directory conflict: %s\n' "$vp_home" >&2
                    exit 1
                fi
                vp_binary="$vp_home/bin/vp"
                if [ ! -x "$vp_binary" ]; then
                    sudo -u "$managed_user" -H env -u VP_HOME bash -o pipefail -c 'curl -fsSL https://vite.plus | bash'
                    test -x "$vp_binary"
                    sudo -u "$managed_user" -H env ${vp_environment:-} "$vp_binary" env setup
                    sudo -u "$managed_user" -H env ${vp_environment:-} "$vp_binary" env on
                    sudo -u "$managed_user" -H env ${vp_environment:-} "$vp_binary" env install lts
                    sudo -u "$managed_user" -H env ${vp_environment:-} "$vp_binary" env default lts
                    sudo -u "$managed_user" -H env ${vp_environment:-} "$vp_binary" install -g --node lts pnpm
                fi
                test -x "$vp_binary"
                pnpm_binary="$vp_home/bin/pnpm"
                test -x "$pnpm_binary"

                sudo -u "$managed_user" -H env BUN_INSTALL=/opt/orbit/bun bash -o pipefail -c 'curl -fsSL https://bun.com/install | bash'
                bun_binary=/opt/orbit/bun/bin/bun
                test -x "$bun_binary"

                chmod -R a+rX /opt/orbit/bun

                launcher_candidates=$(mktemp -d "/usr/local/bin/.orbit-js-runtime.XXXXXX")
                published_paths=
                rollback_javascript_runtime() {
                    runtime_status=$?
                    if [ "$runtime_status" -ne 0 ]; then
                        for published_path in $published_paths; do
                            rm -f -- "$published_path"
                        done
                    fi
                    rm -rf -- "$launcher_candidates"
                    return "$runtime_status"
                }
                trap rollback_javascript_runtime EXIT

                for binary in vp node pnpm npm npx; do
                    target="$vp_home/bin/$binary"
                    candidate="$launcher_candidates/$binary"
                    test -x "$target"
                    launcher_header='#!/bin/sh'
                    if [ -n "${launcher_environment:-}" ]; then
                        launcher_header="$launcher_header\\n$launcher_environment"
                    fi
                    printf '%b\n' "$launcher_header" "exec \"$target\" \"\$@\"" > "$candidate"
                    chmod 0755 "$candidate"
                    chown root:root "$candidate"
                done

                for binary in vp node pnpm npm npx; do
                    launcher="/usr/local/bin/$binary"
                    candidate="$launcher_candidates/$binary"
                    if { [ -e "$launcher" ] || [ -L "$launcher" ]; } \
                        && { [ -L "$launcher" ] || [ ! -f "$launcher" ] \
                            || [ "$(stat -c '%U:%G' "$launcher")" != 'root:root' ] \
                            || [ "$(stat -c '%a' "$launcher")" != '755' ] \
                            || ! cmp -s "$launcher" "$candidate"; }; then
                        printf 'Orbit JavaScript runtime launcher conflict: %s\n' "$launcher" >&2
                        exit 1
                    fi
                done

                if { [ -e /usr/local/bin/bun ] || [ -L /usr/local/bin/bun ]; } \
                    && { [ ! -L /usr/local/bin/bun ] \
                        || [ "$(stat -c '%U:%G' /usr/local/bin/bun)" != 'root:root' ] \
                        || [ "$(readlink /usr/local/bin/bun)" != "$bun_binary" ]; }; then
                    printf 'Orbit JavaScript runtime link conflict: %s\n' /usr/local/bin/bun >&2
                    exit 1
                fi

                for binary in vp node pnpm npm npx; do
                    launcher="/usr/local/bin/$binary"
                    candidate="$launcher_candidates/$binary"
                    if ! { [ -e "$launcher" ] || [ -L "$launcher" ]; }; then
                        mv "$candidate" "$launcher"
                        published_paths="$published_paths $launcher"
                    fi
                done

                if ! { [ -e /usr/local/bin/bun ] || [ -L /usr/local/bin/bun ]; }; then
                    ln -s "$bun_binary" /usr/local/bin/bun
                    published_paths="$published_paths /usr/local/bin/bun"
                fi

                sudo -u "$managed_user" -H /usr/local/bin/vp --version
                sudo -u "$managed_user" -H /usr/local/bin/node --version
                sudo -u "$managed_user" -H /usr/local/bin/pnpm --version
                sudo -u "$managed_user" -H /usr/local/bin/npm --version
                sudo -u "$managed_user" -H /usr/local/bin/npx --version
                sudo -u "$managed_user" -H env BUN_INSTALL=/opt/orbit/bun /usr/local/bin/bun --version

                rm -rf -- "$launcher_candidates"
                launcher_candidates=
                published_paths=
                trap - EXIT
            BASH;

        $composerSetup = <<<'BASH'
                install -d -m 0755 /opt/orbit
                install -d -m 0755 -o "$managed_user" -g "$managed_group" /opt/orbit/composer
                if [ -L /opt/orbit/composer/composer.json ]; then
                    printf 'Orbit Composer manifest conflict: %s\n' /opt/orbit/composer/composer.json >&2
                    exit 1
                elif [ -e /opt/orbit/composer/composer.json ]; then
                    if ! test -f /opt/orbit/composer/composer.json \
                        || ! test "$(stat -c %U:%G /opt/orbit/composer/composer.json)" = "$managed_user:$managed_group"; then
                        printf 'Orbit Composer manifest conflict: %s\n' /opt/orbit/composer/composer.json >&2
                        exit 1
                    fi
                else
                    composer_manifest=$(mktemp /opt/orbit/.composer.json.XXXXXX)
                    cleanup_composer_manifest() {
                        [ -z "${composer_manifest:-}" ] || rm -f -- "$composer_manifest"
                    }
                    trap cleanup_composer_manifest EXIT
                    printf '%s\n' '{"require":{}}' > "$composer_manifest"
                    chmod 0644 "$composer_manifest"
                    chown "$managed_user":"$managed_group" "$composer_manifest"
                    if ! ln "$composer_manifest" /opt/orbit/composer/composer.json; then
                        if [ -L /opt/orbit/composer/composer.json ] \
                            || ! test -f /opt/orbit/composer/composer.json \
                            || ! test "$(stat -c %U:%G /opt/orbit/composer/composer.json)" = "$managed_user:$managed_group"; then
                            rm -f -- "$composer_manifest"
                            printf 'Orbit Composer manifest conflict: %s\n' /opt/orbit/composer/composer.json >&2
                            exit 1
                        fi
                    fi
                    rm -f -- "$composer_manifest"
                fi
                revalidate() {
                    test ! -L /opt/orbit/composer/composer.json
                    test -f /opt/orbit/composer/composer.json
                    test "$(stat -c %U:%G /opt/orbit/composer/composer.json)" = "$managed_user:$managed_group"
                }
                revalidate
                composer_manifest=
                trap - EXIT
                install -d -m 0755 -o "$managed_user" -g "$managed_group" /opt/orbit/composer/vendor /opt/orbit/composer/vendor/bin
                sudo -u "$managed_user" -H env COMPOSER_HOME=/opt/orbit/composer /usr/bin/composer --version --no-ansi
            BASH;

        $input = str_replace('__APP_DEV_SETUP__', $role === RoleName::AppDev ? $appDevSetup : '', $input);
        $input = str_replace(
            '__APP_COMPOSER_SETUP__',
            in_array($role, [RoleName::AppDev, RoleName::AppProd], strict: true) ? $composerSetup : '',
            $input,
        );
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
                (string) count(UbuntuRelease::forRole($role)),
                UbuntuRelease::unsupportedText(),
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
