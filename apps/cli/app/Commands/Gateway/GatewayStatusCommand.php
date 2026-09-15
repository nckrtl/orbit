<?php

declare(strict_types=1);

namespace App\Commands\Gateway;

use App\Commands\GatewayCommand;
use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use App\Support\Console\ConsoleWriter;
use App\Support\Console\ProgressState;
use Orbit\Sdk\Requests\Gateway\ShowGatewayStatusRequest;
use Orbit\Sdk\Responses\Gateway\GatewayStatusResponse;

final class GatewayStatusCommand extends GatewayCommand
{
    #[\Override]
    protected $signature = 'gateway:status
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Show the active gateway status.';

    public function handle(
        GatewayConfigRepository $repository,
        GatewayConnectorFactory $connectors,
    ): int {
        $profile = $this->activeGatewayProfile($repository);

        if ($profile === null) {
            return self::FAILURE;
        }

        $progress = $this->progressDisplay("Check gateway: {$profile->name}");
        $progress->admit('status', 'Request status', 'Requesting status', 'Received status');
        $status = $progress->during('status', fn () => $this->send(
            $connectors->make($profile),
            new ShowGatewayStatusRequest,
            GatewayStatusResponse::class,
        ));

        if (! $status instanceof GatewayStatusResponse) {
            $progress->complete('status', ProgressState::Failure);
            $progress->finish('Gateway status unavailable.');

            return self::FAILURE;
        }

        $progress->complete('status', ProgressState::Success);
        $progress->finish('Gateway status received.');

        $payload = [
            'gateway' => $profile->name,
            'url' => $profile->url,
            ...$status->toArray(),
        ];

        if ($this->option('json') === true) {
            $this->writeJson($payload);

            return self::SUCCESS;
        }

        ConsoleWriter::write($this->output, $this->humanRenderer()->detail("Gateway: {$profile->name}", [
            'URL' => $profile->url,
            'Name' => $status->name,
            'Status' => $status->status,
            'Version' => $status->version,
            'PHP version' => $status->phpVersion,
            'Laravel version' => $status->laravelVersion,
            'Request ID' => $status->requestId,
        ]));

        return self::SUCCESS;
    }
}
