<?php

declare(strict_types=1);

namespace App\Commands\Processes;

use App\Commands\Concerns\RendersAppRuntimeDefinitions;
use App\Commands\Concerns\SelectsAppDefinitionTarget;
use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use JsonException;
use Orbit\Sdk\Requests\Apps\UpdateProcessDefinitionRequest;
use Orbit\Sdk\Responses\Apps\AppRuntimeDefinitionResponse;

final class UpdateProcessCommand extends ProcessCommand
{
    use RendersAppRuntimeDefinitions;
    use SelectsAppDefinitionTarget;

    #[\Override]
    protected $signature = 'process:update
        {name : Process definition name}
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
        {--keep-alive : Keep running through app-dev idle hibernation}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Replace one App process definition.';

    public function handle(
        GatewayConfigRepository $repository,
        GatewayConnectorFactory $connectors,
    ): int {
        $name = $this->stringArgument('name', 'Process definition name', 'process.name_required');

        if ($name === null) {
            return self::FAILURE;
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
        $workingDirectory = $this->stringOption('working-directory');
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

        $appId = $this->appIdOption();

        if ($appId === false) {
            return self::FAILURE;
        }

        if ($appId === null) {
            return $this->renderGatewayFailure(
                'process.target_invalid',
                'The --app option is required.',
            );
        }

        $environments = $this->definitionEnvironments(errorCode: 'process.option_invalid');

        if ($environments === false) {
            return self::FAILURE;
        }

        $spec = [
            'runtime' => $runtime,
            'command' => $command,
            'restart_policy' => $restartPolicy,
            'keep_alive' => $this->option('keep-alive') === true,
        ];

        if ($image !== null) {
            $spec['image'] = $image;
        }

        if ($workingDirectory !== null) {
            $spec['working_directory'] = $workingDirectory;
        }

        if ($environmentWasProvided) {
            $spec['environment'] = $environment;
        }

        if ($portsWereProvided) {
            $spec['ports'] = $ports;
        }

        if ($volumesWereProvided) {
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
            new UpdateProcessDefinitionRequest($appId, $name, $definition),
            AppRuntimeDefinitionResponse::class,
        );

        return $response instanceof AppRuntimeDefinitionResponse
            ? $this->renderDefinition($response, 'Process')
            : self::FAILURE;
    }
}
