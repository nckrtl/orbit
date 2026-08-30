<?php

declare(strict_types=1);

namespace App\Infrastructure\AppDev;

use App\Domain\AppDev\AppDevSourceManager;
use App\Domain\AppDev\AppDevSourceOperationLock;
use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\Nodes\ManagedUserAccount;
use App\Domain\Nodes\ManagedUserAccountResolver;
use App\Domain\Nodes\Storage\CheckoutPathOrigin;
use App\Domain\Nodes\Storage\CheckoutRemovalBoundary;
use App\Domain\Nodes\Storage\StoragePath;
use App\Domain\SourceControl\GitRepositoryOrigin;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Models\Instance;
use App\Models\Workspace;

/** @mago-expect lint:kan-defect,too-many-methods,cyclomatic-complexity Instance and workspace source scripts keep containment and ACL accounting together. */
final readonly class RemoteAppDevSourceManager implements AppDevSourceManager
{
    public function __construct(
        private AppDevSshExecutor $ssh,
        private AppDevSourceOperationLock $lock,
        private ManagedUserAccountResolver $accounts,
        private CheckoutRemovalBoundary $removal,
    ) {}

    public function convergeInstance(Instance $instance): void
    {
        $instance->loadMissing(['app', 'node']);
        $account = $this->accounts->resolve($instance->node);
        $repository = GitRepositoryOrigin::validate($instance->app->repository_url);
        $allowedRoot = $this->removal->instanceRoot($instance, $account);
        $traversalPaths = $this->checkoutTraversalPaths($instance->checkout_path, $account->home);
        $this->lock->synchronized($instance->node_id, function () use (
            $instance,
            $repository,
            $account,
            $allowedRoot,
            $traversalPaths,
        ): void {
            $arguments = [
                'bash',
                '-seu',
                '--',
                $repository,
                $instance->checkout_path,
                $instance->document_root,
                $account->user,
                $account->group,
                $account->home,
                $allowedRoot->value,
            ];
            foreach ($traversalPaths as $path) {
                $arguments[] = $path;
            }
            $this->ssh->execute(
                $instance->node,
                new RemoteCommand(
                    arguments: $arguments,
                    input: self::prepareTraversalPathsFunction().<<<'BASH'
                        repository=$1
                        checkout=$2
                        document_root=$3
                        managed_user=$4
                        managed_group=$5
                        managed_home=$6
                        allowed_root=$7
                        shift 7
                        traversal_paths=("$@")
                        guard_checkout_parent() {
                            parent=$(dirname "$1")
                            case "$parent" in
                                "$allowed_root"|"$allowed_root"/*) ;;
                                *) return 1 ;;
                            esac

                            existing_parent=$parent
                            while [ ! -e "$existing_parent" ] && [ ! -L "$existing_parent" ]; do
                                existing_parent=$(dirname "$existing_parent")
                            done
                            test ! -L "$existing_parent"
                            case "$(realpath -e "$existing_parent")" in
                                "$allowed_root"|"$allowed_root"/*) ;;
                                *) return 1 ;;
                            esac

                            current=$allowed_root
                            IFS=/ read -r -a segments <<< "${parent#"$allowed_root"/}"
                            for segment in "${segments[@]}"; do
                                current="$current/$segment"
                                if [ -e "$current" ] || [ -L "$current" ]; then
                                    test ! -L "$current"
                                fi
                            done
                        }
                        prepare_caddy_access() {
                            checkout_root=$(realpath -e "$checkout")
                            test ! -L "$checkout"
                            test "$checkout_root" = "$checkout"
                            test "$checkout_root" = "$(git -C "$checkout" rev-parse --show-toplevel)"
                            setfacl -P -R -m u:caddy:--- "$checkout_root"
                            find -P "$checkout_root" -type d -exec setfacl -m d:u:caddy:--- -- {} +

                            document_root_path="$checkout/$document_root"
                            test -d "$document_root_path"
                            test ! -L "$document_root_path"
                            document_root_real=$(realpath -e "$document_root_path")
                            case "$document_root_real" in
                                "$checkout_root"|"$checkout_root"/*) ;;
                                *) return 1 ;;
                            esac
                            storage_target=
                            while IFS= read -r -d '' link; do
                                target=$(realpath -e "$link")
                                expected_link="$checkout_root/public/storage"
                                expected_target="$checkout_root/storage/app/public"
                                test "$document_root_real" = "$checkout_root/public"
                                test "$link" = "$expected_link"
                                test "$target" = "$expected_target"
                                test -d "$expected_target"
                                test ! -L "$checkout_root/storage"
                                test ! -L "$checkout_root/storage/app"
                                test ! -L "$expected_target"
                                if find -P "$expected_target" -type l -print -quit | grep -q .; then
                                    return 1
                                fi
                                storage_target=$expected_target
                            done < <(find -P "$document_root_real" -type l -print0)
                        }

                        grant_caddy_access() {
                            setfacl -m u:caddy:--x "$checkout"

                            current=$checkout_root
                            if [ "$document_root_real" = "$checkout_root" ]; then
                                relative_parent=.
                            else
                                relative_parent=${document_root_real#"$checkout_root"/}
                                relative_parent=$(dirname "$relative_parent")
                            fi
                            if [ "$relative_parent" != . ]; then
                                IFS=/ read -r -a segments <<< "$relative_parent"
                                for segment in "${segments[@]}"; do
                                    current="$current/$segment"
                                    test ! -L "$current"
                                    setfacl -m u:caddy:--x "$current"
                                done
                            fi

                            setfacl -P -R -m u:caddy:r-X "$document_root_real"
                            find -P "$document_root_real" -type d -exec setfacl -m d:u:caddy:r-x -- {} +

                            if [ -n "$storage_target" ]; then
                                setfacl -m u:caddy:--x "$checkout_root/storage" "$checkout_root/storage/app"
                                setfacl -P -R -m u:caddy:r-X "$storage_target"
                                find -P "$storage_target" -type d -exec setfacl -m d:u:caddy:r-x -- {} +
                            fi
                        }
                        guard_checkout_parent "$checkout"
                        prepare_traversal_paths
                        create_missing_directory() {
                            if [ -e "$1" ] || [ -L "$1" ]; then
                                test -d "$1"
                                test ! -L "$1"
                                return 0
                            fi
                            install -d -m 0755 -- "$1"
                        }
                        create_missing_directory "$allowed_root"
                        create_missing_directory "$(dirname "$checkout")"
                        case "$(realpath -e "$(dirname "$checkout")")" in
                            "$allowed_root"|"$allowed_root"/*) ;;
                            *) exit 1 ;;
                        esac

                        if [ -e "$checkout" ]; then
                            test ! -L "$checkout"
                            test -d "$checkout/.git"
                            test "$(realpath -e "$checkout")" = "$(git -C "$checkout" rev-parse --show-toplevel)"
                            test "$(git -C "$checkout" remote get-url origin)" = "$repository"
                        else
                            git clone -- "$repository" "$checkout"
                        fi

                        prepare_caddy_access
                        grant_caddy_access
                        BASH,
                ),
                step: 'instance-source',
                errorCode: 'instance.clone_failed',
            );
        });
    }

    public function removeInstance(Instance $instance): void
    {
        $instance->loadMissing(['app', 'node']);
        $account = $this->accounts->resolve($instance->node);
        $repository = GitRepositoryOrigin::validate($instance->app->repository_url);
        $allowedRoot = $this->removal->instanceRoot($instance, $account);
        $this->lock->synchronized($instance->node_id, function () use (
            $instance,
            $repository,
            $account,
            $allowedRoot,
        ): void {
            $releasePaths = $this->releasableTraversalPaths(
                $instance->checkout_path,
                $allowedRoot,
                $instance->node_id,
                $account,
                ignoreInstanceId: $instance->id,
            );
            $arguments = [
                'bash',
                '-seu',
                '--',
                $repository,
                $instance->checkout_path,
                $account->user,
                $account->group,
                $account->home,
                $allowedRoot->value,
            ];
            foreach ($releasePaths as $path) {
                $arguments[] = $path;
            }
            $this->ssh->execute(
                $instance->node,
                new RemoteCommand(
                    arguments: $arguments,
                    input: self::assertRecordedParentsFunction().self::releaseTraversalPathsFunction().<<<'BASH'
                        repository=$1
                        checkout=$2
                        managed_user=$3
                        managed_group=$4
                        managed_home=$5
                        allowed_root=$6
                        shift 6
                        release_paths=("$@")
                        marker_name=user.orbit.caddy_traversal
                        if [ -L "$checkout" ]; then
                            exit 1
                        fi
                        assert_recorded_parents "$checkout" "$allowed_root"
                        if [ -e "$checkout" ]; then
                            test ! -L "$checkout"
                            test "$(stat -c '%U:%G' "$checkout")" = "$managed_user:$managed_group"
                            test "$(realpath -e "$checkout")" = "$(git -C "$checkout" rev-parse --show-toplevel)"
                            test "$(git -C "$checkout" remote get-url origin)" = "$repository"
                            rm -rf -- "$checkout"
                        fi
                        release_traversal_paths
                        BASH,
                ),
                step: 'instance-source-remove',
                errorCode: 'instance.remove_failed',
            );
        });
    }

    public function convergeWorkspace(Workspace $workspace): void
    {
        $workspace->loadMissing(['instance.app', 'instance.node']);
        $account = $this->accounts->resolve($workspace->instance->node);
        $repository = GitRepositoryOrigin::validate($workspace->instance->app->repository_url);
        $allowedRoot = $this->removal->workspaceRoot($workspace, $account);
        $origin = $workspace->checkout_path_origin ?? 'legacy';
        $this->lock->synchronized($workspace->instance->node_id, function () use (
            $repository,
            $workspace,
            $account,
            $allowedRoot,
            $origin,
        ): void {
            $traversalPaths = $this->checkoutTraversalPaths($workspace->checkout_path, $account->home);
            $arguments = [
                'bash',
                '-seu',
                '--',
                $workspace->instance->checkout_path,
                $repository,
                $workspace->checkout_path,
                $workspace->branch,
                $workspace->instance->document_root,
                $account->user,
                $account->group,
                $account->home,
                $allowedRoot->value,
                $origin,
            ];
            foreach ($traversalPaths as $path) {
                $arguments[] = $path;
            }
            $this->ssh->execute(
                $workspace->instance->node,
                new RemoteCommand(
                    arguments: $arguments,
                    input: self::prepareTraversalPathsFunction().<<<'BASH'
                        instance=$1
                        repository=$2
                        checkout=$3
                        branch=$4
                        document_root=$5
                        managed_user=$6
                        managed_group=$7
                        managed_home=$8
                        allowed_root=$9
                        origin=${10}
                        shift 10
                        traversal_paths=("$@")
                        guard_checkout_parent() {
                            parent=$(dirname "$1")
                            case "$parent" in
                                "$allowed_root"|"$allowed_root"/*) ;;
                                *) return 1 ;;
                            esac

                            existing_parent=$parent
                            while [ ! -e "$existing_parent" ] && [ ! -L "$existing_parent" ]; do
                                existing_parent=$(dirname "$existing_parent")
                            done
                            test ! -L "$existing_parent"
                            case "$(realpath -e "$existing_parent")" in
                                "$allowed_root"|"$allowed_root"/*) ;;
                                *) return 1 ;;
                            esac

                            current=$allowed_root
                            IFS=/ read -r -a segments <<< "${parent#"$allowed_root"/}"
                            for segment in "${segments[@]}"; do
                                current="$current/$segment"
                                if [ -e "$current" ] || [ -L "$current" ]; then
                                    test ! -L "$current"
                                fi
                            done
                        }
                        guard_workspace_path() {
                            relative=${checkout#"$managed_home"/}
                            test "$relative" != "$checkout"
                            relative=${relative#/}
                            IFS=/ read -r -a path_segments <<< "$relative"
                            for segment in "${path_segments[@]}"; do
                                case "$segment" in
                                    ''|.|..|*[!A-Za-z0-9._-]*) return 1 ;;
                                esac
                            done
                            case "$checkout" in
                                "$managed_home/.orbit/worktrees/"*) ;;
                                "$managed_home/."*|"$managed_home/apps"|"$managed_home/apps/"*) return 1 ;;
                                "$managed_home/"*) ;;
                                *) return 1 ;;
                            esac
                        }
                        prepare_caddy_access() {
                            checkout_root=$(realpath -e "$checkout")
                            test ! -L "$checkout"
                            test "$checkout_root" = "$checkout"
                            test "$checkout_root" = "$(git -C "$checkout" rev-parse --show-toplevel)"
                            setfacl -P -R -m u:caddy:--- "$checkout_root"
                            find -P "$checkout_root" -type d -exec setfacl -m d:u:caddy:--- -- {} +

                            document_root_path="$checkout/$document_root"
                            test -d "$document_root_path"
                            test ! -L "$document_root_path"
                            document_root_real=$(realpath -e "$document_root_path")
                            case "$document_root_real" in
                                "$checkout_root"|"$checkout_root"/*) ;;
                                *) return 1 ;;
                            esac
                            storage_target=
                            while IFS= read -r -d '' link; do
                                target=$(realpath -e "$link")
                                expected_link="$checkout_root/public/storage"
                                expected_target="$checkout_root/storage/app/public"
                                test "$document_root_real" = "$checkout_root/public"
                                test "$link" = "$expected_link"
                                test "$target" = "$expected_target"
                                test -d "$expected_target"
                                test ! -L "$checkout_root/storage"
                                test ! -L "$checkout_root/storage/app"
                                test ! -L "$expected_target"
                                if find -P "$expected_target" -type l -print -quit | grep -q .; then
                                    return 1
                                fi
                                storage_target=$expected_target
                            done < <(find -P "$document_root_real" -type l -print0)
                        }

                        grant_caddy_access() {
                            setfacl -m u:caddy:--x "$checkout"

                            current=$checkout_root
                            if [ "$document_root_real" = "$checkout_root" ]; then
                                relative_parent=.
                            else
                                relative_parent=${document_root_real#"$checkout_root"/}
                                relative_parent=$(dirname "$relative_parent")
                            fi
                            if [ "$relative_parent" != . ]; then
                                IFS=/ read -r -a segments <<< "$relative_parent"
                                for segment in "${segments[@]}"; do
                                    current="$current/$segment"
                                    test ! -L "$current"
                                    setfacl -m u:caddy:--x "$current"
                                done
                            fi

                            setfacl -P -R -m u:caddy:r-X "$document_root_real"
                            find -P "$document_root_real" -type d -exec setfacl -m d:u:caddy:r-x -- {} +

                            if [ -n "$storage_target" ]; then
                                setfacl -m u:caddy:--x "$checkout_root/storage" "$checkout_root/storage/app"
                                setfacl -P -R -m u:caddy:r-X "$storage_target"
                                find -P "$storage_target" -type d -exec setfacl -m d:u:caddy:r-x -- {} +
                            fi
                        }
                        if [ "$origin" != "derived" ]; then
                            guard_workspace_path
                        fi
                        test ! -L "$instance"
                        test "$(realpath -e "$instance")" = "$(git -C "$instance" rev-parse --show-toplevel)"
                        test "$(git -C "$instance" remote get-url origin)" = "$repository"
                        prepare_traversal_paths

                        if git -C "$instance" worktree list --porcelain | grep -Fx -- "worktree $checkout" >/dev/null; then
                            test -d "$checkout"
                            test "$(git -C "$checkout" symbolic-ref --quiet --short HEAD)" = "$branch"
                        else
                            test ! -L "$checkout"
                            test ! -e "$checkout"
                            guard_checkout_parent "$checkout"
                            case "$(realpath -e "$(dirname "$checkout")")" in
                                "$allowed_root"|"$allowed_root"/*) ;;
                                *) exit 1 ;;
                            esac

                            if git -C "$instance" show-ref --verify --quiet "refs/heads/$branch"; then
                                git -C "$instance" worktree add -- "$checkout" "$branch"
                            else
                                git -C "$instance" worktree add -b "$branch" -- "$checkout" HEAD
                            fi
                        fi

                        prepare_caddy_access
                        grant_caddy_access
                        BASH,
                ),
                step: 'workspace-source',
                errorCode: 'workspace.worktree_failed',
            );
        });
    }

    public function removeWorkspace(Workspace $workspace): void
    {
        $workspace->loadMissing(['instance.app', 'instance.node']);
        $account = $this->accounts->resolve($workspace->instance->node);
        $repository = GitRepositoryOrigin::validate($workspace->instance->app->repository_url);
        $allowedRoot = $this->removal->workspaceRoot($workspace, $account);
        $grouping = $this->removal->groupingDirectory($workspace, $allowedRoot);
        $this->lock->synchronized($workspace->instance->node_id, function () use (
            $repository,
            $workspace,
            $account,
            $allowedRoot,
            $grouping,
        ): void {
            $releasePaths = $this->releasableTraversalPaths(
                $workspace->checkout_path,
                $allowedRoot,
                $workspace->instance->node_id,
                $account,
                ignoreWorkspaceId: $workspace->id,
            );
            $recognized = $grouping instanceof StoragePath
                ? $this->recognizedGroupingCheckouts($workspace, $grouping)
                : [];
            $arguments = [
                'bash',
                '-seu',
                '--',
                $workspace->instance->checkout_path,
                $repository,
                $workspace->checkout_path,
                $workspace->branch,
                $account->user,
                $account->group,
                $account->home,
                $allowedRoot->value,
                $grouping?->value ?? '-',
                (string) count($recognized),
            ];
            foreach ($recognized as $path) {
                $arguments[] = $path;
            }
            foreach ($releasePaths as $path) {
                $arguments[] = $path;
            }
            $this->ssh->execute(
                $workspace->instance->node,
                new RemoteCommand(
                    arguments: $arguments,
                    input: self::assertRecordedParentsFunction().self::releaseTraversalPathsFunction().<<<'BASH'
                            instance=$1
                            repository=$2
                            checkout=$3
                            branch=$4
                            managed_user=$5
                            managed_group=$6
                            managed_home=$7
                            allowed_root=$8
                            grouping_dir=$9
                            recognized_count=${10}
                            shift 10
                            printf '%s\n' "$recognized_count" | grep -Eq '^[0-9]+$'
                            recognized_siblings=()
                            i=0
                            while [ "$i" -lt "$recognized_count" ]; do
                                recognized_siblings+=("$1")
                                shift
                                i=$((i + 1))
                            done
                            release_paths=("$@")
                            marker_name=user.orbit.caddy_traversal
                        if [ -L "$checkout" ]; then
                            exit 1
                        fi
                        assert_recorded_parents "$checkout" "$allowed_root"
                        test ! -L "$instance"
                        test "$(realpath -e "$instance")" = "$(git -C "$instance" rev-parse --show-toplevel)"
                        test "$(git -C "$instance" remote get-url origin)" = "$repository"
                        if [ -e "$checkout" ]; then
                            test ! -L "$checkout"
                            test "$(stat -c '%U:%G' "$checkout")" = "$managed_user:$managed_group"
                            test "$(realpath -e "$checkout")" = "$(git -C "$checkout" rev-parse --show-toplevel)"
                            test "$(git -C "$checkout" remote get-url origin)" = "$repository"
                            git_common_dir() {
                                dir=$(git -C "$1" rev-parse --git-common-dir)
                                case "$dir" in
                                    /*) realpath -e "$dir" ;;
                                    *) realpath -e "$1/$dir" ;;
                                esac
                            }
                            test "$(git_common_dir "$checkout")" = "$(git_common_dir "$instance")"
                            test "$(git -C "$checkout" symbolic-ref --quiet --short HEAD)" = "$branch"
                        fi
                        preflight_derived_grouping() {
                            if [ "$grouping_dir" = '-' ] || [ -z "$grouping_dir" ]; then
                                return 0
                            fi
                            if [ ! -e "$grouping_dir" ] && [ ! -L "$grouping_dir" ]; then
                                return 0
                            fi
                            test -d "$grouping_dir"
                            test ! -L "$grouping_dir"
                            test "$(realpath -e "$grouping_dir")" = "$grouping_dir"
                            test "$(stat -c '%U:%G' "$grouping_dir")" = "$managed_user:$managed_group"
                            shopt -s nullglob dotglob
                            for entry in "$grouping_dir"/*; do
                                recognized=0
                                for sibling in "${recognized_siblings[@]}"; do
                                    if [ "$entry" = "$sibling" ]; then
                                        recognized=1
                                        break
                                    fi
                                done
                                test "$recognized" -eq 1
                                test ! -L "$entry"
                                test -d "$entry"
                                test "$(stat -c '%U:%G' "$entry")" = "$managed_user:$managed_group"
                            done
                        }
                        preflight_derived_grouping

                        if ! git -C "$instance" worktree list --porcelain | grep -Fx -- "worktree $checkout" >/dev/null; then
                            test ! -e "$checkout"
                        else
                            git -C "$instance" worktree remove --force -- "$checkout"
                        fi

                        if [ "$grouping_dir" != '-' ] && [ -n "$grouping_dir" ] && [ -d "$grouping_dir" ] && [ ! -L "$grouping_dir" ]; then
                            if [ -z "$(find -P "$grouping_dir" -mindepth 1 -maxdepth 1 -print -quit)" ]; then
                                rmdir -- "$grouping_dir"
                            fi
                        fi
                        release_traversal_paths
                        BASH,
                ),
                step: 'workspace-source-remove',
                errorCode: 'workspace.remove_failed',
            );
        });
    }

    private static function assertRecordedParentsFunction(): string
    {
        return <<<'BASH'
            assert_recorded_parents() {
                current=$(dirname "$1")
                root=$2
                while :; do
                    if [ -L "$current" ]; then
                        return 1
                    fi
                    if [ -e "$current" ]; then
                        test -d "$current"
                        test "$(realpath -e "$current")" = "$current"
                        case "$current" in
                            "$root"|"$root"/*) ;;
                            *) return 1 ;;
                        esac
                    fi
                    if [ "$current" = "$root" ]; then
                        return 0
                    fi
                    if [ "$current" = "/" ]; then
                        return 1
                    fi
                    next=$(dirname "$current")
                    if [ "$next" = "$current" ]; then
                        return 1
                    fi
                    current=$next
                done
            }

            BASH;
    }

    private static function prepareTraversalPathsFunction(): string
    {
        return <<<'BASH'
            prepare_traversal_paths() {
                acl() {
                    sudo -n "$@"
                }
                state_directory="$managed_home/.orbit/caddy-traversal-state"
                marker_name=user.orbit.caddy_traversal
                install -d -m 0700 -- "$state_directory"
                test -d "$state_directory"
                test ! -L "$state_directory"
                test "$(realpath -e "$state_directory")" = "$state_directory"
                find "$state_directory" -maxdepth 1 -type f -name '.state.*' -delete
                for path in "${traversal_paths[@]}"; do
                    case "$checkout" in
                        "$path"/*) ;;
                        *) return 1 ;;
                    esac
                    if [ ! -e "$path" ] && [ ! -L "$path" ]; then
                        case "$path" in
                            "$allowed_root"|"$allowed_root"/*|"$managed_home"|"$managed_home"/*)
                                install -d -m 0700 -- "$path"
                                ;;
                            *)
                                return 1
                                ;;
                        esac
                    fi
                    test -d "$path"
                    test ! -L "$path"
                    test "$(realpath -e "$path")" = "$path"

                    state_key=$(printf '%s' "$path" | sha256sum | cut -d' ' -f1)
                    state="$state_directory/$state_key"
                    if [ ! -e "$state" ] && [ ! -L "$state" ]; then
                        if acl getfattr --only-values -n "$marker_name" -- "$path" >/dev/null 2>&1; then
                            if acl getfacl -cp "$path" | grep -Eq '^user:caddy:--x$' \
                                && acl getfacl -cp "$path" | grep -Eq '^mask::[r-][w-]x$'; then
                                return 1
                            fi
                            acl setfattr -x "$marker_name" -- "$path"
                        fi

                        state_nonce=$(openssl rand -hex 32)
                        printf '%s\n' "$state_nonce" | grep -Eq '^[0-9a-f]{64}$'
                        temporary_state=$(mktemp "$state_directory/.state.XXXXXX")
                        {
                            printf '%s\n%s\n%s\n' "$path" "$(stat -c '%d:%i' "$path")" "$state_nonce"
                            acl getfacl -cp "$path" | sed '/^default:/d'
                        } > "$temporary_state"
                        chmod 0600 "$temporary_state"
                        acl setfattr -n "$marker_name" -v "$state_nonce" -- "$path"
                        if ! mv -f -- "$temporary_state" "$state"; then
                            acl setfattr -x "$marker_name" -- "$path"
                            rm -f -- "$temporary_state"
                            return 1
                        fi
                    fi
                    test -f "$state"
                    test ! -L "$state"
                    test "$(stat -c '%a' "$state")" = 600
                    test "$(sed -n '1p' "$state")" = "$path"
                    state_identity=$(sed -n '2p' "$state")
                    printf '%s\n' "$state_identity" | grep -Eq '^[0-9]+:[0-9]+$'
                    test "$state_identity" = "$(stat -c '%d:%i' "$path")"
                    state_nonce=$(sed -n '3p' "$state")
                    printf '%s\n' "$state_nonce" | grep -Eq '^[0-9a-f]{64}$'
                    test "$(acl getfattr --only-values -n "$marker_name" -- "$path" 2>/dev/null)" = "$state_nonce"
                    tail -n +4 "$state" | acl setfacl --test --set-file=- "$path" >/dev/null

                    current_acl=$(acl getfacl -cp "$path")
                    traversal_mask=$(printf '%s\n' "$current_acl" | sed -n 's/^mask::\([rwx-]\{3\}\).*$/\1/p')
                    if [ -z "$traversal_mask" ]; then
                        traversal_mask=$(printf '%s\n' "$current_acl" | sed -n 's/^group::\([rwx-]\{3\}\).*$/\1/p')
                    fi
                    printf '%s\n' "$traversal_mask" | grep -Eq '^[rwx-]{3}$'
                    named_execute() {
                        perms=$(printf '%s\n' "$current_acl" | sed -n "s/^user:${1}:\([rwx-]\{3\}\).*$/\1/p")
                        if [ -z "$perms" ]; then
                            perms='---'
                        fi
                        printf '%sx' "${perms%?}"
                    }
                    union_acl() {
                        bits=
                        for slot in r w x; do
                            case "$1$2" in
                                *"$slot"*) bits="${bits}${slot}" ;;
                                *) bits="${bits}-" ;;
                            esac
                        done
                        printf '%s' "$bits"
                    }
                    caddy_perms=$(named_execute caddy)
                    user_perms=$(named_execute "$managed_user")
                    traversal_mask=$(union_acl "$(union_acl "$traversal_mask" "$caddy_perms")" "$user_perms")
                    printf '%s\n' "$caddy_perms" "$user_perms" "$traversal_mask" | grep -Eq '^[rwx-]{3}$'
                    acl setfacl -n -m "u:caddy:$caddy_perms,u:$managed_user:$user_perms,m::$traversal_mask" "$path"
                    acl getfacl -cp "$path" | grep -Fqx "user:caddy:$caddy_perms"
                    acl getfacl -cp "$path" | grep -Fqx "user:$managed_user:$user_perms"
                    acl getfacl -cp "$path" | grep -Fqx "mask::$traversal_mask"
                done
            }

            BASH;
    }

    private static function releaseTraversalPathsFunction(): string
    {
        return <<<'BASH'
            release_traversal_paths() {
                acl() {
                    sudo -n "$@"
                }
                for path in "${release_paths[@]}"; do
                    case "$checkout" in
                        "$path"|"$path"/*) ;;
                        *)
                            case "${grouping_dir:-}" in
                                "$path"|"$path"/*) ;;
                                *) exit 1 ;;
                            esac
                            ;;
                    esac
                    state_directory="$managed_home/.orbit/caddy-traversal-state"
                    state_key=$(printf '%s' "$path" | sha256sum | cut -d' ' -f1)
                    state="$state_directory/$state_key"
                    if [ ! -e "$state" ] && [ ! -L "$state" ]; then
                        if [ ! -e "$path" ] && [ ! -L "$path" ]; then
                            continue
                        fi

                        test -d "$path"
                        test ! -L "$path"
                        test "$(realpath -e "$path")" = "$path"
                        if acl getfattr --only-values -n "$marker_name" -- "$path" >/dev/null 2>&1; then
                            if acl getfacl -cp "$path" | grep -Eq '^user:caddy:--x$' \
                                && acl getfacl -cp "$path" | grep -Eq '^mask::[r-][w-]x$'; then
                                exit 1
                            fi
                            acl setfattr -x "$marker_name" -- "$path"
                        fi
                        continue
                    fi

                    if [ ! -e "$path" ] && [ ! -L "$path" ]; then
                        test -d "$state_directory"
                        test ! -L "$state_directory"
                        test "$(realpath -e "$state_directory")" = "$state_directory"
                        test -f "$state"
                        test ! -L "$state"
                        test "$(stat -c '%a' "$state")" = 600
                        test "$(sed -n '1p' "$state")" = "$path"
                        state_nonce=$(sed -n '3p' "$state")
                        printf '%s\n' "$state_nonce" | grep -Eq '^[0-9a-f]{64}$'
                        rm -f -- "$state"
                        continue
                    fi
                    test -d "$state_directory"
                    test ! -L "$state_directory"
                    test "$(realpath -e "$state_directory")" = "$state_directory"
                    test -d "$path"
                    test ! -L "$path"
                    test "$(realpath -e "$path")" = "$path"
                    test -f "$state"
                    test ! -L "$state"
                    test "$(stat -c '%a' "$state")" = 600
                    test "$(sed -n '1p' "$state")" = "$path"
                    state_identity=$(sed -n '2p' "$state")
                    printf '%s\n' "$state_identity" | grep -Eq '^[0-9]+:[0-9]+$'
                    test "$state_identity" = "$(stat -c '%d:%i' "$path")"
                    state_nonce=$(sed -n '3p' "$state")
                    printf '%s\n' "$state_nonce" | grep -Eq '^[0-9a-f]{64}$'
                    tail -n +4 "$state" | acl setfacl --test --set-file=- "$path" >/dev/null
                    if current_nonce=$(acl getfattr --only-values -n "$marker_name" -- "$path" 2>/dev/null); then
                        test "$current_nonce" = "$state_nonce"
                    elif cmp -s <(tail -n +4 "$state") <(acl getfacl -cp "$path" | sed '/^default:/d'); then
                        rm -f -- "$state"
                        continue
                    else
                        exit 1
                    fi

                    if ! cmp -s <(tail -n +4 "$state") <(acl getfacl -cp "$path" | sed '/^default:/d'); then
                        acl getfacl -cp "$path" | grep -Eq '^user:caddy:[r-][w-]x$'
                        acl getfacl -cp "$path" | grep -Eq '^mask::[r-][w-]x$'
                        tail -n +4 "$state" | acl setfacl --set-file=- "$path"
                        cmp -s <(tail -n +4 "$state") <(acl getfacl -cp "$path" | sed '/^default:/d')
                    fi
                    acl setfattr -x "$marker_name" -- "$path"
                    rm -f -- "$state"
                done
            }

            BASH;
    }

    /** @return list<string> */
    private function recognizedGroupingCheckouts(Workspace $workspace, StoragePath $grouping): array
    {
        $workspace->loadMissing('instance');
        $checkouts = [];
        $siblings = Workspace::query()
            ->whereHas(
                'instance',
                static function ($query) use ($workspace): void {
                    $query
                        ->where('node_id', $workspace->instance->node_id)
                        ->where('app_id', $workspace->instance->app_id);
                },
            )
            ->where('checkout_path_origin', CheckoutPathOrigin::Derived->value)
            ->get(['checkout_path']);

        foreach ($siblings as $sibling) {
            $path = StoragePath::tryParse($sibling->checkout_path);
            $parent = $path instanceof StoragePath ? $path->parent() : null;

            if (
                $path instanceof StoragePath
                && $parent instanceof StoragePath
                && $parent->equals($grouping)
            ) {
                $checkouts[] = $path->value;
            }
        }

        return $checkouts;
    }

    /** @return list<string> */
    private function checkoutTraversalPaths(string $checkout, string $managedHome): array
    {
        $path = StoragePath::parse($checkout);
        $home = StoragePath::tryParse($managedHome);
        /** @var list<string> $paths */
        $paths = [];
        $current = $path->parent();

        while ($current instanceof StoragePath) {
            if ($home instanceof StoragePath && $home->isInside($current)) {
                break;
            }

            array_unshift($paths, $current->value);
            $current = $current->parent();
        }

        return $paths;
    }

    /** @return list<string> */
    private function ancestorPaths(string $checkout): array
    {
        $path = StoragePath::tryParse($checkout);
        /** @var list<string> $paths */
        $paths = [];
        $current = $path;

        while ($current instanceof StoragePath) {
            array_unshift($paths, $current->value);
            $current = $current->parent();
        }

        return $paths;
    }

    /**
     * @return list<string>
     * @mago-expect lint:excessive-parameter-list Remaining checkouts and the recorded root select one release set.
     */
    private function releasableTraversalPaths(
        string $checkout,
        StoragePath $stop,
        int $nodeId,
        ManagedUserAccount $account,
        ?int $ignoreInstanceId = null,
        ?int $ignoreWorkspaceId = null,
    ): array {
        $remaining = [];

        $instances = Instance::query()
            ->where('node_id', $nodeId)
            ->when(
                $ignoreInstanceId !== null,
                static fn ($query) => $query->whereKeyNot($ignoreInstanceId),
            )
            ->get(['checkout_path']);

        foreach ($instances as $instance) {
            $remaining = [...$remaining, ...$this->ancestorPaths($instance->checkout_path)];
        }

        $workspaces = Workspace::query()
            ->whereHas('instance', static fn ($query) => $query->where('node_id', $nodeId))
            ->when(
                $ignoreWorkspaceId !== null,
                static fn ($query) => $query->whereKeyNot($ignoreWorkspaceId),
            )
            ->get(['checkout_path']);

        foreach ($workspaces as $workspace) {
            $remaining = [...$remaining, ...$this->ancestorPaths($workspace->checkout_path)];
        }

        $releasePaths = array_diff($this->checkoutTraversalPaths($checkout, $account->home), $remaining);
        $ordered = [];

        foreach (array_reverse($releasePaths) as $path) {
            if (is_string($path)) {
                $ordered[] = $path;
            }
        }

        return $ordered;
    }
}
