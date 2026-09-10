<?php

declare(strict_types=1);

namespace App\Actions\AppInstances;

use App\Domain\AppInstances\Environment\AppInstanceEnvironmentContextResolver;
use App\Domain\AppInstances\Environment\AppInstanceEnvironmentOperationLock;
use App\Domain\AppInstances\Environment\AppInstanceEnvironmentResult;
use App\Domain\AppInstances\Environment\AppInstanceEnvironmentStore;
use App\Domain\Shared\ResourceOperationException;
use App\Models\AppInstance;

final readonly class UpdateAppInstanceEnvironmentAction
{
    public function __construct(
        private AppInstanceEnvironmentOperationLock $operations,
        private AppInstanceEnvironmentContextResolver $contexts,
        private AppInstanceEnvironmentStore $store,
    ) {}

    public function execute(
        AppInstance $instance,
        string $key,
        #[\SensitiveParameter]
        string $value,
    ): AppInstanceEnvironmentResult {
        return $this->operations->run([$instance->id], function () use (
            $instance,
            $key,
            $value,
        ): AppInstanceEnvironmentResult {
            $context = $this->contexts->resolve($instance->refresh(), requireActiveNode: false);

            if (
                $context->laravel
                && $key === 'APP_URL'
                && $value !== 'https://{{app_instance.hostname}}'
            ) {
                throw new ResourceOperationException(
                    errorCode: 'env.configuration_invalid',
                    message: 'The complete AppInstance environment configuration is invalid.',
                );
            }

            return $this->store->update($context, $key, $value);
        });
    }
}
