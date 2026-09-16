<?php

declare(strict_types=1);

namespace App\Commands\Schedules;

use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\Schedules\ScheduleResponse;

abstract class ScheduleItemCommand extends ScheduleUuidCommand
{
    public function handle(
        GatewayConfigRepository $repository,
        GatewayConnectorFactory $connectors,
    ): int {
        $scheduleId = $this->scheduleId();

        if ($scheduleId === null) {
            return self::FAILURE;
        }

        $connector = $this->gatewayConnector($repository, $connectors);

        if ($connector === null) {
            return self::FAILURE;
        }

        $schedule = $this->sendWithProgress(
            $connector,
            $this->request($scheduleId),
            ScheduleResponse::class,
            $this->progressLabels(),
        );

        if (! $schedule instanceof ScheduleResponse) {
            return self::FAILURE;
        }

        $this->renderSchedule($schedule);

        return self::SUCCESS;
    }

    abstract protected function request(string $scheduleId): GatewayRequest;

    /** @return array{0: string, 1: string, 2: string} */
    abstract protected function progressLabels(): array;
}
