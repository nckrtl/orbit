<?php

declare(strict_types=1);

namespace App\Infrastructure\Instances;

use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\GitHub\RepositoryReadAccess;
use App\Domain\Instances\DevelopmentInstanceBranchInspector;
use App\Domain\Instances\DevelopmentInstanceSourceLifecycle;
use App\Domain\Instances\DevelopmentSourceResolution;
use App\Domain\Instances\InstanceSourceLayout;
use App\Domain\Nodes\ManagedUserAccountResolver;
use App\Domain\Nodes\Storage\CheckoutRemovalBoundary;
use App\Domain\Nodes\Storage\StoragePath;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\SourceControl\GitBranchName;
use App\Domain\SourceControl\GitRepositoryIdentity;
use App\Domain\SourceControl\GitRepositoryOrigin;
use App\Infrastructure\AppDev\DevelopmentSshExecutor;
use App\Infrastructure\GitHub\GitReadScript;
use App\Infrastructure\SourceControl\WorkspaceGit;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Tasks\TaskWorkerUser;
use App\Models\Instance;
use InvalidArgumentException;

final readonly class RemoteDevelopmentInstanceSourceLifecycle implements DevelopmentInstanceBranchInspector, DevelopmentInstanceSourceLifecycle
{
    public function __construct(
        private DevelopmentSshExecutor $ssh,
        private ManagedUserAccountResolver $accounts,
        private CheckoutRemovalBoundary $removal,
        private RepositoryReadAccess $access,
    ) {}

    public function prepare(Instance $instance, bool $allowExisting): void
    {
        $context = $this->context($instance);
        $script = GitReadScript::for($this->access->for($context['repository'], $instance->project->source_access), self::preparedRepositoryGuard($instance->source_prepare_id).<<<'BASH'
                    repository=$1
                    checkout=$2
                    allowed_root=$3
                    managed_user=$4
                    managed_group=$5
                    allow_existing=$6
                    worker_user=$7
                    checkout_parent=$(dirname "$checkout")

                    guard_parent_chain "$checkout_parent" "$allowed_root"
                    create_directory "$allowed_root"
                    create_directory "$checkout_parent"

                    if [ -e "$checkout" ] || [ -L "$checkout" ]; then
                        test "$allow_existing" = 1
                        inspect_prepared_repository
                        share_checkout
                        exit 0
                    fi

                    mkdir -m 0755 -- "$checkout"
                    git_read git -c core.hooksPath=/dev/null -c core.fsmonitor=false clone --no-checkout --origin origin -- "$repository" "$checkout"
                    if [ -n "$prepare_id" ]; then
                        (umask 077; set -C; printf '%s:%s\n' "$prepare_id" "$(stat -c '%d:%i' "$checkout")" > "$checkout/.git/orbit-source-prepare")
                    fi
                    inspect_prepared_repository
                    share_checkout
                    BASH);
        $this->ssh->execute(
            $instance->node,
            new RemoteCommand(
                arguments: [...$this->arguments($instance, $context), $allowExisting ? '1' : '0', $this->workerUser()],
                input: $script->input,
                protectedInput: $script->protectedInput,
            ),
            step: 'app-instance-source-prepare',
            errorCode: 'instance.clone_failed',
        );
    }

    public function inspectPrepared(Instance $instance): void
    {
        $context = $this->context($instance);
        $this->ssh->execute(
            $instance->node,
            new RemoteCommand(
                arguments: [...$this->arguments($instance, $context), $this->workerUser()],
                input: self::preparedRepositoryGuard($instance->source_prepare_id).<<<'BASH'
                    repository=$1
                    checkout=$2
                    allowed_root=$3
                    managed_user=$4
                    managed_group=$5
                    worker_user=$6
                    checkout_parent=$(dirname "$checkout")

                    guard_parent_chain "$checkout_parent" "$allowed_root"
                    inspect_prepared_repository
                    share_checkout
                    BASH,
            ),
            step: 'app-instance-source-inspect',
            errorCode: 'instance.source_identity_invalid',
        );
    }

    public function resolve(Instance $instance): DevelopmentSourceResolution
    {
        $context = $this->context($instance);
        $defaultBranch = $this->defaultBranch($instance);
        $script = GitReadScript::for($this->access->for($context['repository'], $instance->project->source_access), self::preparedRepositoryGuard($instance->source_prepare_id).<<<'BASH'
                    repository=$1
                    checkout=$2
                    allowed_root=$3
                    managed_user=$4
                    managed_group=$5
                    instance_name=$6
                    default_branch=$7
                    branch_override=$8
                    checkout_parent=$(dirname "$checkout")

                    guard_parent_chain "$checkout_parent" "$allowed_root"
                    inspect_prepared_repository
                    git_read git -c core.hooksPath=/dev/null -c core.fsmonitor=false -C "$checkout" fetch --prune -- origin

                    branch=${branch_override:-$instance_name}
                    if [ "$instance_name" = default ] && [ -z "$branch_override" ]; then
                        branch=$default_branch
                        source_ref="refs/remotes/origin/$branch"
                        git -C "$checkout" show-ref --verify --quiet "$source_ref"
                    elif git -C "$checkout" show-ref --verify --quiet "refs/remotes/origin/$branch"; then
                        source_ref="refs/remotes/origin/$branch"
                    elif git -C "$checkout" show-ref --verify --quiet "refs/heads/$branch"; then
                        source_ref="refs/heads/$branch"
                    else
                        source_ref="refs/remotes/origin/$default_branch"
                        git -C "$checkout" show-ref --verify --quiet "$source_ref"
                    fi
                    git -C "$checkout" checkout --quiet --force --no-track -B "$branch" "$source_ref"
                    test "$(git -C "$checkout" symbolic-ref --short HEAD)" = "$branch"
                    commit=$(git -C "$checkout" rev-parse --verify HEAD^{commit})
                    printf '%s\n%s\n' "$branch" "$commit"
                    BASH);
        $result = $this->ssh->execute(
            $instance->node,
            new RemoteCommand(
                arguments: [
                    ...$this->arguments($instance, $context),
                    $instance->name,
                    $defaultBranch,
                    $instance->branch_override ?? '',
                ],
                input: $script->input,
                protectedInput: $script->protectedInput,
            ),
            step: 'app-instance-source-resolve',
            errorCode: 'instance.branch_resolution_failed',
        );

        return $this->resolution($result->stdout, $instance);
    }

    public function assertBranchCheckedOut(Instance $instance, ?string $branch): void
    {
        $context = $this->context($instance);
        try {
            $result = $this->ssh->execute(
                $instance->node,
                new RemoteCommand(
                    arguments: [...$this->arguments($instance, $context), $branch ?? ''],
                    input: self::preparedRepositoryGuard($instance->source_prepare_id).<<<'BASH'
                        repository=$1
                        checkout=$2
                        allowed_root=$3
                        managed_user=$4
                        managed_group=$5
                        expected_branch=$6
                        checkout_parent=$(dirname "$checkout")
                        export GIT_OPTIONAL_LOCKS=0
                        guard_parent_chain "$checkout_parent" "$allowed_root"
                        inspect_prepared_repository identity
                        lock_key=$(printf '%s' "$checkout" | sha256sum | cut -d ' ' -f 1)
                        python3 -c 'import fcntl, os, sys; p=sys.argv[1]; fd=os.open(p, os.O_CREAT|os.O_RDWR|os.O_NOFOLLOW, 0o600)
                        try: fcntl.flock(fd, fcntl.LOCK_EX|fcntl.LOCK_NB)
                        except BlockingIOError: sys.exit(75)' "/tmp/orbit-lifecycle-$(id -u)-$lock_key.lock"
                        current=$(git -C "$checkout" symbolic-ref --quiet --short HEAD || true)
                        origin=$(git -C "$checkout" config --get remote.origin.url)
                        printf '%s' "$origin" | base64 --wrap=0
                        printf '\n'
                        printf '%s' "$current" | base64 --wrap=0
                        printf '\n'
                        BASH,
                ),
                step: 'app-instance-rename-inspect',
                errorCode: 'instance.source_identity_invalid',
            );
        } catch (RuntimeConvergenceException $exception) {
            $code = match ($exception->result?->exitCode) {
                42 => 'instance.branch_not_checked_out',
                75 => 'instance.lifecycle_busy',
                default => null,
            };
            if ($code !== null) {
                throw new ResourceOperationException(
                    $code,
                    $code === 'instance.lifecycle_busy' ? 'The Instance is busy with another lifecycle operation.' : 'HEAD is not on the requested branch.',
                    409,
                    previous: $exception,
                );
            }
            throw $exception;
        }
        $lines = explode("\n", preg_replace('/\n\z/', '', $result->stdout) ?? $result->stdout);
        $origin = count($lines) === 2 ? base64_decode($lines[0], true) : false;
        $current = count($lines) === 2 ? base64_decode($lines[1], true) : false;
        try {
            $valid = is_string($origin) && is_string($current)
                && GitRepositoryIdentity::derive($origin) === $instance->project->repository_identity;
        } catch (InvalidArgumentException) {
            $valid = false;
        }
        if (! $valid) {
            throw new RuntimeConvergenceException('app-instance-rename-inspect', 'instance.source_identity_invalid', 'The checkout origin does not match the Project repository.');
        }
        if ($branch !== null && $current !== $branch) {
            throw new ResourceOperationException('instance.branch_not_checked_out', 'HEAD is not on the requested branch.', 409);
        }
    }

    public function inspectResolved(Instance $instance): DevelopmentSourceResolution
    {
        $context = $this->context($instance);
        $result = $this->ssh->execute(
            $instance->node,
            new RemoteCommand(
                arguments: $this->arguments($instance, $context),
                input: self::preparedRepositoryGuard($instance->source_prepare_id).<<<'BASH'
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

        return $this->resolution($result->stdout, $instance);
    }

    /**
     * @param  array{repository: string, allowedRoot: string, root: StoragePath, managedUser: string, managedGroup: string}  $context
     * @return non-empty-list<string>
     */
    private function arguments(Instance $instance, array $context): array
    {
        return [
            'bash',
            '-seu',
            '--',
            $context['repository'],
            $instance->checkout_path,
            $context['allowedRoot'],
            $context['managedUser'],
            $context['managedGroup'],
        ];
    }

    private function workerUser(): string
    {
        $worker = config('orbit.tasks.worker_user');
        if ($worker === null || $worker === '') {
            return '';
        }
        if (! is_string($worker) || preg_match('/\A[a-z_][a-z0-9_-]{0,31}\z/D', $worker) !== 1 || $worker === 'root') {
            throw new RuntimeConvergenceException(
                step: 'app-instance-source-access',
                errorCode: 'instance.source_identity_invalid',
                message: 'The task worker user is invalid.',
            );
        }

        return $worker;
    }

    private function defaultBranch(Instance $instance): string
    {
        $defaultBranch = $instance->project->default_branch;

        if (! is_string($defaultBranch) || ! GitBranchName::isValid($defaultBranch)) {
            throw new RuntimeConvergenceException(
                step: 'app-instance-source-resolve',
                errorCode: 'instance.branch_resolution_failed',
                message: "Instance [{$instance->name}] has incomplete App source defaults.",
            );
        }

        return $defaultBranch;
    }

    /** @return array{repository: string, allowedRoot: string, root: StoragePath, managedUser: string, managedGroup: string} */
    private function context(Instance $instance): array
    {
        $instance->loadMissing(['project', 'node']);

        if ($instance->source_layout !== InstanceSourceLayout::Checkout->value) {
            throw new RuntimeConvergenceException(
                step: 'app-instance-source-layout',
                errorCode: 'instance.source_layout_conflict',
                message: "Instance [{$instance->name}] does not own an independent checkout.",
            );
        }

        $account = $this->accounts->resolve($instance->node);
        $root = $this->removal->instanceRoot($instance, $account);

        return [
            'repository' => GitRepositoryOrigin::validate($instance->project->repository_url),
            'allowedRoot' => $root->value,
            'root' => $root,
            'managedUser' => $account->user,
            'managedGroup' => $account->group,
        ];
    }

    private function resolution(string $stdout, Instance $instance): DevelopmentSourceResolution
    {
        $lines = preg_split('/\R/', trim($stdout));

        if (
            ! is_array($lines)
            || count($lines) !== 2
            || $lines[0] !== $this->selectedBranch($instance)
            || preg_match('/\A[0-9a-f]{40}(?:[0-9a-f]{24})?\z/D', $lines[1]) !== 1
        ) {
            throw new RuntimeConvergenceException(
                step: 'app-instance-source-result',
                errorCode: 'instance.source_identity_invalid',
                message: "Instance [{$instance->name}] returned invalid source evidence.",
            );
        }

        return new DevelopmentSourceResolution($lines[0], $lines[1]);
    }

    private function selectedBranch(Instance $instance): string
    {
        $branch = is_string($instance->branch_override)
            ? $instance->branch_override
            : ($instance->name === 'default' ? $this->defaultBranch($instance) : $instance->name);

        if (! GitBranchName::isValid($branch)) {
            throw new RuntimeConvergenceException(
                step: 'app-instance-source-resolve',
                errorCode: 'instance.branch_resolution_failed',
                message: "Instance [{$instance->name}] has an invalid branch selection.",
            );
        }

        return $branch;
    }

    private static function preparedRepositoryGuard(?string $prepareId = null): string
    {
        return 'prepare_id='.escapeshellarg($prepareId ?? '')."\n".WorkspaceGit::bashPreamble().WorkspaceGit::workerPreamble(TaskWorkerUser::name()).<<<'BASH'
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
            share_checkout() {
                if [ -z "$worker_user" ] || ! id "$worker_user" >/dev/null 2>&1; then
                    return 0
                fi
                test "$(id -u "$worker_user")" != 0
                test "$worker_user" != "$managed_user"
                test "$(stat -c '%U:%G' "$checkout/.git")" = "$managed_user:$managed_group"
                orbit="$checkout/.git/orbit"
                if [ -e "$orbit" ] || [ -L "$orbit" ]; then
                    test ! -L "$orbit"
                    test -d "$orbit"
                    test "$(stat -c '%U:%G' "$orbit")" = "$managed_user:$managed_group"
                else
                    install -d -m 0775 -- "$orbit"
                fi
                test -f "$checkout/.git/config"
                test ! -L "$checkout/.git/config"
                test "$(stat -c '%U' "$checkout/.git/config")" = "$managed_user"
                test ! -L "$checkout/.git/hooks"
                # setfacl writes access before defaults in a combined call. Finish inheritance first.
                default_grant="d:u:$worker_user:rwX,d:u:$managed_user:rwX"
                find -P "$checkout" -user "$managed_user" -type d -exec setfacl -m "$default_grant" -- {} +
                access_grant="u:$worker_user:rwX,u:$managed_user:rwX"
                if [ -z "$(find -P "$checkout" ! -user "$managed_user" -print -quit)" ]; then
                    setfacl -R -P -m "$access_grant" -- "$checkout"
                else
                    # Worker-owned files already inherit access; only their owner can change their ACL.
                    find -P "$checkout" -user "$managed_user" ! -type l -exec setfacl -m "$access_grant" -- {} +
                fi
                setfacl -m "u:$worker_user:r--" -- "$checkout/.git/config"
                if [ -d "$checkout/.git/hooks" ]; then
                    find -P "$checkout/.git/hooks" -user "$managed_user" -type d -exec setfacl -m "u:$worker_user:r-X,d:u:$worker_user:r-X" -- {} +
                    find -P "$checkout/.git/hooks" -user "$managed_user" ! -type d ! -type l -exec setfacl -m "u:$worker_user:r-X" -- {} +
                fi
                chmod 0775 -- "$orbit"
                if sudo -n -u "$worker_user" -H -- git config --global --fixed-value --get-all safe.directory "$checkout" >/dev/null; then
                    :
                else
                    status=$?
                    test "$status" = 1
                    sudo -n -u "$worker_user" -H -- git config --global --add safe.directory "$checkout"
                fi
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
                if [ "${1:-verify}" = verify ]; then
                    test "$(git -C "$checkout" config --get remote.origin.url)" = "$repository"
                fi
                if [ -n "$prepare_id" ]; then
                    test -f "$checkout/.git/orbit-source-prepare"
                    test ! -L "$checkout/.git/orbit-source-prepare"
                    test "$(stat -c '%U:%G' "$checkout/.git/orbit-source-prepare")" = "$managed_user:$managed_group"
                    test "$(cat "$checkout/.git/orbit-source-prepare")" = "$prepare_id:$(stat -c '%d:%i' "$checkout")"
                fi
            }

            BASH;
    }
}
