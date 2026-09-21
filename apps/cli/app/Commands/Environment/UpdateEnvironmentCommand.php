<?php

declare(strict_types=1);

namespace App\Commands\Environment;

use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\Requests\Environment\UpdateAppInstanceEnvironmentRequest;
use Orbit\Sdk\Responses\Environment\EnvironmentOperationResponse;

final class UpdateEnvironmentCommand extends EnvironmentCommand
{
    #[\Override]
    protected $signature = 'env:update
        {--instance= : Positive Instance ID or exact Route domain}
        {--key= : Environment key to add or replace in stored configuration}
        {--value= : Exact string value; quote empty, multiline, or placeholder values for the shell}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Update one stored Instance environment value without changing the workload file.';

    public function handle(GatewayConfigRepository $repository, GatewayConnectorFactory $connectors): int
    {
        $selector = $this->appInstanceSelector();

        if ($selector === null) {
            return self::FAILURE;
        }

        $key = $this->option('key');

        if (! is_string($key) || $key === '') {
            return $this->renderGatewayFailure('env.key_required', 'Environment key is required.');
        }

        $value = $this->option('value');

        if (! is_string($value)) {
            return $this->renderGatewayFailure('env.value_required', 'Environment value is required.');
        }

        $connector = $this->gatewayConnector($repository, $connectors);

        if ($connector === null) {
            return self::FAILURE;
        }

        $response = $this->sendWithProgress(
            $connector,
            new UpdateAppInstanceEnvironmentRequest(
                appInstance: $selector,
                key: $key,
                value: $value,
            ),
            EnvironmentOperationResponse::class,
            ['Update environment', 'Updating environment', 'Updated environment'],
        );

        return $response instanceof EnvironmentOperationResponse
            ? $this->renderEnvironmentOperation($response)
            : self::FAILURE;
    }
}
