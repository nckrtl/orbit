<?php

declare(strict_types=1);

namespace App\Infrastructure\Nodes;

final class OsReleaseParserProgram
{
    public static function render(): string
    {
        return <<<'BASH'
            expected_id=$1
            unsupported_text=$2
            allowed_count=$3
            shift 3
            allowed_codenames=("${@:1:$allowed_count}")
            shift "$allowed_count"

            fail_os() {
                if [ "$#" -eq 2 ] && [ -n "$1" ] && [ -n "$2" ]; then
                    printf 'Node operating system [%s/%s] is not supported.\n' "$1" "$2" >&2
                else
                    printf '%s\n' "$unsupported_text" >&2
                fi
                exit 1
            }

            if [ ! -r /etc/os-release ]; then
                fail_os
            fi

            os_id=''
            os_codename=''
            while IFS= read -r os_release_line || [ -n "$os_release_line" ]; do
                case "$os_release_line" in
                    ''|'#'*) continue ;;
                    ID=*|VERSION_CODENAME=*)
                        os_release_key=${os_release_line%%=*}
                        os_release_value=${os_release_line#*=}
                        case "$os_release_value" in
                            \"*)
                                [ "${os_release_value: -1}" = '"' ] || fail_os
                                os_release_value=${os_release_value:1:${#os_release_value}-2}
                                ;;
                            \'*)
                                [ "${os_release_value: -1}" = "'" ] || fail_os
                                os_release_value=${os_release_value:1:${#os_release_value}-2}
                                ;;
                        esac
                        if [ -z "$os_release_value" ] || ! [[ "$os_release_value" =~ ^[A-Za-z0-9._-]+$ ]]; then
                            fail_os
                        fi
                        if [ "$os_release_key" = ID ]; then
                            [ -z "$os_id" ] || fail_os
                            os_id=$os_release_value
                        else
                            [ -z "$os_codename" ] || fail_os
                            os_codename=$os_release_value
                        fi
                        ;;
                    *)
                        continue
                        ;;
                esac
            done < /etc/os-release

            if [ -z "$os_id" ] || [ -z "$os_codename" ]; then
                fail_os
            fi

            selected_codename=''
            for allowed_codename in "${allowed_codenames[@]}"; do
                if [ "$os_id" = "$expected_id" ] && [ "$os_codename" = "$allowed_codename" ]; then
                    selected_codename=$allowed_codename
                    break
                fi
            done
            if [ -z "$selected_codename" ]; then
                fail_os "$os_id" "$os_codename"
            fi
            BASH;
    }
}
