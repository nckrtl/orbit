<?php

declare(strict_types=1);

namespace App\Commands\Tools;

use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use App\Support\Console\ProgressOutcome;
use App\Support\Console\ProgressState;
use Orbit\Sdk\GatewayApiException;
use Orbit\Sdk\Requests\Tools\AdoptToolRequest;
use Orbit\Sdk\Responses\Tools\ToolResponse;

final class AdoptToolCommand extends ToolCommand
{
    #[\Override]
    protected $signature = 'tool:adopt
        {package : Manager-native package coordinate}
        {--node= : Numeric target node ID}
        {--manager= : Tool manager name}
        {--constraint= : Optional SemVer safety constraint}
        {--yes : Skip the ownership confirmation prompt}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Adopt one installed package without changing it.';

    public function handle(
        GatewayConfigRepository $repository,
        GatewayConnectorFactory $connectors,
    ): int {
        $nodeId = $this->nodeId();
        if ($nodeId === null) {
            return self::FAILURE;
        }

        $package = $this->packageName();
        if ($package === null) {
            return self::FAILURE;
        }

        $manager = $this->stringOption('manager');
        if ($manager === null) {
            $this->renderGatewayFailure('tool.manager_required', 'Tool manager is required.');

            return self::FAILURE;
        }

        $constraint = $this->stringOption('constraint');
        $connector = $this->gatewayConnector($repository, $connectors);
        if ($connector === null) {
            return self::FAILURE;
        }

        $constraintText = $constraint === null ? '' : ", constraint {$constraint}";
        if (! $this->confirmAction(
            "Take ownership of package [{$package}] ({$manager} on Node #{$nodeId}{$constraintText}) and manage later updates and removal?",
            'Tool adoption cancelled.',
        )) {
            return self::FAILURE;
        }

        $response = $this->sendWithProgress(
            $connector,
            new AdoptToolRequest($nodeId, $manager, $package, $constraint),
            ToolResponse::class,
            ['Adopt Tool', 'Adopting Tool', 'Adopted Tool'],
            static function (ToolResponse $response): ProgressState|ProgressOutcome {
                if (! in_array($response->outcome, ['applied', 'unchanged'], strict: true)) {
                    throw new GatewayApiException(
                        'Gateway response is invalid.',
                        'gateway.invalid_response',
                        requestId: $response->requestId,
                    );
                }

                return $response->outcome === 'unchanged'
                    ? new ProgressOutcome(ProgressState::Skipped, 'Ownership unchanged')
                    : ProgressState::Success;
            },
        );
        if (! $response instanceof ToolResponse) {
            return self::FAILURE;
        }

        $message = $response->outcome === 'applied'
            ? "Tool [{$response->package}] adopted with [{$response->manager}]."
            : "Tool [{$response->package}] already has this ownership with [{$response->manager}].";

        return $this->renderTool($response, $message);
    }

    private function packageName(): ?string
    {
        $package = $this->argument('package');

        if ($package === '') {
            $this->renderGatewayFailure('tool.package_required', 'Package is required.');

            return null;
        }

        if (strlen($package) > 255 || preg_match('/[\x00-\x1F\x7F]/', $package) === 1) {
            $this->renderGatewayFailure('tool.package_invalid', 'Package is invalid.');

            return null;
        }

        return $package;
    }
}
