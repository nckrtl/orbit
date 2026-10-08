<?php

declare(strict_types=1);

namespace App\Commands\Gateway;

use App\Commands\GatewayCommand;
use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use App\Support\Console\ConsoleInterrupted;
use App\Support\Console\ConsoleWriter;
use App\Support\Console\PromptAborted;
use Orbit\Sdk\GatewayApiException;
use Orbit\Sdk\GatewayConnector;
use Orbit\Sdk\Requests\GatewayReleases\ListGatewayReleasesRequest;
use Orbit\Sdk\Requests\GatewayReleases\ShowGatewayReleaseRequest;
use Orbit\Sdk\Responses\GatewayReleases\GatewayReleaseResponse;
use Orbit\Sdk\Responses\GatewayReleases\GatewayReleasesResponse;

final class ShowGatewayReleaseCommand extends GatewayCommand
{
    #[\Override]
    protected $signature = 'gateway:release:show
        {release? : Record id, or a hex SHA of 7 to 40 characters such as the 12-digit release id}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Show one Gateway release record, or the newest record of a commit.';

    public function handle(GatewayConfigRepository $repository, GatewayConnectorFactory $connectors): int
    {
        $connector = $this->gatewayConnector($repository, $connectors);

        if ($connector === null) {
            return self::FAILURE;
        }

        $release = $this->argument('release');

        if ($release === null && $this->consoleMode()->mayPrompt) {
            $release = $this->selectRecord($connector);

            if ($release === null) {
                return self::FAILURE;
            }
        }

        if (! is_string($release) || preg_match('/\A(?:[1-9][0-9]{0,5}|[0-9a-f]{7,40})\z/D', $release) !== 1) {
            return $this->renderGatewayFailure(
                'gateway.release_id_invalid',
                'Name a release by its record id or by a hex SHA of 7 to 40 characters.',
                details: ['field' => 'release'],
            );
        }

        $record = $this->sendWithProgress(
            $connector,
            new ShowGatewayReleaseRequest($release),
            GatewayReleaseResponse::class,
            ['Show Gateway release', 'Loading Gateway release', 'Loaded Gateway release'],
        );

        if (! $record instanceof GatewayReleaseResponse) {
            return self::FAILURE;
        }

        if ($this->option('json') === true) {
            $this->writeJson($record->toArray());

            return self::SUCCESS;
        }

        ConsoleWriter::write($this->output, $this->humanRenderer()->detail(
            'Gateway release: '.($record->release ?? $record->requested ?? (string) $record->id),
            GatewayReleaseOutput::detail($record),
        ));
        $this->writeHumanMessage('Request ID: '.$record->requestId);

        return self::SUCCESS;
    }

    private function selectRecord(GatewayConnector $connector): ?string
    {
        try {
            $records = $this->spinnerDisplay()->during(
                'Loading Gateway releases',
                fn (): GatewayReleasesResponse => $this->sendOrThrow($connector, new ListGatewayReleasesRequest, GatewayReleasesResponse::class),
            );
        } catch (GatewayApiException $exception) {
            $this->renderApiFailure($exception);

            return null;
        }

        $rows = [];

        foreach ($records->releases as $record) {
            $rows[(string) $record->id] = GatewayReleaseOutput::row($record);
        }

        try {
            return (string) $this->commandPrompts()->selectEntity('Gateway release', ['ID', 'RELEASE', 'TRIGGER', 'OUTCOME', 'ERROR', 'DURATION', 'STARTED'], $rows);
        } catch (PromptAborted|ConsoleInterrupted $exception) {
            $this->renderGatewayFailure(
                'input.cancelled',
                $exception instanceof PromptAborted && $exception->getMessage() !== '' ? $exception->getMessage() : 'No release record was selected.',
            );

            return null;
        }
    }
}
