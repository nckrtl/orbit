<?php

declare(strict_types=1);

namespace App\Domain\Doctor;

use InvalidArgumentException;

final readonly class ProcessInspectionData
{
    /** @param list<SystemdProcessObservationData> $systemdObservations */
    public function __construct(
        public bool $present,
        public ?ProcessInspectionStatus $status,
        public array $systemdObservations = [],
    ) {
        if ($present !== ($status !== null)) {
            throw new InvalidArgumentException('A present process needs a bounded status.');
        }

        if (count($systemdObservations) > 2 || (! $present && $systemdObservations !== [])) {
            throw new InvalidArgumentException('Systemd process evidence needs at most two present observations.');
        }
    }

    public function isCrashLoop(): bool
    {
        foreach ($this->systemdObservations as $observation) {
            if ($observation->isAutoRestart()) {
                return true;
            }
        }

        if (count($this->systemdObservations) !== 2) {
            return false;
        }

        [$before, $after] = $this->systemdObservations;

        return $before->restarts !== null && $after->restarts !== null && $after->restarts > $before->restarts;
    }

    public function crashLoopEvidence(): string
    {
        return implode(' -> ', array_map(
            static fn (SystemdProcessObservationData $observation): string => $observation->evidence(),
            $this->systemdObservations,
        ));
    }
}
