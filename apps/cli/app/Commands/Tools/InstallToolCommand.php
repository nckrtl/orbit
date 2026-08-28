<?php

declare(strict_types=1);

namespace App\Commands\Tools;

use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
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
        {--manager= : apt, vp, or composer}
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
            if (! $this->mayPrompt()) {
                return $this->renderGatewayFailure('tool.manager_required', 'Tool manager is required.');
            }

            $managers = $this->send($connector, new ListToolManagersRequest($nodeId), ToolManagersResponse::class);
            if (! $managers instanceof ToolManagersResponse) {
                return self::FAILURE;
            }

            $names = array_values(array_unique(array_map(
                static fn (ToolManagerResponse $item): string => $item->name,
                array_filter(
                    $managers->managers,
                    static fn (ToolManagerResponse $item): bool => $item->status === 'active',
                ),
            )));
            sort($names);
            if ($names === []) {
                return $this->renderGatewayFailure('tool.manager_required', 'No active tool manager is available.');
            }
            $manager = $this->chooseString('Tool manager', $names);
            if ($manager === null) {
                return $this->renderGatewayFailure(
                    'gateway.invalid_response',
                    'Gateway response is invalid.',
                );
            }
        }

        $package = $this->promptedStringArgument(
            'package',
            'Package',
            'tool.package_required',
            'Package is required.',
        );
        if ($package === null) {
            return self::FAILURE;
        }
        if (strlen($package) > 255 || preg_match('/[\x00-\x1F\x7F]/', $package) === 1) {
            return $this->renderGatewayFailure('tool.package_invalid', 'Package is invalid.');
        }

        $response = $this->send(
            $connector,
            $this->request($nodeId, $manager, $package, $this->stringOption('constraint')),
            ToolResponse::class,
        );
        if (! $response instanceof ToolResponse) {
            return self::FAILURE;
        }

        $message = match ($response->outcome) {
            'applied' => "Tool [{$response->package}] installed with [{$response->manager}].",
            'unchanged' => "Tool [{$response->package}] is already installed with [{$response->manager}].",
            default => null,
        };

        if ($message === null) {
            return $this->renderGatewayFailure(
                'gateway.invalid_response',
                'Gateway response is invalid.',
                $response->requestId,
            );
        }

        if ($this->option('json') === true) {
            $this->writeToolJson($response);

            return self::SUCCESS;
        }

        $this->info($message);
        $this->line("Request ID: {$response->requestId}");

        return self::SUCCESS;
    }

    protected function request(int $nodeId, string $manager, string $package, ?string $constraint): InstallToolRequest
    {
        return new InstallToolRequest($nodeId, $manager, $package, $constraint);
    }
}
