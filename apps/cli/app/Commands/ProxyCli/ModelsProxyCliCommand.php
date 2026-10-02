<?php

declare(strict_types=1);

namespace App\Commands\ProxyCli;

use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use App\Support\Console\ConsoleWriter;
use Orbit\Sdk\Requests\ProxyCli\ListProxyCliModelsRequest;
use Orbit\Sdk\Responses\ProxyCli\ProxyCliModelResponse;
use Orbit\Sdk\Responses\ProxyCli\ProxyCliModelsResponse;

final class ModelsProxyCliCommand extends ProxyCliCommand
{
    #[\Override]
    protected $signature = 'proxycli:models {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'List models from the collector snapshot.';

    public function handle(GatewayConfigRepository $repository, GatewayConnectorFactory $factory): int
    {
        $connector = $this->gatewayConnector($repository, $factory);

        if ($connector === null) {
            return self::FAILURE;
        }

        $response = $this->sendWithProgress(
            $connector,
            new ListProxyCliModelsRequest,
            ProxyCliModelsResponse::class,
            ['List proxycli models', 'Loading models', 'Loaded models'],
        );

        if (! $response instanceof ProxyCliModelsResponse) {
            return self::FAILURE;
        }

        if ($this->option('json') === true) {
            $this->writeJson($response->toArray());

            return self::SUCCESS;
        }

        ConsoleWriter::write($this->output, $this->humanRenderer()->table(
            ['Model', 'Provider'],
            array_map(static fn (ProxyCliModelResponse $model): array => [
                $model->id,
                $model->provider,
            ], $response->models),
            'No models in the snapshot.',
        ));
        $this->writeHumanMessage("Request ID: {$response->requestId}");

        return self::SUCCESS;
    }
}
