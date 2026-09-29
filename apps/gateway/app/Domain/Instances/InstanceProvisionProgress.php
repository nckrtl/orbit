<?php

declare(strict_types=1);

namespace App\Domain\Instances;

use App\Models\Instance;

final readonly class InstanceProvisionProgress
{
    /** @var list<string> */
    private const array CompletedSteps = ['active'];

    public static function isInFlight(Instance $instance, InstanceState $settled): bool
    {
        if ($instance->status === InstanceState::Active || $instance->failed_step !== null) {
            return false;
        }

        return self::isBeforeSettled($instance->status, $settled)
            || ($instance->provisioning_step !== null && ! self::isCompletedStep($instance->provisioning_step));
    }

    public static function isCompletedStep(string $step): bool
    {
        return in_array($step, self::CompletedSteps, true);
    }

    private static function isBeforeSettled(InstanceState $status, InstanceState $settled): bool
    {
        $progression = [
            InstanceState::Reserved,
            InstanceState::CheckoutPrepared,
            InstanceState::SourceResolved,
            InstanceState::Active,
        ];

        $statusPosition = array_search($status, $progression, true);
        $settledPosition = array_search($settled, $progression, true);

        return is_int($statusPosition) && is_int($settledPosition) && $statusPosition < $settledPosition;
    }
}
