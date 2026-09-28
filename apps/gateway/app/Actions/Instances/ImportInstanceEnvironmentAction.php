<?php

declare(strict_types=1);

namespace App\Actions\Instances;

use App\Domain\Instances\Environment\InstanceEnvironmentContextResolver;
use App\Domain\Instances\Environment\InstanceEnvironmentImporter;
use App\Domain\Instances\Environment\InstanceEnvironmentOperationLock;
use App\Domain\Instances\Environment\InstanceEnvironmentReader;
use App\Domain\Instances\Environment\InstanceEnvironmentResult;
use App\Domain\Instances\Environment\InstanceEnvironmentStore;
use App\Domain\Instances\Environment\InstanceOperationPreflight;
use App\Models\Instance;

final readonly class ImportInstanceEnvironmentAction
{
    public function __construct(
        private InstanceEnvironmentOperationLock $operations,
        private InstanceEnvironmentContextResolver $contexts,
        private InstanceOperationPreflight $preflight,
        private InstanceEnvironmentReader $reader,
        private InstanceEnvironmentImporter $importer,
        private InstanceEnvironmentStore $store,
    ) {}

    public function execute(Instance $instance, bool $replace): InstanceEnvironmentResult
    {
        return $this->operations->run([$instance->id], function () use (
            $instance,
            $replace,
        ): InstanceEnvironmentResult {
            $context = $this->contexts->resolve($instance->refresh(), requireActiveNode: true);
            $this->preflight->assertEnvironmentReadable($context);
            $values = $this->importer->parse($this->reader->read($context));

            if ($context->laravel) {
                $values['APP_URL'] = 'https://{{instance.domain}}';
            }

            return $this->store->import($context, $values, $replace);
        });
    }
}
