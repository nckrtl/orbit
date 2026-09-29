<?php

declare(strict_types=1);

namespace App\Actions\Instances;

use App\Domain\Instances\Environment\InstanceEnvironmentContextResolver;
use App\Domain\Instances\Environment\InstanceEnvironmentOperationLock;
use App\Domain\Instances\Environment\InstanceEnvironmentResult;
use App\Domain\Instances\Environment\InstanceEnvironmentStore;
use App\Domain\Instances\Environment\InstanceEnvironmentValidator;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Instance;

final readonly class UpdateInstanceEnvironmentAction
{
    public function __construct(
        private InstanceEnvironmentOperationLock $operations,
        private InstanceEnvironmentContextResolver $contexts,
        private InstanceEnvironmentStore $store,
    ) {}

    public function execute(
        Instance $instance,
        string $key,
        #[\SensitiveParameter]
        string $value,
    ): InstanceEnvironmentResult {
        return $this->operations->run([$instance->id], function () use (
            $instance,
            $key,
            $value,
        ): InstanceEnvironmentResult {
            $context = $this->contexts->resolve($instance->refresh(), requireActiveNode: false);

            if (
                $context->laravel
                && $key === 'APP_URL'
                && $value !== 'https://{{instance.domain}}'
            ) {
                throw new ResourceOperationException(
                    errorCode: 'env.configuration_invalid',
                    message: 'The complete Instance environment configuration is invalid.',
                    details: [
                        'key' => 'APP_URL',
                        'rule' => InstanceEnvironmentValidator::RuleLaravelAppUrl,
                    ],
                );
            }

            return $this->store->update($context, $key, $value);
        });
    }
}
