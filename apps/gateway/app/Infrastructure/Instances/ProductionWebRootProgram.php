<?php

declare(strict_types=1);

namespace App\Infrastructure\Instances;

/**
 * Release work for the web roots that a production Instance's Routes with a web root serve: each
 * application directory's `.env` link to its stable file in `<home>/env/`, and Caddy access to each
 * web root. A caller sets `user`, `home`, and `served_web_roots`, which is empty for an Instance
 * without such a Route, so its releases are unchanged.
 */
final class ProductionWebRootProgram
{
    /** @param list<array{web_root: string, directory: string, suffix: string}> $applications */
    public static function entries(array $applications): string
    {
        if ($applications === []) {
            return '';
        }

        return base64_encode(implode('', array_map(
            static fn (array $application): string => "{$application['web_root']}\t{$application['directory']}\n",
            $applications,
        )));
    }

    public static function functions(): string
    {
        return <<<'BASH'
            served_web_root_entries() {
                if [ -n "${served_web_roots:-}" ]; then
                    printf '%s' "$served_web_roots" | base64 --decode
                fi
            }
            served_relative_path() {
                printf '%s' "$1" | grep -Eq '^[A-Za-z0-9._-]+(/[A-Za-z0-9._-]+)*$' || return 1
                case "/$1/" in */./*|*/../*) return 1 ;; esac
            }
            # Links <release>/<directory>/.env to <home>/env/<directory>/.env with a relative target. The
            # stable file can still be missing; the APP_URL writer creates it.
            link_served_environments() {
                local release=$1 web_root directory application stable depth target environment
                while IFS=$'\t' read -r web_root directory; do
                    served_relative_path "$web_root" || exit 1
                    if [ -n "$directory" ]; then
                        served_relative_path "$directory" || exit 1
                        application="$release/$directory"
                        stable="env/$directory/.env"
                        depth=$((3 + $(printf '%s' "$directory" | tr -cd / | wc -c)))
                    else
                        application=$release
                        stable=env/.env
                        depth=2
                    fi
                    sudo -u "$user" -H test -d "$application" || exit 1
                    sudo -u "$user" -H test ! -L "$application" || exit 1
                    test "$(sudo -u "$user" -H realpath -e -- "$application")" = "$application" || exit 1
                    target="$(printf '../%.0s' $(seq 1 "$depth"))$stable"
                    environment="$application/.env"
                    if sudo -u "$user" -H test -L "$environment"; then
                        test "$(sudo -u "$user" -H readlink -- "$environment")" = "$target" || exit 1
                    else
                        sudo -u "$user" -H test ! -e "$environment" || exit 1
                        sudo -u "$user" -H ln -s -- "$target" "$environment"
                    fi
                done < <(served_web_root_entries)
            }
            # Checks without a change that each web root and its application directory exist in the release,
            # and that the web root holds no link.
            check_served_web_roots() {
                local release=$1 web_root directory selected_root application unexpected_symlink
                while IFS=$'\t' read -r web_root directory; do
                    served_relative_path "$web_root" || exit 1
                    selected_root=$(sudo -u "$user" -H realpath -m -- "$release/$web_root")
                    test "$selected_root" = "$release/$web_root" || exit 1
                    sudo -u "$user" -H test -d "$selected_root" || exit 1
                    unexpected_symlink=$(sudo find -P "$selected_root" -type l -print -quit)
                    test -z "$unexpected_symlink" || exit 1
                    application=$release
                    if [ -n "$directory" ]; then application="$release/$directory"; fi
                    test "$(sudo -u "$user" -H realpath -e -- "$application")" = "$application" || exit 1
                done < <(served_web_root_entries)
            }
            # Grants Caddy the same access to each web root as activation grants to the Instance root.
            grant_served_web_roots() {
                local release=$1 web_root directory selected_root unexpected_symlink ancestor
                while IFS=$'\t' read -r web_root directory; do
                    served_relative_path "$web_root" || exit 1
                    selected_root=$(sudo -u "$user" -H realpath -m -- "$release/$web_root")
                    case "$selected_root" in "$release"/*) ;; *) exit 1 ;; esac
                    sudo -u "$user" -H test -d "$selected_root" || exit 1
                    unexpected_symlink=$(sudo find -P "$selected_root" -type l -print -quit)
                    test -z "$unexpected_symlink" || exit 1
                    ancestor=$(dirname -- "$selected_root")
                    while [ "$ancestor" != "$release" ]; do
                        case "$ancestor" in "$release"/*) ;; *) exit 1 ;; esac
                        sudo test -d "$ancestor" || exit 1
                        sudo test ! -L "$ancestor" || exit 1
                        sudo setfacl -m u:caddy:--x "$ancestor"
                        ancestor=$(dirname -- "$ancestor")
                    done
                    sudo setfacl -m u:caddy:--x "$release"
                    sudo setfacl -P -R -m u:caddy:r-X "$selected_root"
                    sudo find -P "$selected_root" -type d -exec setfacl -m d:u:caddy:r-x -- {} +
                done < <(served_web_root_entries)
            }
            BASH;
    }
}
