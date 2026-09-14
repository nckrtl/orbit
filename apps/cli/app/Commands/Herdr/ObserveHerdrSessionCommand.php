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
        {--origin= : HTTPS browser origin allowed to use the grant}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Issue one short-lived receive-only Herdr observation grant.';

    public function handle(
        GatewayConfigRepository $repository,
        GatewayConnectorFactory $connectors,
    ): int {
        if (($failure = $this->guardExtension()) !== null) {
            return $failure;
        }

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

        $cols = filter_var($this->option('cols'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 20, 'max_range' => 400]]);
        $rows = filter_var($this->option('rows'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 5, 'max_range' => 200]]);
        $origin = $this->stringOption('origin');

        if (! is_int($cols) || ! is_int($rows) || $origin === null
            || preg_match('/\Ahttps:\/\/[A-Za-z0-9](?:[A-Za-z0-9.-]*[A-Za-z0-9])?(?::[1-9][0-9]{0,4})?\z/D', $origin) !== 1) {
            return $this->renderGatewayFailure(
                'herdr.grant_invalid',
                'Observation viewport columns, rows, and a valid HTTPS origin are required.',
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
            new IssueObservationGrantRequest($listed->id, $pane, $terminal, $cols, $rows, $origin),
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
