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
        {--instance= : Positive AppInstance ID or exact Route hostname}
        {--key= : Environment key to add or replace in stored configuration}
        {--value= : Exact string value; quote empty, multiline, or placeholder values for the shell}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Update one stored AppInstance environment value without changing the workload file.';

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

        $response = $this->send(
            $connector,
            new UpdateAppInstanceEnvironmentRequest(
                appInstance: $selector,
                key: $key,
                value: $value,
            ),
            EnvironmentOperationResponse::class,
        );

        return $response instanceof EnvironmentOperationResponse
            ? $this->renderEnvironmentOperation($response)
            : self::FAILURE;
    }
}
