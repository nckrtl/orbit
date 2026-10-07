<?php

declare(strict_types=1);

namespace App\Commands\Gateway;

use App\Commands\GatewayCommand;
use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use App\Support\Console\ConsoleWriter;
use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\GatewayReleases\GatewayReleaseAutomationResponse;

/** Shows or changes automatic Gateway releases and renders the resulting state. */
abstract class GatewayReleaseAutomationCommand extends GatewayCommand
{
    /** @param array{string, string, string} $labels */
    protected function automation(
        GatewayConfigRepository $repository,
        GatewayConnectorFactory $connectors,
        GatewayRequest $request,
        array $labels,
    ): int {
        $connector = $this->gatewayConnector($repository, $connectors);

        if ($connector === null) {
            return self::FAILURE;
        }

        $state = $this->sendWithProgress($connector, $request, GatewayReleaseAutomationResponse::class, $labels);

        if (! $state instanceof GatewayReleaseAutomationResponse) {
            return self::FAILURE;
        }

        if ($this->option('json') === true) {
            $this->writeJson($state->toArray());

            return self::SUCCESS;
        }

        ConsoleWriter::write($this->output, $this->humanRenderer()->detail('Automatic releases', GatewayReleaseOutput::automation($state)));
        $this->writeHumanMessage('Request ID: '.$state->requestId);

        return self::SUCCESS;
    }
}
