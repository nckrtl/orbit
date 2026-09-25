<?php

declare(strict_types=1);

namespace App\Infrastructure\Activity;

use App\Actions\Activities\FinalizeInterruptedActivitiesAction;
use App\Models\Activity;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Ends a `running` Activity when PHP shuts its request down before the middleware records the outcome
 * (ADR 0158). PHP runs shutdown code after a fatal error, such as exhausted memory or an exceeded
 * execution time, and after a client abort. A SIGKILL, including the kernel OOM killer and the PHP-FPM
 * request limit, runs nothing; the scheduled sweep ends those rows.
 *
 * The Gateway's exception handler calls finalizeArmed() when it reports a fatal error, before it
 * builds log context that can exhaust memory again and stop every later shutdown function.
 */
final class ActivityShutdownFinalizer
{
    /** Extra memory a fatal memory error grants the shutdown code that records the outcome. */
    private const int SHUTDOWN_MEMORY_BYTES = 32 * 1024 * 1024;

    /** @var array<int, self> */
    private static array $armed = [];

    private function __construct(private readonly int $activityId) {}

    public static function arm(Activity $activity): self
    {
        $finalizer = new self((int) $activity->getKey());
        self::$armed[spl_object_id($finalizer)] = $finalizer;

        register_shutdown_function($finalizer->finalize(...));

        return $finalizer;
    }

    /** Ends every Activity whose request has not recorded an outcome yet. */
    public static function finalizeArmed(): void
    {
        foreach (self::$armed as $finalizer) {
            $finalizer->finalize();
        }
    }

    public function disarm(): void
    {
        unset(self::$armed[spl_object_id($this)]);
    }

    public function armed(): bool
    {
        return isset(self::$armed[spl_object_id($this)]);
    }

    public function finalize(): void
    {
        if (! $this->armed()) {
            return;
        }

        $this->disarm();

        try {
            self::allowShutdownMemory();

            FinalizeInterruptedActivitiesAction::finalize(
                Activity::query()->whereKey($this->activityId),
                Carbon::now(),
            );
        } catch (Throwable) {
            // The scheduled sweep ends the row when the database is unavailable here.
        }
    }

    private static function allowShutdownMemory(): void
    {
        $error = error_get_last();

        if (! is_array($error) || ! str_contains($error['message'], 'Allowed memory size')) {
            return;
        }

        $limit = ini_parse_quantity((string) ini_get('memory_limit'));

        if ($limit > 0 && memory_get_usage() + self::SHUTDOWN_MEMORY_BYTES > $limit) {
            ini_set('memory_limit', (string) (memory_get_usage() + self::SHUTDOWN_MEMORY_BYTES));
        }
    }
}
