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
use App\Domain\Shared\ResourceOperationException;
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
                $values = $this->initializeEmptyAppKey($instance, $values);
            }

            return $this->store->import($context, $values, $replace);
        });
    }

    /**
     * Import `.env` when the file exists, without replacing stored keys. A missing file imports
     * nothing. The read itself checks the user, the path, the file type, the owner, and the size.
     */
    public function importExisting(Instance $instance): ?InstanceEnvironmentResult
    {
        return $this->operations->run([$instance->id], function () use ($instance): ?InstanceEnvironmentResult {
            $context = $this->contexts->resolve($instance->refresh(), requireActiveNode: true);

            try {
                $contents = $this->reader->read($context);
            } catch (ResourceOperationException $exception) {
                if ($exception->errorCode === 'env.import_source_missing') {
                    return null;
                }

                throw $exception;
            }

            $values = $this->importer->parse($contents);

            if ($context->laravel) {
                $values['APP_URL'] = 'https://{{instance.domain}}';
                $values = $this->initializeEmptyAppKey($instance, $values);
            }

            return $this->store->import($context, $values, replace: false);
        });
    }

    /**
     * @param  array<string, string>  $values
     * @return array<string, string>
     */
    private function initializeEmptyAppKey(Instance $instance, #[\SensitiveParameter] array $values): array
    {
        if (($values['APP_KEY'] ?? null) !== '') {
            return $values;
        }

        $stored = $instance->environmentValues()->where('env_key', 'APP_KEY')->first()?->env_value;
        $values['APP_KEY'] = is_string($stored) && $stored !== ''
            ? $stored
            : 'base64:'.base64_encode(random_bytes(32));

        return $values;
    }
}
