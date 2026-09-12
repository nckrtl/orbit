<?php

declare(strict_types=1);

namespace App\Infrastructure\Doctor;

use App\Domain\Doctor\DoctorInspectionException;
use App\Domain\Doctor\InstanceInspectionData;
use App\Domain\Doctor\InstanceStateInspector;
use App\Domain\Nodes\ManagedUserAccountResolver;
use App\Domain\Nodes\Storage\CheckoutRemovalBoundary;
use App\Domain\SourceControl\GitRepositoryOrigin;
use App\Infrastructure\AppDev\AppDevSshExecutor;
use App\Infrastructure\Processes\CommandDeadline;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Processes\ProtectedInput;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Models\AppInstance;
use Throwable;

final readonly class NativeInstanceStateInspector implements InstanceStateInspector
{
    public function __construct(
        private AppDevSshExecutor $ssh,
        private CommandDeadline $deadline,
        private ManagedUserAccountResolver $accounts,
        private CheckoutRemovalBoundary $removal,
        private ProductionInstanceInspectionExpectationFactory $productionExpectations,
    ) {}

    public function inspect(AppInstance $appInstance): InstanceInspectionData
    {
        $appInstance->loadMissing(['app', 'node']);

        if ($appInstance->environment === 'production') {
            return $this->inspectProduction($appInstance);
        }

        try {
            $account = $this->accounts->resolve($appInstance->node);
            $root = $this->removal->appInstanceRoot($appInstance, $account);
            $repository = GitRepositoryOrigin::validate($appInstance->app->repository_url);
            $result = $this->ssh->execute(
                $appInstance->node,
                new RemoteCommand(
                    arguments: [
                        'bash',
                        '-seu',
                        '--',
                        $repository,
                        $appInstance->checkout_path,
                        $root->value,
                        $account->user,
                        $account->group,
                        $appInstance->source_layout,
                        $appInstance->branch ?? '',
                        $appInstance->starting_commit ?? '',
                        $appInstance->registration_detached ? '1' : '0',
                    ],
                    input: self::remoteScript(),
                ),
                step: 'doctor-instance',
                errorCode: 'instance.inspection_failed',
                commandTimeout: $this->deadline->cap(30.0),
            );
        } catch (Throwable) {
            throw new DoctorInspectionException;
        }

        $values = $this->parse($result, 4);

        return new InstanceInspectionData(
            checkoutExists: $values[0],
            repositoryLayoutMatches: $values[1],
            originMatches: $values[2],
            sourceIdentityMatches: $values[3],
        );
    }

    private function inspectProduction(AppInstance $appInstance): InstanceInspectionData
    {
        try {
            $expectation = $this->productionExpectations->make($appInstance);
            $result = $this->ssh->execute(
                $appInstance->node,
                new RemoteCommand(
                    arguments: ['sudo', 'bash', '-s', '--'],
                    protectedInput: ProtectedInput::fromString($this->productionProgram($expectation)),
                    maxOutputBytes: 128,
                ),
                step: 'doctor-instance',
                errorCode: 'instance.inspection_failed',
                commandTimeout: $this->deadline->cap(30.0),
            );
            if ($result->stderr !== '') {
                throw new DoctorInspectionException;
            }
            $values = $this->parse($result, 6);
        } catch (Throwable) {
            throw new DoctorInspectionException;
        }

        return new InstanceInspectionData(
            checkoutExists: true,
            repositoryLayoutMatches: true,
            originMatches: true,
            sourceIdentityMatches: true,
            productionHomeMatches: $values[0],
            releaseSelectionMatches: $values[1],
            selectedReleaseRootMatches: $values[2],
            environmentProjectionMatches: $values[3],
            phpFpmProjectionMatches: $values[4],
            caddyProjectionMatches: $values[5],
        );
    }

    /** @return list<bool> */
    private function parse(CommandResult $result, int $count): array
    {
        $values = explode("\n", $result->stdout);
        $terminator = array_pop($values);

        if (
            ! $result->succeeded()
            || $result->truncated
            || $terminator !== ''
            || count($values) !== $count
            || array_diff($values, ['0', '1']) !== []
        ) {
            throw new DoctorInspectionException;
        }

        return array_map(static fn (string $value): bool => $value === '1', $values);
    }

    private static function remoteScript(): string
    {
        return <<<'BASH'
            repository=$1
            checkout=$2
            allowed_root=$3
            managed_user=$4
            managed_group=$5
            source_layout=$6
            branch=$7
            starting_commit=$8
            detached=$9

            emit() {
                if "$@"; then printf '1\n'; else printf '0\n'; fi
            }
            checkout_exists() {
                case "$checkout" in "$allowed_root"/*) ;; *) return 1 ;; esac
                test -d "$checkout" &&
                    test ! -L "$checkout" &&
                    test "$(realpath -e "$checkout")" = "$checkout" &&
                    test "$(stat -c '%U:%G' "$checkout")" = "$managed_user:$managed_group"
            }
            repository_layout_matches() {
                checkout_exists || return 1
                test "$(git -C "$checkout" rev-parse --show-toplevel)" = "$checkout" || return 1

                git_dir=$(git -C "$checkout" rev-parse --absolute-git-dir) || return 1
                common_dir=$(git -C "$checkout" rev-parse --path-format=absolute --git-common-dir) || return 1

                if [ "$source_layout" = checkout ]; then
                    test -d "$checkout/.git" &&
                        test ! -L "$checkout/.git" &&
                        test "$git_dir" = "$checkout/.git" &&
                        test "$common_dir" = "$checkout/.git"
                else
                    test "$source_layout" = worktree &&
                        test -f "$checkout/.git" &&
                        test ! -L "$checkout/.git" &&
                        test "$git_dir" != "$common_dir"
                fi
            }
            origin_matches() {
                test "$(git -C "$checkout" remote get-url origin)" = "$repository"
            }
            source_identity_matches() {
                test -n "$starting_commit" || return 1
                test "$(git -C "$checkout" rev-parse --verify "$starting_commit^{commit}")" = "$starting_commit" || return 1
                git -C "$checkout" merge-base --is-ancestor "$starting_commit" HEAD || return 1

                if [ "$detached" = 1 ]; then
                    ! git -C "$checkout" symbolic-ref --quiet HEAD >/dev/null
                else
                    test -n "$branch" && test "$(git -C "$checkout" symbolic-ref --short HEAD)" = "$branch"
                fi
            }

            emit checkout_exists
            emit repository_layout_matches
            emit origin_matches
            emit source_identity_matches
            BASH;
    }

    private function productionProgram(ProductionInstanceInspectionExpectation $expectation): string
    {
        $runtime = $expectation->runtime;
        $configuration = $expectation->runtimeConfiguration;
        $runtimeExpected = $runtime !== null && $configuration !== null;
        $runtimeValues = [
            'version' => '',
            'service' => '',
            'pool' => '',
            'socket' => '',
            'runtime_directory' => '',
            'generated_directory' => '',
            'local_tuning' => '',
            'unit_path' => '',
            'marker_path' => '',
            'main' => '',
            'pool_configuration' => '',
            'master_ini' => '',
            'unit' => '',
            'marker' => '',
        ];
        if ($runtimeExpected) {
            $runtimeValues = [
                'version' => $runtime->version,
                'service' => $runtime->service,
                'pool' => $runtime->pool,
                'socket' => $runtime->socket,
                'runtime_directory' => $runtime->runtimeDirectory,
                'generated_directory' => $runtime->generatedDirectory,
                'local_tuning' => $runtime->localTuningPath,
                'unit_path' => $runtime->unitPath,
                'marker_path' => $runtime->markerPath,
                'main' => base64_encode($configuration->main),
                'pool_configuration' => base64_encode($configuration->pool),
                'master_ini' => base64_encode($configuration->masterIni),
                'unit' => base64_encode($configuration->unit),
                'marker' => base64_encode($runtime->marker()),
            ];
        }
        $values = [
            'user' => $expectation->user,
            'home' => $expectation->home,
            'root' => $expectation->root,
            'environment' => base64_encode($expectation->environment()),
            'caddy' => base64_encode($expectation->caddy),
            'association' => $expectation->associationMatches ? '1' : '0',
            'runtime_expected' => $runtimeExpected ? '1' : '0',
            ...$runtimeValues,
        ];

        $assignments = collect($values)
            ->map(static fn (string $value, string $key): string => $key.'='.escapeshellarg($value))
            ->implode("\n");

        return <<<BASH
            set -u
            {$assignments}

            emit() {
                if "\$@"; then printf '1\n'; else printf '0\n'; fi
            }
            exact_file() {
                path=\$1
                encoded=\$2
                owner=\$3
                mode=\$4
                test -f "\$path" && test ! -L "\$path" || return 1
                test "\$(stat -c '%U:%G:%a' -- "\$path" 2>/dev/null)" = "\$owner:\$mode" || return 1
                printf '%s' "\$encoded" | base64 --decode | cmp -s -- "\$path" -
            }
            home_matches() {
                test "\$home" = "/home/\$user" || return 1
                test -d "\$home" && test ! -L "\$home" || return 1
                test "\$(realpath -e -- "\$home" 2>/dev/null)" = "\$home" || return 1
                test "\$(stat -c '%U:%G' -- "\$home" 2>/dev/null)" = "\$user:\$user"
            }
            selected_release() {
                current="\$home/current"
                test -L "\$current" || return 1
                target=\$(readlink -- "\$current") || return 1
                printf '%s' "\$target" | grep -Eq '^releases/[A-Za-z0-9][A-Za-z0-9._-]{0,127}\$' || return 1
                selected=\$(realpath -e -- "\$current" 2>/dev/null) || return 1
                case "\$selected" in "\$home/releases/"*) ;; *) return 1 ;; esac
                test -d "\$selected" && test ! -L "\$selected" || return 1
                test "\$(stat -c '%U:%G' -- "\$current" 2>/dev/null)" = "\$user:\$user" || return 1
                test "\$(stat -c '%U:%G' -- "\$selected" 2>/dev/null)" = "\$user:\$user"
            }
            release_selection_matches() {
                current="\$home/current"
                if test ! -e "\$current" && test ! -L "\$current"; then return 0; fi
                selected_release
            }
            selected_root_matches() {
                current="\$home/current"
                if test ! -e "\$current" && test ! -L "\$current"; then return 0; fi
                selected_release || return 1
                case "\$root" in ''|/*|..|../*|*/../*|*/..) return 1 ;; esac
                selected=\$(realpath -e -- "\$current" 2>/dev/null) || return 1
                selected_root=\$(realpath -e -- "\$selected/\$root" 2>/dev/null) || return 1
                case "\$selected_root" in "\$selected"|"\$selected/"*) ;; *) return 1 ;; esac
                test -d "\$selected_root"
            }
            environment_matches() {
                exact_file "\$home/.env" "\$environment" "\$user:\$user" 600 || return 1
                current="\$home/current"
                if test ! -e "\$current" && test ! -L "\$current"; then return 0; fi
                selected_release || return 1
                test -L "\$selected/.env" || return 1
                test "\$(realpath -e -- "\$selected/.env" 2>/dev/null)" = "\$home/.env"
            }
            local_tuning_matches() {
                test -f "\$local_tuning" && test ! -L "\$local_tuning" || return 1
                test "\$(stat -c '%U:%G:%a' -- "\$local_tuning" 2>/dev/null)" = root:root:644 || return 1
                awk -v expected_pool="[\$pool]" '
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
                ' "\$local_tuning"
            }
            php_fpm_matches() {
                test "\$association" = 1 || return 1
                if test "\$runtime_expected" = 0; then return 0; fi
                test -d "\$runtime_directory" && test ! -L "\$runtime_directory" || return 1
                test "\$(stat -c '%U:%G:%a' -- "\$runtime_directory" 2>/dev/null)" = root:root:755 || return 1
                test -d "\$generated_directory" && test ! -L "\$generated_directory" || return 1
                test "\$(stat -c '%U:%G:%a' -- "\$generated_directory" 2>/dev/null)" = root:root:755 || return 1
                exact_file "\$generated_directory/php-fpm.conf" "\$main" root:root 644 || return 1
                exact_file "\$generated_directory/pool.conf" "\$pool_configuration" root:root 644 || return 1
                exact_file "\$generated_directory/master.ini" "\$master_ini" root:root 644 || return 1
                exact_file "\$unit_path" "\$unit" root:root 644 || return 1
                exact_file "\$marker_path" "\$marker" root:root 644 || return 1
                local_tuning_matches || return 1
                PHP_INI_SCAN_DIR="/etc/php/\$version/fpm/conf.d:\$generated_directory" \
                    "/usr/sbin/php-fpm\$version" -y "\$generated_directory/php-fpm.conf" -t >/dev/null 2>&1 || return 1
                systemctl is-active --quiet "\$service" 2>/dev/null || return 1
                test -z "\$(systemctl show --property=User --value "\$service" 2>/dev/null)" || return 1
                main_pid=\$(systemctl show --property=MainPID --value "\$service" 2>/dev/null) || return 1
                test "\$main_pid" -gt 1 2>/dev/null || return 1
                test "\$(readlink -f -- "/proc/\$main_pid/exe" 2>/dev/null)" = "/usr/sbin/php-fpm\$version" || return 1
                test "\$(awk '/^Uid:/ { print $2 ":" $3 ":" $4 ":" $5 }' "/proc/\$main_pid/status" 2>/dev/null)" = 0:0:0:0 || return 1
                test -S "\$socket" || return 1
                test "\$(stat -c '%U:%G:%a' -- "\$socket" 2>/dev/null)" = "\$user:caddy:660"
            }
            caddy_matches() {
                source=\$(readlink -f -- /etc/caddy/Caddyfile 2>/dev/null) || return 1
                test -f "\$source" || return 1
                fragment="\$(dirname -- "\$source")/fragments/app-dev.caddy"
                test -f "\$fragment" && test ! -L "\$fragment" || return 1
                printf '%s' "\$caddy" | base64 --decode | cmp -s -- "\$fragment" -
            }

            emit home_matches
            emit release_selection_matches
            emit selected_root_matches
            emit environment_matches
            emit php_fpm_matches
            emit caddy_matches
            BASH;
    }
}
