<?php

declare(strict_types=1);

namespace App\Infrastructure\AppInstances;

use App\Domain\AppDev\AppDevSourceOperationLock;
use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\AppInstances\AppInstanceSourceLayout;
use App\Domain\AppInstances\Removal\AppInstanceSourceInventory;
use App\Domain\AppInstances\Removal\AppInstanceSourceMismatch;
use App\Domain\AppInstances\Removal\AppInstanceSourceRevalidationExpectation;
use App\Domain\AppInstances\Removal\AppInstanceSourceRevalidationState;
use App\Domain\AppInstances\Removal\DevelopmentAppInstanceSourceFinalizer;
use App\Domain\AppInstances\Removal\DevelopmentAppInstanceSourceRemoval;
use App\Domain\GitHub\RepositoryReadAccess;
use App\Domain\Nodes\ManagedUserAccountResolver;
use App\Domain\Nodes\Storage\CheckoutRemovalBoundary;
use App\Domain\Nodes\Storage\StoragePath;
use App\Domain\SourceControl\GitRepositoryIdentity;
use App\Infrastructure\AppDev\AppDevSshExecutor;
use App\Infrastructure\GitHub\GitReadScript;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Models\App as OrbitApp;
use App\Models\AppInstance;
use App\Models\AppInstanceRemovalMember;
use App\Models\Node;
use Illuminate\Support\Str;
use InvalidArgumentException;

final readonly class RemoteDevelopmentAppInstanceSourceRemoval implements DevelopmentAppInstanceSourceFinalizer, DevelopmentAppInstanceSourceRemoval
{
    /**
     * Exit statuses that the guest inspection and removal scripts use to name the identity check they refused.
     *
     * @var array<int, AppInstanceSourceMismatch>
     */
    private const array ScriptMismatches = [
        10 => AppInstanceSourceMismatch::Path,
        11 => AppInstanceSourceMismatch::Ownership,
        12 => AppInstanceSourceMismatch::Layout,
        13 => AppInstanceSourceMismatch::Origin,
        14 => AppInstanceSourceMismatch::Branch,
        15 => AppInstanceSourceMismatch::Worktrees,
    ];

    /** Exit status of the guest removal script when normal removal found dirty or unpublished source. */
    private const int UnsafeContentStatus = 20;

    /** Exit status of the guest removal script when the source changed between inspection and deletion. */
    private const int ChangedSourceStatus = 21;

    public function __construct(
        private AppDevSshExecutor $ssh,
        private ManagedUserAccountResolver $accounts,
        private CheckoutRemovalBoundary $boundaries,
        private AppDevSourceOperationLock $lock,
        private RepositoryReadAccess $access,
    ) {}

    public function inspect(
        AppInstance $appInstance,
        bool $force,
        bool $inspectContent = true,
    ): AppInstanceSourceInventory {
        return $this->lock->synchronized(
            $appInstance->node_id,
            fn (): AppInstanceSourceInventory => $this->inspectLocked($appInstance, $force, $inspectContent),
        );
    }

    private function inspectLocked(
        AppInstance $appInstance,
        bool $force,
        bool $inspectContent,
    ): AppInstanceSourceInventory {
        $context = $this->context($appInstance);

        return $this->inspectPathLocked(
            appInstance: $appInstance,
            physicalCheckout: $appInstance->checkout_path,
            logicalCheckout: $appInstance->checkout_path,
            root: $context['root'],
            user: $context['user'],
            group: $context['group'],
            layout: $appInstance->source_layout,
            expectedBranch: $context['branch'],
            expectedRepositoryIdentity: $context['repositoryIdentity'],
            force: $force,
            inspectContent: $inspectContent,
        );
    }

    /**
     * @param  array<string, string>  $quarantineMappings
     */
    private function inspectPathLocked(
        AppInstance $appInstance,
        string $physicalCheckout,
        string $logicalCheckout,
        StoragePath $root,
        string $user,
        string $group,
        string $layout,
        ?string $expectedBranch,
        string $expectedRepositoryIdentity,
        bool $force,
        bool $inspectContent = true,
        array $quarantineMappings = [],
    ): AppInstanceSourceInventory {
        $result = $this->executeRefusable(
            $appInstance,
            new RemoteCommand(
                arguments: [
                    'bash',
                    '-seu',
                    '--',
                    $physicalCheckout,
                    $root->value,
                    $user,
                    $group,
                    $layout,
                    $expectedBranch ?? '',
                    $inspectContent && ! $force ? '1' : '0',
                ],
                input: self::inspectionScript(),
            ),
            step: 'app-instance-source-removal-inspect',
            force: $force,
        );

        $values = preg_split('/\R/', trim($result->stdout));

        if (! is_array($values) || count($values) !== 8) {
            $this->invalidEvidence($appInstance, $force);
        }

        [$top, $common, $origin, $branch, $commit, $dirty, $sourceIdentity, $worktrees] = array_map(
            fn (string $value): string => $this->decode($value, $appInstance, $force),
            $values,
        );
        $branch = $branch === '' ? null : $branch;
        $top = $top === $physicalCheckout ? $logicalCheckout : $top;
        $common = $common === $physicalCheckout.'/.git' ? $logicalCheckout.'/.git' : $common;
        $checkout = StoragePath::tryParse($top);
        $commonPath = StoragePath::tryParse($common);

        if (
            ! $checkout instanceof StoragePath
            || ! $commonPath instanceof StoragePath
            || preg_match('/\A[0-9a-f]{40}(?:[0-9a-f]{24})?\z/D', $commit) !== 1
            || ! in_array($dirty, ['', '0', '1'], true)
            || preg_match('/\A[0-9]+:[0-9]+\z/D', $sourceIdentity) !== 1
        ) {
            $this->invalidEvidence($appInstance, $force);
        }

        if (
            $checkout->value !== $logicalCheckout
            || $layout === AppInstanceSourceLayout::Checkout->value
            && $commonPath->value !== $logicalCheckout.'/.git'
            || $layout === AppInstanceSourceLayout::Worktree->value
            && $commonPath->value === $logicalCheckout.'/.git'
        ) {
            $this->mismatch($appInstance, AppInstanceSourceMismatch::Layout);
        }

        if ($branch !== $expectedBranch) {
            $this->mismatch($appInstance, AppInstanceSourceMismatch::Branch);
        }

        try {
            $repositoryIdentity = GitRepositoryIdentity::derive($origin);
        } catch (InvalidArgumentException) {
            $this->mismatch($appInstance, AppInstanceSourceMismatch::Origin);
        }

        if ($repositoryIdentity !== $expectedRepositoryIdentity) {
            $this->mismatch($appInstance, AppInstanceSourceMismatch::Origin);
        }

        if ($inspectContent && ! $force && $dirty !== '0') {
            $this->unsafeContent($appInstance);
        }

        if ($inspectContent && ! $force && ! $this->isPublished($appInstance, $origin, $commit)) {
            $this->unsafeContent($appInstance);
        }

        $linkedWorktreePaths = $this->worktreePaths(
            $worktrees,
            $appInstance,
            $checkout->value,
            $force,
            $quarantineMappings,
        );
        $payload = [
            'app_instance_id' => $appInstance->id,
            'layout' => $layout,
            'repository_identity' => $repositoryIdentity,
            'checkout_path' => $checkout->value,
            'root' => $root->value,
            'branch' => $branch,
            'starting_commit' => $commit,
            'common_repository_path' => dirname($commonPath->value),
            'source_identity' => $sourceIdentity,
            'linked_worktree_paths' => $linkedWorktreePaths,
        ];

        return new AppInstanceSourceInventory(
            appInstanceId: $appInstance->id,
            layout: $layout,
            repositoryIdentity: $repositoryIdentity,
            checkoutPath: $checkout->value,
            root: $root->value,
            branch: $branch,
            startingCommit: $commit,
            commonRepositoryPath: dirname($commonPath->value),
            sourceIdentity: $sourceIdentity,
            linkedWorktreePaths: $linkedWorktreePaths,
            origin: $origin,
            worktreeInventory: $worktrees,
            digest: hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR)),
        );
    }

    public function remove(
        AppInstance $appInstance,
        AppInstanceSourceInventory $inventory,
        bool $force,
    ): void {
        $this->lock->synchronized(
            $appInstance->node_id,
            fn () => $this->removeLocked($appInstance, $inventory, $force),
        );
    }

    private function removeLocked(
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
        ) {
            $this->invalidEvidence($appInstance, $force);
        }

        if ($inventory->linkedWorktreePaths !== [$appInstance->checkout_path]) {
            $this->mismatch($appInstance, AppInstanceSourceMismatch::Worktrees, 'app-instance-source-remove');
        }

        $groupingDirectory = $this->boundaries
            ->appInstanceGroupingDirectory($appInstance, $context['root'])
            ->value;
        $script = GitReadScript::for(
            $this->access->for($inventory->origin),
            self::releaseEmptyGroupingDirectoryFunction().self::removalScript(),
        );
        $this->executeRefusable(
            $appInstance,
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
                    $inventory->branch ?? '',
                    $inventory->startingCommit,
                    $inventory->sourceIdentity,
                    $inventory->repositoryIdentity,
                    $force ? '1' : '0',
                ],
                input: $script->input,
                protectedInput: $script->protectedInput,
            ),
            step: 'app-instance-source-remove',
            force: $force,
        );
    }

    public function prepare(
        AppInstanceRemovalMember $member,
        ?AppInstanceSourceRevalidationExpectation $expectation = null,
    ): void {
        $this->lock->synchronized($member->node_id, function () use ($member, $expectation): void {
            $this->inspectRecordedLocked($member, AppInstanceSourceRevalidationState::Present, $expectation);
            [$node, $user, $group, $root] = $this->memberContext($member);
            $this->ssh->execute(
                $node,
                new RemoteCommand(
                    arguments: [
                        'bash',
                        '-seu',
                        '--',
                        $root->value,
                        $member->app_instance_removal_id,
                        (string) $member->id,
                        $member->source_digest,
                        $user,
                        $group,
                        (string) $member->checkout_path,
                        (string) $member->common_repository_path,
                        $member->source_layout,
                    ],
                    input: self::preparationScript(),
                ),
                step: 'app-instance-removal-prepare',
                errorCode: 'instance.remove_refused',
            );
        });
    }

    public function revalidate(
        AppInstanceRemovalMember $member,
        ?AppInstanceSourceRevalidationExpectation $expectation = null,
    ): AppInstanceSourceRevalidationState {
        return $this->lock->synchronized($member->node_id, function () use (
            $member,
            $expectation,
        ): AppInstanceSourceRevalidationState {
            $state = $member->source_prepared_at === null
                ? AppInstanceSourceRevalidationState::Present
                : $this->revalidationStateLocked($member);
            $receiptStructure = $state === AppInstanceSourceRevalidationState::ReceiptPendingCleanup
                ? $this->receiptStructureStateLocked($member)
                : null;

            if (
                in_array(
                    $state,
                    [AppInstanceSourceRevalidationState::Present, AppInstanceSourceRevalidationState::Quarantined],
                    true,
                )
                || $state === AppInstanceSourceRevalidationState::ReceiptPendingCleanup
                && $receiptStructure === 'intact'
            ) {
                $states = $expectation->authenticatedMemberStates ?? [];
                $states[$member->id] = $state;
                $authenticatedExpectation = $expectation instanceof AppInstanceSourceRevalidationExpectation
                    ? new AppInstanceSourceRevalidationExpectation(
                        $expectation->requiredLinkedWorktreePaths,
                        $expectation->permittedLinkedWorktreePaths,
                        $states,
                    )
                    : null;
                $this->inspectRecordedLocked(
                    $member,
                    $state,
                    $authenticatedExpectation,
                    allowDependentWorktrees: true,
                );
            }

            return $state;
        });
    }

    public function inspectRecorded(
        AppInstanceRemovalMember $member,
        AppInstanceSourceRevalidationState $state,
        ?AppInstanceSourceRevalidationExpectation $expectation = null,
    ): AppInstanceSourceInventory {
        return $this->lock->synchronized(
            $member->node_id,
            fn (): AppInstanceSourceInventory => $this->inspectRecordedLocked($member, $state, $expectation),
        );
    }

    public function finalize(
        AppInstanceRemovalMember $member,
        ?AppInstanceSourceRevalidationExpectation $expectation = null,
    ): string {
        return $this->lock->synchronized($member->node_id, function () use ($member, $expectation): string {
            $state = $this->revalidationStateLocked($member);
            $receipt = $this->receipt($member);

            if ($state === AppInstanceSourceRevalidationState::Completed) {
                return $receipt;
            }

            if ($state === AppInstanceSourceRevalidationState::ReceiptPendingCleanup) {
                $receiptStructure = $this->receiptStructureStateLocked($member);

                if ($receiptStructure === 'incomplete') {
                    return $this->cleanupReceiptLocked($member, $receipt);
                }
            }

            $inventory = $this->inspectRecordedLocked($member, $state, $expectation);
            [$node, $user, $group, $root, $groupingDirectory] = $this->memberContext($member);
            $removal = $member->removal()->firstOrFail();
            $script = GitReadScript::for(
                $this->access->for($inventory->origin),
                self::releaseEmptyGroupingDirectoryFunction().self::finalizationScript(),
            );
            $result = $this->ssh->execute(
                $node,
                new RemoteCommand(
                    arguments: [
                        'bash',
                        '-seu',
                        '--',
                        (string) $member->checkout_path,
                        $root->value,
                        (string) $member->common_repository_path,
                        $member->source_layout,
                        $member->branch ?? '',
                        $member->app_instance_removal_id,
                        (string) $member->id,
                        $member->source_digest,
                        $receipt,
                        $removal->force ? '1' : '0',
                        $user,
                        $group,
                        (string) $member->source_identity,
                        $inventory->origin,
                        base64_encode($inventory->worktreeInventory),
                        (string) $member->source_commit,
                        $groupingDirectory,
                    ],
                    input: $script->input,
                    protectedInput: $script->protectedInput,
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

    private function inspectRecordedLocked(
        AppInstanceRemovalMember $member,
        AppInstanceSourceRevalidationState $state,
        ?AppInstanceSourceRevalidationExpectation $expectation = null,
        bool $allowDependentWorktrees = false,
    ): AppInstanceSourceInventory {
        if ($state === AppInstanceSourceRevalidationState::Completed) {
            $this->recordedConflict($member, 'A completed source has no inspectable source inventory.');
        }

        $this->assertDevelopmentMember($member);
        $appInstance = AppInstance::query()->with(['app', 'node'])->find($member->app_instance_id);

        if (! $appInstance instanceof AppInstance) {
            $this->recordedConflict($member, 'The recorded AppInstance removal member is unavailable.');
        }

        $this->assertRecordedOwnership($member, $appInstance);
        $context = $this->context($appInstance);
        $logicalCheckout = (string) $member->checkout_path;
        $physicalCheckout = $state === AppInstanceSourceRevalidationState::Present
            ? $logicalCheckout
            : $this->quarantinePath($member, $context['root']);
        $removal = $member->removal()->firstOrFail();
        $expectation ??= $this->exactExpectation($member);
        $authenticatedStates = $expectation->authenticatedMemberStates;
        $authenticatedStates[$member->id] = $state;
        $expectation = new AppInstanceSourceRevalidationExpectation(
            $expectation->requiredLinkedWorktreePaths,
            $expectation->permittedLinkedWorktreePaths,
            $authenticatedStates,
        );
        $inventory = $this->inspectPathLocked(
            appInstance: $appInstance,
            physicalCheckout: $physicalCheckout,
            logicalCheckout: $logicalCheckout,
            root: $context['root'],
            user: $context['user'],
            group: $context['group'],
            layout: $member->source_layout,
            expectedBranch: $member->branch,
            expectedRepositoryIdentity: (string) $member->repository_identity,
            force: (bool) $removal->force,
            quarantineMappings: $this->quarantineMappings($member, $context['root'], $expectation),
        );

        if (
            $member->common_repository_path !== $inventory->commonRepositoryPath
            || $member->source_identity !== $inventory->sourceIdentity
            || $member->source_commit !== $inventory->startingCommit
            || $member->source_digest !== $this->originalDigest($member, $inventory)
        ) {
            $this->recordedConflict(
                $member,
                "AppInstance [{$member->name}] source identity changed after removal acceptance.",
            );
        }

        $this->assertExpectedLinkedInventory($member, $inventory, $expectation);

        if (
            ! $allowDependentWorktrees
            && $member->source_layout === AppInstanceSourceLayout::Checkout->value
            && $inventory->linkedWorktreePaths !== [$logicalCheckout]
        ) {
            $this->recordedConflict(
                $member,
                "AppInstance [{$member->name}] checkout has linked worktrees.",
            );
        }

        return $inventory;
    }

    private function exactExpectation(
        AppInstanceRemovalMember $member,
    ): AppInstanceSourceRevalidationExpectation {
        $paths = $member->linked_worktree_paths;
        sort($paths, SORT_STRING);

        return new AppInstanceSourceRevalidationExpectation($paths, $paths);
    }

    private function originalDigest(
        AppInstanceRemovalMember $member,
        AppInstanceSourceInventory $inventory,
    ): string {
        return hash('sha256', json_encode([
            'app_instance_id' => $inventory->appInstanceId,
            'layout' => $inventory->layout,
            'repository_identity' => $inventory->repositoryIdentity,
            'checkout_path' => $inventory->checkoutPath,
            'root' => $inventory->root,
            'branch' => $inventory->branch,
            'starting_commit' => $inventory->startingCommit,
            'common_repository_path' => $inventory->commonRepositoryPath,
            'source_identity' => $inventory->sourceIdentity,
            'linked_worktree_paths' => $member->linked_worktree_paths,
        ], JSON_THROW_ON_ERROR));
    }

    private function assertExpectedLinkedInventory(
        AppInstanceRemovalMember $member,
        AppInstanceSourceInventory $inventory,
        AppInstanceSourceRevalidationExpectation $expectation,
    ): void {
        $required = array_values(array_unique($expectation->requiredLinkedWorktreePaths));
        $permitted = array_values(array_unique($expectation->permittedLinkedWorktreePaths));
        $live = $inventory->linkedWorktreePaths;
        sort($required, SORT_STRING);
        sort($permitted, SORT_STRING);
        sort($live, SORT_STRING);

        if (
            array_diff($required, $live) !== []
            || array_diff($live, $permitted) !== []
        ) {
            $this->recordedConflict(
                $member,
                'The linked-worktree inventory changed after removal acceptance.',
            );
        }
    }

    private function cleanupReceiptLocked(AppInstanceRemovalMember $member, string $receipt): string
    {
        [$node, $user, $group, $root, $groupingDirectory] = $this->memberContext($member);
        $result = $this->ssh->execute(
            $node,
            new RemoteCommand(
                arguments: [
                    'bash',
                    '-seu',
                    '--',
                    (string) $member->checkout_path,
                    $root->value,
                    (string) $member->common_repository_path,
                    $member->source_layout,
                    $member->app_instance_removal_id,
                    (string) $member->id,
                    $member->source_digest,
                    $receipt,
                    $user,
                    $group,
                    (string) $member->source_identity,
                    $groupingDirectory,
                ],
                input: self::releaseEmptyGroupingDirectoryFunction().self::receiptCleanupScript(),
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
    }

    private function receiptStructureStateLocked(AppInstanceRemovalMember $member): string
    {
        [$node, $user, $group, $root] = $this->memberContext($member);
        $result = $this->ssh->execute(
            $node,
            new RemoteCommand(
                arguments: [
                    'bash',
                    '-seu',
                    '--',
                    (string) $member->checkout_path,
                    $root->value,
                    (string) $member->common_repository_path,
                    $member->source_layout,
                    $member->app_instance_removal_id,
                    (string) $member->id,
                    $member->source_digest,
                    $this->receipt($member),
                    $user,
                    $group,
                    (string) $member->source_identity,
                ],
                input: self::receiptStructureScript(),
            ),
            step: 'app-instance-removal-revalidation',
            errorCode: 'instance.removal_conflict',
        );

        return match (trim($result->stdout)) {
            'complete', 'incomplete', 'intact' => trim($result->stdout),
            default => $this->recordedConflict(
                $member,
                'AppInstance removal returned invalid receipt-recovery evidence.',
            ),
        };
    }

    private function revalidationStateLocked(
        AppInstanceRemovalMember $member,
    ): AppInstanceSourceRevalidationState {
        $this->assertDevelopmentMember($member);
        [$node, $user, $group, $root] = $this->memberContext($member);
        $result = $this->ssh->execute(
            $node,
            new RemoteCommand(
                arguments: [
                    'bash',
                    '-seu',
                    '--',
                    (string) $member->checkout_path,
                    $root->value,
                    $member->app_instance_removal_id,
                    (string) $member->id,
                    $member->source_digest,
                    $this->receipt($member),
                    (string) $member->source_identity,
                    $user,
                    $group,
                ],
                input: self::revalidationScript(),
            ),
            step: 'app-instance-removal-revalidation',
            errorCode: 'instance.removal_conflict',
        );
        $state = AppInstanceSourceRevalidationState::tryFrom(trim($result->stdout));

        if (! $state instanceof AppInstanceSourceRevalidationState) {
            $this->recordedConflict($member, 'AppInstance removal returned invalid source-presence evidence.');
        }

        if (
            $state === AppInstanceSourceRevalidationState::Completed
            && $member->source_layout === AppInstanceSourceLayout::Worktree->value
        ) {
            return match ($this->receiptStructureStateLocked($member)) {
                'complete' => AppInstanceSourceRevalidationState::Completed,
                'incomplete' => AppInstanceSourceRevalidationState::ReceiptPendingCleanup,
                default => $this->recordedConflict(
                    $member,
                    'Completed worktree evidence still has an inspectable source.',
                ),
            };
        }

        return $state;
    }

    /** @return array{0: Node, 1: string, 2: string, 3: StoragePath, 4: string} */
    private function memberContext(AppInstanceRemovalMember $member): array
    {
        $appInstance = AppInstance::query()->with(['app', 'node'])->find($member->app_instance_id);

        if (! $appInstance instanceof AppInstance) {
            $appInstance = $this->deletedMemberContext($member);
        }

        $this->assertRecordedOwnership($member, $appInstance);

        $context = $this->context($appInstance);

        return [
            $appInstance->node,
            $context['user'],
            $context['group'],
            $context['root'],
            $this->boundaries->appInstanceGroupingDirectory($appInstance, $context['root'])->value,
        ];
    }

    private function assertRecordedOwnership(
        AppInstanceRemovalMember $member,
        AppInstance $appInstance,
    ): void {
        if (
            $member->app_id !== $appInstance->app_id
            || $member->node_id !== $appInstance->node_id
            || $member->name !== $appInstance->name
            || $member->environment !== $appInstance->environment
            || $member->source_layout !== $appInstance->source_layout
            || $member->checkout_path !== $appInstance->checkout_path
            || $member->root !== $appInstance->effectiveRoot()
            || $member->branch !== $appInstance->branch
            || $member->starting_commit !== $appInstance->starting_commit
            || $member->repository_identity !== $appInstance->app->repository_identity
        ) {
            $this->recordedConflict($member, 'The recorded AppInstance removal ownership changed.');
        }
    }

    private function deletedMemberContext(AppInstanceRemovalMember $member): AppInstance
    {
        $app = OrbitApp::query()->find($member->app_id);
        $node = Node::query()->find($member->node_id);

        if (
            $member->row_deleted_at === null
            || ! $app instanceof OrbitApp
            || ! $node instanceof Node
        ) {
            $this->recordedConflict($member, 'The recorded AppInstance removal member is unavailable.');
        }

        $appInstance = new AppInstance;
        $appInstance->forceFill([
            'app_id' => $member->app_id,
            'node_id' => $member->node_id,
            'name' => $member->name,
            'environment' => $member->environment,
            'source_layout' => $member->source_layout,
            'checkout_path' => $member->checkout_path,
            'root' => $member->root,
            'branch' => $member->branch,
            'starting_commit' => $member->starting_commit,
        ]);
        $appInstance->setAttribute('id', $member->app_instance_id);
        $appInstance->setRelation('app', $app);
        $appInstance->setRelation('node', $node);

        return $appInstance;
    }

    private function assertDevelopmentMember(AppInstanceRemovalMember $member): void
    {
        if (
            $member->environment !== 'development'
            || ! in_array(
                $member->source_layout,
                [AppInstanceSourceLayout::Checkout->value, AppInstanceSourceLayout::Worktree->value],
                true,
            )
            || ! is_string($member->checkout_path)
            || ! is_string($member->root)
            || $member->getAttribute('branch') !== null
            && ! is_string($member->getAttribute('branch'))
            || ! is_string($member->starting_commit)
            || ! is_string($member->source_commit)
            || ! is_string($member->common_repository_path)
            || ! is_string($member->source_identity)
            || ! is_string($member->repository_identity)
            || ! Str::isUuid($member->app_instance_removal_id)
            || $member->id < 1
            || preg_match('/\A[0-9a-f]{64}\z/D', $member->source_digest) !== 1
            || preg_match('/\A[0-9a-f]{40}(?:[0-9a-f]{24})?\z/D', $member->source_commit) !== 1
        ) {
            $this->recordedConflict($member, 'The recorded AppInstance removal source evidence is incomplete.');
        }
    }

    private function recordedConflict(AppInstanceRemovalMember $member, string $message): never
    {
        throw new RuntimeConvergenceException(
            step: 'app-instance-removal-revalidation',
            errorCode: 'instance.removal_conflict',
            message: $message,
        );
    }

    private function receipt(AppInstanceRemovalMember $member): string
    {
        return hash(
            'sha256',
            "{$member->app_instance_removal_id}\0{$member->id}\0{$member->source_digest}\0finalized",
        );
    }

    /** @return array<string, string> */
    private function quarantineMappings(
        AppInstanceRemovalMember $member,
        StoragePath $root,
        ?AppInstanceSourceRevalidationExpectation $expectation = null,
    ): array {
        if (! $expectation instanceof AppInstanceSourceRevalidationExpectation) {
            return [$this->quarantinePath($member, $root) => (string) $member->checkout_path];
        }

        $mappings = [];
        $members = $member->removal->members()->orderBy('position')->get();

        foreach ($members as $recordedMember) {
            $state = $expectation->authenticatedMemberStates[$recordedMember->id] ?? null;

            if (
                $recordedMember->common_repository_path !== $member->common_repository_path
                || ! in_array(
                    $state,
                    [
                        AppInstanceSourceRevalidationState::Quarantined,
                        AppInstanceSourceRevalidationState::ReceiptPendingCleanup,
                    ],
                    true,
                )
            ) {
                continue;
            }

            $mappings[$this->quarantinePath($recordedMember, $root)] = (string) $recordedMember->checkout_path;
        }

        return $mappings;
    }

    private function quarantinePath(AppInstanceRemovalMember $member, StoragePath $root): string
    {
        return sprintf(
            '%s/.orbit-removals/%s.%d.quarantine',
            $root->value,
            $member->app_instance_removal_id,
            $member->id,
        );
    }

    /** @return array{root: StoragePath, user: string, group: string, branch: ?string, repositoryIdentity: string} */
    private function context(AppInstance $appInstance): array
    {
        $appInstance->loadMissing(['app', 'node']);

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

        $branch = $appInstance->getAttribute('branch');

        if ($branch !== null && ! is_string($branch) || ! is_string($appInstance->starting_commit)) {
            $this->invalidEvidence($appInstance, false);
        }

        $repositoryIdentity = $appInstance->app->getAttribute('repository_identity');

        if (! is_string($repositoryIdentity) || $repositoryIdentity === '') {
            $this->invalidEvidence($appInstance, false);
        }

        $account = $this->accounts->resolve($appInstance->node);

        return [
            'root' => $this->boundaries->appInstanceRoot($appInstance, $account),
            'user' => $account->user,
            'group' => $account->group,
            'branch' => $branch,
            'repositoryIdentity' => $repositoryIdentity,
        ];
    }

    /**
     * @param  array<string, string>  $quarantineMappings
     * @return list<string>
     */
    private function worktreePaths(
        string $inventory,
        AppInstance $appInstance,
        string $expectedCheckout,
        bool $force,
        array $quarantineMappings = [],
    ): array {
        $paths = [];

        foreach (self::liveWorktrees($inventory) as $value) {
            if (isset($quarantineMappings[$value])) {
                $value = $quarantineMappings[$value];
            }

            $path = StoragePath::tryParse($value);

            if (! $path instanceof StoragePath) {
                $this->invalidEvidence($appInstance, $force);
            }

            $paths[] = $path->value;
        }

        $paths = array_values(array_unique($paths));
        sort($paths, SORT_STRING);

        if (! in_array($expectedCheckout, $paths, true)) {
            $this->mismatch($appInstance, AppInstanceSourceMismatch::Worktrees);
        }

        return $paths;
    }

    /**
     * The worktree paths of a `git worktree list --porcelain -z` listing, without the ones Git
     * marks prunable because their directory is gone, such as a test worktree under a cleared
     * `/tmp`. Git drops those records on its next prune, and removing the checkout removes them.
     *
     * @return list<string>
     */
    private static function liveWorktrees(string $inventory): array
    {
        /** @var list<array{path: string, prunable: bool}> $records */
        $records = [];

        foreach (explode("\0", $inventory) as $field) {
            if (str_starts_with($field, 'worktree ')) {
                $records[] = ['path' => substr($field, 9), 'prunable' => false];
            } elseif ($records !== [] && ($field === 'prunable' || str_starts_with($field, 'prunable '))) {
                $records[array_key_last($records)]['prunable'] = true;
            }
        }

        return array_values(array_map(
            static fn (array $record): string => $record['path'],
            array_filter($records, static fn (array $record): bool => ! $record['prunable']),
        ));
    }

    private function isPublished(AppInstance $appInstance, string $origin, string $commit): bool
    {
        $script = GitReadScript::for($this->access->for($origin), self::publicationScript());
        $result = $this->ssh->execute(
            $appInstance->node,
            new RemoteCommand(
                arguments: ['bash', '-seu', '--', $origin, $commit],
                input: $script->input,
                protectedInput: $script->protectedInput,
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

    /**
     * Run a guest script that refuses with a named exit status, and translate that status into its error code.
     */
    private function executeRefusable(
        AppInstance $appInstance,
        RemoteCommand $command,
        string $step,
        bool $force,
    ): CommandResult {
        try {
            return $this->ssh->execute(
                $appInstance->node,
                $command,
                step: $step,
                errorCode: $this->failureCode($force),
            );
        } catch (RuntimeConvergenceException $exception) {
            $this->refuse($appInstance, $force, $exception);
        }
    }

    private function refuse(AppInstance $appInstance, bool $force, RuntimeConvergenceException $exception): never
    {
        if (
            $exception->errorCode !== $this->failureCode($force)
            || ! $exception->result instanceof CommandResult
        ) {
            throw $exception;
        }

        $status = $exception->result->exitCode;
        $mismatch = self::ScriptMismatches[$status] ?? null;

        if ($mismatch instanceof AppInstanceSourceMismatch) {
            $this->mismatch($appInstance, $mismatch, $exception->step);
        }

        match ($status) {
            self::UnsafeContentStatus => $this->unsafeContent($appInstance, $exception->step),
            self::ChangedSourceStatus => throw new RuntimeConvergenceException(
                step: $exception->step,
                errorCode: 'instance.removal_conflict',
                message: "AppInstance [{$appInstance->name}] source changed after inspection.",
            ),
            default => throw $exception,
        };
    }

    private function mismatch(
        AppInstance $appInstance,
        AppInstanceSourceMismatch $mismatch,
        string $step = 'app-instance-source-removal-inspect',
    ): never {
        throw new RuntimeConvergenceException(
            step: $step,
            errorCode: $mismatch->value,
            message: $mismatch->describe($appInstance->name),
        );
    }

    private function unsafeContent(
        AppInstance $appInstance,
        string $step = 'app-instance-source-removal-inspect',
    ): never {
        throw new RuntimeConvergenceException(
            step: $step,
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

    private static function preparationScript(): string
    {
        return <<<'BASH'
            root=$1
            operation=$2
            member=$3
            digest=$4
            managed_user=$5
            managed_group=$6
            checkout=$7
            common_repository=$8
            layout=$9
            state="$root/.orbit-removals"
            journal="$state/$operation.$member.journal"
            receipt="$state/$operation.$member.receipt"
            quarantine="$state/$operation.$member.quarantine"
            recovery="$state/$operation.$member.recovery"
            test -d "$root"
            test ! -L "$root"
            test "$(realpath -e "$root")" = "$root"
            test "$(stat -c '%U:%G' "$root")" = "$managed_user:$managed_group"
            if [ -e "$state" ] || [ -L "$state" ]; then
                test -d "$state"
                test ! -L "$state"
                test "$(realpath -e "$state")" = "$state"
            else
                install -d -m 0700 -- "$state"
            fi
            test "$(stat -c '%U:%G' "$state")" = "$managed_user:$managed_group"
            test ! -e "$receipt"
            test ! -L "$receipt"
            test ! -e "$quarantine"
            test ! -L "$quarantine"
            test ! -e "$recovery"
            test ! -L "$recovery"
            case "$layout" in
                checkout) ;;
                worktree)
                    test -f "$checkout/.git"
                    test ! -L "$checkout/.git"
                    git_dir=$(git -C "$checkout" rev-parse --absolute-git-dir)
                    test "$(dirname "$git_dir")" = "$common_repository/.git/worktrees"
                    test -d "$git_dir"
                    test ! -L "$git_dir"
                    ;;
                *) exit 1 ;;
            esac
            if [ -e "$journal" ] || [ -L "$journal" ]; then
                test -f "$journal"
                test ! -L "$journal"
                printf '%s\n' "$digest" | cmp -s - "$journal"
                exit 0
            fi
            candidate=$(mktemp "$state/.$operation.$member.journal.XXXXXX")
            trap 'rm -f -- "$candidate"' EXIT
            printf '%s\n' "$digest" > "$candidate"
            chmod 0600 -- "$candidate"
            mv -T -- "$candidate" "$journal"
            trap - EXIT
            BASH;
    }

    private static function revalidationScript(): string
    {
        return <<<'BASH'
            checkout=$1
            root=$2
            operation=$3
            member=$4
            digest=$5
            receipt=$6
            source_identity=$7
            managed_user=$8
            managed_group=$9
            state="$root/.orbit-removals"
            journal="$state/$operation.$member.journal"
            receipt_path="$state/$operation.$member.receipt"
            quarantine="$state/$operation.$member.quarantine"
            recovery="$state/$operation.$member.recovery"
            test -d "$state"
            test ! -L "$state"
            test "$(realpath -e "$state")" = "$state"
            test "$(stat -c '%U:%G' "$state")" = "$managed_user:$managed_group"
            test -f "$journal"
            test ! -L "$journal"
            test "$(stat -c '%U:%G' "$journal")" = "$managed_user:$managed_group"
            printf '%s\n' "$digest" | cmp -s - "$journal"
            if [ -e "$checkout" ] || [ -L "$checkout" ]; then
                test ! -e "$quarantine"
                test ! -L "$quarantine"
                test ! -e "$receipt_path"
                test ! -L "$receipt_path"
                test ! -e "$recovery"
                test ! -L "$recovery"
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
                test "$(stat -c '%U:%G' "$quarantine")" = "$managed_user:$managed_group"
                if [ -e "$receipt_path" ] || [ -L "$receipt_path" ]; then
                    test -f "$receipt_path"
                    test ! -L "$receipt_path"
                    printf '%s\n' "$receipt" | cmp -s - "$receipt_path"
                    printf 'receipt-pending-cleanup\n'
                    exit 0
                fi
                printf 'quarantined\n'
                exit 0
            fi
            test -f "$receipt_path"
            test ! -L "$receipt_path"
            printf '%s\n' "$receipt" | cmp -s - "$receipt_path"
            printf 'completed\n'
            BASH;
    }

    private static function receiptStructureScript(): string
    {
        return <<<'BASH'
            checkout=$1
            root=$2
            common_repository=$3
            layout=$4
            operation=$5
            member=$6
            digest=$7
            receipt=$8
            managed_user=$9
            shift 9
            managed_group=$1
            source_identity=$2
            state="$root/.orbit-removals"
            journal="$state/$operation.$member.journal"
            receipt_path="$state/$operation.$member.receipt"
            quarantine="$state/$operation.$member.quarantine"
            recovery="$state/$operation.$member.recovery"
            test -d "$state"
            test ! -L "$state"
            test "$(realpath -e "$state")" = "$state"
            test "$(stat -c '%U:%G' "$state")" = "$managed_user:$managed_group"
            test -f "$journal"
            test ! -L "$journal"
            test "$(stat -c '%U:%G' "$journal")" = "$managed_user:$managed_group"
            printf '%s\n' "$digest" | cmp -s - "$journal"
            test -f "$receipt_path"
            test ! -L "$receipt_path"
            test "$(stat -c '%U:%G' "$receipt_path")" = "$managed_user:$managed_group"
            printf '%s\n' "$receipt" | cmp -s - "$receipt_path"
            test ! -e "$checkout"
            test ! -L "$checkout"
            quarantine_present=0
            if [ -e "$quarantine" ] || [ -L "$quarantine" ]; then
                test -d "$quarantine"
                test ! -L "$quarantine"
                test "$(realpath -e "$quarantine")" = "$quarantine"
                test "$(stat -c '%d:%i' "$quarantine")" = "$source_identity"
                test "$(stat -c '%U:%G' "$quarantine")" = "$managed_user:$managed_group"
                quarantine_present=1
            fi
            case "$layout" in
                checkout)
                    test "$quarantine_present" = 1
                    test ! -e "$recovery"
                    test ! -L "$recovery"
                    if [ -e "$quarantine/.git" ] || [ -L "$quarantine/.git" ]; then
                        printf 'intact\n'
                    else
                        printf 'incomplete\n'
                    fi
                    ;;
                worktree)
                    test -f "$recovery"
                    test ! -L "$recovery"
                    test "$(stat -c '%U:%G' "$recovery")" = "$managed_user:$managed_group"
                    mapfile -t recovery_fields < "$recovery"
                    test "${#recovery_fields[@]}" = 4
                    admin_encoded=${recovery_fields[0]}
                    admin_identity=${recovery_fields[1]}
                    common_identity=${recovery_fields[2]}
                    worktrees_identity=${recovery_fields[3]}
                    admin=$(printf '%s' "$admin_encoded" | base64 --decode)
                    test "$(printf '%s' "$admin" | base64 --wrap=0)" = "$admin_encoded"
                    [[ "$admin_identity" =~ ^[0-9]+:[0-9]+$ ]]
                    [[ "$common_identity" =~ ^[0-9]+:[0-9]+$ ]]
                    [[ "$worktrees_identity" =~ ^[0-9]+:[0-9]+$ ]]
                    test "$(dirname "$admin")" = "$common_repository/.git/worktrees"
                    worktrees="$common_repository/.git/worktrees"
                    common_present=0
                    if [ -e "$common_repository/.git" ] || [ -L "$common_repository/.git" ]; then
                        test -d "$common_repository/.git"
                        test ! -L "$common_repository/.git"
                        test "$(stat -c '%d:%i' "$common_repository/.git")" = "$common_identity"
                        test "$(stat -c '%U:%G' "$common_repository/.git")" = "$managed_user:$managed_group"
                        common_present=1
                    else
                        test ! -e "$common_repository"
                        test ! -L "$common_repository"
                    fi
                    worktrees_present=0
                    if [ -e "$worktrees" ] || [ -L "$worktrees" ]; then
                        test "$common_present" = 1
                        test -d "$worktrees"
                        test ! -L "$worktrees"
                        test "$(stat -c '%d:%i' "$worktrees")" = "$worktrees_identity"
                        test "$(stat -c '%U:%G' "$worktrees")" = "$managed_user:$managed_group"
                        worktrees_present=1
                    fi
                    matching=0
                    if [ "$worktrees_present" = 1 ]; then
                        for candidate in "$worktrees"/*; do
                            if [ ! -e "$candidate" ] && [ ! -L "$candidate" ]; then
                                continue
                            fi
                            if [ -f "$candidate/gitdir" ] && [ ! -L "$candidate/gitdir" ] && \
                                { printf '%s\n' "$checkout/.git" | cmp -s - "$candidate/gitdir" || \
                                    printf '%s\n' "$quarantine/.git" | cmp -s - "$candidate/gitdir"; }; then
                                test "$candidate" = "$admin"
                                matching=$((matching + 1))
                            fi
                        done
                    fi
                    test "$matching" -le 1
                    admin_present=0
                    admin_complete=0
                    if [ -e "$admin" ] || [ -L "$admin" ]; then
                        test -d "$admin"
                        test ! -L "$admin"
                        test "$(stat -c '%d:%i' "$admin")" = "$admin_identity"
                        test "$(stat -c '%U:%G' "$admin")" = "$managed_user:$managed_group"
                        if [ -e "$admin/gitdir" ] || [ -L "$admin/gitdir" ]; then
                            test -f "$admin/gitdir"
                            test ! -L "$admin/gitdir"
                            printf '%s\n' "$quarantine/.git" | cmp -s - "$admin/gitdir"
                            test "$matching" = 1
                            admin_complete=1
                        else
                            test "$matching" = 0
                        fi
                        admin_present=1
                    else
                        test "$matching" = 0
                    fi
                    git_file_present=0
                    if [ -e "$quarantine/.git" ] || [ -L "$quarantine/.git" ]; then
                        test -f "$quarantine/.git"
                        test ! -L "$quarantine/.git"
                        printf 'gitdir: %s\n' "$admin" | cmp -s - "$quarantine/.git"
                        git_file_present=1
                    fi
                    if [ "$quarantine_present" = 0 ]; then
                        if [ "$admin_present" = 0 ]; then
                            printf 'complete\n'
                        else
                            printf 'incomplete\n'
                        fi
                    elif [ "$admin_complete" = 1 ] && [ "$git_file_present" = 1 ]; then
                        printf 'intact\n'
                    else
                        printf 'incomplete\n'
                    fi
                    ;;
                *) exit 1 ;;
            esac
            BASH;
    }

    private static function receiptCleanupScript(): string
    {
        return <<<'BASH'
            checkout=$1
            root=$2
            common_repository=$3
            layout=$4
            operation=$5
            member=$6
            digest=$7
            receipt=$8
            managed_user=$9
            shift 9
            managed_group=$1
            source_identity=$2
            grouping_directory=$3
            state="$root/.orbit-removals"
            journal="$state/$operation.$member.journal"
            receipt_path="$state/$operation.$member.receipt"
            quarantine="$state/$operation.$member.quarantine"
            recovery="$state/$operation.$member.recovery"
            test -d "$state"
            test ! -L "$state"
            test "$(realpath -e "$state")" = "$state"
            test "$(stat -c '%U:%G' "$state")" = "$managed_user:$managed_group"
            test -f "$journal"
            test ! -L "$journal"
            test "$(stat -c '%U:%G' "$journal")" = "$managed_user:$managed_group"
            printf '%s\n' "$digest" | cmp -s - "$journal"
            test -f "$receipt_path"
            test ! -L "$receipt_path"
            test "$(stat -c '%U:%G' "$receipt_path")" = "$managed_user:$managed_group"
            printf '%s\n' "$receipt" | cmp -s - "$receipt_path"
            test ! -e "$checkout"
            test ! -L "$checkout"
            quarantine_present=0
            if [ -e "$quarantine" ] || [ -L "$quarantine" ]; then
                test -d "$quarantine"
                test ! -L "$quarantine"
                test "$(realpath -e "$quarantine")" = "$quarantine"
                test "$(stat -c '%d:%i' "$quarantine")" = "$source_identity"
                test "$(stat -c '%U:%G' "$quarantine")" = "$managed_user:$managed_group"
                quarantine_present=1
            fi
            case "$layout" in
                checkout)
                    test "$quarantine_present" = 1
                    test ! -e "$recovery"
                    test ! -L "$recovery"
                    test ! -e "$quarantine/.git"
                    test ! -L "$quarantine/.git"
                    test "$(stat -c '%d:%i' "$quarantine")" = "$source_identity"
                    test "$(stat -c '%U:%G' "$quarantine")" = "$managed_user:$managed_group"
                    rm -rf -- "$quarantine"
                    ;;
                worktree)
                    test -f "$recovery"
                    test ! -L "$recovery"
                    test "$(stat -c '%U:%G' "$recovery")" = "$managed_user:$managed_group"
                    mapfile -t recovery_fields < "$recovery"
                    test "${#recovery_fields[@]}" = 4
                    admin_encoded=${recovery_fields[0]}
                    admin_identity=${recovery_fields[1]}
                    common_identity=${recovery_fields[2]}
                    worktrees_identity=${recovery_fields[3]}
                    admin=$(printf '%s' "$admin_encoded" | base64 --decode)
                    test "$(printf '%s' "$admin" | base64 --wrap=0)" = "$admin_encoded"
                    [[ "$admin_identity" =~ ^[0-9]+:[0-9]+$ ]]
                    [[ "$common_identity" =~ ^[0-9]+:[0-9]+$ ]]
                    [[ "$worktrees_identity" =~ ^[0-9]+:[0-9]+$ ]]
                    test "$(dirname "$admin")" = "$common_repository/.git/worktrees"
                    test -d "$common_repository/.git"
                    test ! -L "$common_repository/.git"
                    test "$(stat -c '%d:%i' "$common_repository/.git")" = "$common_identity"
                    test "$(stat -c '%U:%G' "$common_repository/.git")" = "$managed_user:$managed_group"
                    worktrees="$common_repository/.git/worktrees"
                    test -d "$worktrees"
                    test ! -L "$worktrees"
                    test "$(stat -c '%d:%i' "$worktrees")" = "$worktrees_identity"
                    test "$(stat -c '%U:%G' "$worktrees")" = "$managed_user:$managed_group"
                    matching=0
                    for candidate in "$worktrees"/*; do
                        if [ ! -e "$candidate" ] && [ ! -L "$candidate" ]; then
                            continue
                        fi
                        if [ -f "$candidate/gitdir" ] && [ ! -L "$candidate/gitdir" ] && \
                            { printf '%s\n' "$checkout/.git" | cmp -s - "$candidate/gitdir" || \
                                printf '%s\n' "$quarantine/.git" | cmp -s - "$candidate/gitdir"; }; then
                            test "$candidate" = "$admin"
                            matching=$((matching + 1))
                        fi
                    done
                    test "$matching" -le 1
                    if [ -e "$admin" ] || [ -L "$admin" ]; then
                        test -d "$admin"
                        test ! -L "$admin"
                        test "$(stat -c '%d:%i' "$admin")" = "$admin_identity"
                        test "$(stat -c '%U:%G' "$admin")" = "$managed_user:$managed_group"
                        if [ -e "$admin/gitdir" ] || [ -L "$admin/gitdir" ]; then
                            test -f "$admin/gitdir"
                            test ! -L "$admin/gitdir"
                            printf '%s\n' "$quarantine/.git" | cmp -s - "$admin/gitdir"
                            test "$matching" = 1
                        else
                            test "$matching" = 0
                        fi
                        if [ -e "$quarantine/.git" ] || [ -L "$quarantine/.git" ]; then
                            test -f "$quarantine/.git"
                            test ! -L "$quarantine/.git"
                            printf 'gitdir: %s\n' "$admin" | cmp -s - "$quarantine/.git"
                        fi
                        test "$(stat -c '%d:%i' "$admin")" = "$admin_identity"
                        test "$(stat -c '%U:%G' "$admin")" = "$managed_user:$managed_group"
                        rm -rf -- "$admin"
                    else
                        test "$matching" = 0
                    fi
                    if [ "$quarantine_present" = 1 ]; then
                        test "$(stat -c '%d:%i' "$quarantine")" = "$source_identity"
                        test "$(stat -c '%U:%G' "$quarantine")" = "$managed_user:$managed_group"
                        rm -rf -- "$quarantine"
                    fi
                    ;;
                *) exit 1 ;;
            esac
            test ! -e "$quarantine"
            test ! -L "$quarantine"
            release_empty_grouping_directory "$grouping_directory"
            printf '%s\n' "$receipt"
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
            operation=$6
            member=$7
            digest=$8
            shift 8
            receipt=$1
            force=$2
            managed_user=$3
            managed_group=$4
            source_identity=$5
            expected_origin=$6
            expected_worktrees=$7
            source_commit=$8
            grouping_directory=$9
            export GIT_OPTIONAL_LOCKS=0
            state="$root/.orbit-removals"
            journal="$state/$operation.$member.journal"
            receipt_path="$state/$operation.$member.receipt"
            quarantine="$state/$operation.$member.quarantine"
            recovery="$state/$operation.$member.recovery"
            test -d "$state"
            test ! -L "$state"
            test "$(realpath -e "$state")" = "$state"
            test "$(stat -c '%U:%G' "$state")" = "$managed_user:$managed_group"
            test -f "$journal"
            test ! -L "$journal"
            test "$(stat -c '%U:%G' "$journal")" = "$managed_user:$managed_group"
            printf '%s\n' "$digest" | cmp -s - "$journal"
            if [ ! -e "$checkout" ] && [ ! -L "$checkout" ] && \
                [ ! -e "$quarantine" ] && [ ! -L "$quarantine" ]; then
                test -f "$receipt_path"
                test ! -L "$receipt_path"
                printf '%s\n' "$receipt" | cmp -s - "$receipt_path"
                release_empty_grouping_directory "$grouping_directory"
                printf '%s\n' "$receipt"
                exit 0
            fi
            physical=$checkout
            if [ -e "$checkout" ] || [ -L "$checkout" ]; then
                test ! -e "$quarantine"
                test ! -L "$quarantine"
                test ! -e "$receipt_path"
                test ! -L "$receipt_path"
            else
                physical=$quarantine
                test -d "$quarantine"
                test ! -L "$quarantine"
                if [ -e "$receipt_path" ] || [ -L "$receipt_path" ]; then
                    test -f "$receipt_path"
                    test ! -L "$receipt_path"
                    printf '%s\n' "$receipt" | cmp -s - "$receipt_path"
                fi
            fi
            case "$physical" in "$root"/*) ;; *) exit 1 ;; esac
            current=$root
            relative=${physical#"$root"/}
            old_ifs=$IFS
            IFS=/
            for segment in $relative; do
                IFS=$old_ifs
                current="$current/$segment"
                test ! -L "$current"
                IFS=/
            done
            IFS=$old_ifs
            test -d "$physical"
            test ! -L "$physical"
            test "$(realpath -e "$physical")" = "$physical"
            test "$(stat -c '%d:%i' "$physical")" = "$source_identity"
            test "$(stat -c '%U:%G' "$physical")" = "$managed_user:$managed_group"
            test "$(stat -c '%U:%G' "$(dirname "$physical")")" = "$managed_user:$managed_group"
            test "$(git -C "$physical" rev-parse --show-toplevel)" = "$physical"
            git_dir=$(git -C "$physical" rev-parse --absolute-git-dir)
            common=$(git -C "$physical" rev-parse --path-format=absolute --git-common-dir)
            case "$layout" in
                checkout)
                    test -d "$physical/.git"
                    test ! -L "$physical/.git"
                    test "$git_dir" = "$physical/.git"
                    test "$common" = "$physical/.git"
                    ;;
                worktree)
                    test -f "$physical/.git"
                    test ! -L "$physical/.git"
                    test "$git_dir" != "$common"
                    test "$(dirname "$common")" = "$common_repository"
                    test -d "$common_repository/.git"
                    test ! -L "$common_repository/.git"
                    ;;
                *) exit 1 ;;
            esac
            current_branch=$(git -C "$physical" symbolic-ref --quiet --short HEAD || true)
            test "$current_branch" = "$branch"
            test "$(git -C "$physical" rev-parse --verify HEAD^{commit})" = "$source_commit"
            origin_with_marker=$(git -C "$physical" config --get remote.origin.url && printf x)
            origin=${origin_with_marker%x}
            case "$origin" in
                *$'\n') origin=${origin%$'\n'} ;;
                *) exit 1 ;;
            esac
            test "$origin" = "$expected_origin"
            worktrees=$(git -C "$physical" worktree list --porcelain -z | base64 --wrap=0)
            test "$worktrees" = "$expected_worktrees"
            if [ "$force" != 1 ]; then
                test -z "$(git -C "$physical" status --porcelain --untracked-files=all)"
                scratch=$(mktemp -d)
                trap 'rm -rf -- "$scratch"' EXIT
                git init --bare --quiet "$scratch/repository.git"
                git --git-dir="$scratch/repository.git" remote add origin "$origin"
                git_read git --git-dir="$scratch/repository.git" fetch --quiet --no-tags --filter=blob:none origin \
                    '+refs/heads/*:refs/remotes/origin/*' '+refs/tags/*:refs/tags/*'
                published=0
                if git --git-dir="$scratch/repository.git" cat-file -e "$source_commit^{commit}" 2>/dev/null; then
                    while IFS= read -r advertised; do
                        tip=$(git --git-dir="$scratch/repository.git" rev-parse --verify "$advertised^{commit}" 2>/dev/null) || continue
                        if git --git-dir="$scratch/repository.git" merge-base --is-ancestor "$source_commit" "$tip"; then
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
            if [ "$physical" = "$checkout" ]; then
                case "$layout" in
                    worktree) git --git-dir="$common_repository/.git" worktree move "$checkout" "$quarantine" ;;
                    checkout) mv -- "$checkout" "$quarantine" ;;
                esac
                physical=$quarantine
            fi
            test -d "$physical"
            test ! -L "$physical"
            test "$(stat -c '%d:%i' "$physical")" = "$source_identity"
            case "$layout" in
                checkout)
                    test ! -e "$recovery"
                    test ! -L "$recovery"
                    ;;
                worktree)
                    git_dir=$(git -C "$physical" rev-parse --absolute-git-dir)
                    worktrees="$common_repository/.git/worktrees"
                    test "$(dirname "$git_dir")" = "$worktrees"
                    test -d "$common_repository/.git"
                    test ! -L "$common_repository/.git"
                    test "$(stat -c '%U:%G' "$common_repository/.git")" = "$managed_user:$managed_group"
                    test -d "$worktrees"
                    test ! -L "$worktrees"
                    test "$(stat -c '%U:%G' "$worktrees")" = "$managed_user:$managed_group"
                    test -d "$git_dir"
                    test ! -L "$git_dir"
                    test "$(stat -c '%U:%G' "$git_dir")" = "$managed_user:$managed_group"
                    admin_encoded=$(printf '%s' "$git_dir" | base64 --wrap=0)
                    admin_identity=$(stat -c '%d:%i' "$git_dir")
                    common_identity=$(stat -c '%d:%i' "$common_repository/.git")
                    worktrees_identity=$(stat -c '%d:%i' "$worktrees")
                    if [ -e "$recovery" ] || [ -L "$recovery" ]; then
                        test -f "$recovery"
                        test ! -L "$recovery"
                        test "$(stat -c '%U:%G' "$recovery")" = "$managed_user:$managed_group"
                        printf '%s\n%s\n%s\n%s\n' \
                            "$admin_encoded" "$admin_identity" "$common_identity" "$worktrees_identity" | \
                            cmp -s - "$recovery"
                    else
                        candidate=$(mktemp "$state/.$operation.$member.recovery.XXXXXX")
                        trap 'rm -f -- "$candidate"' EXIT
                        printf '%s\n%s\n%s\n%s\n' \
                            "$admin_encoded" "$admin_identity" "$common_identity" "$worktrees_identity" > "$candidate"
                        chmod 0600 -- "$candidate"
                        mv -T -- "$candidate" "$recovery"
                        trap - EXIT
                    fi
                    ;;
                *) exit 1 ;;
            esac
            if [ ! -e "$receipt_path" ] && [ ! -L "$receipt_path" ]; then
                candidate=$(mktemp "$state/.$operation.$member.receipt.XXXXXX")
                trap 'rm -f -- "$candidate"' EXIT
                printf '%s\n' "$receipt" > "$candidate"
                chmod 0600 -- "$candidate"
                mv -T -- "$candidate" "$receipt_path"
                trap - EXIT
            else
                test -f "$receipt_path"
                test ! -L "$receipt_path"
                test "$(stat -c '%U:%G' "$receipt_path")" = "$managed_user:$managed_group"
                printf '%s\n' "$receipt" | cmp -s - "$receipt_path"
            fi
            case "$layout" in
                worktree) git --git-dir="$common_repository/.git" worktree remove --force "$quarantine" ;;
                checkout) rm -rf -- "$quarantine" ;;
            esac
            test ! -e "$quarantine"
            test ! -L "$quarantine"
            release_empty_grouping_directory "$grouping_directory"
            printf '%s\n' "$receipt"
            BASH;
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
            inspect_content=$7
            export GIT_OPTIONAL_LOCKS=0
            failure=10
            trap 'exit "$failure"' ERR
            case "$checkout" in "$root"/*) ;; *) exit "$failure" ;; esac
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
            failure=11
            test "$(stat -c '%U:%G' "$checkout")" = "$managed_user:$managed_group"
            test "$(stat -c '%U:%G' "$(dirname "$checkout")")" = "$managed_user:$managed_group"
            failure=12
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
                *) exit "$failure" ;;
            esac
            commit=$(git -C "$checkout" rev-parse --verify HEAD^{commit})
            failure=13
            origin_with_marker=$(git -C "$checkout" config --get remote.origin.url && printf x)
            origin=${origin_with_marker%x}
            case "$origin" in
                *$'\n') origin=${origin%$'\n'} ;;
                *) exit "$failure" ;;
            esac
            failure=14
            branch=$(git -C "$checkout" symbolic-ref --quiet --short HEAD || true)
            test "$branch" = "$expected_branch"
            failure=1
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
            git_read git --git-dir="$scratch/repository.git" fetch --quiet --no-tags --filter=blob:none origin \
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
            expected_commit=$7
            expected_source_identity=$8
            shift 8
            expected_repository_identity=$1
            force=$2
            export GIT_OPTIONAL_LOCKS=0
            failure=10
            trap 'exit "$failure"' ERR
            test "$grouping_directory" = "$(dirname "$checkout")"
            case "$checkout" in "$root"/*) ;; *) exit "$failure" ;; esac
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
            test -d "$grouping_directory"
            test ! -L "$grouping_directory"
            test "$(realpath -e "$grouping_directory")" = "$grouping_directory"
            failure=21
            test "$(stat -c '%d:%i' "$checkout")" = "$expected_source_identity"
            failure=11
            test "$(stat -c '%U:%G' "$checkout")" = "$managed_user:$managed_group"
            test "$(stat -c '%U:%G' "$grouping_directory")" = "$managed_user:$managed_group"
            failure=12
            test -d "$checkout/.git"
            test ! -L "$checkout/.git"
            test "$(git -C "$checkout" rev-parse --show-toplevel)" = "$checkout"
            test "$(git -C "$checkout" rev-parse --absolute-git-dir)" = "$checkout/.git"
            test "$(git -C "$checkout" rev-parse --path-format=absolute --git-common-dir)" = "$checkout/.git"
            failure=14
            current_branch=$(git -C "$checkout" symbolic-ref --quiet --short HEAD || true)
            test "$current_branch" = "$expected_branch"
            failure=21
            test "$(git -C "$checkout" rev-parse --verify HEAD^{commit})" = "$expected_commit"
            failure=13
            origin_with_marker=$(git -C "$checkout" config --get remote.origin.url && printf x)
            origin=${origin_with_marker%x}
            case "$origin" in
                *$'\n') origin=${origin%$'\n'} ;;
                *) exit "$failure" ;;
            esac
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
            failure=15
            linked_count=0
            linked_path=
            linked_prunable=0
            # A prunable worktree's directory is gone, so it does not keep the checkout in use.
            count_linked() {
                if [ -n "$linked_path" ] && [ "$linked_prunable" = 0 ]; then
                    linked_count=$((linked_count + 1))
                    test "$linked_path" = "$checkout"
                fi
                linked_path=
                linked_prunable=0
            }
            while IFS= read -r -d '' field; do
                case "$field" in
                    'worktree '*)
                        count_linked
                        linked_path=${field#worktree }
                        ;;
                    prunable|'prunable '*)
                        linked_prunable=1
                        ;;
                    '')
                        count_linked
                        ;;
                esac
            done < <(git -C "$checkout" worktree list --porcelain -z)
            count_linked
            test "$linked_count" = 1
            if [ "$force" != 1 ]; then
                failure=20
                test -z "$(git -C "$checkout" status --porcelain --untracked-files=all)"
                failure=1
                scratch=$(mktemp -d)
                trap 'rm -rf -- "$scratch"' EXIT
                git init --bare --quiet "$scratch/repository.git"
                git --git-dir="$scratch/repository.git" remote add origin "$origin"
                git_read git --git-dir="$scratch/repository.git" fetch --quiet --no-tags --filter=blob:none origin \
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
                failure=20
                test "$published" = 1
                failure=1
                rm -rf -- "$scratch"
                trap - EXIT
            fi
            failure=1
            rm -rf -- "$checkout"
            release_empty_grouping_directory "$grouping_directory"
            BASH;
    }

    private static function releaseEmptyGroupingDirectoryFunction(): string
    {
        return <<<'BASH'
            release_empty_grouping_directory() {
                grouping_directory=$1
                test "$grouping_directory" != "$root"
                test "$grouping_directory" = "$(dirname "$checkout")"
                case "$grouping_directory" in
                    "$root"/*) ;;
                    *) return 1 ;;
                esac
                if [ ! -e "$grouping_directory" ] && [ ! -L "$grouping_directory" ]; then
                    return 0
                fi
                if [ -L "$grouping_directory" ] || [ ! -d "$grouping_directory" ]; then
                    return 0
                fi
                if [ "$(realpath -e "$grouping_directory")" != "$grouping_directory" ]; then
                    return 0
                fi
                if [ "$(stat -c '%U:%G' "$grouping_directory")" != "$managed_user:$managed_group" ]; then
                    return 0
                fi
                if [ -n "$(find -P "$grouping_directory" -mindepth 1 -maxdepth 1 -print -quit)" ]; then
                    return 0
                fi
                rmdir -- "$grouping_directory"
            }

            BASH;
    }
}
