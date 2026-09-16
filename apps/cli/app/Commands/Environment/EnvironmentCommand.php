<?php

declare(strict_types=1);

namespace App\Commands\Environment;

use App\Commands\GatewayCommand;
use App\Support\Console\ConsoleWriter;
use Orbit\Sdk\Responses\Environment\EnvironmentOperationResponse;

abstract class EnvironmentCommand extends GatewayCommand
{
    protected function appInstanceSelector(): ?string
    {
        $selector = $this->option('instance');

        if (! is_string($selector) || $selector === '') {
            $this->renderGatewayFailure(
                'env.instance_required',
                'AppInstance ID or Route domain is required.',
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

        $fields = [
            'AppInstance ID' => $response->appInstanceId,
            'Operation' => $response->operation,
            'Changed' => $response->changed ? 'true' : 'false',
            'Stored keys' => $response->keyCount,
        ];

        if (in_array($response->operation, ['import', 'update'], strict: true)) {
            $fields['Workload file'] = 'unchanged';
        }

        $fields['Request ID'] = $response->requestId;
        ConsoleWriter::write($this->output, $this->humanRenderer()->detail('App instance environment', $fields));

        return self::SUCCESS;
    }
}
