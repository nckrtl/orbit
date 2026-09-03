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
                        $appInstance->branch ?? '',
                        $appInstance->starting_commit ?? '',
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
            repositoryIndependent: $values[1],
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
            branch=$6
            starting_commit=$7

            emit() {
                if "$@"; then printf '1\n'; else printf '0\n'; fi
            }
            checkout_exists() {
                test -d "$checkout"
                test ! -L "$checkout"
                case "$checkout" in "$allowed_root"/*) ;; *) return 1 ;; esac
                test "$(realpath -e "$checkout")" = "$checkout"
                test "$(stat -c '%U:%G' "$checkout")" = "$managed_user:$managed_group"
            }
            repository_independent() {
                checkout_exists
                test -d "$checkout/.git"
                test ! -L "$checkout/.git"
                test "$(git -C "$checkout" rev-parse --show-toplevel)" = "$checkout"
            }
            origin_matches() {
                repository_independent
                test "$(git -C "$checkout" remote get-url origin)" = "$repository"
            }
            source_identity_matches() {
                origin_matches
                test -n "$branch"
                test -n "$starting_commit"
                test "$(git -C "$checkout" symbolic-ref --short HEAD)" = "$branch"
                test "$(git -C "$checkout" rev-parse --verify "$starting_commit^{commit}")" = "$starting_commit"
                git -C "$checkout" merge-base --is-ancestor "$starting_commit" HEAD
            }

            emit checkout_exists
            emit repository_independent
            emit origin_matches
            emit source_identity_matches
            BASH;
    }
}
