<?php

declare(strict_types=1);

namespace App\Actions\AppInstances;

use App\Domain\AppInstances\Environment\AppInstanceEnvironmentContext;
use App\Domain\AppInstances\Environment\AppInstanceEnvironmentContextResolver;
use App\Domain\AppInstances\Environment\AppInstanceEnvironmentOperationLock;
use App\Domain\AppInstances\Environment\AppInstanceEnvironmentRenderer;
use App\Domain\AppInstances\Environment\AppInstanceEnvironmentResult;
use App\Domain\AppInstances\Environment\AppInstanceEnvironmentRouteHostname;
use App\Domain\AppInstances\Environment\AppInstanceEnvironmentStore;
use App\Domain\AppInstances\Environment\AppInstanceEnvironmentSynchronizer;
use App\Domain\AppInstances\Environment\AppInstanceEnvironmentWriter;
use App\Domain\AppInstances\Environment\AppInstanceOperationPreflight;
use App\Domain\AppInstances\Environment\AppInstanceRouteEnvironmentSynchronizer;
use App\Domain\Shared\ResourceOperationException;
use App\Models\AppInstance;

final readonly class SynchronizeAppInstanceEnvironmentAction implements AppInstanceEnvironmentSynchronizer, AppInstanceRouteEnvironmentSynchronizer
{
    public function __construct(
        private AppInstanceEnvironmentOperationLock $operations,
        private AppInstanceEnvironmentContextResolver $contexts,
        private AppInstanceEnvironmentStore $store,
        private AppInstanceOperationPreflight $preflight,
        private AppInstanceEnvironmentRenderer $renderer,
        private AppInstanceEnvironmentWriter $writer,
    ) {}

    public function execute(AppInstance $instance): AppInstanceEnvironmentResult
    {
        return $this->operations->run(
            [$instance->id],
            fn (): AppInstanceEnvironmentResult => $this->synchronize(
                $this->contexts->resolve($instance->refresh(), requireActiveNode: true),
            ),
        );
    }

    public function synchronizeRouteHostname(
        AppInstance $instance,
        AppInstanceEnvironmentRouteHostname $hostname,
    ): AppInstanceEnvironmentResult {
        return $this->operations->run(
            [$instance->id],
            fn (): AppInstanceEnvironmentResult => $this->synchronize(
                $this->contexts->resolveForRouteTransition(
                    $instance->refresh(),
                    $hostname,
                    requireActiveNode: true,
                ),
            ),
        );
    }

    private function synchronize(AppInstanceEnvironmentContext $context): AppInstanceEnvironmentResult
    {
        $requiredCapacity = $this->store->synchronizationCapacity($context);
        $this->preflight->assertEnvironmentWritable($context, $requiredCapacity);
        $snapshot = $this->store->synchronizationSnapshot($context);
        $contents = $this->renderer->render($context, $snapshot->values());
        $result = $this->writer->write($context, $contents);

        if (! $result->confirmed || ! is_bool($result->changed)) {
            throw new ResourceOperationException(
                errorCode: 'env.sync_unconfirmed',
                message: 'The AppInstance environment synchronization result is unconfirmed. Retry the request.',
                status: 409,
            );
        }

        return new AppInstanceEnvironmentResult(
            appInstanceId: $context->appInstanceId,
            operation: 'sync',
            changed: $result->changed,
            keyCount: $snapshot->keyCount(),
        );
    }
}
