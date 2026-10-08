<?php

declare(strict_types=1);

namespace App\Commands\Gateway;

use App\Commands\GatewayCommand;
use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use App\Support\Console\ConsoleWriter;
use Orbit\Sdk\Requests\GatewayReleases\ListGatewayReleasesRequest;
use Orbit\Sdk\Responses\GatewayReleases\GatewayReleasesResponse;

final class ListGatewayReleasesCommand extends GatewayCommand
{
    #[\Override]
    protected $signature = 'gateway:release:list
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'List the newest Gateway release records, newest first.';

    public function handle(GatewayConfigRepository $repository, GatewayConnectorFactory $connectors): int
    {
        $connector = $this->gatewayConnector($repository, $connectors);

        if ($connector === null) {
            return self::FAILURE;
        }

        $response = $this->sendWithProgress(
            $connector,
            new ListGatewayReleasesRequest,
            GatewayReleasesResponse::class,
            ['List Gateway releases', 'Loading Gateway releases', 'Loaded Gateway releases'],
        );

        if (! $response instanceof GatewayReleasesResponse) {
            return self::FAILURE;
        }

        if ($this->option('json') === true) {
            $this->writeJson($response->toArray());

            return self::SUCCESS;
        }

        ConsoleWriter::write($this->output, $this->humanRenderer()->table(
            ['ID', 'RELEASE', 'TRIGGER', 'OUTCOME', 'ERROR', 'DURATION', 'STARTED'],
            array_map(GatewayReleaseOutput::row(...), $response->releases),
            'No Gateway release records found.',
        ));
        $this->writeHumanMessage('Request ID: '.$response->requestId);

        return self::SUCCESS;
    }
}
