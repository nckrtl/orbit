<?php

declare(strict_types=1);

namespace App\Infrastructure\AppInstances;

use App\Domain\AppDev\AppDevSourceOperationLock;
use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\AppInstances\AppInstanceSourceLayout;
use App\Domain\AppInstances\Removal\AppInstanceSourceInventory;
use App\Domain\AppInstances\Removal\AppInstanceSourceRevalidationState;
use App\Domain\AppInstances\Removal\DevelopmentAppInstanceSourceRemoval;
use App\Domain\Nodes\ManagedUserAccountResolver;
use App\Domain\Nodes\Storage\CheckoutRemovalBoundary;
use App\Domain\Nodes\Storage\StoragePath;
use App\Domain\SourceControl\GitRepositoryIdentity;
use App\Infrastructure\AppDev\AppDevSshExecutor;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Models\AppInstance;
use App\Models\AppInstanceRemovalMember;
use App\Models\Node;

/**
 * @mago-expect lint:kan-defect Source removal keeps one fail-closed journal and identity protocol.
 * @mago-expect lint:cyclomatic-complexity Source inspection is one fail-closed identity boundary.
 * @mago-expect lint:too-many-methods The adapter keeps its remote journal and receipt protocol together.
 */
final readonly class RemoteDevelopmentAppInstanceSourceRemoval implements DevelopmentAppInstanceSourceRemoval
{
    public function __construct(
        private AppDevSshExecutor $ssh,
        private ManagedUserAccountResolver $accounts,
        private CheckoutRemovalBoundary $boundaries,
        private AppDevSourceOperationLock $lock,
    ) {}

    public function inspect(AppInstance $appInstance, bool $force): AppInstanceSourceInventory
    {
        $appInstance->loadMissing(['app', 'node']);

        if ($appInstance->environment === 'production') {
            return $this->productionInventory($appInstance);
        }

        return $this->lock->synchronized(
            $appInstance->node_id,
            fn (): AppInstanceSourceInventory => $this->inspectLocked($appInstance, $force),
        );
    }

    public function prepare(AppInstanceRemovalMember $member): void
    {
        if ($member->environment === 'production') {
            return;
        }

        $this->lock->synchronized($member->node_id, function () use ($member): void {
            $this->revalidateLocked($member);
            [$node, $account] = $this->memberContext($member);
            $this->ssh->execute(
                $node,
                new RemoteCommand(
                    arguments: [
                        'bash',
                        '-seu',
                        '--',
                        (string) $member->root,
                        $member->app_instance_removal_id,
                        (string) $member->id,
                        $member->source_digest,
                        $account->user,
                        $account->group,
                    ],
                    input: <<<'BASH'
                        root=$1
                        operation=$2
                        member=$3
                        digest=$4
                        managed_user=$5
                        managed_group=$6
                        state="$root/.orbit-removals"
                        journal="$state/$operation.$member.journal"
                        test -d "$root"
                        test ! -L "$root"
                        test "$(realpath -e "$root")" = "$root"
                        install -d -m 0700 -- "$state"
                        test "$(stat -c '%U:%G' "$state")" = "$managed_user:$managed_group"
                        candidate="$state/.$operation.$member.journal.candidate"
                        printf '%s\n' "$digest" > "$candidate"
                        chmod 0600 -- "$candidate"
                        mv -fT -- "$candidate" "$journal"
                        BASH,
                ),
                step: 'app-instance-removal-prepare',
                errorCode: 'instance.remove_refused',
            );
        });
    }

    public function revalidate(AppInstanceRemovalMember $member): AppInstanceSourceRevalidationState
    {
        if ($member->environment === 'production') {
            return AppInstanceSourceRevalidationState::Present;
        }

        return $this->lock->synchronized($member->node_id, function () use (
            $member,
        ): AppInstanceSourceRevalidationState {
            if ($member->source_prepared_at === null) {
                $this->revalidateLocked($member);

                return AppInstanceSourceRevalidationState::Present;
            }

            $state = $this->revalidationStateLocked($member);

            if ($state !== AppInstanceSourceRevalidationState::Present) {
                return $state;
            }

            $this->revalidateLocked($member);

            return $state;
        });
    }

    public function finalize(AppInstanceRemovalMember $member): string
    {
        if ($member->environment === 'production') {
            return hash('sha256', "production-retained\0{$member->source_digest}");
        }

        return $this->lock->synchronized($member->node_id, function () use ($member): string {
            [$node, $account] = $this->memberContext($member);
            $removal = $member->removal()->firstOrFail();
            $receipt = hash(
                'sha256',
                "{$member->app_instance_removal_id}\0{$member->id}\0{$member->source_digest}\0finalized",
            );
            $result = $this->ssh->execute(
                $node,
                new RemoteCommand(
                    arguments: [
                        'bash',
                        '-seu',
                        '--',
                        (string) $member->checkout_path,
                        (string) $member->root,
                        (string) $member->common_repository_path,
                        $member->source_layout,
                        (string) $member->branch,
                        (string) $member->starting_commit,
                        $member->app_instance_removal_id,
                        (string) $member->id,
                        $member->source_digest,
                        $receipt,
                        $removal->force ? '1' : '0',
                        $account->user,
                        $account->group,
                        (string) $member->source_identity,
                    ],
                    input: self::finalizationScript(),
                ),
                step: 'app-instance-source-finalization',
                errorCode: 'instance.removal_incomplete',
            );

            if (trim($result->stdout) !== $receipt) {
                throw new RuntimeConvergenceException(
                    step: 'app-instance-source-finalization',
                    errorCode: 'instance.finalization_evidence_invalid',
                    message: 'AppInstance removal returned invalid finalization evidence.',
                );
            }

            return $receipt;
        });
    }

    private function inspectLocked(AppInstance $appInstance, bool $force): AppInstanceSourceInventory
    {
        $context = $this->sourceContext($appInstance);
        $result = $this->ssh->execute(
            $appInstance->node,
            new RemoteCommand(
                arguments: [
                    'bash',
                    '-seu',
                    '--',
                    $appInstance->checkout_path,
                    $context['root']->value,
                    $context['account']->user,
                    $context['account']->group,
                    $appInstance->source_layout,
                    (string) $appInstance->branch,
                    (string) $appInstance->starting_commit,
                    $force ? '1' : '0',
                ],
                input: self::inspectionScript(),
            ),
            step: 'app-instance-source-removal-inspect',
            errorCode: 'instance.remove_refused',
        );

        $values = preg_split('/\R/', trim($result->stdout));

        if (! is_array($values) || count($values) !== 9) {
            $this->invalidEvidence($appInstance);
        }

        [$top, $common, $origin, $branch, $commit, $dirty, $published, $sourceIdentity, $worktrees] = array_map(
            fn (string $value): string => $this->decode($value, $appInstance),
            $values,
        );
        $checkout = StoragePath::tryParse($top);
        $commonPath = StoragePath::tryParse($common);

        if (
            ! $checkout instanceof StoragePath
            || ! $commonPath instanceof StoragePath
            || $checkout->value !== $appInstance->checkout_path
            || $branch !== $appInstance->branch
            || ! in_array($dirty, ['0', '1'], true)
            || ! in_array($published, ['0', '1'], true)
        ) {
            $this->invalidEvidence($appInstance);
        }

        try {
            $repositoryIdentity = GitRepositoryIdentity::derive($origin);
        } catch (\InvalidArgumentException) {
            $this->invalidEvidence($appInstance);
        }

        if ($repositoryIdentity !== $appInstance->app->repository_identity) {
            $this->invalidEvidence($appInstance);
        }

        $linkedWorktrees = $this->worktreePaths($worktrees, $appInstance);
        $commonRepository = dirname($commonPath->value);
        $payload = [
            'app_instance_id' => $appInstance->id,
            'layout' => $appInstance->source_layout,
            'repository_identity' => $repositoryIdentity,
            'checkout_path' => $checkout->value,
            'root' => $context['root']->value,
            'branch' => $branch,
            'starting_commit' => $commit,
            'common_repository_path' => $commonRepository,
            'source_identity' => $sourceIdentity,
        ];

        return new AppInstanceSourceInventory(
            appInstanceId: $appInstance->id,
            layout: $appInstance->source_layout,
            repositoryIdentity: $repositoryIdentity,
            checkoutPath: $checkout->value,
            root: $context['root']->value,
            branch: $branch,
            startingCommit: $commit,
            commonRepositoryPath: $commonRepository,
            sourceIdentity: $sourceIdentity,
            linkedWorktreePaths: $linkedWorktrees,
            digest: hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR)),
        );
    }

    private function revalidateLocked(AppInstanceRemovalMember $member): void
    {
        $appInstance = AppInstance::query()->with(['app', 'node'])->find($member->app_instance_id);

        if (! $appInstance instanceof AppInstance) {
            throw new RuntimeConvergenceException(
                step: 'app-instance-removal-revalidation',
                errorCode: 'instance.removal_conflict',
                message: 'The recorded AppInstance removal member is unavailable.',
            );
        }

        $force = (bool) $member->removal()->firstOrFail()->force;
        $inventory = $this->inspectLocked($appInstance, $force);

        if ($inventory->digest !== $member->source_digest) {
            throw new RuntimeConvergenceException(
                step: 'app-instance-removal-revalidation',
                errorCode: 'instance.removal_conflict',
                message: "AppInstance [{$member->name}] source identity changed after removal acceptance.",
            );
        }
    }

    private function revalidationStateLocked(AppInstanceRemovalMember $member): AppInstanceSourceRevalidationState
    {
        [$node] = $this->memberContext($member);
        $receipt = hash(
            'sha256',
            "{$member->app_instance_removal_id}\0{$member->id}\0{$member->source_digest}\0finalized",
        );
        $result = $this->ssh->execute(
            $node,
            new RemoteCommand(
                arguments: [
                    'bash',
                    '-seu',
                    '--',
                    (string) $member->checkout_path,
                    (string) $member->root,
                    $member->app_instance_removal_id,
                    (string) $member->id,
                    $member->source_digest,
                    $receipt,
                    (string) $member->source_identity,
                ],
                input: <<<'BASH'
                    checkout=$1
                    root=$2
                    operation=$3
                    member=$4
                    digest=$5
                    receipt=$6
                    source_identity=$7
                    state="$root/.orbit-removals"
                    journal="$state/$operation.$member.journal"
                    receipt_path="$state/$operation.$member.receipt"
                    quarantine="$state/$operation.$member.quarantine"
                    test -d "$state"
                    test ! -L "$state"
                    test "$(cat "$journal")" = "$digest"
                    if [ -e "$checkout" ] || [ -L "$checkout" ]; then
                        test ! -e "$quarantine"
                        test ! -L "$quarantine"
                        test ! -e "$receipt_path"
                        test ! -L "$receipt_path"
                        test -d "$checkout"
                        test ! -L "$checkout"
                        test "$(stat -c '%d:%i' "$checkout")" = "$source_identity"
                        printf 'present\n'
                        exit 0
                    fi
                    if [ -e "$quarantine" ] || [ -L "$quarantine" ]; then
                        test -d "$quarantine"
                        test ! -L "$quarantine"
                        test "$(stat -c '%d:%i' "$quarantine")" = "$source_identity"
                        if [ -e "$receipt_path" ] || [ -L "$receipt_path" ]; then
                            test -f "$receipt_path"
                            test ! -L "$receipt_path"
                            test "$(cat "$receipt_path")" = "$receipt"
                            printf 'receipt-pending-cleanup\n'
                            exit 0
                        fi
                        printf 'quarantined\n'
                        exit 0
                    fi
                    test -f "$receipt_path"
                    test ! -L "$receipt_path"
                    test "$(cat "$receipt_path")" = "$receipt"
                    printf 'completed\n'
                    BASH,
            ),
            step: 'app-instance-removal-revalidation',
            errorCode: 'instance.removal_conflict',
        );
        $state = trim($result->stdout);

        $resolved = AppInstanceSourceRevalidationState::tryFrom($state);

        if (! $resolved instanceof AppInstanceSourceRevalidationState) {
            throw new RuntimeConvergenceException(
                step: 'app-instance-removal-revalidation',
                errorCode: 'instance.removal_conflict',
                message: 'AppInstance removal returned invalid source-presence evidence.',
            );
        }

        return $resolved;
    }

    /** @return array{0: Node, 1: \App\Domain\Nodes\ManagedUserAccount} */
    private function memberContext(AppInstanceRemovalMember $member): array
    {
        $node = Node::query()->findOrFail($member->node_id);

        return [$node, $this->accounts->resolve($node)];
    }

    /** @return array{root: StoragePath, account: \App\Domain\Nodes\ManagedUserAccount} */
    private function sourceContext(AppInstance $appInstance): array
    {
        if (! in_array(
            $appInstance->source_layout,
            [AppInstanceSourceLayout::Checkout->value, AppInstanceSourceLayout::Worktree->value],
            true,
        )) {
            throw new RuntimeConvergenceException(
                step: 'app-instance-source-layout',
                errorCode: 'instance.source_layout_conflict',
                message: "AppInstance [{$appInstance->name}] has an invalid source layout.",
            );
        }

        if (! is_string($appInstance->branch) || ! is_string($appInstance->starting_commit)) {
            $this->invalidEvidence($appInstance);
        }

        $account = $this->accounts->resolve($appInstance->node);

        return [
            'root' => $this->boundaries->appInstanceRoot($appInstance, $account),
            'account' => $account,
        ];
    }

    private function productionInventory(AppInstance $appInstance): AppInstanceSourceInventory
    {
        $payload = [
            'app_instance_id' => $appInstance->id,
            'environment' => 'production',
            'node_id' => $appInstance->node_id,
            'path' => $appInstance->checkout_path,
        ];

        return new AppInstanceSourceInventory(
            appInstanceId: $appInstance->id,
            layout: $appInstance->source_layout,
            repositoryIdentity: $appInstance->app->repository_identity,
            checkoutPath: $appInstance->checkout_path,
            root: $appInstance->root,
            branch: $appInstance->branch,
            startingCommit: $appInstance->starting_commit,
            commonRepositoryPath: null,
            sourceIdentity: null,
            linkedWorktreePaths: [],
            digest: hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR)),
        );
    }

    /** @return list<string> */
    private function worktreePaths(string $inventory, AppInstance $appInstance): array
    {
        $paths = [];

        foreach (explode("\0", $inventory) as $field) {
            if (! str_starts_with($field, 'worktree ')) {
                continue;
            }

            $path = substr($field, 9);
            $parsed = StoragePath::tryParse($path);

            if (! $parsed instanceof StoragePath) {
                $this->invalidEvidence($appInstance);
            }

            $paths[] = $parsed->value;
        }

        sort($paths, SORT_STRING);

        if (! in_array($appInstance->checkout_path, $paths, true)) {
            $this->invalidEvidence($appInstance);
        }

        return $paths;
    }

    private function decode(string $value, AppInstance $appInstance): string
    {
        $decoded = base64_decode($value, true);

        if (! is_string($decoded)) {
            $this->invalidEvidence($appInstance);
        }

        return $decoded;
    }

    private function invalidEvidence(AppInstance $appInstance): never
    {
        throw new RuntimeConvergenceException(
            step: 'app-instance-source-removal-inspect',
            errorCode: 'instance.source_identity_invalid',
            message: "AppInstance [{$appInstance->name}] has invalid source evidence.",
        );
    }

    private static function inspectionScript(): string
    {
        return <<<'BASH'
            checkout=$1
            root=$2
            managed_user=$3
            managed_group=$4
            layout=$5
            expected_branch=$6
            expected_commit=$7
            force=$8
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
            case "$layout" in
                checkout)
                    test -d "$checkout/.git"
                    test ! -L "$checkout/.git"
                    test "$git_dir" = "$checkout/.git"
                    test "$common" = "$checkout/.git"
                    ;;
                worktree)
                    test -f "$checkout/.git"
                    test ! -L "$checkout/.git"
                    test "$git_dir" != "$common"
                    test -d "$common"
                    test ! -L "$common"
                    ;;
                *) exit 1 ;;
            esac
            origin=$(git -C "$checkout" remote get-url origin)
            branch=$(git -C "$checkout" symbolic-ref --short HEAD)
            commit=$(git -C "$checkout" rev-parse --verify HEAD^{commit})
            test "$branch" = "$expected_branch"
            git -C "$checkout" merge-base --is-ancestor "$expected_commit" HEAD
            dirty=0
            test -z "$(git -C "$checkout" status --porcelain --untracked-files=all)" || dirty=1
            published=0
            advertised=$(mktemp)
            trap 'rm -f -- "$advertised"' EXIT
            git -C "$checkout" ls-remote --refs origin 'refs/heads/*' 'refs/tags/*' > "$advertised"
            while read -r advertised_commit advertised_ref; do
                test -n "$advertised_ref" || continue
                if [ "$advertised_commit" = "$commit" ]; then
                    published=1
                    break
                fi
                if git -C "$checkout" cat-file -e "$advertised_commit^{commit}" 2>/dev/null && \
                    git -C "$checkout" merge-base --is-ancestor "$commit" "$advertised_commit"; then
                    published=1
                    break
                fi
            done < "$advertised"
            if [ "$force" != 1 ]; then
                test "$dirty" = 0
                test "$published" = 1
            fi
            encode() { printf '%s' "$1" | base64 --wrap=0; printf '\n'; }
            encode "$top"
            encode "$common"
            encode "$origin"
            encode "$branch"
            encode "$commit"
            encode "$dirty"
            encode "$published"
            encode "$(stat -c '%d:%i' "$checkout")"
            git -C "$checkout" worktree list --porcelain -z | base64 --wrap=0
            printf '\n'
            BASH;
    }

    private static function finalizationScript(): string
    {
        return <<<'BASH'
            checkout=$1
            root=$2
            common_repository=$3
            layout=$4
            branch=$5
            starting_commit=$6
            operation=$7
            member=$8
            digest=$9
            shift 9
            receipt=$1
            force=$2
            managed_user=$3
            managed_group=$4
            source_identity=$5
            state="$root/.orbit-removals"
            journal="$state/$operation.$member.journal"
            receipt_path="$state/$operation.$member.receipt"
            quarantine="$state/$operation.$member.quarantine"
            test -d "$state"
            test ! -L "$state"
            test "$(cat "$journal")" = "$digest"
            if [ ! -e "$checkout" ] && [ ! -L "$checkout" ] && \
                [ ! -e "$quarantine" ] && [ ! -L "$quarantine" ]; then
                test -f "$receipt_path"
                test ! -L "$receipt_path"
                test "$(cat "$receipt_path")" = "$receipt"
                printf '%s\n' "$receipt"
                exit 0
            fi
            if [ -e "$checkout" ] || [ -L "$checkout" ]; then
                test ! -e "$quarantine"
                test ! -L "$quarantine"
                test ! -e "$receipt_path"
                test ! -L "$receipt_path"
                test -d "$checkout"
                test ! -L "$checkout"
                test "$(realpath -e "$checkout")" = "$checkout"
                test "$(stat -c '%d:%i' "$checkout")" = "$source_identity"
                test "$(stat -c '%U:%G' "$checkout")" = "$managed_user:$managed_group"
                test "$(git -C "$checkout" rev-parse --show-toplevel)" = "$checkout"
                test "$(git -C "$checkout" symbolic-ref --short HEAD)" = "$branch"
                git -C "$checkout" merge-base --is-ancestor "$starting_commit" HEAD
                if [ "$force" != 1 ]; then
                    test -z "$(git -C "$checkout" status --porcelain --untracked-files=all)"
                    commit=$(git -C "$checkout" rev-parse --verify HEAD^{commit})
                    published=0
                    advertised=$(mktemp)
                    trap 'rm -f -- "$advertised"' EXIT
                    git -C "$checkout" ls-remote --refs origin 'refs/heads/*' 'refs/tags/*' > "$advertised"
                    while read -r advertised_commit advertised_ref; do
                        test -n "$advertised_ref" || continue
                        if [ "$advertised_commit" = "$commit" ]; then
                            published=1
                            break
                        fi
                        if git -C "$checkout" cat-file -e "$advertised_commit^{commit}" 2>/dev/null && \
                            git -C "$checkout" merge-base --is-ancestor "$commit" "$advertised_commit"; then
                            published=1
                            break
                        fi
                    done < "$advertised"
                    test "$published" = 1
                    rm -f -- "$advertised"
                    trap - EXIT
                fi
                case "$layout" in
                    worktree)
                        test "$(dirname "$(git -C "$checkout" rev-parse --path-format=absolute --git-common-dir)")" = "$common_repository"
                        git --git-dir="$common_repository/.git" worktree move "$checkout" "$quarantine"
                        ;;
                    checkout)
                        test "$(git -C "$checkout" rev-parse --absolute-git-dir)" = "$checkout/.git"
                        test "$(git -C "$checkout" rev-parse --path-format=absolute --git-common-dir)" = "$checkout/.git"
                        test "$(git -C "$checkout" worktree list --porcelain | grep -c '^worktree ')" = 1
                        mv -- "$checkout" "$quarantine"
                        ;;
                    *) exit 1 ;;
                esac
            else
                test -d "$quarantine"
                test ! -L "$quarantine"
                test "$(stat -c '%d:%i' "$quarantine")" = "$source_identity"
                if [ -e "$receipt_path" ] || [ -L "$receipt_path" ]; then
                    test -f "$receipt_path"
                    test ! -L "$receipt_path"
                    test "$(cat "$receipt_path")" = "$receipt"
                fi
            fi
            test -d "$quarantine"
            test ! -L "$quarantine"
            test "$(stat -c '%d:%i' "$quarantine")" = "$source_identity"
            if [ ! -e "$receipt_path" ] && [ ! -L "$receipt_path" ]; then
                receipt_candidate="$state/.$operation.$member.receipt.candidate"
                printf '%s\n' "$receipt" > "$receipt_candidate"
                chmod 0600 -- "$receipt_candidate"
                mv -fT -- "$receipt_candidate" "$receipt_path"
            fi
            case "$layout" in
                worktree) git --git-dir="$common_repository/.git" worktree remove --force "$quarantine" ;;
                checkout) rm -rf -- "$quarantine" ;;
            esac
            test ! -e "$quarantine"
            printf '%s\n' "$receipt"
            BASH;
    }
}
