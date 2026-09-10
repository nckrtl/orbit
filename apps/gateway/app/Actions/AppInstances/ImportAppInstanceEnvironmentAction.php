<?php

declare(strict_types=1);

namespace App\Actions\AppInstances;

use App\Domain\AppInstances\Environment\AppInstanceEnvironmentContextResolver;
use App\Domain\AppInstances\Environment\AppInstanceEnvironmentImporter;
use App\Domain\AppInstances\Environment\AppInstanceEnvironmentOperationLock;
use App\Domain\AppInstances\Environment\AppInstanceEnvironmentReader;
use App\Domain\AppInstances\Environment\AppInstanceEnvironmentResult;
use App\Domain\AppInstances\Environment\AppInstanceEnvironmentStore;
use App\Domain\AppInstances\Environment\AppInstanceOperationPreflight;
use App\Models\AppInstance;

final readonly class ImportAppInstanceEnvironmentAction
{
    /** @mago-expect lint:excessive-parameter-list Import keeps its operation owner and each existing read and storage boundary explicit. */
    public function __construct(
        private AppInstanceEnvironmentOperationLock $operations,
        private AppInstanceEnvironmentContextResolver $contexts,
        private AppInstanceOperationPreflight $preflight,
        private AppInstanceEnvironmentReader $reader,
        private AppInstanceEnvironmentImporter $importer,
        private AppInstanceEnvironmentStore $store,
    ) {}

    public function execute(AppInstance $instance, bool $replace): AppInstanceEnvironmentResult
    {
        return $this->operations->run([$instance->id], function () use (
            $instance,
            $replace,
        ): AppInstanceEnvironmentResult {
            $context = $this->contexts->resolve($instance->refresh(), requireActiveNode: true);
            $this->preflight->assertEnvironmentReadable($context);
            $values = $this->importer->parse($this->reader->read($context));

            if ($context->laravel) {
                $values['APP_URL'] = 'https://{{app_instance.hostname}}';
            }

            return $this->store->import($context, $values, $replace);
        });
    }
}
