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
    ) {}

    public function inspect(AppInstance $appInstance): InstanceInspectionData
    {
        $appInstance->loadMissing(['app', 'node']);

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
}
