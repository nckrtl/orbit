<?php

declare(strict_types=1);

use App\Actions\Activities\FinalizeInterruptedActivitiesAction;
use App\Infrastructure\Activity\ActivityShutdownFinalizer;
use App\Infrastructure\Gateway\GatewayFpmConfigRenderer;
use App\Models\Activity;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

function interrupted_activity_row(string $status, int $ageSeconds, string $command = 'process:list'): int
{
    $createdAt = Carbon::now()->subSeconds($ageSeconds);

    return (int) DB::table('activity_log')->insertGetId([
        'log_name' => 'commands',
        'description' => $command,
        'event' => 'command',
        'request_id' => (string) Str::uuid(),
        'command' => $command,
        'status' => $status,
        'error_code' => $status === 'failed' ? 'http.500' : null,
        'created_at' => $createdAt,
        'updated_at' => $createdAt,
    ]);
}

/** @return array{status: string, error_code: string|null, updated_at: string|null} */
function interrupted_activity_state(int $id): array
{
    $row = DB::table('activity_log')->where('id', $id)->first(['status', 'error_code', 'updated_at']);

    return ['status' => $row->status, 'error_code' => $row->error_code, 'updated_at' => (string) $row->updated_at];
}

describe('the interrupted Activity sweep', function (): void {
    it('ends every running row older than the bound as failed with activity.interrupted', function (): void {
        $stale = interrupted_activity_row('running', 11 * 86_400);
        $justStale = interrupted_activity_row('running', 901, 'node:role:relocate');

        Artisan::call('orbit:activity-finalize-interrupted');

        expect(Artisan::output())->toContain('Finalized 2 interrupted Activity records.')
            ->and(interrupted_activity_state($stale))->toMatchArray(['status' => 'failed', 'error_code' => 'activity.interrupted'])
            ->and(interrupted_activity_state($justStale))->toMatchArray(['status' => 'failed', 'error_code' => 'activity.interrupted']);
    });

    it('leaves a request that may still be in flight and every finished row untouched', function (): void {
        // A streamed deployment or relocation can run until the 600-second PHP-FPM limit.
        $atRequestLimit = interrupted_activity_row('running', 600, 'instance:deploy');
        $withinMargin = interrupted_activity_row('running', 899, 'node:role:relocate');
        $succeeded = interrupted_activity_row('succeeded', 86_400);
        $failed = interrupted_activity_row('failed', 86_400);
        $before = array_map(interrupted_activity_state(...), [$atRequestLimit, $withinMargin, $succeeded, $failed]);

        expect(app(FinalizeInterruptedActivitiesAction::class)->execute())->toBe(0)
            ->and(array_map(interrupted_activity_state(...), [$atRequestLimit, $withinMargin, $succeeded, $failed]))->toBe($before);
    });

    it('is idempotent', function (): void {
        $stale = interrupted_activity_row('running', 3_600);
        $action = app(FinalizeInterruptedActivitiesAction::class);

        expect($action->execute())->toBe(1);
        $finalized = interrupted_activity_state($stale);
        Carbon::setTestNow(Carbon::now()->addMinutes(5));

        expect($action->execute())->toBe(0)
            ->and(interrupted_activity_state($stale))->toBe($finalized);
    });

    it('ends at most one batch of rows per run, oldest first', function (): void {
        $ids = [];

        foreach (range(1, FinalizeInterruptedActivitiesAction::BATCH + 3) as $index) {
            $ids[] = interrupted_activity_row('running', 3_600);
        }

        $action = app(FinalizeInterruptedActivitiesAction::class);

        expect($action->execute())->toBe(FinalizeInterruptedActivitiesAction::BATCH)
            ->and(interrupted_activity_state(end($ids))['status'])->toBe('running')
            ->and($action->execute())->toBe(3)
            ->and(DB::table('activity_log')->where('status', 'running')->count())->toBe(0);
    });

    it('waits longer than PHP-FPM lets any Gateway request run', function (): void {
        preg_match('/request_terminate_timeout = (\d+)s/', new GatewayFpmConfigRenderer()->renderPool('/checkout', '/home/orbit/.orbit'), $fpm);

        expect($fpm[1] ?? null)->not->toBeNull()
            ->and(config('orbit.activity_interrupted_after'))->toBeGreaterThanOrEqual((int) $fpm[1] + 60);
    });

    it('runs on the Gateway scheduler every five minutes without overlapping', function (): void {
        Artisan::call('schedule:list');

        expect(Artisan::output())->toMatch('/\*\/5 \* \* \* \*\s+php artisan orbit:activity-finalize-interrupted/');
    });
});

describe('the shutdown finalizer', function (): void {
    it('ends its running row when the request shuts down before recording an outcome', function (): void {
        $activity = Activity::query()->findOrFail(interrupted_activity_row('running', 1, 'instance:register'));

        ActivityShutdownFinalizer::arm($activity)->finalize();

        expect(interrupted_activity_state($activity->id))->toMatchArray(['status' => 'failed', 'error_code' => 'activity.interrupted']);
    });

    it('does nothing once the request recorded its outcome', function (): void {
        $running = Activity::query()->findOrFail(interrupted_activity_row('running', 1));
        $disarmed = ActivityShutdownFinalizer::arm($running);
        $disarmed->disarm();
        $disarmed->finalize();

        $succeeded = Activity::query()->findOrFail(interrupted_activity_row('succeeded', 1));
        ActivityShutdownFinalizer::arm($succeeded)->finalize();

        expect(interrupted_activity_state($running->id)['status'])->toBe('running')
            ->and(interrupted_activity_state($succeeded->id))->toMatchArray(['status' => 'succeeded', 'error_code' => null]);
    });

    it('ends the row after a real fatal error and leaves a recorded outcome alone', function (string $mode, string $status, ?string $errorCode): void {
        $database = sys_get_temp_dir().'/orbit-gateway-test-'.bin2hex(random_bytes(8)).'.sqlite';
        touch($database);

        try {
            $process = new Process(
                [PHP_BINARY, base_path('tests/Fixtures/Activity/ShutdownFatalProbe.php')],
                base_path(),
                ['ORBIT_TEST_DATABASE' => $database, 'ORBIT_ACTIVITY_PROBE_MODE' => $mode],
            );
            $process->setTimeout(120);
            $process->run();

            $row = new PDO('sqlite:'.$database)->query('SELECT status, error_code FROM activity_log')->fetch(PDO::FETCH_ASSOC);

            expect($process->isSuccessful())->toBeFalse()
                ->and($process->getOutput().$process->getErrorOutput())->toContain('Allowed memory size')
                ->and($row)->toBe(['status' => $status, 'error_code' => $errorCode]);
        } finally {
            @unlink($database);
        }
    })->with([
        'killed by the memory limit' => ['armed', 'failed', 'activity.interrupted'],
        'after the outcome was recorded' => ['disarmed', 'succeeded', null],
    ]);
});
