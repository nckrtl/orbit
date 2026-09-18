<?php

declare(strict_types=1);

namespace App\Commands\Realtime;

use App\Commands\GatewayCommand;
use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use App\Support\Console\ConsoleWriter;
use Orbit\Sdk\Requests\Realtime\ShowRealtimeRequest;
use Orbit\Sdk\Responses\Realtime\RealtimeResponse;

final class RealtimeShowCommand extends GatewayCommand
{
    #[\Override]
    protected $signature = 'realtime:show
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Show the Gateway realtime (Reverb) connection.';

    public function handle(
        GatewayConfigRepository $repository,
        GatewayConnectorFactory $connectors,
    ): int {
        $profile = $this->activeGatewayProfile($repository);

        if ($profile === null) {
            return self::FAILURE;
        }

        $response = $this->sendWithProgress(
            $connectors->make($profile),
            new ShowRealtimeRequest,
            RealtimeResponse::class,
            ['Show realtime connection', 'Loading realtime connection', 'Loaded realtime connection'],
        );

        if (! $response instanceof RealtimeResponse) {
            return self::FAILURE;
        }

        if ($this->option('json') === true) {
            $this->writeJson($response->toArray());

            return self::SUCCESS;
        }

        $configured = $response->url !== null;

        ConsoleWriter::write($this->output, $this->humanRenderer()->detail('Realtime', [
            'Status' => $configured ? 'configured' : 'not configured',
            'URL' => $response->url,
            'Channel' => $response->channel,
            'Key' => $response->key,
        ]));

        $this->writeHumanMessage("Request ID: {$response->requestId}");

        return self::SUCCESS;
    }
}
