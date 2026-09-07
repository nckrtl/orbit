<?php

declare(strict_types=1);

namespace App\Actions\AppInstances;

use App\Domain\AppDev\AppDevSourceOperationLock;
use App\Domain\AppInstances\AppInstanceSourceKind;
use App\Domain\AppInstances\DevelopmentAppInstanceSourceLifecycle;
use App\Domain\Nodes\Storage\ManagedCheckoutOverlap;
use App\Domain\Nodes\Storage\StoragePath;
use App\Domain\Shared\ResourceOperationException;
use App\Models\AppInstance;

final readonly class RemoveAppInstanceAction
{
    public function __construct(
        private AppDevSourceOperationLock $sourceLock,
        private DevelopmentAppInstanceSourceLifecycle $source,
        private ManagedCheckoutOverlap $checkoutOverlap,
    ) {}

    public function execute(AppInstance $appInstance, bool $discardSource): AppInstance
    {
        return $this->sourceLock->synchronized(
            $appInstance->node_id,
            function () use ($appInstance, $discardSource): AppInstance {
                $snapshot = $appInstance->refresh()->load(['app', 'node']);

                if ($snapshot->routes()->where('routes.status', 'active')->exists()) {
                    throw new ResourceOperationException(
                        errorCode: 'route.reconciliation_required',
                        message: 'The active AppInstance Route must be reconciled before removal.',
                        status: 409,
                    );
                }

                if ($snapshot->source_kind !== AppInstanceSourceKind::ManagedClone->value) {
                    throw new ResourceOperationException(
                        errorCode: 'instance.source_kind_conflict',
                        message: "AppInstance [{$snapshot->name}] is not an Orbit-managed clone.",
                        status: 409,
                    );
                }

                $checkout = StoragePath::tryParse($snapshot->checkout_path);

                if (! $checkout instanceof StoragePath) {
                    throw new ResourceOperationException(
                        errorCode: 'instance.checkout_path_unsafe',
                        message: "AppInstance [{$snapshot->name}] has an unsafe checkout path.",
                        status: 409,
                    );
                }

                $this->checkoutOverlap->assertAvailable(
                    $snapshot->node_id,
                    $checkout,
                    'instance.checkout_path_unsafe',
                    ignoreAppInstanceId: $snapshot->id,
                );
                $this->source->remove($snapshot, $discardSource);
                $snapshot->delete();

                return $snapshot;
            },
        );
    }
}
