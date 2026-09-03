<?php

declare(strict_types=1);

namespace App\Infrastructure\AppInstances;

use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\AppInstances\RegisteredWorktreeInspector;
use App\Domain\AppInstances\RegisteredWorktreeObservation;
use App\Domain\Nodes\ManagedUserAccountResolver;
use App\Domain\SourceControl\GitRepositoryOrigin;
use App\Infrastructure\AppDev\AppDevSshExecutor;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Models\App;
use App\Models\Node;

final readonly class RemoteRegisteredWorktreeInspector implements RegisteredWorktreeInspector
{
    public function __construct(
        private AppDevSshExecutor $ssh,
        private ManagedUserAccountResolver $accounts,
    ) {}

    public function inspect(
        Node $node,
        App $app,
        string $checkoutPath,
        string $effectiveRoot,
    ): RegisteredWorktreeObservation {
        $account = $this->accounts->resolve($node);
        $repository = GitRepositoryOrigin::validate($app->repository_url);
        $gatewayCheckout = rtrim((string) config('orbit.gateway_checkout'), '/');
        $result = $this->ssh->execute(
            $node,
            new RemoteCommand(
                arguments: [
                    'bash',
                    '-seu',
                    '--',
                    $repository,
                    $checkoutPath,
                    $effectiveRoot,
                    $account->user,
                    $account->group,
                    $account->home,
                    $gatewayCheckout,
                ],
                input: <<<'BASH'
                    repository=$1
                    checkout=$2
                    relative_root=$3
                    managed_user=$4
                    managed_group=$5
                    managed_home=$6
                    gateway_checkout=$7

                    inspect_chain() {
                        candidate=$1
                        current=
                        relative=${candidate#/}
                        old_ifs=$IFS
                        IFS=/
                        for segment in $relative; do
                            IFS=$old_ifs
                            current="$current/$segment"
                            test ! -L "$current"
                            test "$(realpath -e "$current")" = "$current"
                            mode=$(stat -c '%a' "$current")
                            test $((0$mode & 0002)) -eq 0
                            if [ $((0$mode & 0020)) -ne 0 ]; then
                                test "$(stat -c '%U:%G' "$current")" = "$managed_user:$managed_group"
                            fi
                            IFS=/
                        done
                        IFS=$old_ifs
                    }

                    test -d "$checkout"
                    test ! -L "$checkout"
                    test "$(realpath -e "$checkout")" = "$checkout"
                    inspect_chain "$checkout"
                    test "$(stat -c '%U:%G' "$checkout")" = "$managed_user:$managed_group"

                    case "$checkout" in
                        /boot|/boot/*|/dev|/dev/*|/etc|/etc/*|/proc|/proc/*|/run|/run/*|/sys|/sys/*|/usr|/usr/*|/opt/orbit|/opt/orbit/*|/var/lib/orbit|/var/lib/orbit/*|/var/www|/var/www/*)
                            exit 1
                            ;;
                    esac
                    for protected_root in /boot /dev /etc /proc /run /sys /usr /opt/orbit /var/lib/orbit /var/www; do
                        case "$protected_root" in
                            "$checkout"|"$checkout"/*) exit 1 ;;
                        esac
                    done
                    if [ -n "$gateway_checkout" ]; then
                        case "$checkout" in
                            "$gateway_checkout"|"$gateway_checkout"/*) exit 1 ;;
                        esac
                        case "$gateway_checkout" in
                            "$checkout"|"$checkout"/*) exit 1 ;;
                        esac
                    fi
                    case "$managed_home" in
                        "$checkout"|"$checkout"/*) exit 1 ;;
                    esac
                    case "$checkout" in
                        "$managed_home"/.orbit|"$managed_home"/.orbit/*|"$managed_home"/.ssh|"$managed_home"/.ssh/*)
                            exit 1
                            ;;
                        "$managed_home"/.*)
                            case "$checkout" in
                                "$managed_home"/.codex/*) ;;
                                *) exit 1 ;;
                            esac
                            ;;
                    esac

                    test -f "$checkout/.git"
                    test ! -L "$checkout/.git"
                    test "$(stat -c '%U:%G' "$checkout/.git")" = "$managed_user:$managed_group"
                    git_file_mode=$(stat -c '%a' "$checkout/.git")
                    test $((0$git_file_mode & 0002)) -eq 0
                    test "$(git -C "$checkout" rev-parse --show-toplevel)" = "$checkout"
                    git_dir=$(git -C "$checkout" rev-parse --absolute-git-dir)
                    common_dir=$(git -C "$checkout" rev-parse --path-format=absolute --git-common-dir)
                    test "$git_dir" != "$common_dir"
                    case "$git_dir" in
                        "$common_dir"/worktrees/*) ;;
                        *) exit 1 ;;
                    esac
                    test -d "$git_dir"
                    test -d "$common_dir"
                    test ! -L "$git_dir"
                    test ! -L "$common_dir"
                    test "$(realpath -e "$git_dir")" = "$git_dir"
                    test "$(realpath -e "$common_dir")" = "$common_dir"
                    test "$(stat -c '%U:%G' "$git_dir")" = "$managed_user:$managed_group"
                    test "$(stat -c '%U:%G' "$common_dir")" = "$managed_user:$managed_group"
                    worktree_count=$(git --git-dir="$common_dir" worktree list --porcelain | awk -v exact="$checkout" '$0 == "worktree " exact { count++ } END { print count + 0 }')
                    test "$worktree_count" = 1
                    test "$(git -C "$checkout" remote get-url origin)" = "$repository"

                    effective_root="$checkout/$relative_root"
                    test -d "$effective_root"
                    test ! -L "$effective_root"
                    test "$(realpath -e "$effective_root")" = "$effective_root"
                    test "$(stat -c '%U:%G' "$effective_root")" = "$managed_user:$managed_group"
                    case "$effective_root" in
                        "$checkout"/*) ;;
                        *) exit 1 ;;
                    esac
                    inspect_chain "$effective_root"

                    if branch=$(git -C "$checkout" symbolic-ref --quiet --short HEAD); then
                        test -n "$branch"
                    else
                        branch=-
                    fi
                    commit=$(git -C "$checkout" rev-parse --verify HEAD^{commit})
                    source_identity=$(printf '%s\0%s' "$common_dir" "$git_dir" | sha256sum | awk '{ print $1 }')
                    printf '%s\n%s\n%s\n%s\n' "$checkout" "$branch" "$commit" "$source_identity"
                    BASH,
            ),
            step: 'registered-worktree-inspect',
            errorCode: 'instance.worktree_invalid',
            commandTimeout: 30.0,
        );

        return $this->observation($result->stdout);
    }

    private function observation(string $stdout): RegisteredWorktreeObservation
    {
        $lines = preg_split('/\R/', trim($stdout));

        if (! is_array($lines) || count($lines) !== 4) {
            throw $this->invalidResult();
        }

        [$checkout, $branch, $commit, $identity] = $lines;

        if (
            $checkout === ''
            || $branch !== '-'
            && ($branch === ''
            || strlen($branch) > 255)
            || preg_match('/\A[0-9a-f]{40}(?:[0-9a-f]{24})?\z/D', $commit) !== 1
            || preg_match('/\A[0-9a-f]{64}\z/D', $identity) !== 1
        ) {
            throw $this->invalidResult();
        }

        return new RegisteredWorktreeObservation(
            checkoutPath: $checkout,
            branch: $branch === '-' ? null : $branch,
            startingCommit: $commit,
            sourceIdentity: $identity,
        );
    }

    private function invalidResult(): RuntimeConvergenceException
    {
        return new RuntimeConvergenceException(
            step: 'registered-worktree-inspect-result',
            errorCode: 'instance.worktree_identity_invalid',
            message: 'Registered worktree inspection returned invalid bounded evidence.',
        );
    }
}
