<?php

declare(strict_types=1);

namespace App\Commands\Gateway;

use App\Commands\GatewayCommand;
use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use App\Support\Console\ConsoleWriter;
use App\Support\Console\ProgressOutcome;
use App\Support\Console\ProgressState;
use Orbit\Sdk\Requests\GatewayReleases\SmokeGatewayReleaseRequest;
use Orbit\Sdk\Responses\GatewayReleases\GatewayReleaseSmokeResponse;

final class SmokeGatewayReleaseCommand extends GatewayCommand
{
    #[\Override]
    protected $signature = 'gateway:release:smoke
        {commit? : Hex SHA the live Gateway must serve, 7 to 40 characters. Default: the current release}
        {--since= : Runtime handoff time, ISO 8601 with a zone. The scheduler, agent view, and a tick must have started after it}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Run the smoke tests of the current release against the live Gateway. Changes nothing.';

    public function handle(GatewayConfigRepository $repository, GatewayConnectorFactory $connectors): int
    {
        $commit = $this->argument('commit');
        $since = $this->option('since');

        if ($commit !== null && preg_match('/\A[0-9a-f]{7,40}\z/D', $commit) !== 1) {
            return $this->renderGatewayFailure(
                'gateway.release_commit_invalid',
                'Name the commit by its hex SHA (7 to 40 characters).',
                details: ['field' => 'commit'],
            );
        }

        $connector = $this->gatewayConnector($repository, $connectors);

        if ($connector === null) {
            return self::FAILURE;
        }

        $result = $this->sendWithProgress(
            $connector,
            new SmokeGatewayReleaseRequest($commit, is_string($since) && $since !== '' ? $since : null),
            GatewayReleaseSmokeResponse::class,
            ['Run smoke tests', 'Running smoke tests', 'Smoke tests passed'],
            static fn (GatewayReleaseSmokeResponse $result): ProgressState|ProgressOutcome => $result->passed()
                ? ProgressState::Success
                : new ProgressOutcome(ProgressState::Failure, 'Smoke tests failed'),
        );

        if (! $result instanceof GatewayReleaseSmokeResponse) {
            return self::FAILURE;
        }

        if ($this->option('json') === true) {
            $this->writeJson($result->toArray());

            return $result->passed() ? self::SUCCESS : self::FAILURE;
        }

        ConsoleWriter::write($this->output, $this->humanRenderer()->detail(
            'Gateway smoke: '.($result->release ?? substr($result->sha, 0, 12)),
            GatewayReleaseOutput::smoke($result),
        ));
        ConsoleWriter::write($this->output, $this->humanRenderer()->table(
            ['CHECK', 'STATUS', 'ERROR', 'MESSAGE', 'DURATION'],
            GatewayReleaseOutput::smokeChecks($result),
            'The smoke report lists no checks.',
        ));
        $this->writeHumanMessage('Request ID: '.$result->requestId);

        return $result->passed() ? self::SUCCESS : self::FAILURE;
    }
}
