<?php

declare(strict_types=1);

namespace App\Actions\AppInstances;

use App\Data\AppInstances\CreateAppInstanceData;
use App\Domain\AppDev\AppDevSourceOperationLock;
use App\Domain\AppInstances\AppInstanceSourceKind;
use App\Domain\AppInstances\AppInstanceState;
use App\Domain\AppInstances\DevelopmentAppInstanceSourceLifecycle;
use App\Domain\AppInstances\DevelopmentSourceResolution;
use App\Domain\Nodes\ManagedUserAccountResolver;
use App\Domain\Nodes\RoleName;
use App\Domain\Nodes\Storage\ManagedCheckoutOverlap;
use App\Domain\Nodes\Storage\NodeSettingsNormalizer;
use App\Domain\Nodes\Storage\StorageRootResolver;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\SourceControl\GitBranchName;
use App\Domain\SourceControl\GitRepositoryOrigin;
use App\Domain\SourceControl\RelativeWebRoot;
use App\Models\App as OrbitApp;
use App\Models\AppInstance;
use App\Models\Node;
use Illuminate\Support\Facades\DB;

/**
 * @mago-expect lint:cyclomatic-complexity The action keeps the closed durable state machine visible.
 * @mago-expect lint:too-many-methods The action keeps every transition and evidence check in one durable state machine.
 */
final readonly class CreateAppInstanceAction
{
    /** @mago-expect lint:excessive-parameter-list The action names each narrow placement and source collaborator explicitly. */
    public function __construct(
        private ManagedUserAccountResolver $accounts,
        private StorageRootResolver $storageRoots,
        private NodeSettingsNormalizer $nodeSettings,
        private ManagedCheckoutOverlap $checkoutOverlap,
        private AppDevSourceOperationLock $sourceLock,
        private DevelopmentAppInstanceSourceLifecycle $source,
    ) {}

    /** @return array{appInstance: AppInstance, created: bool} */
    public function execute(CreateAppInstanceData $data): array
    {
        $app = OrbitApp::query()->findOrFail($data->appId);
        $this->assertCompleteSourceDefaults($app);
        $requestedNode = Node::query()->findOrFail($data->nodeId);
        $root = $data->root === null ? null : RelativeWebRoot::validate($data->root);
        $existing = AppInstance::query()
            ->where('app_id', $app->id)
            ->where('name', $data->name)
            ->first();

        if ($existing instanceof AppInstance) {
            $this->assertRetryIdentity($existing, $requestedNode, $root);
            $appInstance = $existing;
            $created = false;
        } else {
            $this->assertPlacement($requestedNode);
            $account = $this->accounts->resolve($requestedNode);
            $roots = $this->storageRoots->resolveApps(
                $this->nodeSettings->fromStored($requestedNode->settings),
                $this->nodeSettings->legacyFromStored($requestedNode->settings),
                $account,
            );
            $checkout = $roots->instance->append($app->slug, $data->name);
            $this->checkoutOverlap->assertAvailable(
                $requestedNode->id,
                $checkout,
                'instance.path_taken',
            );
            $appInstance = AppInstance::query()->create([
                'app_id' => $app->id,
                'node_id' => $requestedNode->id,
                'name' => $data->name,
                'source_kind' => AppInstanceSourceKind::ManagedClone,
                'checkout_path' => $checkout->value,
                'root' => $root,
                'status' => AppInstanceState::Reserved,
            ]);
            $created = true;
        }

        $result = $this->sourceLock->synchronized(
            $appInstance->node_id,
            fn (): AppInstance => $this->resume($appInstance, ! $created),
        );

        return ['appInstance' => $result, 'created' => $created];
    }

    private function resume(AppInstance $appInstance, bool $allowPreparedSource): AppInstance
    {
        while (true) {
            $appInstance->refresh()->loadMissing(['app', 'node']);
            $this->assertPersistedOwnership($appInstance);

            if ($appInstance->status === AppInstanceState::Reserved) {
                $this->source->prepare($appInstance, $allowPreparedSource);
                $this->transition($appInstance, AppInstanceState::Reserved, [
                    'status' => AppInstanceState::CheckoutPrepared,
                ]);

                continue;
            }

            if ($appInstance->status === AppInstanceState::CheckoutPrepared) {
                $this->source->inspectPrepared($appInstance);
                $resolution = $this->source->resolve($appInstance);
                $this->assertResolution($appInstance, $resolution);
                $this->transition($appInstance, AppInstanceState::CheckoutPrepared, [
                    'branch' => $resolution->branch,
                    'starting_commit' => $resolution->startingCommit,
                    'status' => AppInstanceState::SourceResolved,
                ]);

                continue;
            }

            if ($appInstance->status === AppInstanceState::SourceResolved) {
                $this->source->inspectPrepared($appInstance);
                $this->assertStoredResolution($appInstance, $this->source->inspectResolved($appInstance));
                $this->transition($appInstance, AppInstanceState::SourceResolved, [
                    'status' => AppInstanceState::Active,
                ]);

                return $appInstance->refresh();
            }

            $this->assertStoredResolutionEvidence($appInstance);
            $this->source->inspectPrepared($appInstance);

            return $appInstance->refresh();
        }
    }

    /** @param array<string, mixed> $attributes */
    private function transition(AppInstance $appInstance, AppInstanceState $from, array $attributes): void
    {
        DB::transaction(function () use ($appInstance, $from, $attributes): void {
            $locked = AppInstance::query()->lockForUpdate()->findOrFail($appInstance->id);

            if ($locked->status !== $from) {
                throw $this->conflict('instance.lifecycle_conflict', 'AppInstance lifecycle evidence changed.');
            }

            $locked->update($attributes);
        });
    }

    private function assertCompleteSourceDefaults(OrbitApp $app): void
    {
        if (
            ! is_string($app->main_branch)
            || ! GitBranchName::isValid($app->main_branch)
            || ! is_string($app->root)
            || ! RelativeWebRoot::isValid($app->root)
        ) {
            throw new ResourceOperationException(
                errorCode: 'app.source_defaults_incomplete',
                message: "App [{$app->slug}] does not have complete source defaults.",
            );
        }

        GitRepositoryOrigin::validate($app->repository_url);
    }

    private function assertPlacement(Node $node): void
    {
        if ($node->status !== LifecycleStatus::Active || $node->platform !== 'linux') {
            throw new ResourceOperationException('instance.node_inactive', 'The selected app-dev Node is not active.');
        }

        if (! $node->roles()->where('role', RoleName::AppDev)->where('status', LifecycleStatus::Active)->exists()) {
            throw new ResourceOperationException(
                'instance.node_not_app_dev',
                'The selected Node has no active app-dev role.',
            );
        }
    }

    private function assertRetryIdentity(
        AppInstance $appInstance,
        Node $requestedNode,
        ?string $root,
    ): void {
        $recordedNode = Node::query()->findOrFail($appInstance->node_id);

        if (
            $requestedNode->id !== $recordedNode->id
            || $appInstance->source_kind !== AppInstanceSourceKind::ManagedClone->value
            || $appInstance->root !== $root
        ) {
            throw $this->conflict('instance.placement_conflict', 'AppInstance placement is immutable.');
        }

        $this->assertPlacement($recordedNode);
    }

    private function assertPersistedOwnership(AppInstance $appInstance): void
    {
        if ($appInstance->source_kind !== AppInstanceSourceKind::ManagedClone->value) {
            throw $this->conflict('instance.source_kind_conflict', 'AppInstance source ownership is invalid.');
        }
    }

    private function assertResolution(
        AppInstance $appInstance,
        DevelopmentSourceResolution $resolution,
    ): void {
        if (
            $resolution->branch !== $appInstance->name
            || preg_match('/\A[0-9a-f]{40}(?:[0-9a-f]{24})?\z/D', $resolution->startingCommit) !== 1
        ) {
            throw $this->conflict('instance.source_identity_invalid', 'Resolved source identity is invalid.');
        }
    }

    private function assertStoredResolution(
        AppInstance $appInstance,
        DevelopmentSourceResolution $resolution,
    ): void {
        $this->assertStoredResolutionEvidence($appInstance);
        $this->assertResolution($appInstance, $resolution);

        if (
            $appInstance->branch !== $resolution->branch
            || $appInstance->starting_commit !== $resolution->startingCommit
        ) {
            throw $this->conflict('instance.source_identity_changed', 'AppInstance source identity changed.');
        }
    }

    private function assertStoredResolutionEvidence(AppInstance $appInstance): void
    {
        if (
            $appInstance->branch !== $appInstance->name
            || ! is_string($appInstance->starting_commit)
            || preg_match('/\A[0-9a-f]{40}(?:[0-9a-f]{24})?\z/D', $appInstance->starting_commit) !== 1
        ) {
            throw $this->conflict('instance.source_identity_changed', 'AppInstance source identity changed.');
        }
    }

    private function conflict(string $errorCode, string $message): ResourceOperationException
    {
        return new ResourceOperationException($errorCode, $message, 409);
    }
}
