<?php

declare(strict_types=1);

namespace App\Actions\Instances;

use App\Domain\Instances\Environment\InstanceEnvironmentContextResolver;
use App\Domain\Instances\Environment\InstanceEnvironmentOperationLock;
use App\Domain\Instances\Environment\InstanceEnvironmentRenderer;
use App\Domain\Instances\Environment\InstanceEnvironmentResult;
use App\Domain\Instances\Environment\InstanceEnvironmentStore;
use App\Domain\Instances\Environment\InstanceEnvironmentWriter;
use App\Domain\Instances\Environment\InstanceOperationPreflight;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Instance;

final readonly class CloneInstanceEnvironmentAction
{
    public function __construct(
        private InstanceEnvironmentOperationLock $operations,
        private InstanceEnvironmentContextResolver $contexts,
        private InstanceEnvironmentStore $store,
        private InstanceOperationPreflight $preflight,
        private InstanceEnvironmentRenderer $renderer,
        private InstanceEnvironmentWriter $writer,
    ) {}

    public function execute(Instance $source, Instance $target): InstanceEnvironmentResult
    {
        return $this->operations->run(
            [$source->id, $target->id],
            function () use ($source, $target): InstanceEnvironmentResult {
                $source = $source->refresh();
                $target = $target->refresh();

                if ($target->clone_candidate_id !== $source->id) {
                    throw new ResourceOperationException(
                        errorCode: 'env.owner_changed',
                        message: 'The Instance environment owner changed during the operation.',
                        status: 409,
                    );
                }

                $sourceContext = $this->contexts->resolve($source, requireActiveNode: true);
                $targetContext = $this->contexts->resolveForClone($target, requireActiveNode: true);
                $this->store->copyForClone($sourceContext, $targetContext);
                $this->store->forceAppProdMode($target);
                $requiredCapacity = $this->store->cloneSynchronizationCapacity($targetContext);
                $this->preflight->assertEnvironmentWritable($targetContext, $requiredCapacity);
                $snapshot = $this->store->cloneSynchronizationSnapshot($targetContext);
                $contents = $this->renderer->render($targetContext, $snapshot->values());
                $result = $this->writer->write($targetContext, $contents);

                if (! $result->confirmed || ! is_bool($result->changed)) {
                    throw new ResourceOperationException(
                        errorCode: 'env.sync_unconfirmed',
                        message: 'The Instance environment synchronization result is unconfirmed. Retry the request.',
                        status: 409,
                    );
                }

                return new InstanceEnvironmentResult(
                    instanceId: $targetContext->instanceId,
                    operation: 'clone',
                    changed: $result->changed,
                    keyCount: $snapshot->keyCount(),
                );
            },
        );
    }
}
