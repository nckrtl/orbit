<?php

declare(strict_types=1);

namespace App\Infrastructure\AppInstances;

use App\Domain\AppInstances\ProductionPhpRuntimeIdentity;
use App\Domain\AppInstances\ProductionPhpRuntimeManager;
use App\Infrastructure\AppProd\AppProdSshExecutor;
use App\Infrastructure\Nodes\RemotePhpPackageManager;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Models\AppInstance;

final readonly class RemoteProductionPhpRuntimeManager implements ProductionPhpRuntimeManager
{
    public function __construct(
        private ProductionPhpRuntimeConfigRenderer $renderer,
        private AppProdSshExecutor $ssh,
        private string $lockDirectory = '/run/lock/orbit',
        private RemotePhpPackageManager $packages = new RemotePhpPackageManager,
    ) {}

    public function converge(AppInstance $appInstance): void
    {
        $identity = ProductionPhpRuntimeIdentity::from($appInstance);
        $configuration = $this->renderer->render($identity);
        /** @var \Illuminate\Support\Collection<int, string> $versions */
        $versions = collect([$identity->version]);
        $this->packages->installPackagesOnlyForAppProd(
            $appInstance->node->loadMissing('roles'),
            $versions,
            $this->ssh,
        );

        $this->ssh->execute(
            $appInstance->node,
            new RemoteCommand(
                arguments: [
                    'sudo',
                    'bash',
                    '-seu',
                    '--',
                    'converge',
                    $identity->user,
                    $identity->home,
                    $identity->version,
                    $identity->service,
                    $identity->pool,
                    $identity->socket,
                    $identity->runtimeDirectory,
                    $identity->generatedDirectory,
                    $identity->localTuningPath,
                    $identity->unitPath,
                    $identity->markerPath,
                    $this->lockDirectory,
                    base64_encode($configuration->main),
                    base64_encode($configuration->pool),
                    base64_encode($configuration->localDefaults),
                    base64_encode($configuration->unit),
                    base64_encode($identity->marker()),
                ],
                input: $this->convergeScript(),
            ),
            step: 'app-prod-php-runtime-converge',
            errorCode: 'app-prod.php_runtime_convergence_failed',
        );
    }

    public function remove(AppInstance $appInstance): void
    {
        $identity = ProductionPhpRuntimeIdentity::from($appInstance);

        $this->ssh->execute(
            $appInstance->node,
            new RemoteCommand(
                arguments: [
                    'sudo',
                    'bash',
                    '-seu',
                    '--',
                    'remove',
                    $identity->user,
                    $identity->service,
                    $identity->pool,
                    $identity->socket,
                    $identity->runtimeDirectory,
                    $identity->generatedDirectory,
                    $identity->localTuningPath,
                    $identity->unitPath,
                    $identity->markerPath,
                    $this->lockDirectory,
                    base64_encode($identity->marker()),
                ],
                input: $this->removeScript(),
            ),
            step: 'app-prod-php-runtime-remove',
            errorCode: 'app-prod.php_runtime_removal_failed',
        );
    }

    private function convergeScript(): string
    {
        return <<<'BASH'
            operation=$1
            user=$2
            home=$3
            version=$4
            service=$5
            pool=$6
            socket=$7
            runtime_directory=$8
            generated_directory=$9
            local_tuning=${10}
            unit_path=${11}
            marker_path=${12}
            lock_directory=${13}
            main_configuration=${14}
            pool_configuration=${15}
            local_defaults=${16}
            unit_configuration=${17}
            marker_configuration=${18}
            test "$operation" = converge

            test "$home" = "/home/$user"
            expected_service="orbit-$user-php${version}-fpm.service"
            test "$service" = "$expected_service"
            test "$pool" = "orbit-$user"
            test "$socket" = "/run/php/$user.sock"
            test "$runtime_directory" = "/etc/orbit/php-fpm/$user"
            test "$generated_directory" = "$runtime_directory/generated"
            test "$local_tuning" = "$runtime_directory/local.conf"
            test "$unit_path" = "/etc/systemd/system/$service"
            test "$marker_path" = "$runtime_directory/orbit.identity"
            id "$user" >/dev/null
            test -d "$home"
            test ! -L "$home"
            test "$(stat -c '%U:%G' -- "$home")" = "$user:$user"

            umask 0077
            if ! mkdir -- "$lock_directory" 2>/dev/null; then
                test -d "$lock_directory"
                test ! -L "$lock_directory"
            fi
            if [ "$lock_directory" = /run/lock/orbit ]; then
                chmod 0700 -- "$lock_directory"
                test "$(stat -c '%U:%G:%a' -- "$lock_directory")" = root:root:700
            fi
            lock="$lock_directory/production-php-$user.lock"
            if [ -e "$lock" ] || [ -L "$lock" ]; then
                test -f "$lock"
                test ! -L "$lock"
                test "$(stat -c '%U:%G' -- "$lock")" = root:root
            fi
            exec 9>>"$lock"
            chmod 0600 -- "$lock"
            flock -w 30 9

            expected_marker=$(mktemp)
            work_directory=$(mktemp -d)
            printf '%s' "$marker_configuration" | base64 --decode > "$expected_marker"
            trap 'rm -f -- "$expected_marker"; rm -rf -- "$work_directory"' EXIT

            runtime_created=0
            if [ ! -e "$runtime_directory" ] && [ ! -L "$runtime_directory" ]; then
                install -d -o root -g root -m 0755 -- "$runtime_directory"
                runtime_created=1
            fi
            test -d "$runtime_directory"
            test ! -L "$runtime_directory"
            test "$(stat -c '%U:%G:%a' -- "$runtime_directory")" = root:root:755

            if [ -e "$marker_path" ] || [ -L "$marker_path" ]; then
                test -f "$marker_path"
                test ! -L "$marker_path"
                test "$(stat -c '%U:%G:%a' -- "$marker_path")" = root:root:644
                cmp -s -- "$expected_marker" "$marker_path"
            else
                if [ "$runtime_created" = 0 ]; then
                    test ! -e "$generated_directory"
                    test ! -e "$local_tuning"
                fi
                test ! -e "$unit_path"
                test ! -e "$socket"
                marker_candidate="$runtime_directory/.orbit.identity.$$.candidate"
                install -o root -g root -m 0644 -- "$expected_marker" "$marker_candidate"
                mv -fT -- "$marker_candidate" "$marker_path"
            fi

            if [ ! -e "$local_tuning" ]; then
                test ! -L "$local_tuning"
                printf '%s' "$local_defaults" | base64 --decode > "$work_directory/local.defaults"
                local_candidate="$runtime_directory/.local.conf.$$.candidate"
                install -o root -g root -m 0644 -- "$work_directory/local.defaults" "$local_candidate"
                mv -fT -- "$local_candidate" "$local_tuning"
            else
                test -f "$local_tuning"
                test ! -L "$local_tuning"
                test "$(stat -c '%U:%G:%a' -- "$local_tuning")" = root:root:644
            fi
            local_before=$(sha256sum -- "$local_tuning" | awk '{print $1}')

            awk -v expected_pool="[$pool]" '
                /^[[:space:]]*($|;|#)/ { next }
                /^[[:space:]]*\[/ {
                    line=$0
                    gsub(/^[[:space:]]+|[[:space:]]+$/, "", line)
                    if (line != expected_pool) exit 1
                    next
                }
                {
                    line=tolower($0)
                    sub(/^[[:space:]]+/, "", line)
                    if (line ~ /^include[[:space:]]*=/) exit 1
                    if (line ~ /^(pid|user|group|listen|listen[.]owner|listen[.]group|listen[.]mode|chdir|env\[home\]|env\[user\])[[:space:]]*=/) exit 1
                }
            ' "$local_tuning"

            printf '%s' "$main_configuration" | base64 --decode > "$work_directory/php-fpm.conf"
            printf '%s' "$pool_configuration" | base64 --decode > "$work_directory/pool.conf"
            printf '%s' "$unit_configuration" | base64 --decode > "$work_directory/unit"
            cp -- "$local_tuning" "$work_directory/local.conf"
            sha256sum -- "$work_directory/local.conf" | awk '{print $1}' > "$work_directory/local.sha256"
            cp -- "$work_directory/php-fpm.conf" "$work_directory/php-fpm.validate.conf"
            sed -i \
                -e "s#^include = .*/generated/pool[.]conf\$#include = $work_directory/pool.conf#" \
                -e "s#^include = .*/local[.]conf\$#include = $work_directory/local.conf#" \
                "$work_directory/php-fpm.validate.conf"
            /usr/sbin/php-fpm"$version" -y "$work_directory/php-fpm.validate.conf" -t

            had_generated=0
            had_unit=0
            was_active=0
            was_enabled=0
            if [ -e "$generated_directory" ] || [ -L "$generated_directory" ]; then
                test -d "$generated_directory"
                test ! -L "$generated_directory"
                test "$(stat -c '%U:%G:%a' -- "$generated_directory")" = root:root:755
                unexpected_generated=$(find -P "$generated_directory" -mindepth 1 -maxdepth 1 \
                    ! -name php-fpm.conf ! -name pool.conf ! -name local.sha256 -print -quit)
                test -z "$unexpected_generated"
                for generated_file in php-fpm.conf pool.conf local.sha256; do
                    generated_path="$generated_directory/$generated_file"
                    test -f "$generated_path"
                    test ! -L "$generated_path"
                    test "$(stat -c '%U:%G:%a' -- "$generated_path")" = root:root:644
                done
                cp -a -- "$generated_directory" "$work_directory/generated.backup"
                had_generated=1
            fi
            if [ -e "$unit_path" ] || [ -L "$unit_path" ]; then
                test -f "$unit_path"
                test ! -L "$unit_path"
                test "$(stat -c '%U:%G:%a' -- "$unit_path")" = root:root:644
                cp -a -- "$unit_path" "$work_directory/unit.backup"
                had_unit=1
            fi
            systemctl is-active --quiet "$service" && was_active=1 || true
            systemctl is-enabled --quiet "$service" && was_enabled=1 || true
            if [ -e "$socket" ] || [ -L "$socket" ]; then
                test -S "$socket"
                test "$(stat -c '%U:%G:%a' -- "$socket")" = "$user:caddy:660"
            fi
            runtime_changed=0
            for comparison in php-fpm.conf pool.conf local.sha256; do
                if [ ! -f "$generated_directory/$comparison" ] \
                    || ! cmp -s -- "$work_directory/$comparison" "$generated_directory/$comparison"
                then
                    runtime_changed=1
                fi
            done
            if [ ! -f "$unit_path" ] || ! cmp -s -- "$work_directory/unit" "$unit_path"; then
                runtime_changed=1
            fi

            published=0
            restore_runtime() {
                status=$?
                trap - EXIT
                if [ "$status" -ne 0 ] && [ "$published" = 1 ]; then
                    systemctl disable --now "$service" >/dev/null 2>&1 || true
                    rm -rf -- "$generated_directory"
                    if [ "$had_generated" = 1 ]; then
                        cp -a -- "$work_directory/generated.backup" "$generated_directory"
                    fi
                    rm -f -- "$unit_path"
                    if [ "$had_unit" = 1 ]; then
                        cp -a -- "$work_directory/unit.backup" "$unit_path"
                    fi
                    systemctl daemon-reload || true
                    if [ "$was_enabled" = 1 ]; then systemctl enable "$service" >/dev/null 2>&1 || true; fi
                    if [ "$was_active" = 1 ]; then systemctl start "$service" >/dev/null 2>&1 || true; fi
                fi
                rm -f -- "$expected_marker"
                rm -rf -- "$work_directory"
                exit "$status"
            }
            if [ "$was_active" = 1 ] && [ "$runtime_changed" = 0 ]; then
                systemctl enable "$service"
            else
                trap restore_runtime EXIT

                generated_candidate="$runtime_directory/.generated.$$.candidate"
                install -d -o root -g root -m 0755 -- "$generated_candidate"
                printf '%s' "$main_configuration" | base64 --decode > "$generated_candidate/php-fpm.conf"
                printf '%s' "$pool_configuration" | base64 --decode > "$generated_candidate/pool.conf"
                cp -- "$work_directory/local.sha256" "$generated_candidate/local.sha256"
                chown root:root -- "$generated_candidate/php-fpm.conf" "$generated_candidate/pool.conf" "$generated_candidate/local.sha256"
                chmod 0644 -- "$generated_candidate/php-fpm.conf" "$generated_candidate/pool.conf" "$generated_candidate/local.sha256"
                unit_candidate="/etc/systemd/system/.$service.$$.candidate"
                printf '%s' "$unit_configuration" | base64 --decode > "$unit_candidate"
                chown root:root -- "$unit_candidate"
                chmod 0644 -- "$unit_candidate"
                published=1
                if [ ! -e "$generated_directory" ]; then
                    install -d -o root -g root -m 0755 -- "$generated_directory"
                fi
                mv -fT -- "$generated_candidate/php-fpm.conf" "$generated_directory/php-fpm.conf"
                mv -fT -- "$generated_candidate/pool.conf" "$generated_directory/pool.conf"
                mv -fT -- "$generated_candidate/local.sha256" "$generated_directory/local.sha256"
                rmdir -- "$generated_candidate"
                mv -fT -- "$unit_candidate" "$unit_path"
                systemctl daemon-reload
                if [ "$was_active" = 1 ]; then
                    systemctl enable "$service"
                    systemctl restart "$service"
                else
                    systemctl enable --now "$service"
                fi
            fi
            systemctl is-active --quiet "$service"
            main_pid=$(systemctl show --property MainPID --value "$service")
            test "$main_pid" -gt 1
            test -e "/proc/$main_pid/exe"
            test "$(readlink -f -- "/proc/$main_pid/exe")" = "/usr/sbin/php-fpm$version"
            test -S "$socket"
            test "$(stat -c '%U:%G:%a' -- "$socket")" = "$user:caddy:660"
            local_after=$(sha256sum -- "$local_tuning" | awk '{print $1}')
            test "$local_before" = "$local_after"

            published=0
            trap - EXIT
            rm -f -- "$expected_marker"
            rm -rf -- "$work_directory"
            BASH;
    }

    private function removeScript(): string
    {
        return <<<'BASH'
            operation=$1
            user=$2
            service=$3
            pool=$4
            socket=$5
            runtime_directory=$6
            generated_directory=$7
            local_tuning=$8
            unit_path=$9
            marker_path=${10}
            lock_directory=${11}
            marker_configuration=${12}
            test "$operation" = remove

            case "$service" in
                php*.service) exit 1 ;;
                orbit-"$user"-php*-fpm.service) ;;
                *) exit 1 ;;
            esac
            test "$pool" = "orbit-$user"
            test "$socket" = "/run/php/$user.sock"
            test "$runtime_directory" = "/etc/orbit/php-fpm/$user"
            test "$generated_directory" = "$runtime_directory/generated"
            test "$local_tuning" = "$runtime_directory/local.conf"
            test "$unit_path" = "/etc/systemd/system/$service"
            test "$marker_path" = "$runtime_directory/orbit.identity"

            umask 0077
            if ! mkdir -- "$lock_directory" 2>/dev/null; then
                test -d "$lock_directory"
                test ! -L "$lock_directory"
            fi
            lock="$lock_directory/production-php-$user.lock"
            exec 9>>"$lock"
            chmod 0600 -- "$lock"
            flock -w 30 9

            expected_marker=$(mktemp)
            printf '%s' "$marker_configuration" | base64 --decode > "$expected_marker"
            trap 'rm -f -- "$expected_marker"' EXIT
            if [ -e "$marker_path" ] || [ -L "$marker_path" ]; then
                test -f "$marker_path"
                test ! -L "$marker_path"
                test "$(stat -c '%U:%G:%a' -- "$marker_path")" = root:root:644
                cmp -s -- "$expected_marker" "$marker_path"
            else
                test ! -e "$generated_directory"
                test ! -e "$unit_path"
                test ! -e "$socket"
                exit 0
            fi

            if ! systemctl disable --now "$service"; then
                test ! -e "$unit_path"
                systemctl stop "$service" >/dev/null 2>&1 || true
            fi
            rm -rf -- "$generated_directory"
            rm -f -- "$unit_path"
            systemctl daemon-reload
            if [ -e "$socket" ] || [ -L "$socket" ]; then
                test -S "$socket"
                test "$(stat -c '%U:%G' -- "$socket")" = "$user:caddy"
                rm -f -- "$socket"
            fi
            if [ -e "$local_tuning" ] || [ -L "$local_tuning" ]; then
                test -f "$local_tuning"
                test ! -L "$local_tuning"
            fi
            test -f "$local_tuning" || true
            rm -f -- "$marker_path"
            test ! -e "$generated_directory"
            test ! -e "$unit_path"
            test ! -e "$socket"
            test -d "$runtime_directory"
            BASH;
    }
}
