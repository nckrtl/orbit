<?php

declare(strict_types=1);

namespace App\Infrastructure\Tasks;

use App\Domain\AppDev\RuntimeConvergenceException;

/** The task account is optional during rollout, but a configured account never falls back to SSH's user. */
final readonly class TaskWorkerUser
{
    public static function name(): ?string
    {
        $worker = config('orbit.tasks.worker_user');
        if ($worker === null || $worker === '') {
            return null;
        }
        if (! is_string($worker) || preg_match('/\A[a-z_][a-z0-9_-]{0,31}\z/D', $worker) !== 1 || $worker === 'root') {
            throw new RuntimeConvergenceException(
                step: 'task-worker-user',
                errorCode: 'tasks.check_failed',
                message: 'The task worker user is invalid.',
            );
        }

        return $worker;
    }

    /** @param list<string> $arguments
     * @return list<string>
     */
    public static function arguments(array $arguments): array
    {
        $worker = self::name();

        if ($worker === null) {
            return $arguments;
        }

        return ['bash', '-ceu', <<<'BASH'
            worker=$1
            shift
            worker_uid=$(id -u "$worker") || exit 126
            test "$worker_uid" != 0 && test "$worker_uid" != "$(id -u)" || exit 126
            exec sudo -n -u "$worker" -H -- "$@"
            BASH, '--', $worker, ...$arguments];
    }
}
