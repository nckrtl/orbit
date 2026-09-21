<?php

declare(strict_types=1);

namespace App\Commands\Schedules;

use App\Commands\Concerns\RendersAppRuntimeDefinitions;
use App\Commands\Concerns\SelectsAppDefinitionTarget;
use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use JsonException;
use Orbit\Sdk\Requests\Apps\CreateScheduleDefinitionRequest;
use Orbit\Sdk\Requests\Schedules\AppInstanceScheduleTarget;
use Orbit\Sdk\Requests\Schedules\CreateScheduleRequest;
use Orbit\Sdk\Requests\Schedules\NodeScheduleTarget;
use Orbit\Sdk\Responses\Apps\AppRuntimeDefinitionResponse;
use Orbit\Sdk\Responses\Schedules\ScheduleResponse;

final class CreateScheduleCommand extends ScheduleCommand
{
    use RendersAppRuntimeDefinitions;
    use SelectsAppDefinitionTarget;

    #[\Override]
    protected $signature = 'schedule:create
        {name : Schedule name}
        {--node= : Positive Node ID}
        {--instance= : Positive Instance ID}
        {--project= : Numeric Project ID}
        {--app= : Numeric Project ID (compatibility)}
        {--for= : Comma-separated definition environments}
        {--calendar= : Native systemd calendar expression}
        {--command= : Command to run}
        {--timeout=3600 : Execution timeout in seconds, from 1 to 86400}
        {--no-start : Install an Instance Schedule with its timer disabled and stopped}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Create one Node or Instance Schedule, or a Project Schedule definition.';

    public function handle(
        GatewayConfigRepository $repository,
        GatewayConnectorFactory $connectors,
    ): int {
        $name = $this->stringArgument('name', 'Schedule name', 'schedule.name_required');
        $calendar = $this->stringOption('calendar');
        $command = $this->stringOption('command');
        $timeout = filter_var(
            $this->option('timeout'),
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1, 'max_range' => 86_400]],
        );

        if (
            $name === null
            || strlen($name) > 63
            || preg_match('/\A[a-z0-9]+(?:-[a-z0-9]+)*\z/D', $name) !== 1
        ) {
            return $this->renderGatewayFailure('schedule.name_invalid', 'Schedule name is invalid.');
        }

        if (
            $calendar === null
            || strlen($calendar) > 255
            || preg_match('/\A[\x20-\x7E]+\z/D', $calendar) !== 1
        ) {
            return $this->renderGatewayFailure('schedule.calendar_invalid', 'Schedule calendar is invalid.');
        }

        if (
            $command === null
            || strlen($command) > 4096
            || preg_match('/[\x00\r\n]/', $command) === 1
        ) {
            return $this->renderGatewayFailure('schedule.command_invalid', 'Schedule command is invalid.');
        }

        if (! is_int($timeout)) {
            return $this->renderGatewayFailure(
                'schedule.timeout_invalid',
                'Schedule timeout must be between 1 and 86400 seconds.',
            );
        }

        $selector = $this->exclusiveScheduleTarget();

        if ($selector === null) {
            return self::FAILURE;
        }

        if ($selector === 'app') {
            return $this->createDefinition($repository, $connectors, $name, $calendar, $command, $timeout);
        }

        if ($this->stringOption('for') !== null) {
            return $this->renderGatewayFailure(
                'schedule.option_invalid',
                'The --for option requires --project or --app.',
            );
        }

        $target = $this->target();

        if (! $target instanceof NodeScheduleTarget && ! $target instanceof AppInstanceScheduleTarget) {
            return self::FAILURE;
        }

        $connector = $this->gatewayConnector($repository, $connectors);

        if ($connector === null) {
            return self::FAILURE;
        }

        $schedule = $this->sendWithProgress(
            $connector,
            new CreateScheduleRequest(
                target: $target,
                name: $name,
                calendar: $calendar,
                command: $command,
                timeoutSeconds: $timeout,
                start: $this->option('no-start') === true ? false : null,
            ),
            ScheduleResponse::class,
            ['Create Schedule', 'Creating Schedule', 'Created Schedule'],
        );

        if (! $schedule instanceof ScheduleResponse) {
            return self::FAILURE;
        }

        $this->renderSchedule($schedule);

        return self::SUCCESS;
    }

    /** @return 'app'|'instance'|'node'|null */
    private function exclusiveScheduleTarget(): ?string
    {
        $hasProject = $this->providedProjectOption();
        $hasInstance = $this->providedOption('instance');
        $hasNode = $this->providedOption('node');
        $count = (int) $hasProject + (int) $hasInstance + (int) $hasNode;

        if ($this->providedOption('project') && $this->providedOption('app')) {
            $this->renderGatewayFailure(
                'schedule.target_conflict',
                'Use only one of --project or --app.',
            );

            return null;
        }

        if ($count > 1) {
            $this->renderGatewayFailure(
                'schedule.target_conflict',
                'Use only one of --project, --app, --node, or --instance.',
            );

            return null;
        }

        if ($count === 0) {
            $this->renderGatewayFailure(
                'schedule.target_required',
                'Exactly one of --project, --app, --node, or --instance is required.',
            );

            return null;
        }

        return match (true) {
            $hasProject => 'app',
            $hasInstance => 'instance',
            default => 'node',
        };
    }

    private function createDefinition(
        GatewayConfigRepository $repository,
        GatewayConnectorFactory $connectors,
        string $name,
        string $calendar,
        string $command,
        int $timeout,
    ): int {
        if ($this->option('no-start') === true) {
            return $this->renderGatewayFailure(
                'schedule.option_invalid',
                'The --no-start option requires --instance.',
            );
        }

        $appId = $this->appIdOption();

        if ($appId === false) {
            return self::FAILURE;
        }

        if ($appId === null) {
            return $this->renderGatewayFailure(
                'schedule.target_required',
                'The --project or --app option is required.',
            );
        }

        $environments = $this->definitionEnvironments(errorCode: 'schedule.option_invalid');

        if ($environments === false) {
            return self::FAILURE;
        }

        try {
            $definition = json_encode(
                [
                    'name' => $name,
                    'environments' => $environments,
                    'spec' => [
                        'command' => $command,
                        'calendar' => $calendar,
                        'timeout_seconds' => $timeout,
                    ],
                ],
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
            );
        } catch (JsonException) {
            return $this->renderGatewayFailure(
                'schedule.definition_invalid',
                'Schedule definition could not be encoded.',
            );
        }

        $connector = $this->gatewayConnector($repository, $connectors);

        if ($connector === null) {
            return self::FAILURE;
        }

        $response = $this->sendWithProgress(
            $connector,
            new CreateScheduleDefinitionRequest($appId, $definition),
            AppRuntimeDefinitionResponse::class,
            ['Create Schedule definition', 'Creating Schedule definition', 'Created Schedule definition'],
        );

        return $response instanceof AppRuntimeDefinitionResponse
            ? $this->renderDefinition($response, 'Schedule')
            : self::FAILURE;
    }

    private function target(): NodeScheduleTarget|AppInstanceScheduleTarget|null
    {
        $node = $this->option('node');
        $instance = $this->option('instance');

        if ($node !== null && $instance !== null) {
            $this->renderGatewayFailure(
                'schedule.target_conflict',
                'Use only one of --project, --app, --node, or --instance.',
            );

            return null;
        }

        if ($node !== null) {
            if ($this->option('no-start') === true) {
                $this->renderGatewayFailure(
                    'schedule.option_invalid',
                    'The --no-start option requires --instance.',
                );

                return null;
            }

            $nodeId = $this->positiveOptionId($node, 'Node', 'schedule.node_id_invalid');

            return $nodeId === null ? null : new NodeScheduleTarget($nodeId);
        }

        $instanceId = $this->positiveOptionId(
            $instance,
            'Instance',
            'schedule.instance_id_invalid',
        );

        return $instanceId === null ? null : new AppInstanceScheduleTarget($instanceId);
    }

    private function positiveOptionId(mixed $value, string $label, string $errorCode): ?int
    {
        if (is_int($value)) {
            $value = (string) $value;
        }

        if (! is_string($value) || preg_match('/\A[1-9][0-9]*\z/D', $value) !== 1) {
            $this->renderGatewayFailure($errorCode, "{$label} ID must be a positive integer.");

            return null;
        }

        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        if (! is_int($id)) {
            $this->renderGatewayFailure($errorCode, "{$label} ID must be a positive integer.");

            return null;
        }

        return $id;
    }
}
