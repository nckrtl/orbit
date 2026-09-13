<?php

declare(strict_types=1);

namespace App\Commands\Herdr;

use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\Requests\Herdr\IssueObservationGrantRequest;
use Orbit\Sdk\Responses\Herdr\ObservationGrantResponse;

final class ObserveHerdrSessionCommand extends HerdrSessionCommand
{
    #[\Override]
    protected $signature = 'herdr:observe
        {session : Named Herdr session}
        {--node= : Node ID or registered name}
        {--pane= : Recorded pane identity}
        {--terminal= : Expected terminal identity}
        {--cols= : Viewport columns}
        {--rows= : Viewport rows}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Issue one short-lived receive-only Herdr observation grant.';

    public function handle(
        GatewayConfigRepository $repository,
        GatewayConnectorFactory $connectors,
    ): int {
        $name = $this->sessionName();

        if ($name === null) {
            return self::FAILURE;
        }

        $pane = $this->stringOption('pane');
        $terminal = $this->stringOption('terminal');

        if ($pane === null || preg_match('/\A[A-Za-z0-9._:-]+\z/D', $pane) !== 1 || strlen($pane) > 64) {
            return $this->renderGatewayFailure(
                'herdr.grant_invalid',
                'Observation pane is required.',
            );
        }

        if ($terminal === null || preg_match('/\A[A-Za-z0-9._:-]+\z/D', $terminal) !== 1 || strlen($terminal) > 64) {
            return $this->renderGatewayFailure(
                'herdr.grant_invalid',
                'Observation terminal is required.',
            );
        }

        $cols = filter_var($this->option('cols'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 500]]);
        $rows = filter_var($this->option('rows'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 200]]);

        if (! is_int($cols) || ! is_int($rows)) {
            return $this->renderGatewayFailure(
                'herdr.grant_invalid',
                'Observation viewport columns and rows are required.',
            );
        }

        $connector = $this->gatewayConnector($repository, $connectors);

        if ($connector === null) {
            return self::FAILURE;
        }

        $nodeId = $this->requiredNodeId($connector);

        if ($nodeId === null) {
            return self::FAILURE;
        }

        $listed = $this->resolveSession($connector, $nodeId, $name);

        if ($listed === null) {
            return self::FAILURE;
        }

        $response = $this->send(
            $connector,
            new IssueObservationGrantRequest($listed->id, $pane, $terminal, $cols, $rows),
            ObservationGrantResponse::class,
        );

        if (! $response instanceof ObservationGrantResponse) {
            return self::FAILURE;
        }

        if ($this->option('json') === true) {
            $this->writeJson($response->toArray());

            return self::SUCCESS;
        }

        $this->info("Observation grant for [{$response->pane}] expires at {$response->expiresAt}.");
        $this->line($response->observerUrl);
        $this->line("Request ID: {$response->requestId}");

        return self::SUCCESS;
    }
}
