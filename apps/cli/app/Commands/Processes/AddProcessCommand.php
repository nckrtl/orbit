<?php

declare(strict_types=1);

namespace App\Commands\Processes;

use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\Requests\Processes\AddProcessRequest;
use Orbit\Sdk\Responses\Processes\ProcessResponse;

/**
 * @mago-expect lint:cyclomatic-complexity The command validates one explicit option shape before transport.
 * @mago-expect lint:halstead The option contract maps two runtime shapes to one typed request.
 * @mago-expect lint:kan-defect Each branch fails fast before the one HTTP request.
 */
final class AddProcessCommand extends ProcessCommand
{
    #[\Override]
    protected $signature = 'process:add
        {name : Process name}
        {--instance= : Numeric instance ID}
        {--workspace= : Numeric workspace ID}
        {--runtime= : Runtime such as systemd, launchd, or docker}
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
    protected $description = 'Add one systemd service or Docker container process.';

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

        $target = $this->target();

        if ($target === null) {
            return self::FAILURE;
        }

        $runtimeWasProvided = $this->input->hasParameterOption('--runtime');
        $runtimeValue = $this->option('runtime');
        $runtime = $runtimeWasProvided && is_string($runtimeValue) ? $runtimeValue : null;

        if (
            $runtimeWasProvided
            && (! is_string($runtimeValue)
            || strlen($runtimeValue) > 64
            || preg_match('//u', $runtimeValue) !== 1
            || preg_match('/\p{Cc}/u', $runtimeValue) === 1)
        ) {
            return $this->renderGatewayFailure(
                'process.runtime_invalid',
                'Process runtime is invalid.',
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

        $connector = $this->gatewayConnector($repository, $connectors);

        if ($connector === null) {
            return self::FAILURE;
        }

        $process = $this->send(
            $connector,
            new AddProcessRequest(
                targetType: $target['type'],
                targetId: $target['id'],
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

    /** @return array<string, string>|null */
    private function environment(): ?array
    {
        $environment = [];
        $values = $this->stringListOption('environment');

        if (count($values) > 100) {
            $this->renderInvalidEnvironment();

            return null;
        }

        foreach ($values as $value) {
            [$name, $item] = array_pad(explode('=', $value, limit: 2), length: 2, value: null);

            if (
                ! is_string($name)
                || ! is_string($item)
                || strlen($item) > 4096
                || preg_match('/\A[A-Za-z_][A-Za-z0-9_]*\z/D', $name) !== 1
                || preg_match('/[\x00\r\n]/', $value) === 1
            ) {
                $this->renderInvalidEnvironment();

                return null;
            }

            $environment[$name] = $item;
        }

        return $environment;
    }

    /** @return list<array{source: string, target: string, read_only: bool}>|null */
    private function volumes(): ?array
    {
        $volumes = [];
        $values = $this->stringListOption('volume');

        if (count($values) > 100) {
            $this->renderInvalidVolume();

            return null;
        }

        foreach ($values as $value) {
            $segments = explode(':', $value);
            $readOnly = ($segments[2] ?? null) === 'ro';
            $source = $segments[0];
            $target = $segments[1] ?? '';

            if (
                preg_match('/[\x00\r\n]/', $value) === 1
                || ! in_array(count($segments), [2, 3], strict: true)
                || strlen($source) > 4096
                || strlen($target) > 4096
                || ($segments[2] ?? null) !== null
                && ! $readOnly
            ) {
                $this->renderInvalidVolume();

                return null;
            }

            $volumes[] = [
                'source' => $segments[0],
                'target' => $segments[1],
                'read_only' => $readOnly,
            ];
        }

        return $volumes;
    }

    /** @return list<string>|null */
    private function ports(): ?array
    {
        $ports = $this->stringListOption('port');

        if (
            count($ports) > 100
            || array_any(
                $ports,
                static fn (string $port): bool => strlen($port) > 4096
                || preg_match('/[\x00-\x1F\x7F]/', $port) === 1,
            )
        ) {
            $this->renderInvalidPort();

            return null;
        }

        return $ports;
    }

    private function renderInvalidEnvironment(): void
    {
        $this->renderGatewayFailure(
            'process.environment_invalid',
            'Invalid environment value. Use NAME=VALUE.',
        );
    }

    private function renderInvalidPort(): void
    {
        $this->renderGatewayFailure(
            'process.port_invalid',
            'Process port value is invalid.',
        );
    }

    private function renderInvalidVolume(): void
    {
        $this->renderGatewayFailure(
            'process.volume_invalid',
            'Invalid volume. Use SOURCE:TARGET[:ro].',
        );
    }
}
