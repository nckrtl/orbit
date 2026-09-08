<?php

declare(strict_types=1);

namespace App\Infrastructure\AppInstances;

use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\AppInstances\AppInstanceSourceLayout;
use App\Domain\AppInstances\Removal\AppInstanceSourceInventory;
use App\Domain\AppInstances\Removal\DevelopmentAppInstanceSourceRemoval;
use App\Domain\Nodes\ManagedUserAccountResolver;
use App\Domain\Nodes\Storage\CheckoutRemovalBoundary;
use App\Domain\Nodes\Storage\StoragePath;
use App\Domain\SourceControl\GitRepositoryIdentity;
use App\Infrastructure\AppDev\AppDevSshExecutor;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Models\AppInstance;
use InvalidArgumentException;

/**
 * @mago-expect lint:cyclomatic-complexity The adapter keeps each fail-closed source identity branch together.
 * @mago-expect lint:too-many-methods The adapter owns one removal-only inspection and deletion protocol.
 */
final readonly class RemoteDevelopmentAppInstanceSourceRemoval implements DevelopmentAppInstanceSourceRemoval
{
    public function __construct(
        private AppDevSshExecutor $ssh,
        private ManagedUserAccountResolver $accounts,
        private CheckoutRemovalBoundary $boundaries,
    ) {}

    public function inspect(AppInstance $appInstance, bool $force): AppInstanceSourceInventory
    {
        $context = $this->context($appInstance);
        $result = $this->ssh->execute(
            $appInstance->node,
            new RemoteCommand(
                arguments: [
                    'bash',
                    '-seu',
                    '--',
                    $appInstance->checkout_path,
                    $context['root']->value,
                    $context['user'],
                    $context['group'],
                    $context['branch'],
                    $context['startingCommit'],
                    $force ? '0' : '1',
                ],
                input: self::inspectionScript(),
            ),
            step: 'app-instance-source-removal-inspect',
            errorCode: $this->failureCode($force),
        );

        $values = preg_split('/\R/', trim($result->stdout));

        if (! is_array($values) || count($values) !== 8) {
            $this->invalidEvidence($appInstance, $force);
        }

        [$top, $common, $origin, $branch, $commit, $dirty, $sourceIdentity, $worktrees] = array_map(
            fn (string $value): string => $this->decode($value, $appInstance, $force),
            $values,
        );
        $checkout = StoragePath::tryParse($top);
        $commonPath = StoragePath::tryParse($common);

        if (
            ! $checkout instanceof StoragePath
            || ! $commonPath instanceof StoragePath
            || $checkout->value !== $appInstance->checkout_path
            || $commonPath->value !== $appInstance->checkout_path.'/.git'
            || $branch !== $context['branch']
            || preg_match('/\A[0-9a-f]{40}(?:[0-9a-f]{24})?\z/D', $commit) !== 1
            || ! in_array($dirty, ['', '0', '1'], true)
            || preg_match('/\A[0-9]+:[0-9]+\z/D', $sourceIdentity) !== 1
        ) {
            $this->invalidEvidence($appInstance, $force);
        }

        try {
            $repositoryIdentity = GitRepositoryIdentity::derive($origin);
        } catch (InvalidArgumentException) {
            $this->invalidEvidence($appInstance, $force);
        }

        if ($repositoryIdentity !== $context['repositoryIdentity']) {
            $this->invalidEvidence($appInstance, $force);
        }

        if (! $force && $dirty !== '0') {
            $this->unsafeContent($appInstance);
        }

        if (! $force && ! $this->isPublished($appInstance, $origin, $commit)) {
            $this->unsafeContent($appInstance);
        }

        $linkedWorktreePaths = $this->worktreePaths(
            $worktrees,
            $appInstance,
            $checkout->value,
            $force,
        );
        $payload = [
            'app_instance_id' => $appInstance->id,
            'layout' => AppInstanceSourceLayout::Checkout->value,
            'repository_identity' => $repositoryIdentity,
            'checkout_path' => $checkout->value,
            'root' => $context['root']->value,
            'branch' => $branch,
            'starting_commit' => $commit,
            'common_repository_path' => dirname($commonPath->value),
            'source_identity' => $sourceIdentity,
            'linked_worktree_paths' => $linkedWorktreePaths,
        ];

        return new AppInstanceSourceInventory(
            appInstanceId: $appInstance->id,
            layout: AppInstanceSourceLayout::Checkout->value,
            repositoryIdentity: $repositoryIdentity,
            checkoutPath: $checkout->value,
            root: $context['root']->value,
            branch: $branch,
            startingCommit: $commit,
            commonRepositoryPath: dirname($commonPath->value),
            sourceIdentity: $sourceIdentity,
            linkedWorktreePaths: $linkedWorktreePaths,
            digest: hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR)),
        );
    }

    public function remove(
        AppInstance $appInstance,
        AppInstanceSourceInventory $inventory,
        bool $force,
    ): void {
        $context = $this->context($appInstance);

        if (
            $inventory->appInstanceId !== $appInstance->id
            || $inventory->layout !== AppInstanceSourceLayout::Checkout->value
            || $inventory->repositoryIdentity !== $context['repositoryIdentity']
            || $inventory->checkoutPath !== $appInstance->checkout_path
            || $inventory->root !== $context['root']->value
            || $inventory->branch !== $context['branch']
            || $inventory->linkedWorktreePaths !== [$appInstance->checkout_path]
        ) {
            $this->invalidEvidence($appInstance, $force);
        }

        $groupingDirectory = $this->boundaries
            ->appInstanceGroupingDirectory($appInstance, $context['root'])
            ->value;
        $this->ssh->execute(
            $appInstance->node,
            new RemoteCommand(
                arguments: [
                    'bash',
                    '-seu',
                    '--',
                    $inventory->checkoutPath,
                    $inventory->root,
                    $groupingDirectory,
                    $context['user'],
                    $context['group'],
                    $inventory->branch,
                    $context['startingCommit'],
                    $inventory->startingCommit,
                    $inventory->sourceIdentity,
                    $inventory->repositoryIdentity,
                    $force ? '1' : '0',
                ],
                input: self::removalScript(),
            ),
            step: 'app-instance-source-remove',
            errorCode: $this->failureCode($force),
        );
    }

    /** @return array{root: StoragePath, user: string, group: string, branch: string, startingCommit: string, repositoryIdentity: string} */
    private function context(AppInstance $appInstance): array
    {
        $appInstance->loadMissing(['app', 'node']);

        if ($appInstance->source_layout !== AppInstanceSourceLayout::Checkout->value) {
            throw new RuntimeConvergenceException(
                step: 'app-instance-source-layout',
                errorCode: 'instance.source_layout_conflict',
                message: "AppInstance [{$appInstance->name}] does not own an independent checkout.",
            );
        }

        $branch = $appInstance->branch;
        $startingCommit = $appInstance->starting_commit;

        if (! is_string($branch) || ! is_string($startingCommit)) {
            $this->invalidEvidence($appInstance, false);
        }

        $repositoryIdentity = $appInstance->app->repository_identity;

        if (! is_string($repositoryIdentity) || $repositoryIdentity === '') {
            $this->invalidEvidence($appInstance, false);
        }

        $account = $this->accounts->resolve($appInstance->node);

        return [
            'root' => $this->boundaries->appInstanceRoot($appInstance, $account),
            'user' => $account->user,
            'group' => $account->group,
            'branch' => $branch,
            'startingCommit' => $startingCommit,
            'repositoryIdentity' => $repositoryIdentity,
        ];
    }

    /** @return list<string> */
    private function worktreePaths(
        string $inventory,
        AppInstance $appInstance,
        string $expectedCheckout,
        bool $force,
    ): array {
        $paths = [];

        foreach (explode("\0", $inventory) as $field) {
            if (! str_starts_with($field, 'worktree ')) {
                continue;
            }

            $path = StoragePath::tryParse(substr($field, 9));

            if (! $path instanceof StoragePath) {
                $this->invalidEvidence($appInstance, $force);
            }

            $paths[] = $path->value;
        }

        $paths = array_values(array_unique($paths));
        sort($paths, SORT_STRING);

        if (! in_array($expectedCheckout, $paths, true)) {
            $this->invalidEvidence($appInstance, $force);
        }

        return $paths;
    }

    private function isPublished(AppInstance $appInstance, string $origin, string $commit): bool
    {
        $result = $this->ssh->execute(
            $appInstance->node,
            new RemoteCommand(
                arguments: ['bash', '-seu', '--', $origin, $commit],
                input: self::publicationScript(),
            ),
            step: 'app-instance-source-removal-publication',
            errorCode: 'instance.remove_refused',
        );

        return trim($result->stdout) === '1';
    }

    private function decode(
        string $value,
        AppInstance $appInstance,
        bool $force,
    ): string {
        $decoded = base64_decode($value, true);

        if (! is_string($decoded)) {
            $this->invalidEvidence($appInstance, $force);
        }

        return $decoded;
    }

    private function unsafeContent(AppInstance $appInstance): never
    {
        throw new RuntimeConvergenceException(
            step: 'app-instance-source-removal-inspect',
            errorCode: 'instance.remove_refused',
            message: "AppInstance [{$appInstance->name}] has dirty or unpublished source.",
        );
    }

    private function invalidEvidence(AppInstance $appInstance, bool $force): never
    {
        throw new RuntimeConvergenceException(
            step: 'app-instance-source-removal-inspect',
            errorCode: $this->failureCode($force),
            message: "AppInstance [{$appInstance->name}] has invalid source evidence.",
        );
    }

    private function failureCode(bool $force): string
    {
        return $force ? 'instance.force_failed' : 'instance.remove_refused';
    }

    private static function inspectionScript(): string
    {
        return <<<'BASH'
            checkout=$1
            root=$2
            managed_user=$3
            managed_group=$4
            expected_branch=$5
            expected_starting_commit=$6
            inspect_content=$7
            export GIT_OPTIONAL_LOCKS=0
            case "$checkout" in "$root"/*) ;; *) exit 1 ;; esac
            current=$root
            relative=${checkout#"$root"/}
            old_ifs=$IFS
            IFS=/
            for segment in $relative; do
                IFS=$old_ifs
                current="$current/$segment"
                test ! -L "$current"
                IFS=/
            done
            IFS=$old_ifs
            test -d "$checkout"
            test "$(realpath -e "$checkout")" = "$checkout"
            test "$(stat -c '%U:%G' "$checkout")" = "$managed_user:$managed_group"
            test "$(stat -c '%U:%G' "$(dirname "$checkout")")" = "$managed_user:$managed_group"
            top=$(git -C "$checkout" rev-parse --show-toplevel)
            git_dir=$(git -C "$checkout" rev-parse --absolute-git-dir)
            common=$(git -C "$checkout" rev-parse --path-format=absolute --git-common-dir)
            test "$top" = "$checkout"
            test -d "$checkout/.git"
            test ! -L "$checkout/.git"
            test "$git_dir" = "$checkout/.git"
            test "$common" = "$checkout/.git"
            origin=$(git -C "$checkout" remote get-url origin)
            branch=$(git -C "$checkout" symbolic-ref --short HEAD)
            commit=$(git -C "$checkout" rev-parse --verify HEAD^{commit})
            test "$branch" = "$expected_branch"
            git -C "$checkout" merge-base --is-ancestor "$expected_starting_commit" HEAD
            dirty=
            if [ "$inspect_content" = 1 ]; then
                dirty=0
                test -z "$(git -C "$checkout" status --porcelain --untracked-files=all)" || dirty=1
            fi
            encode() { printf '%s' "$1" | base64 --wrap=0; printf '\n'; }
            encode "$top"
            encode "$common"
            encode "$origin"
            encode "$branch"
            encode "$commit"
            encode "$dirty"
            encode "$(stat -c '%d:%i' "$checkout")"
            git -C "$checkout" worktree list --porcelain -z | base64 --wrap=0
            printf '\n'
            BASH;
    }

    private static function publicationScript(): string
    {
        return <<<'BASH'
            origin=$1
            commit=$2
            scratch=$(mktemp -d)
            trap 'rm -rf -- "$scratch"' EXIT
            git init --bare --quiet "$scratch/repository.git"
            git --git-dir="$scratch/repository.git" remote add origin "$origin"
            git --git-dir="$scratch/repository.git" fetch --quiet --no-tags --filter=blob:none origin \
                '+refs/heads/*:refs/remotes/origin/*' '+refs/tags/*:refs/tags/*'
            published=0
            if git --git-dir="$scratch/repository.git" cat-file -e "$commit^{commit}" 2>/dev/null; then
                while IFS= read -r advertised; do
                    tip=$(git --git-dir="$scratch/repository.git" rev-parse --verify "$advertised^{commit}" 2>/dev/null) || continue
                    if git --git-dir="$scratch/repository.git" merge-base --is-ancestor "$commit" "$tip"; then
                        published=1
                        break
                    fi
                done < <(git --git-dir="$scratch/repository.git" for-each-ref \
                    --format='%(refname)' refs/remotes/origin refs/tags)
            fi
            printf '%s\n' "$published"
            BASH;
    }

    private static function removalScript(): string
    {
        return <<<'BASH'
            checkout=$1
            root=$2
            grouping_directory=$3
            managed_user=$4
            managed_group=$5
            expected_branch=$6
            expected_starting_commit=$7
            expected_commit=$8
            expected_source_identity=$9
            shift 9
            expected_repository_identity=$1
            force=$2
            export GIT_OPTIONAL_LOCKS=0
            test "$grouping_directory" = "$(dirname "$checkout")"
            case "$checkout" in "$root"/*) ;; *) exit 1 ;; esac
            current=$root
            relative=${checkout#"$root"/}
            old_ifs=$IFS
            IFS=/
            for segment in $relative; do
                IFS=$old_ifs
                current="$current/$segment"
                test ! -L "$current"
                IFS=/
            done
            IFS=$old_ifs
            test -d "$checkout"
            test "$(realpath -e "$checkout")" = "$checkout"
            test "$(stat -c '%d:%i' "$checkout")" = "$expected_source_identity"
            test "$(stat -c '%U:%G' "$checkout")" = "$managed_user:$managed_group"
            test -d "$grouping_directory"
            test ! -L "$grouping_directory"
            test "$(realpath -e "$grouping_directory")" = "$grouping_directory"
            test "$(stat -c '%U:%G' "$grouping_directory")" = "$managed_user:$managed_group"
            test -d "$checkout/.git"
            test ! -L "$checkout/.git"
            test "$(git -C "$checkout" rev-parse --show-toplevel)" = "$checkout"
            test "$(git -C "$checkout" rev-parse --absolute-git-dir)" = "$checkout/.git"
            test "$(git -C "$checkout" rev-parse --path-format=absolute --git-common-dir)" = "$checkout/.git"
            test "$(git -C "$checkout" symbolic-ref --short HEAD)" = "$expected_branch"
            test "$(git -C "$checkout" rev-parse --verify HEAD^{commit})" = "$expected_commit"
            git -C "$checkout" merge-base --is-ancestor "$expected_starting_commit" HEAD
            origin=$(git -C "$checkout" remote get-url origin)
            repository_identity=$(printf '%s' "$origin" | php -r '
                $repository = stream_get_contents(STDIN);

                if (
                    preg_match("//u", $repository) !== 1
                    || $repository === ""
                    || preg_match("/[\\p{Z}\\p{C}]/u", $repository) !== 0
                ) {
                    exit(1);
                }

                $matches = [];

                if (preg_match("/\\Agit@([^:\\s?#]+):([^\\s?#]+)\\z/u", $repository, $matches) === 1) {
                    $host = $matches[1];
                    $path = $matches[2];
                } else {
                    $parts = parse_url($repository);

                    if (!is_array($parts)) {
                        exit(1);
                    }

                    $scheme = is_string($parts["scheme"] ?? null) ? $parts["scheme"] : null;
                    $host = is_string($parts["host"] ?? null) ? $parts["host"] : null;
                    $path = is_string($parts["path"] ?? null) ? $parts["path"] : null;

                    if (!is_string($host) || $host === "" || !is_string($path) || $path === "") {
                        exit(1);
                    }

                    if (array_key_exists("query", $parts) || array_key_exists("fragment", $parts)) {
                        exit(1);
                    }

                    $https = $scheme === "https"
                        && !array_key_exists("user", $parts)
                        && !array_key_exists("pass", $parts);
                    $ssh = $scheme === "ssh" && !array_key_exists("pass", $parts);

                    if (!$https && !$ssh) {
                        exit(1);
                    }
                }

                $path = trim($path, "/");

                if (str_ends_with($path, ".git")) {
                    $path = substr($path, 0, -4);
                }

                fwrite(STDOUT, strtolower($host)."/".rtrim($path, "/"));
            ')
            test "$repository_identity" = "$expected_repository_identity"
            linked_count=0
            while IFS= read -r -d '' field; do
                case "$field" in
                    'worktree '*)
                        linked_path=${field#worktree }
                        linked_count=$((linked_count + 1))
                        test "$linked_path" = "$checkout"
                        ;;
                esac
            done < <(git -C "$checkout" worktree list --porcelain -z)
            test "$linked_count" = 1
            if [ "$force" != 1 ]; then
                test -z "$(git -C "$checkout" status --porcelain --untracked-files=all)"
                scratch=$(mktemp -d)
                trap 'rm -rf -- "$scratch"' EXIT
                git init --bare --quiet "$scratch/repository.git"
                git --git-dir="$scratch/repository.git" remote add origin "$origin"
                git --git-dir="$scratch/repository.git" fetch --quiet --no-tags --filter=blob:none origin \
                    '+refs/heads/*:refs/remotes/origin/*' '+refs/tags/*:refs/tags/*'
                published=0
                if git --git-dir="$scratch/repository.git" cat-file -e "$expected_commit^{commit}" 2>/dev/null; then
                    while IFS= read -r advertised; do
                        tip=$(git --git-dir="$scratch/repository.git" rev-parse --verify "$advertised^{commit}" 2>/dev/null) || continue
                        if git --git-dir="$scratch/repository.git" merge-base --is-ancestor "$expected_commit" "$tip"; then
                            published=1
                            break
                        fi
                    done < <(git --git-dir="$scratch/repository.git" for-each-ref \
                        --format='%(refname)' refs/remotes/origin refs/tags)
                fi
                test "$published" = 1
                rm -rf -- "$scratch"
                trap - EXIT
            fi
            rm -rf -- "$checkout"
            rmdir --ignore-fail-on-non-empty -- "$grouping_directory"
            BASH;
    }
}
