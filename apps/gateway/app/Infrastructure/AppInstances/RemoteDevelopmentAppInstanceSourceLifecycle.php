<?php

declare(strict_types=1);

namespace App\Infrastructure\AppInstances;

use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\AppInstances\AppInstanceSourceKind;
use App\Domain\AppInstances\DevelopmentAppInstanceSourceLifecycle;
use App\Domain\AppInstances\DevelopmentSourceResolution;
use App\Domain\Nodes\ManagedUserAccountResolver;
use App\Domain\Nodes\Storage\CheckoutRemovalBoundary;
use App\Domain\Nodes\Storage\StoragePath;
use App\Domain\SourceControl\GitBranchName;
use App\Domain\SourceControl\GitRepositoryOrigin;
use App\Infrastructure\AppDev\AppDevSshExecutor;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Models\AppInstance;

/** @mago-expect lint:too-many-methods The adapter keeps one fixed source-only lifecycle and its evidence checks together. */
final readonly class RemoteDevelopmentAppInstanceSourceLifecycle implements DevelopmentAppInstanceSourceLifecycle
{
    public function __construct(
        private AppDevSshExecutor $ssh,
        private ManagedUserAccountResolver $accounts,
        private CheckoutRemovalBoundary $removal,
    ) {}

    public function prepare(AppInstance $appInstance, bool $allowExisting): void
    {
        $context = $this->context($appInstance);
        $this->ssh->execute(
            $appInstance->node,
            new RemoteCommand(
                arguments: [...$this->arguments($appInstance, $context), $allowExisting ? '1' : '0'],
                input: self::preparedRepositoryGuard().<<<'BASH'
                    repository=$1
                    checkout=$2
                    allowed_root=$3
                    managed_user=$4
                    managed_group=$5
                    allow_existing=$6
                    checkout_parent=$(dirname "$checkout")

                    guard_parent_chain "$checkout_parent" "$allowed_root"
                    create_directory "$allowed_root"
                    create_directory "$checkout_parent"

                    if [ -e "$checkout" ] || [ -L "$checkout" ]; then
                        test "$allow_existing" = 1
                        inspect_prepared_repository
                        exit 0
                    fi

                    git clone --no-checkout --origin origin -- "$repository" "$checkout"
                    inspect_prepared_repository
                    BASH,
            ),
            step: 'app-instance-source-prepare',
            errorCode: 'instance.clone_failed',
        );
    }

    public function inspectPrepared(AppInstance $appInstance): void
    {
        $context = $this->context($appInstance);
        $this->ssh->execute(
            $appInstance->node,
            new RemoteCommand(
                arguments: $this->arguments($appInstance, $context),
                input: self::preparedRepositoryGuard().<<<'BASH'
                    repository=$1
                    checkout=$2
                    allowed_root=$3
                    managed_user=$4
                    managed_group=$5
                    checkout_parent=$(dirname "$checkout")

                    guard_parent_chain "$checkout_parent" "$allowed_root"
                    inspect_prepared_repository
                    BASH,
            ),
            step: 'app-instance-source-inspect',
            errorCode: 'instance.source_identity_invalid',
        );
    }

    public function resolve(AppInstance $appInstance): DevelopmentSourceResolution
    {
        $context = $this->context($appInstance);
        $mainBranch = $this->mainBranch($appInstance);
        $result = $this->ssh->execute(
            $appInstance->node,
            new RemoteCommand(
                arguments: [
                    ...$this->arguments($appInstance, $context),
                    $appInstance->name,
                    $mainBranch,
                ],
                input: self::preparedRepositoryGuard().<<<'BASH'
                    repository=$1
                    checkout=$2
                    allowed_root=$3
                    managed_user=$4
                    managed_group=$5
                    branch=$6
                    main_branch=$7
                    checkout_parent=$(dirname "$checkout")

                    guard_parent_chain "$checkout_parent" "$allowed_root"
                    inspect_prepared_repository
                    git -C "$checkout" fetch --prune -- origin
                    if git -C "$checkout" show-ref --verify --quiet "refs/remotes/origin/$branch"; then
                        source_ref="refs/remotes/origin/$branch"
                    else
                        source_ref="refs/remotes/origin/$main_branch"
                        git -C "$checkout" show-ref --verify --quiet "$source_ref"
                    fi
                    git -C "$checkout" checkout --quiet --force --no-track -B "$branch" "$source_ref"
                    test "$(git -C "$checkout" symbolic-ref --short HEAD)" = "$branch"
                    commit=$(git -C "$checkout" rev-parse --verify HEAD^{commit})
                    printf '%s\n%s\n' "$branch" "$commit"
                    BASH,
            ),
            step: 'app-instance-source-resolve',
            errorCode: 'instance.branch_resolution_failed',
        );

        return $this->resolution($result->stdout, $appInstance);
    }

    public function inspectResolved(AppInstance $appInstance): DevelopmentSourceResolution
    {
        $context = $this->context($appInstance);
        $result = $this->ssh->execute(
            $appInstance->node,
            new RemoteCommand(
                arguments: $this->arguments($appInstance, $context),
                input: self::preparedRepositoryGuard().<<<'BASH'
                    repository=$1
                    checkout=$2
                    allowed_root=$3
                    managed_user=$4
                    managed_group=$5
                    checkout_parent=$(dirname "$checkout")

                    guard_parent_chain "$checkout_parent" "$allowed_root"
                    inspect_prepared_repository
                    branch=$(git -C "$checkout" symbolic-ref --short HEAD)
                    commit=$(git -C "$checkout" rev-parse --verify HEAD^{commit})
                    printf '%s\n%s\n' "$branch" "$commit"
                    BASH,
            ),
            step: 'app-instance-source-inspect-resolved',
            errorCode: 'instance.source_identity_invalid',
        );

        return $this->resolution($result->stdout, $appInstance);
    }

    public function remove(AppInstance $appInstance, bool $discardSource): void
    {
        $context = $this->context($appInstance);
        $resolution = $this->storedResolution($appInstance);
        $groupingDirectory = $this->removal
            ->appInstanceGroupingDirectory($appInstance, $context['root'])
            ->value;
        $this->ssh->execute(
            $appInstance->node,
            new RemoteCommand(
                arguments: [
                    ...$this->arguments($appInstance, $context),
                    $groupingDirectory,
                    $resolution->branch,
                    $resolution->startingCommit,
                    $discardSource ? '1' : '0',
                ],
                input: self::preparedRepositoryGuard().<<<'BASH'
                    repository=$1
                    checkout=$2
                    allowed_root=$3
                    managed_user=$4
                    managed_group=$5
                    grouping_directory=$6
                    branch=$7
                    starting_commit=$8
                    discard_source=$9
                    checkout_parent=$(dirname "$checkout")

                    test "$grouping_directory" = "$checkout_parent"
                    guard_parent_chain "$checkout_parent" "$allowed_root"
                    inspect_prepared_repository
                    test "$(git -C "$checkout" symbolic-ref --short HEAD)" = "$branch"

                    if [ "$discard_source" != 1 ]; then
                        test -z "$(git -C "$checkout" status --porcelain --untracked-files=all)"
                        git -C "$checkout" fetch --prune -- origin
                        if git -C "$checkout" show-ref --verify --quiet "refs/remotes/origin/$branch"; then
                            git -C "$checkout" merge-base --is-ancestor HEAD "refs/remotes/origin/$branch"
                        else
                            test "$(git -C "$checkout" rev-parse --verify HEAD^{commit})" = "$starting_commit"
                        fi
                    fi

                    test -d "$grouping_directory"
                    test ! -L "$grouping_directory"
                    test "$(realpath -e "$grouping_directory")" = "$grouping_directory"
                    test "$(stat -c '%U:%G' "$grouping_directory")" = "$managed_user:$managed_group"
                    rm -rf -- "$checkout"
                    rmdir --ignore-fail-on-non-empty -- "$grouping_directory"
                    BASH,
            ),
            step: 'app-instance-source-remove',
            errorCode: $discardSource ? 'instance.discard_failed' : 'instance.remove_refused',
        );
    }

    /**
     * @param array{repository: string, allowedRoot: string, root: StoragePath, managedUser: string, managedGroup: string} $context
     * @return non-empty-list<string>
     */
    private function arguments(AppInstance $appInstance, array $context): array
    {
        return [
            'bash',
            '-seu',
            '--',
            $context['repository'],
            $appInstance->checkout_path,
            $context['allowedRoot'],
            $context['managedUser'],
            $context['managedGroup'],
        ];
    }

    private function mainBranch(AppInstance $appInstance): string
    {
        $mainBranch = $appInstance->app->main_branch;

        if (! is_string($mainBranch) || ! GitBranchName::isValid($mainBranch)) {
            throw new RuntimeConvergenceException(
                step: 'app-instance-source-resolve',
                errorCode: 'instance.branch_resolution_failed',
                message: "AppInstance [{$appInstance->name}] has incomplete App source defaults.",
            );
        }

        return $mainBranch;
    }

    private function storedResolution(AppInstance $appInstance): DevelopmentSourceResolution
    {
        $branch = $appInstance->branch;
        $startingCommit = $appInstance->starting_commit;

        if (! is_string($branch) || ! is_string($startingCommit)) {
            throw new RuntimeConvergenceException(
                step: 'app-instance-source-remove',
                errorCode: 'instance.source_identity_invalid',
                message: "AppInstance [{$appInstance->name}] has incomplete source evidence.",
            );
        }

        return $this->resolution("{$branch}\n{$startingCommit}\n", $appInstance);
    }

    /** @return array{repository: string, allowedRoot: string, root: StoragePath, managedUser: string, managedGroup: string} */
    private function context(AppInstance $appInstance): array
    {
        $appInstance->loadMissing(['app', 'node']);

        if ($appInstance->source_kind !== AppInstanceSourceKind::ManagedClone->value) {
            throw new RuntimeConvergenceException(
                step: 'app-instance-source-kind',
                errorCode: 'instance.source_kind_conflict',
                message: "AppInstance [{$appInstance->name}] is not an Orbit-managed clone.",
            );
        }

        $account = $this->accounts->resolve($appInstance->node);
        $root = $this->removal->appInstanceRoot($appInstance, $account);

        return [
            'repository' => GitRepositoryOrigin::validate($appInstance->app->repository_url),
            'allowedRoot' => $root->value,
            'root' => $root,
            'managedUser' => $account->user,
            'managedGroup' => $account->group,
        ];
    }

    private function resolution(string $stdout, AppInstance $appInstance): DevelopmentSourceResolution
    {
        $lines = preg_split('/\R/', trim($stdout));

        if (
            ! is_array($lines)
            || count($lines) !== 2
            || $lines[0] !== $appInstance->name
            || preg_match('/\A[0-9a-f]{40}(?:[0-9a-f]{24})?\z/D', $lines[1]) !== 1
        ) {
            throw new RuntimeConvergenceException(
                step: 'app-instance-source-result',
                errorCode: 'instance.source_identity_invalid',
                message: "AppInstance [{$appInstance->name}] returned invalid source evidence.",
            );
        }

        return new DevelopmentSourceResolution($lines[0], $lines[1]);
    }

    private static function preparedRepositoryGuard(): string
    {
        return <<<'BASH'
            guard_parent_chain() {
                parent=$1
                root=$2
                case "$parent" in
                    "$root"|"$root"/*) ;;
                    *) return 1 ;;
                esac

                current=
                relative=${parent#/}
                if [ -n "$relative" ]; then
                    old_ifs=$IFS
                    IFS=/
                    for segment in $relative; do
                        IFS=$old_ifs
                        current="$current/$segment"
                        if [ -e "$current" ] || [ -L "$current" ]; then
                            test ! -L "$current"
                            test -d "$current"
                            test "$(realpath -e "$current")" = "$current"
                        fi
                        IFS=/
                    done
                    IFS=$old_ifs
                fi
            }
            create_directory() {
                if [ -e "$1" ] || [ -L "$1" ]; then
                    test -d "$1"
                    test ! -L "$1"
                    return 0
                fi
                install -d -m 0755 -- "$1"
            }
            inspect_prepared_repository() {
                test -d "$checkout"
                test ! -L "$checkout"
                test "$(realpath -e "$checkout")" = "$checkout"
                test "$(stat -c '%U:%G' "$checkout")" = "$managed_user:$managed_group"
                test -d "$checkout/.git"
                test ! -L "$checkout/.git"
                test "$(git -C "$checkout" rev-parse --show-toplevel)" = "$checkout"
                test "$(git -C "$checkout" rev-parse --absolute-git-dir)" = "$checkout/.git"
                test "$(git -C "$checkout" rev-parse --path-format=absolute --git-common-dir)" = "$checkout/.git"
                test "$(git -C "$checkout" remote get-url origin)" = "$repository"
            }

            BASH;
    }
}
