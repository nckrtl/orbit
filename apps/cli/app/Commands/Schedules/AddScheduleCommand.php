<?php

declare(strict_types=1);

namespace App\Commands\Schedules;

use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\Requests\Schedules\AddScheduleRequest;
use Orbit\Sdk\Requests\Schedules\AppInstanceScheduleTarget;
use Orbit\Sdk\Requests\Schedules\NodeScheduleTarget;
use Orbit\Sdk\Responses\Schedules\ScheduleResponse;

final class AddScheduleCommand extends ScheduleCommand
{
    #[\Override]
    protected $signature = 'schedule:add
        {name : Schedule name}
        {--node= : Positive Node ID}
        {--instance= : Positive AppInstance ID}
        {--calendar= : Native systemd calendar expression}
        {--command= : Command to run}
        {--timeout=3600 : Execution timeout in seconds, from 1 to 86400}
        {--no-start : Install an AppInstance Schedule with its timer disabled and stopped}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Add one Node or AppInstance Schedule through the Gateway.';

    public function handle(
        GatewayConfigRepository $repository,
        GatewayConnectorFactory $connectors,
    ): int {
        $target = $this->target();

        if (! $target instanceof NodeScheduleTarget && ! $target instanceof AppInstanceScheduleTarget) {
            return self::FAILURE;
        }

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

        $connector = $this->gatewayConnector($repository, $connectors);

        if ($connector === null) {
            return self::FAILURE;
        }

        $schedule = $this->send(
            $connector,
            new AddScheduleRequest(
                target: $target,
                name: $name,
                calendar: $calendar,
                command: $command,
                timeoutSeconds: $timeout,
                start: $this->option('no-start') === true ? false : null,
            ),
            ScheduleResponse::class,
        );

        if (! $schedule instanceof ScheduleResponse) {
            return self::FAILURE;
        }

        $this->renderSchedule($schedule);

        return self::SUCCESS;
    }

    private function target(): NodeScheduleTarget|AppInstanceScheduleTarget|null
    {
        $node = $this->option('node');
        $instance = $this->option('instance');

        if ($node === null && $instance === null) {
            $this->renderGatewayFailure(
                'schedule.target_required',
                'Exactly one of --node or --instance is required.',
            );

            return null;
        }

        if ($node !== null && $instance !== null) {
            $this->renderGatewayFailure(
                'schedule.target_conflict',
                'Use only one of --node or --instance.',
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
            'AppInstance',
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
