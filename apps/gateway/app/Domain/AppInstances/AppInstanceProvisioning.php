<?php

declare(strict_types=1);

namespace App\Domain\AppInstances;

use App\Models\AppInstance;

final readonly class AppInstanceProvisioning
{
    /** @var list<string> */
    private const array CompletedSteps = ['active', 'clone-completed'];

    public static function isInFlight(AppInstance $instance, AppInstanceState $settled): bool
    {
        if ($instance->status === AppInstanceState::Active || $instance->failed_step !== null) {
            return false;
        }

        return self::isBeforeSettled($instance->status, $settled)
            || ($instance->provisioning_step !== null && ! self::isCompletedStep($instance->provisioning_step));
    }

    public static function isCompletedStep(string $step): bool
    {
        return in_array($step, self::CompletedSteps, true);
    }

    private static function isBeforeSettled(AppInstanceState $status, AppInstanceState $settled): bool
    {
        $progression = [
            AppInstanceState::Reserved,
            AppInstanceState::CheckoutPrepared,
            AppInstanceState::SourceResolved,
            AppInstanceState::Active,
        ];

        $statusPosition = array_search($status, $progression, true);
        $settledPosition = array_search($settled, $progression, true);

        return is_int($statusPosition) && is_int($settledPosition) && $statusPosition < $settledPosition;
    }
}
