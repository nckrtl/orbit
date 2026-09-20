<?php

declare(strict_types=1);

namespace App\Commands\Analytics;

use App\Commands\GatewayCommand;
use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use App\Support\Console\ConsoleWriter;
use Laravel\Prompts\PasswordPrompt;
use Orbit\Sdk\Requests\Analytics\SetAnalyticsCredentialsRequest;
use Orbit\Sdk\Requests\Analytics\ShowAnalyticsCredentialsRequest;
use Orbit\Sdk\Requests\Analytics\UnsetAnalyticsCredentialsRequest;
use Orbit\Sdk\Responses\Analytics\AnalyticsCredentialsResponse;

final class CredentialsAnalyticsCommand extends GatewayCommand
{
    #[\Override]
    protected $signature = 'analytics:credentials
        {--set : Store a Plausible Stats API key}
        {--api-key= : The key to store; prompted when --set is used in a terminal}
        {--unset : Clear the stored key}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Show whether a Plausible Stats API key is stored, store one, or clear it.';

    public function handle(GatewayConfigRepository $repository, GatewayConnectorFactory $factory): int
    {
        $set = $this->option('set') === true;
        $unset = $this->option('unset') === true;

        if ($set && $unset) {
            return $this->renderGatewayFailure(
                'analytics.credentials_conflict',
                'Use --set or --unset, not both.',
            );
        }

        $connector = $this->gatewayConnector($repository, $factory);

        if ($connector === null) {
            return self::FAILURE;
        }

        if ($unset) {
            $response = $this->sendWithProgress(
                $connector,
                new UnsetAnalyticsCredentialsRequest,
                AnalyticsCredentialsResponse::class,
                ['Clear analytics credentials', 'Clearing the Stats API key', 'Cleared the Stats API key'],
            );

            return $this->render($response, 'The Plausible Stats API key is no longer stored.');
        }

        if ($set) {
            $key = $this->apiKey();

            if ($key === null) {
                return self::FAILURE;
            }

            $response = $this->sendWithProgress(
                $connector,
                new SetAnalyticsCredentialsRequest($key),
                AnalyticsCredentialsResponse::class,
                ['Store analytics credentials', 'Storing the Stats API key', 'Stored the Stats API key'],
            );

            return $this->render($response, 'The Plausible Stats API key is stored.');
        }

        $response = $this->sendWithProgress(
            $connector,
            new ShowAnalyticsCredentialsRequest,
            AnalyticsCredentialsResponse::class,
            ['Show analytics credentials', 'Loading credential status', 'Loaded credential status'],
        );

        return $this->render(
            $response,
            $response instanceof AnalyticsCredentialsResponse && $response->configured
                ? 'A Plausible Stats API key is stored.'
                : 'No Plausible Stats API key is stored.',
        );
    }

    private function apiKey(): ?string
    {
        $key = $this->option('api-key');

        if (is_string($key) && $key !== '') {
            return $this->validKey($key);
        }

        if ($this->consoleMode()->mayPrompt) {
            $key = $this->commandPrompts()->run(fn (): PasswordPrompt => new PasswordPrompt(
                'Plausible Stats API key',
                required: true,
            ));

            return is_string($key) ? $this->validKey($key) : null;
        }

        $this->renderGatewayFailure(
            'analytics.api_key_invalid',
            'Provide --api-key, or run this command in a terminal.',
            details: ['field' => 'api_key'],
        );

        return null;
    }

    private function validKey(string $key): ?string
    {
        if (strlen($key) < 8 || strlen($key) > 512 || preg_match('/[\x00-\x1F\x7F]/', $key) === 1) {
            $this->renderGatewayFailure(
                'analytics.api_key_invalid',
                'A Stats API key is 8 to 512 characters with no control characters.',
                details: ['field' => 'api_key'],
            );

            return null;
        }

        return $key;
    }

    private function render(?AnalyticsCredentialsResponse $response, string $title): int
    {
        if (! $response instanceof AnalyticsCredentialsResponse) {
            return self::FAILURE;
        }

        if ($this->option('json') === true) {
            $this->writeJson($response->toArray());

            return self::SUCCESS;
        }

        ConsoleWriter::write($this->output, $this->humanRenderer()->detail($title, [
            'Stored' => $response->configured ? 'yes' : 'no',
            'Driver' => $response->driver,
            'Request ID' => $response->requestId,
        ]));

        return self::SUCCESS;
    }
}
