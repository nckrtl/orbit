<?php

declare(strict_types=1);

namespace App\Commands\Tools;

use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use App\Support\Console\ProgressState;
use Laravel\Prompts\TextPrompt;
use Orbit\Sdk\GatewayApiException;
use Orbit\Sdk\GatewayConnector;
use Orbit\Sdk\Requests\Tools\InstallToolRequest;
use Orbit\Sdk\Requests\Tools\ListToolManagersRequest;
use Orbit\Sdk\Responses\Tools\ToolManagerResponse;
use Orbit\Sdk\Responses\Tools\ToolManagersResponse;
use Orbit\Sdk\Responses\Tools\ToolResponse;

final class InstallToolCommand extends ToolCommand
{
    #[\Override]
    protected $signature = 'tool:install
        {package? : Manager-native package coordinate}
        {--node= : Numeric target node ID}
        {--manager= : Tool manager name}
        {--constraint= : Optional SemVer safety constraint}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Install a tool through the gateway.';

    public function handle(
        GatewayConfigRepository $repository,
        GatewayConnectorFactory $connectors,
    ): int {
        $nodeId = $this->nodeId();
        if ($nodeId === null) {
            return self::FAILURE;
        }

        $connector = $this->gatewayConnector($repository, $connectors);
        if ($connector === null) {
            return self::FAILURE;
        }

        $manager = $this->stringOption('manager');
        if ($manager === null) {
            $manager = $this->promptForManager($connector, $nodeId);
            if ($manager === null) {
                return self::FAILURE;
            }
        }

        $package = $this->resolvePackage();
        if ($package === null) {
            return self::FAILURE;
        }

        $response = $this->sendWithProgress(
            $connector,
            new InstallToolRequest($nodeId, $manager, $package, $this->stringOption('constraint')),
            ToolResponse::class,
            ['Install Tool', 'Installing Tool', 'Installed Tool'],
            static function (object $response): ProgressState {
                if (! $response instanceof ToolResponse || ! in_array($response->outcome, ['applied', 'unchanged'], strict: true)) {
                    throw new GatewayApiException(
                        'Gateway response is invalid.',
                        'gateway.invalid_response',
                        requestId: $response instanceof ToolResponse ? $response->requestId : null,
                    );
                }

                return $response->outcome === 'unchanged' ? ProgressState::Skipped : ProgressState::Success;
            },
        );
        if (! $response instanceof ToolResponse) {
            return self::FAILURE;
        }

        $message = $response->outcome === 'applied'
            ? "Tool [{$response->package}] installed with [{$response->manager}]."
            : "Tool [{$response->package}] is already installed with [{$response->manager}].";

        return $this->renderTool($response, $message);
    }

    private function promptForManager(GatewayConnector $connector, int $nodeId): ?string
    {
        if (! $this->consoleMode()->mayPrompt) {
            $this->renderGatewayFailure('tool.manager_required', 'Tool manager is required.');

            return null;
        }

        $rows = $this->resolveManagerRows($connector, $nodeId);

        if ($rows === null) {
            return null;
        }

        return (string) $this->commandPrompts()->selectEntity('Tool manager', ['Name', 'Status'], $rows);
    }

    /**
     * Loads the manager data list for the prompt: eligible managers, sorted by name.
     * Renders the "no manager" failure and returns null when none are eligible.
     *
     * @return array<string, array{0: string, 1: string}>|null
     */
    protected function resolveManagerRows(GatewayConnector $connector, int $nodeId): ?array
    {
        $managers = $this->sendWithProgress(
            $connector,
            new ListToolManagersRequest($nodeId),
            ToolManagersResponse::class,
            ['List Tool managers', 'Loading Tool managers', 'Loaded Tool managers'],
        );
        if (! $managers instanceof ToolManagersResponse) {
            return null;
        }

        $rows = self::eligibleManagerRows($managers);

        if ($rows === []) {
            $this->renderGatewayFailure('tool.manager_required', 'No supported tool manager is available.');

            return null;
        }

        return $rows;
    }

    /**
     * Eligible means the manager can run an install: already active, or provisionable from uninstalled.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    private static function eligibleManagerRows(ToolManagersResponse $managers): array
    {
        $eligible = array_filter(
            $managers->managers,
            static fn (ToolManagerResponse $item): bool => in_array(
                $item->status,
                ['active', 'uninstalled'],
                strict: true,
            ),
        );

        $rows = [];
        foreach ($eligible as $item) {
            $rows[$item->name] = [$item->name, $item->status];
        }
        ksort($rows);

        return $rows;
    }

    private function resolvePackage(): ?string
    {
        $package = $this->argument('package');

        if ($package === null && $this->consoleMode()->mayPrompt) {
            $package = $this->commandPrompts()->run(fn (): TextPrompt => new TextPrompt(
                'Package',
                required: true,
                validate: self::packageError(...),
            ));
        }

        if (! is_string($package) || $package === '') {
            $this->renderGatewayFailure('tool.package_required', 'Package is required.');

            return null;
        }

        if (self::packageError($package) !== null) {
            $this->renderGatewayFailure('tool.package_invalid', 'Package is invalid.');

            return null;
        }

        return $package;
    }

    private static function packageError(string $value): ?string
    {
        if ($value === '') {
            return 'Package is required.';
        }

        return strlen($value) > 255 || preg_match('/[\x00-\x1F\x7F]/', $value) === 1
            ? 'Package is invalid.'
            : null;
    }
}
