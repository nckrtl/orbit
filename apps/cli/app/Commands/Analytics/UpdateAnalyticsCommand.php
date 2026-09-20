<?php

declare(strict_types=1);

namespace App\Commands\Analytics;

use App\Commands\GatewayCommand;
use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use App\Support\Console\ConsoleWriter;
use Laravel\Prompts\TextPrompt;
use Orbit\Sdk\Requests\Analytics\UpdateAnalyticsRequest;
use Orbit\Sdk\Responses\Analytics\AnalyticsUpdateResponse;

final class UpdateAnalyticsCommand extends GatewayCommand
{
    #[\Override]
    protected $signature = 'analytics:update
        {version? : Plausible Community Edition version, for example 3.2.1}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Pin another Plausible version for the analytics role.';

    public function handle(GatewayConfigRepository $repository, GatewayConnectorFactory $factory): int
    {
        $version = $this->argument('version');

        if ($version === null && $this->consoleMode()->mayPrompt) {
            $version = $this->commandPrompts()->run(fn (): TextPrompt => new TextPrompt(
                'Plausible version',
                placeholder: '3.2.1',
                required: true,
                validate: self::versionError(...),
            ));
        }

        if (! is_string($version) || self::versionError($version) !== null) {
            return $this->renderGatewayFailure(
                'analytics.version_invalid',
                'A Plausible version has the form 3.2.1.',
                details: ['field' => 'version'],
            );
        }

        $connector = $this->gatewayConnector($repository, $factory);

        if ($connector === null) {
            return self::FAILURE;
        }

        $response = $this->sendWithProgress(
            $connector,
            new UpdateAnalyticsRequest($version),
            AnalyticsUpdateResponse::class,
            ['Update Plausible', 'Replacing the plausible Process', 'Updated Plausible'],
        );

        if (! $response instanceof AnalyticsUpdateResponse) {
            return self::FAILURE;
        }

        if ($this->option('json') === true) {
            $this->writeJson($response->toArray());

            return self::SUCCESS;
        }

        ConsoleWriter::write($this->output, $this->humanRenderer()->detail(
            $response->version === $response->previousVersion
                ? "Plausible already runs {$response->version} on node [{$response->nodeName}]."
                : "Plausible updated on node [{$response->nodeName}].",
            [
                'Node' => "{$response->nodeName} (#{$response->nodeId})",
                'Version' => $response->version,
                'Previous version' => $response->previousVersion,
                'Request ID' => $response->requestId,
            ],
        ));

        return self::SUCCESS;
    }

    private static function versionError(string $version): ?string
    {
        return preg_match('/\A\d+\.\d+\.\d+\z/D', $version) === 1 ? null : 'Use three numbers, for example 3.2.1.';
    }
}
