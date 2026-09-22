<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

use App\Domain\Shared\ResourceOperationException;

final readonly class TaskVerificationPolicy
{
    public const string Model = 'jev-1.13.0';

    public const string Version = 'task-evidence-v1';

    public const string Profile = 'orbit-composer-v2';

    public function requiredForApp(int $appId): bool
    {
        return in_array($appId, config('orbit.tasks.verification_app_ids', []), true);
    }

    /** @param array<mixed> $criteria */
    public function validatePlan(int $appId, array $criteria): bool
    {
        $required = $this->requiredForApp($appId);
        if ($required !== ($criteria !== [])) {
            throw new ResourceOperationException('tasks.verification_plan_required',
                $required ? 'This App requires verification criteria before implementation.' : 'Task verification is not enabled for this App.', 422);
        }
        if ($required) {
            $this->threshold();
        }

        return $required;
    }

    public function threshold(): float
    {
        $value = config('orbit.tasks.verification_noul_threshold');
        if (! is_numeric($value) || ! is_finite((float) $value) || (float) $value <= 0.5 || (float) $value > 1) {
            throw new ResourceOperationException('tasks.verification_not_calibrated', 'Set an evaluated Noul threshold before enabling task verification.', 409);
        }

        return (float) $value;
    }

    /** @param array<mixed> $value */
    public function digest(array $value): string
    {
        return hash('sha256', json_encode($value, JSON_THROW_ON_ERROR));
    }
}
