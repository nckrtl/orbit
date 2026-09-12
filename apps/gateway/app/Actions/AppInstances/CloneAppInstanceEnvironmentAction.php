<?php

declare(strict_types=1);

namespace App\Actions\AppInstances;

use App\Domain\AppInstances\Environment\AppInstanceEnvironmentContextResolver;
use App\Domain\AppInstances\Environment\AppInstanceEnvironmentOperationLock;
use App\Domain\AppInstances\Environment\AppInstanceEnvironmentRenderer;
use App\Domain\AppInstances\Environment\AppInstanceEnvironmentResult;
use App\Domain\AppInstances\Environment\AppInstanceEnvironmentStore;
use App\Domain\AppInstances\Environment\AppInstanceEnvironmentWriter;
use App\Domain\AppInstances\Environment\AppInstanceOperationPreflight;
use App\Domain\Shared\ResourceOperationException;
use App\Models\AppInstance;

final readonly class CloneAppInstanceEnvironmentAction
{
    public function __construct(
        private AppInstanceEnvironmentOperationLock $operations,
        private AppInstanceEnvironmentContextResolver $contexts,
        private AppInstanceEnvironmentStore $store,
        private AppInstanceOperationPreflight $preflight,
        private AppInstanceEnvironmentRenderer $renderer,
        private AppInstanceEnvironmentWriter $writer,
    ) {}

    public function execute(AppInstance $source, AppInstance $target): AppInstanceEnvironmentResult
    {
        return $this->operations->run(
            [$source->id, $target->id],
            function () use ($source, $target): AppInstanceEnvironmentResult {
                $source = $source->refresh();
                $target = $target->refresh();

                if ($target->clone_candidate_id !== $source->id) {
                    throw new ResourceOperationException(
                        errorCode: 'env.owner_changed',
                        message: 'The AppInstance environment owner changed during the operation.',
                        status: 409,
                    );
                }

                $sourceContext = $this->contexts->resolve($source, requireActiveNode: true);
                $targetContext = $this->contexts->resolveForClone($target, requireActiveNode: true);
                $this->store->copyForClone($sourceContext, $targetContext);
                $requiredCapacity = $this->store->cloneSynchronizationCapacity($targetContext);
                $this->preflight->assertEnvironmentWritable($targetContext, $requiredCapacity);
                $snapshot = $this->store->cloneSynchronizationSnapshot($targetContext);
                $contents = $this->renderer->render($targetContext, $snapshot->values());
                $result = $this->writer->write($targetContext, $contents);

                if (! $result->confirmed || ! is_bool($result->changed)) {
                    throw new ResourceOperationException(
                        errorCode: 'env.sync_unconfirmed',
                        message: 'The AppInstance environment synchronization result is unconfirmed. Retry the request.',
                        status: 409,
                    );
                }

                return new AppInstanceEnvironmentResult(
                    appInstanceId: $targetContext->appInstanceId,
                    operation: 'clone',
                    changed: $result->changed,
                    keyCount: $snapshot->keyCount(),
                );
            },
        );
    }
}
