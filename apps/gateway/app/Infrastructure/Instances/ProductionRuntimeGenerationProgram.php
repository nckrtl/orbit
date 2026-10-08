<?php

declare(strict_types=1);

namespace App\Infrastructure\Instances;

final class ProductionRuntimeGenerationProgram
{
    public static function functions(): string
    {
        return <<<'BASH'
            runtime_pool_generation() {
                printf '%s' "$pool_configuration" | base64 --decode \
                    | grep -E '^(chdir = |; Orbit application release: )' \
                    | sha256sum | awk '{print $1}'
            }
            runtime_master_token() {
                local pid start boot
                pid=$(systemctl show --property MainPID --value "$service") || return 1
                case "$pid" in ''|*[!0-9]*) return 1 ;; esac
                test "$pid" -gt 1 || return 1
                boot=$(cat "$proc_root/sys/kernel/random/boot_id") || return 1
                printf '%s' "$boot" | grep -Eq '^[0-9a-f-]{36}$' || return 1
                start=$(sed 's/^.*) //' "$proc_root/$pid/stat" | awk '{print $20}') || return 1
                case "$start" in ''|*[!0-9]*) return 1 ;; esac
                test "$(systemctl show --property MainPID --value "$service")" = "$pid" || return 1
                printf '%s\n%s\n%s' "$boot" "$pid" "$start"
            }
            guard_runtime_receipt() {
                local path=$1
                if [ -e "$path" ] || [ -L "$path" ]; then
                    test -f "$path" && test ! -L "$path" || return 1
                    test "$(stat -c '%U:%G:%a' -- "$path")" = root:root:600 || return 1
                fi
            }
            write_runtime_receipt() {
                local path=$1 value=$2 candidate
                guard_runtime_receipt "$path" || return 1
                candidate=$(mktemp "$runtime_directory/.runtime-receipt.XXXXXXXX") || return 1
                printf '%s\n' "$value" > "$candidate" || return 1
                chown root:root -- "$candidate" || return 1
                chmod 0600 -- "$candidate" || return 1
                sync -f "$candidate" || return 1
                mv -fT -- "$candidate" "$path" || return 1
                sync -f "$runtime_directory"
            }
            # The receipt names the master that confirmed the generation. With no pending receipt, a
            # master that started later (after a reboot, or a restart in the same boot) loaded the same
            # confirmed files, so a restart outside Orbit does not make the generation unapplied.
            runtime_generation_applied() {
                local token applied boot start recorded_boot recorded_start
                test ! -e "$runtime_directory/.runtime-generation.pending" \
                    && test ! -L "$runtime_directory/.runtime-generation.pending" || return 1
                guard_runtime_receipt "$runtime_directory/.runtime-generation.applied" || return 1
                test -f "$runtime_directory/.runtime-generation.applied" || return 1
                token=$(runtime_master_token) || return 1
                applied=$(cat "$runtime_directory/.runtime-generation.applied") || return 1
                test "$(printf '%s\n' "$applied" | sed -n 1p)" = "$(runtime_pool_generation)" || return 1
                test "$(printf '%s\n' "$applied" | wc -l)" -eq 4 || return 1
                if test "$applied" = "$(runtime_pool_generation)
            $token"; then
                    return 0
                fi
                boot=$(printf '%s\n' "$token" | sed -n 1p)
                start=$(printf '%s\n' "$token" | sed -n 3p)
                recorded_boot=$(printf '%s\n' "$applied" | sed -n 2p)
                recorded_start=$(printf '%s\n' "$applied" | sed -n 4p)
                printf '%s' "$recorded_boot" | grep -Eq '^[0-9a-f-]{36}$' || return 1
                case "$recorded_start" in ''|*[!0-9]*) return 1 ;; esac
                test "$recorded_boot" != "$boot" && return 0
                test "$start" -gt "$recorded_start"
            }
            begin_runtime_generation() {
                guard_runtime_receipt "$runtime_directory/.runtime-generation.applied" || return 1
                write_runtime_receipt "$runtime_directory/.runtime-generation.pending" "$(runtime_pool_generation)"
            }
            confirm_runtime_generation() {
                local token value
                token=$(runtime_master_token) || return 1
                value="$(runtime_pool_generation)
            $token"
                write_runtime_receipt "$runtime_directory/.runtime-generation.applied" "$value" || return 1
                rm -f -- "$runtime_directory/.runtime-generation.pending" || return 1
                sync -f "$runtime_directory" || return 1
                runtime_generation_applied
            }
            BASH;
    }
}
