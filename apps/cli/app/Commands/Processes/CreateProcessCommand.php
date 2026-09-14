<?php

declare(strict_types=1);

namespace App\Commands\Processes;

use App\Commands\Concerns\RendersAppRuntimeDefinitions;
use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use JsonException;
use Orbit\Sdk\Requests\Apps\CreateProcessDefinitionRequest;
use Orbit\Sdk\Requests\Processes\CreateProcessRequest;
use Orbit\Sdk\Responses\Apps\AppRuntimeDefinitionResponse;
use Orbit\Sdk\Responses\Processes\ProcessResponse;

final class CreateProcessCommand extends TargetedProcessCommand
{
    use RendersAppRuntimeDefinitions;

    #[\Override]
    protected $signature = 'process:create
        {name : Process name}
        {--instance= : Positive AppInstance ID}
        {--node= : Node ID or registered name}
        {--app= : Numeric App ID}
        {--for= : Comma-separated definition environments}
        {--runtime=systemd : systemd or docker}
        {--command=* : One command argument; repeat for each argv item}
        {--image= : Docker image}
        {--working-directory= : Runtime working directory}
        {--environment=* : Docker NAME=VALUE; repeat as needed}
        {--port=* : Docker HOST:CONTAINER[/tcp|udp]; repeat as needed}
        {--volume=* : Docker SOURCE:TARGET[:ro]; repeat as needed}
        {--restart=never : never, on-failure, always, or unless-stopped}
        {--start : Start after adding}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Create one systemd service, Docker container process, or App process definition.';

    public function handle(
        GatewayConfigRepository $repository,
        GatewayConnectorFactory $connectors,
    ): int {
        $name = $this->stringArgument('name', 'Process name', 'process.name_required');

        if ($name === null) {
            return self::FAILURE;
        }

        if (
            strlen($name) > 63
            || preg_match('/[\x00-\x1F\x7F]/', $name) === 1
        ) {
            return $this->renderGatewayFailure(
                'process.name_invalid',
                'Process name is invalid.',
            );
        }

        $runtime = $this->stringOption('runtime');

        if ($runtime === null || ! in_array($runtime, ['systemd', 'docker'], strict: true)) {
            return $this->renderGatewayFailure(
                'process.runtime_invalid',
                'Process runtime must be systemd or docker.',
            );
        }

        $command = $this->stringListOption('command');

        if (
            count($command) > 64
            || array_any(
                $command,
                static fn (string $argument): bool => strlen($argument) > 4096
                || preg_match('/[\x00\r\n]/', $argument) === 1,
            )
        ) {
            return $this->renderGatewayFailure(
                'process.command_invalid',
                'Process command arguments are invalid.',
            );
        }

        $restartPolicy = $this->stringOption('restart');

        if (
            $restartPolicy === null
            || ! in_array($restartPolicy, ['never', 'on-failure', 'always', 'unless-stopped'], strict: true)
        ) {
            return $this->renderGatewayFailure(
                'process.restart_policy_invalid',
                'Invalid process restart policy.',
            );
        }

        $image = $this->stringOption('image');

        if (
            $image !== null
            && (strlen($image) > 255
            || preg_match('/[\x00-\x1F\x7F]/', $image) === 1)
        ) {
            return $this->renderGatewayFailure(
                'process.image_invalid',
                'Docker image is invalid.',
            );
        }

        $workingDirectory = $this->stringOption('working-directory');

        if (
            $workingDirectory !== null
            && (strlen($workingDirectory) > 4096
            || preg_match('/[\x00-\x1F\x7F]/', $workingDirectory) === 1)
        ) {
            return $this->renderGatewayFailure(
                'process.working_directory_invalid',
                'Process working directory is invalid.',
            );
        }

        $environmentWasProvided = $this->input->hasParameterOption('--environment');
        $environment = $this->environment();

        if ($environment === null) {
            return self::FAILURE;
        }

        $volumesWereProvided = $this->input->hasParameterOption('--volume');
        $volumes = $this->volumes();

        if ($volumes === null) {
            return self::FAILURE;
        }

        $portsWereProvided = $this->input->hasParameterOption('--port');
        $ports = $this->ports();

        if ($ports === null) {
            return self::FAILURE;
        }

        $selector = $this->exclusiveProcessTarget();

        if ($selector === null) {
            return self::FAILURE;
        }

        if ($selector === 'app') {
            return $this->createDefinition(
                $repository,
                $connectors,
                $name,
                $runtime,
                $command,
                $image,
                $workingDirectory,
                $environmentWasProvided ? $environment : null,
                $portsWereProvided ? $ports : null,
                $volumesWereProvided ? $volumes : null,
                $restartPolicy,
            );
        }

        if ($this->stringOption('for') !== null) {
            return $this->renderGatewayFailure(
                'process.option_invalid',
                'The --for option requires --app.',
            );
        }

        $connector = $this->gatewayConnector($repository, $connectors);

        if ($connector === null) {
            return self::FAILURE;
        }

        $target = $this->processTarget($connector);

        if ($target === null) {
            return self::FAILURE;
        }

        $process = $this->send(
            $connector,
            new CreateProcessRequest(
                target: $target,
                name: $name,
                runtime: $runtime,
                command: $command,
                image: $image,
                workingDirectory: $workingDirectory,
                environment: $environmentWasProvided ? $environment : null,
                ports: $portsWereProvided ? $ports : null,
                volumes: $volumesWereProvided ? $volumes : null,
                restartPolicy: $restartPolicy,
                start: $this->option('start') === true,
            ),
            ProcessResponse::class,
        );

        if (! $process instanceof ProcessResponse) {
            return self::FAILURE;
        }

        if ($this->option('json') === true) {
            $this->writeJson($this->sanitizedProcessPayload($process->toArray()));

            return self::SUCCESS;
        }

        $this->info("Process [{$process->name}] is {$process->runtimeStatus}.");
        $this->line("Request ID: {$process->requestId}");

        return self::SUCCESS;
    }

    /**
     * @param  list<string>  $command
     * @param  array<string, string>|null  $environment
     * @param  list<string>|null  $ports
     * @param  list<array{source: string, target: string, read_only: bool}>|null  $volumes
     */
    private function createDefinition(
        GatewayConfigRepository $repository,
        GatewayConnectorFactory $connectors,
        string $name,
        string $runtime,
        array $command,
        ?string $image,
        ?string $workingDirectory,
        ?array $environment,
        ?array $ports,
        ?array $volumes,
        string $restartPolicy,
    ): int {
        if ($this->option('start') === true) {
            return $this->renderGatewayFailure(
                'process.option_invalid',
                'The --start option requires --instance or --node.',
            );
        }

        $appId = $this->appIdOption();
        $environments = $this->definitionEnvironments(required: true, errorCode: 'process.option_invalid');

        if ($appId === false || $appId === null || $environments === false || $environments === null) {
            return self::FAILURE;
        }

        $spec = [
            'runtime' => $runtime,
            'command' => $command,
            'restart_policy' => $restartPolicy,
        ];

        if ($image !== null) {
            $spec['image'] = $image;
        }

        if ($workingDirectory !== null) {
            $spec['working_directory'] = $workingDirectory;
        }

        if ($environment !== null) {
            $spec['environment'] = $environment;
        }

        if ($ports !== null) {
            $spec['ports'] = $ports;
        }

        if ($volumes !== null) {
            $spec['volumes'] = $volumes;
        }

        try {
            $definition = json_encode(
                [
                    'name' => $name,
                    'environments' => $environments,
                    'spec' => $spec,
                ],
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
            );
        } catch (JsonException) {
            return $this->renderGatewayFailure(
                'process.definition_invalid',
                'Process definition could not be encoded.',
            );
        }

        $connector = $this->gatewayConnector($repository, $connectors);

        if ($connector === null) {
            return self::FAILURE;
        }

        $response = $this->send(
            $connector,
            new CreateProcessDefinitionRequest($appId, $definition),
            AppRuntimeDefinitionResponse::class,
        );

        return $response instanceof AppRuntimeDefinitionResponse
            ? $this->renderDefinition($response, 'Process')
            : self::FAILURE;
    }
}
