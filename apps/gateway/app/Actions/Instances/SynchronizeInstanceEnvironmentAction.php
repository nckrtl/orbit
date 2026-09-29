<?php

declare(strict_types=1);

namespace App\Actions\Instances;

use App\Domain\Instances\Environment\InstanceEnvironmentContext;
use App\Domain\Instances\Environment\InstanceEnvironmentContextResolver;
use App\Domain\Instances\Environment\InstanceEnvironmentOperationLock;
use App\Domain\Instances\Environment\InstanceEnvironmentRenderer;
use App\Domain\Instances\Environment\InstanceEnvironmentResult;
use App\Domain\Instances\Environment\InstanceEnvironmentRouteDomain;
use App\Domain\Instances\Environment\InstanceEnvironmentStore;
use App\Domain\Instances\Environment\InstanceEnvironmentSynchronizer;
use App\Domain\Instances\Environment\InstanceEnvironmentWriter;
use App\Domain\Instances\Environment\InstanceOperationPreflight;
use App\Domain\Instances\Environment\InstanceRouteEnvironmentSynchronizer;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Instance;

final readonly class SynchronizeInstanceEnvironmentAction implements InstanceEnvironmentSynchronizer, InstanceRouteEnvironmentSynchronizer
{
    public function __construct(
        private InstanceEnvironmentOperationLock $operations,
        private InstanceEnvironmentContextResolver $contexts,
        private InstanceEnvironmentStore $store,
        private InstanceOperationPreflight $preflight,
        private InstanceEnvironmentRenderer $renderer,
        private InstanceEnvironmentWriter $writer,
    ) {}

    public function execute(Instance $instance): InstanceEnvironmentResult
    {
        return $this->operations->run(
            [$instance->id],
            fn (): InstanceEnvironmentResult => $this->synchronize(
                $this->contexts->resolve($instance->refresh(), requireActiveNode: true),
            ),
        );
    }

    public function synchronizeRouteDomain(
        Instance $instance,
        InstanceEnvironmentRouteDomain $domain,
    ): InstanceEnvironmentResult {
        return $this->operations->run(
            [$instance->id],
            fn (): InstanceEnvironmentResult => $this->synchronize(
                $this->contexts->resolveForRouteTransition(
                    $instance->refresh(),
                    $domain,
                    requireActiveNode: true,
                ),
            ),
        );
    }

    private function synchronize(InstanceEnvironmentContext $context): InstanceEnvironmentResult
    {
        $requiredCapacity = $this->store->synchronizationCapacity($context);
        $this->preflight->assertEnvironmentWritable($context, $requiredCapacity);
        $snapshot = $this->store->synchronizationSnapshot($context);
        $contents = $this->renderer->render($context, $snapshot->values());
        $result = $this->writer->write($context, $contents);

        if (! $result->confirmed || ! is_bool($result->changed)) {
            throw new ResourceOperationException(
                errorCode: 'env.sync_unconfirmed',
                message: 'The Instance environment synchronization result is unconfirmed. Retry the request.',
                status: 409,
            );
        }

        return new InstanceEnvironmentResult(
            instanceId: $context->instanceId,
            operation: 'sync',
            changed: $result->changed,
            keyCount: $snapshot->keyCount(),
        );
    }
}
