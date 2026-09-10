<?php

declare(strict_types=1);

namespace App\Commands\Environment;

use App\Commands\GatewayCommand;
use Orbit\Sdk\Responses\Environment\EnvironmentOperationResponse;

abstract class EnvironmentCommand extends GatewayCommand
{
    protected function appInstanceSelector(): ?string
    {
        $selector = $this->option('instance');

        if (! is_string($selector) || $selector === '') {
            $this->renderGatewayFailure(
                'env.instance_required',
                'AppInstance ID or Route hostname is required.',
            );

            return null;
        }

        return $selector;
    }

    protected function renderEnvironmentOperation(EnvironmentOperationResponse $response): int
    {
        $payload = $response->toArray();

        if (in_array($response->operation, ['import', 'update'], strict: true)) {
            $payload['workload_file_changed'] = false;
        }

        if ($this->option('json') === true) {
            $this->writeJson($payload);

            return self::SUCCESS;
        }

        $this->line("AppInstance ID: {$response->appInstanceId}");
        $this->line("Operation: {$response->operation}");
        $this->line('Changed: '.($response->changed ? 'true' : 'false'));
        $this->line("Stored keys: {$response->keyCount}");

        if (in_array($response->operation, ['import', 'update'], strict: true)) {
            $this->line('Workload file: unchanged');
        }

        $this->line("Request ID: {$response->requestId}");

        return self::SUCCESS;
    }
}
