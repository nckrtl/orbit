<?php

declare(strict_types=1);

namespace App\Infrastructure\Nodes\Roles;

use App\Domain\Nodes\RoleName;
use App\Domain\Nodes\UbuntuRelease;
use App\Infrastructure\Ssh\RemoteCommand;

final readonly class NodeRolePrerequisiteCommandFactory
{
    public function make(RoleName $role): RemoteCommand
    {
        if ($role === RoleName::Gateway) {
            return new RemoteCommand(['true']);
        }

        $input = <<<'BASH'
            role=$1
            shift
            release_count=$1
            shift
            requirement_text=$1
            shift

            if ! [ -r /etc/os-release ]; then
                printf '%s\n' "$requirement_text" >&2
                exit 1
            fi

            os_id=
            os_codename=
            found_id=false
            found_codename=false
            while IFS= read -r line || [ -n "$line" ]; do
                case "$line" in
                    ID=*)
                        [ "$found_id" = false ] || { printf '%s\n' "$requirement_text" >&2; exit 1; }
                        os_id=${line#ID=}
                        if [[ "$os_id" =~ ^\"[A-Za-z0-9._-]+\"$ ]]; then os_id=${os_id:1:${#os_id}-2}; elif [[ "$os_id" =~ ^\'[A-Za-z0-9._-]+\'$ ]]; then os_id=${os_id:1:${#os_id}-2}; elif [[ ! "$os_id" =~ ^[A-Za-z0-9._-]+$ ]]; then printf '%s\n' "$requirement_text" >&2; exit 1; fi
                        found_id=true
                        ;;
                    VERSION_CODENAME=*)
                        [ "$found_codename" = false ] || { printf '%s\n' "$requirement_text" >&2; exit 1; }
                        os_codename=${line#VERSION_CODENAME=}
                        if [[ "$os_codename" =~ ^\"[A-Za-z0-9._-]+\"$ ]]; then os_codename=${os_codename:1:${#os_codename}-2}; elif [[ "$os_codename" =~ ^\'[A-Za-z0-9._-]+\'$ ]]; then os_codename=${os_codename:1:${#os_codename}-2}; elif [[ ! "$os_codename" =~ ^[A-Za-z0-9._-]+$ ]]; then printf '%s\n' "$requirement_text" >&2; exit 1; fi
                        found_codename=true
                        ;;
                esac
            done < /etc/os-release

            if [ "$found_id" != true ] || [ "$found_codename" != true ]; then
                printf '%s\n' "$requirement_text" >&2
                exit 1
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
                printf '%s\n' "$requirement_text" >&2
                exit 1
            fi

            export DEBIAN_FRONTEND=noninteractive
            apt-get update
            apt-get install --yes --no-install-recommends -- "$@"

            __APP_DEV_SETUP__

            __APP_COMPOSER_SETUP__

            __APP_HOST_RUNTIME__
            BASH;
        $appDevSetup = <<<'BASH'
                install -d -m 0755 -o orbit -g orbit /home/orbit/apps /home/orbit/.orbit/worktrees
                setfacl -m u:caddy:--x /home/orbit /home/orbit/apps /home/orbit/.orbit /home/orbit/.orbit/worktrees
            BASH;
        $runtime = <<<'BASH'
                if { [ -e /opt/orbit ] || [ -L /opt/orbit ]; } \
                    && { [ -L /opt/orbit ] || [ ! -d /opt/orbit ] || [ "$(stat -c '%U:%G' /opt/orbit)" != 'root:root' ]; }; then
                    printf 'Orbit JavaScript runtime directory conflict: %s\n' /opt/orbit >&2
                    exit 1
                fi

                for directory in /opt/orbit/vite-plus /opt/orbit/bun; do
                    if { [ -e "$directory" ] || [ -L "$directory" ]; } \
                        && { [ -L "$directory" ] || [ ! -d "$directory" ] || [ "$(stat -c '%U:%G' "$directory")" != 'orbit:orbit' ]; }; then
                        printf 'Orbit JavaScript runtime directory conflict: %s\n' "$directory" >&2
                        exit 1
                    fi
                done

                install -d -m 0755 /opt/orbit
                install -d -m 0755 -o orbit -g orbit /opt/orbit/vite-plus /opt/orbit/bun

                sudo -u orbit -H env VP_HOME=/opt/orbit/vite-plus bash -o pipefail -lc 'curl -fsSL https://vite.plus | bash'
                vp_binary=/opt/orbit/vite-plus/bin/vp
                test -x "$vp_binary"
                sudo -u orbit -H env VP_HOME=/opt/orbit/vite-plus "$vp_binary" env setup
                sudo -u orbit -H env VP_HOME=/opt/orbit/vite-plus "$vp_binary" env on
                sudo -u orbit -H env VP_HOME=/opt/orbit/vite-plus "$vp_binary" env install lts
                sudo -u orbit -H env VP_HOME=/opt/orbit/vite-plus "$vp_binary" env default lts
                sudo -u orbit -H env VP_HOME=/opt/orbit/vite-plus "$vp_binary" install -g --node lts pnpm
                pnpm_binary=/opt/orbit/vite-plus/bin/pnpm
                test -x "$pnpm_binary"

                sudo -u orbit -H env BUN_INSTALL=/opt/orbit/bun bash -o pipefail -lc 'curl -fsSL https://bun.com/install | bash'
                bun_binary=/opt/orbit/bun/bin/bun
                test -x "$bun_binary"

                chmod -R a+rX /opt/orbit/vite-plus /opt/orbit/bun

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
                    target="/opt/orbit/vite-plus/bin/$binary"
                    candidate="$launcher_candidates/$binary"
                    test -x "$target"
                    printf '%s\n' '#!/bin/sh' 'export VP_HOME=/opt/orbit/vite-plus' "exec \"$target\" \"\$@\"" > "$candidate"
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

                env VP_HOME=/opt/orbit/vite-plus /usr/local/bin/vp --version
                /usr/local/bin/node --version
                /usr/local/bin/pnpm --version
                /usr/local/bin/npm --version
                /usr/local/bin/npx --version
                /usr/local/bin/bun --version

                rm -rf -- "$launcher_candidates"
                launcher_candidates=
                published_paths=
                trap - EXIT
            BASH;

        $composerSetup = <<<'BASH'
                install -d -m 0755 /opt/orbit
                install -d -m 0755 -o orbit -g orbit /opt/orbit/composer
                if [ -L /opt/orbit/composer/composer.json ]; then
                    printf 'Orbit Composer manifest conflict: %s\n' /opt/orbit/composer/composer.json >&2
                    exit 1
                elif [ -e /opt/orbit/composer/composer.json ]; then
                    if ! test -f /opt/orbit/composer/composer.json \
                        || ! test "$(stat -c %U:%G /opt/orbit/composer/composer.json)" = orbit:orbit; then
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
                    chown orbit:orbit "$composer_manifest"
                    if ! ln "$composer_manifest" /opt/orbit/composer/composer.json; then
                        if [ -L /opt/orbit/composer/composer.json ] \
                            || ! test -f /opt/orbit/composer/composer.json \
                            || ! test "$(stat -c %U:%G /opt/orbit/composer/composer.json)" = orbit:orbit; then
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
                    test "$(stat -c %U:%G /opt/orbit/composer/composer.json)" = orbit:orbit
                }
                revalidate
                composer_manifest=
                trap - EXIT
                install -d -m 0755 -o orbit -g orbit /opt/orbit/composer/vendor/bin
                sudo -u orbit -H env COMPOSER_HOME=/opt/orbit/composer /usr/bin/composer --version --no-ansi
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
                (string) count(UbuntuRelease::forRole($role)),
                UbuntuRelease::requirementTextFor(UbuntuRelease::forRole($role)),
                ...array_map(
                    static fn (UbuntuRelease $release): string => $release->value,
                    UbuntuRelease::forRole($role),
                ),
                ...$this->packages($role),
            ],
            input: $input,
        );
    }

    /** @return non-empty-list<string> */
    private function packages(RoleName $role): array
    {
        return match ($role) {
            RoleName::AppDev, RoleName::AppProd => [
                'acl',
                'attr',
                'caddy',
                'composer',
                'docker.io',
                'git',
                'openssl',
                'unzip',
            ],
            RoleName::Vpn => ['dnsmasq', 'openssl'],
            RoleName::Gateway => ['ca-certificates'],
        };
    }
}
