<?php

declare(strict_types=1);

namespace App\Commands\Schedules;

use App\Commands\Concerns\RendersProjectRuntimeDefinitions;
use App\Commands\Concerns\SelectsProjectDefinitionTarget;
use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use JsonException;
use Orbit\Sdk\Requests\Projects\UpdateScheduleDefinitionRequest;
use Orbit\Sdk\Responses\Projects\ProjectRuntimeDefinitionResponse;

final class UpdateScheduleCommand extends ScheduleCommand
{
    use RendersProjectRuntimeDefinitions;
    use SelectsProjectDefinitionTarget;

    #[\Override]
    protected $signature = 'schedule:update
        {name : Schedule definition name}
        {--project= : Numeric Project ID}
        {--app= : App name; required when the Project has several apps}
        {--for= : Comma-separated definition environments}
        {--calendar= : Native systemd calendar expression}
        {--command= : Command to run}
        {--timeout=3600 : Execution timeout in seconds, from 1 to 86400}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Replace one Project Schedule definition.';

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

        $projectId = $this->projectIdOption();

        if ($projectId === false) {
            return self::FAILURE;
        }

        if ($projectId === null) {
            return $this->renderGatewayFailure(
                'schedule.target_required',
                'The --project option is required.',
            );
        }

        $environments = $this->definitionEnvironments(errorCode: 'schedule.option_invalid');

        if ($environments === false) {
            return self::FAILURE;
        }

        try {
            $definition = json_encode(
                [
                    ...($this->stringOption('app') === null ? [] : ['app' => $this->stringOption('app')]),
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
            new UpdateScheduleDefinitionRequest($projectId, $name, $definition),
            ProjectRuntimeDefinitionResponse::class,
            ['Update Schedule definition', 'Updating Schedule definition', 'Updated Schedule definition'],
        );

        return $response instanceof ProjectRuntimeDefinitionResponse
            ? $this->renderDefinition($response, 'Schedule')
            : self::FAILURE;
    }
}
