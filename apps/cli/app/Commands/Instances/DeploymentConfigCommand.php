<?php

declare(strict_types=1);

namespace App\Commands\Instances;

use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use JsonException;
use Orbit\Sdk\Requests\Deployments\DeploymentStepInput;
use Orbit\Sdk\Requests\Deployments\ShowAppInstanceDeploymentConfigRequest;
use Orbit\Sdk\Requests\Deployments\UpdateAppInstanceDeploymentConfigRequest;
use Orbit\Sdk\Responses\Deployments\DeploymentConfigResponse;
use Orbit\Sdk\Responses\Deployments\DeploymentStepResponse;
use SensitiveParameter;

final class DeploymentConfigCommand extends DeploymentCommand
{
    private const int MAXIMUM_CONFIG_BYTES = 1024 * 1024;

    #[\Override]
    protected $signature = 'instance:deployment-config
        {instance : Numeric instance ID}
        {--file= : Complete JSON deployment configuration file}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Show or replace a production AppInstance deployment configuration.';

    public function handle(GatewayConfigRepository $repository, GatewayConnectorFactory $connectors): int
    {
        $instanceId = $this->positiveId('instance', 'Instance', 'instance.id_invalid');

        if ($instanceId === null) {
            return self::FAILURE;
        }

        $file = $this->option('file');

        if ($file === '') {
            return $this->invalidConfigFile();
        }

        $connector = $this->gatewayConnector($repository, $connectors);

        if ($connector === null) {
            return self::FAILURE;
        }

        if ($file !== null) {
            $config = $this->readConfig($file);

            if ($config === null) {
                return self::FAILURE;
            }

            $request = new UpdateAppInstanceDeploymentConfigRequest(
                appInstanceId: $instanceId,
                branch: $config['branch'],
                steps: $config['steps'],
            );
        } else {
            $request = new ShowAppInstanceDeploymentConfigRequest($instanceId);
        }

        $response = $this->send($connector, $request, DeploymentConfigResponse::class);

        if (! $response instanceof DeploymentConfigResponse) {
            return self::FAILURE;
        }

        if ($this->option('json') === true) {
            $this->writeJson($response->toArray());

            return self::SUCCESS;
        }

        $this->line('Branch: '.$this->terminalValue($response->branch));
        $this->line('Steps:');

        if ($response->steps === []) {
            $this->line('- none');
        }

        foreach ($response->steps as $step) {
            $this->renderStep($step);
        }

        $this->line('Request ID: '.$response->requestId);

        return self::SUCCESS;
    }

    /** @return array{branch: string, steps: list<DeploymentStepInput>}|null */
    private function readConfig(#[SensitiveParameter] string $path): ?array
    {
        if (! is_file($path) || ! is_readable($path)) {
            $this->invalidConfigFile();

            return null;
        }

        $size = filesize($path);

        if (! is_int($size) || $size > self::MAXIMUM_CONFIG_BYTES) {
            $this->invalidConfigFile();

            return null;
        }

        $contents = file_get_contents($path);

        if (! is_string($contents)) {
            $this->invalidConfigFile();

            return null;
        }

        try {
            $config = json_decode($contents, associative: true, depth: 16, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $this->invalidConfigFile();

            return null;
        }

        if (! is_array($config) || array_is_list($config) || ! $this->hasExactFields($config, ['branch', 'steps'])) {
            $this->invalidConfigFile();

            return null;
        }

        $branch = $config['branch'];
        $steps = $config['steps'];

        if (! is_string($branch) || ! is_array($steps) || ! array_is_list($steps)) {
            $this->invalidConfigFile();

            return null;
        }

        $inputs = [];

        foreach ($steps as $step) {
            $input = $this->stepInput($step);

            if (! $input instanceof DeploymentStepInput) {
                $this->invalidConfigFile();

                return null;
            }

            $inputs[] = $input;
        }

        return ['branch' => $branch, 'steps' => $inputs];
    }

    private function stepInput(#[SensitiveParameter] mixed $step): ?DeploymentStepInput
    {
        if (! is_array($step) || array_is_list($step)) {
            return null;
        }

        $expected = ['name', 'phase', 'command'];

        if (array_key_exists('timeout_seconds', $step)) {
            $expected[] = 'timeout_seconds';
        }

        if (! $this->hasExactFields($step, $expected)) {
            return null;
        }

        $name = $step['name'];
        $phase = $step['phase'];
        $command = $step['command'];
        $timeout = $step['timeout_seconds'] ?? null;

        if (
            ! is_string($name)
            || ! is_string($phase)
            || ! is_string($command)
            || ($timeout !== null && ! is_int($timeout))
        ) {
            return null;
        }

        return new DeploymentStepInput($name, $phase, $command, $timeout);
    }

    private function renderStep(DeploymentStepResponse $step): void
    {
        $this->line('- Name: '.$this->terminalValue($step->name));
        $this->line('  Phase: '.$step->phase);
        $this->line('  Command: '.$this->terminalValue($step->command));
        $this->line("  Timeout: {$step->timeoutSeconds} seconds");
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @param  list<string>  $expected
     */
    private function hasExactFields(#[SensitiveParameter] array $data, array $expected): bool
    {
        return count($data) === count($expected) && array_all(
            array_keys($data),
            static fn (mixed $key): bool => is_string($key) && in_array($key, $expected, strict: true),
        );
    }

    private function invalidConfigFile(): int
    {
        return $this->renderGatewayFailure(
            'deployment.config_file_invalid',
            'Deployment configuration file is invalid.',
        );
    }
}
