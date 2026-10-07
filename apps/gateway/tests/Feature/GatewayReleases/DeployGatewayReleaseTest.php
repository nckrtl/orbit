<?php

declare(strict_types=1);

use App\Actions\GatewayReleases\ConfigureGatewayReleaseAction;
use App\Actions\GatewayReleases\DeployGatewayReleaseAction;
use App\Actions\GatewayReleases\RollbackGatewayReleaseAction;
use App\Domain\GatewayReleases\GatewayReleaseDatabase;
use App\Domain\GatewayReleases\GatewayReleaseException;
use App\Domain\GatewayReleases\GatewayReleaseRuntime;
use App\Domain\GatewayReleases\GatewayReleaseSmoke;
use App\Domain\GatewayReleases\GatewayReleaseVerifier;
use App\Domain\GatewayReleases\GatewayReleaseWebBuild;
use App\Infrastructure\GatewayReleases\GatewayReleaseGuard;
use App\Infrastructure\GatewayReleases\GatewayReleaseLock;
use App\Infrastructure\GatewayReleases\GatewayReleasePromoter;
use App\Infrastructure\GatewayReleases\GatewayReleaseRecorder;
use App\Infrastructure\GatewayReleases\GatewayReleaseRetry;
use App\Infrastructure\GatewayReleases\GatewayReleaseSwitcher;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Processes\ProcessInvocation;
use App\Infrastructure\Processes\ProcessRunner;
use App\Models\Activity;
use App\Models\GatewayRelease;
use Illuminate\Support\Facades\Schema;
use Tests\Support\GatewayReleaseFixture;

beforeEach(function (): void {
    $this->fixture = new GatewayReleaseFixture;
    $this->live = $this->fixture->layout->currentPath();
    mkdir($this->live.'/apps/gateway', 0755, true);
    file_put_contents($this->live.'/apps/gateway/live.txt', 'serving');
    config(['orbit.home' => $this->fixture->base.'/home']);
});

afterEach(function (): void {
    $this->fixture->cleanup();
});

describe('gateway:release:deploy', function (): void {
    it('switches to the release only after prepare, verifies, then publishes the web app before smoke', function (): void {
        adopt_release($this->fixture);
        $sha = $this->fixture->commit('Second release');
        $order = new ReleaseSteps;
        $action = release_deployer($this->fixture, passing_verifier($order), recording_runtime($order), new OpenReleaseDatabase, recording_web($order), recording_smoke($order));

        $deployed = $action->execute($sha);

        expect($deployed->outcome)->toBe('verified')
            ->and($deployed->sha)->toBe($sha)
            ->and($this->fixture->layout->currentReleaseId())->toBe($deployed->id)
            // Verify runs before the scheduler drain, so a broken release is found without waiting for it.
            ->and($order->steps)->toBe(['handoff:'.$deployed->id, 'verify', 'schedule:'.$deployed->id, 'web:publish', 'smoke'])
            ->and(is_file($this->live.'/apps/gateway/live.txt'))->toBeFalse()
            ->and(GatewayRelease::query()->value('outcome'))->toBe('verified')
            ->and(Activity::query()->value('command'))->toBe('gateway:release:deploy')
            ->and(Activity::query()->value('status'))->toBe('succeeded');
    });

    it('switches back and repeats the runtime handoff when verification fails and no migrations ran', function (): void {
        $first = adopt_release($this->fixture);
        $sha = $this->fixture->commit('Broken release');
        $order = new ReleaseSteps;
        $verifier = passing_verifier($order);
        $verifier->failSha = $sha;
        $action = release_deployer($this->fixture, $verifier, recording_runtime($order), new OpenReleaseDatabase, recording_web($order), recording_smoke($order));

        $exception = release_failure(fn () => $action->execute($sha));
        $record = GatewayRelease::query()->first();

        expect($exception->errorCode)->toBe('gateway.release_verify_failed')
            ->and($exception->step)->toBe('verify')
            ->and($this->fixture->layout->currentReleaseId())->toBe($first)
            ->and($record->outcome)->toBe('switched_back')
            ->and($record->migrations_ran)->toBeFalse()
            ->and($order->steps)->toBe([
                'handoff:'.substr($sha, 0, 12),
                'verify',
                'web:restore',
                'handoff:'.$first,
            ])
            ->and(Activity::query()->value('status'))->toBe('failed');
    });

    it('runs smoke only after the web switch and switches back when smoke fails', function (): void {
        $first = adopt_release($this->fixture);
        $sha = $this->fixture->commit('Smoke fails');
        $order = new ReleaseSteps;
        $smoke = recording_smoke($order);
        $smoke->fail = true;
        $action = release_deployer($this->fixture, passing_verifier($order), recording_runtime($order), new OpenReleaseDatabase, recording_web($order), $smoke);

        expect(release_failure(fn () => $action->execute($sha))->step)->toBe('smoke')
            ->and($this->fixture->layout->currentReleaseId())->toBe($first)
            // The scheduler already moved, so switch-back moves it back too.
            ->and($order->steps)->toBe([
                'handoff:'.substr($sha, 0, 12),
                'verify',
                'schedule:'.substr($sha, 0, 12),
                'web:publish',
                'smoke',
                'web:restore',
                'handoff:'.$first,
                'schedule:'.$first,
            ]);
    });

    it('pauses on the new release when verification fails after migrations ran', function (): void {
        $first = adopt_release($this->fixture);
        $sha = $this->fixture->commit('Migrated release');
        $order = new ReleaseSteps;
        $database = new OpenReleaseDatabase;
        $database->pending = ['2026_10_13_000000_create_gateway_releases_table'];
        $verifier = passing_verifier($order);
        $verifier->failSha = $sha;
        $action = release_deployer($this->fixture, $verifier, recording_runtime($order), $database, recording_web($order), recording_smoke($order));

        $exception = release_failure(fn () => $action->execute($sha));
        $record = GatewayRelease::query()->first();
        $marker = $this->fixture->base.'/home/gateway-release.paused';

        expect($exception->step)->toBe('verify')
            ->and($this->fixture->layout->currentReleaseId())->toBe(substr($sha, 0, 12))
            ->and($this->fixture->layout->currentReleaseId())->not->toBe($first)
            ->and($record->outcome)->toBe('paused')
            ->and($record->migrations_ran)->toBeTrue()
            ->and($record->snapshot_path)->toBe('/var/tmp/orbit-pre-'.substr($sha, 0, 12).'.sqlite')
            ->and(is_file($marker))->toBeTrue()
            ->and($order->steps)->not->toContain('web:publish')
            ->and($order->steps)->not->toContain('handoff:'.$first);
    });

    it('leaves the current release in place when the switch is interrupted', function (): void {
        $first = adopt_release($this->fixture);
        $sha = $this->fixture->commit('Interrupted');
        $switcher = new GatewayReleaseSwitcher($this->fixture->layout, new readonly class($this->fixture) implements ProcessRunner
        {
            public function __construct(private GatewayReleaseFixture $fixture) {}

            public function run(ProcessInvocation $invocation): CommandResult
            {
                if (($invocation->arguments[0] ?? '') === 'mv') {
                    return new CommandResult(1, '', 'mv failed', 1, false);
                }

                return $this->fixture->run($invocation);
            }
        });
        $order = new ReleaseSteps;
        $action = release_deployer($this->fixture, passing_verifier(), recording_runtime($order), new OpenReleaseDatabase, recording_web($order), recording_smoke($order), $switcher);

        expect(release_failure(fn () => $action->execute($sha))->errorCode)->toBe('gateway.release_switch_failed')
            ->and($this->fixture->layout->currentReleaseId())->toBe($first)
            ->and(file_exists($this->live.'.next'))->toBeFalse()
            ->and(GatewayRelease::query()->value('outcome'))->toBe('failed');
    });

    it('marks a commit failed when prepare fails and does not move the current release', function (): void {
        $first = adopt_release($this->fixture);
        $this->fixture->write('apps/gateway/composer.fail', 'fail');
        $sha = $this->fixture->commit('Broken dependencies');

        $order = new ReleaseSteps;
        $exception = release_failure(fn () => release_deployer($this->fixture, passing_verifier(), recording_runtime($order), new OpenReleaseDatabase, recording_web($order), recording_smoke($order))->execute($sha));

        expect($exception->errorCode)->toBe('gateway.release_dependencies_failed')
            ->and($this->fixture->layout->currentReleaseId())->toBe($first)
            ->and(GatewayRelease::query()->first()->outcome)->toBe('failed')
            // A dependency install can fail on the network, so the commit gets more attempts.
            ->and(GatewayRelease::query()->first()->retryable)->toBeTrue()
            ->and(GatewayRelease::query()->first()->sha)->toBe($sha);
    });

    it('refuses a second release step while one holds the lock', function (): void {
        $sha = $this->fixture->commit('Locked');
        $lock = new GatewayReleaseLock($this->fixture->base.'/home/gateway-release.lock');
        $order = new ReleaseSteps;
        $action = release_deployer($this->fixture, passing_verifier(), recording_runtime($order), new OpenReleaseDatabase, recording_web($order), recording_smoke($order), lock: $lock);

        $exception = $lock->run(fn (): GatewayReleaseException => release_failure(fn () => $action->execute($sha)));

        expect($exception->errorCode)->toBe('gateway.release_in_progress')
            ->and(GatewayRelease::query()->count())->toBe(0);
    });

    it('refuses to roll back across a migration without force and switches when forced', function (): void {
        $first = adopt_release($this->fixture);
        $sha = $this->fixture->commit('Migrated forward');
        $database = new OpenReleaseDatabase;
        $database->files = [
            $this->fixture->layout->releasePath($first) => ['2026_01_01_000000_one.php'],
            $this->fixture->layout->releasePath(substr($sha, 0, 12)) => ['2026_01_01_000000_one.php', '2026_02_01_000000_two.php'],
        ];
        $order = new ReleaseSteps;
        $deployed = release_deployer($this->fixture, passing_verifier(), recording_runtime($order), $database, recording_web($order), recording_smoke($order))->execute($sha);
        $database->applied = ['2026_01_01_000000_one', '2026_02_01_000000_two'];
        GatewayRelease::query()->create([
            'release_id' => $deployed->id,
            'sha' => $sha,
            'trigger' => 'deploy',
            'outcome' => 'verified',
            'migrations_ran' => true,
            'snapshot_path' => '/home/orbit/.orbit/backups/pre-'.$deployed->id.'.sqlite',
            'phases' => [],
            'duration_ms' => 1,
        ]);
        $rollback = release_rollback($this->fixture, passing_verifier(), recording_runtime($order), $database, recording_web($order), recording_smoke($order));

        $refused = release_failure(fn () => $rollback->execute($first));

        expect($refused->errorCode)->toBe('gateway.release_migration_crossed')
            ->and($refused->getMessage())->toContain('2026_02_01_000000_two')
            ->and($refused->getMessage())->toContain('pre-'.$deployed->id.'.sqlite')
            ->and($this->fixture->layout->currentReleaseId())->toBe($deployed->id);

        $forced = $rollback->execute($first, force: true);

        expect($forced->outcome)->toBe('verified')
            ->and($forced->trigger)->toBe('rollback')
            ->and($this->fixture->layout->currentReleaseId())->toBe($first);
    });

    it('switches back and records the release when an unexpected error ends the handoff', function (): void {
        $first = adopt_release($this->fixture);
        $sha = $this->fixture->commit('Handoff crashes');
        $order = new ReleaseSteps;
        $action = release_deployer($this->fixture, passing_verifier($order), recording_runtime($order, crashOn: substr($sha, 0, 12)), new OpenReleaseDatabase, recording_web($order), recording_smoke($order));

        $exception = release_failure(fn () => $action->execute($sha));
        $record = GatewayRelease::query()->sole();

        expect($exception->errorCode)->toBe('gateway.release_unexpected_failure')
            ->and($exception->step)->toBe('handoff')
            ->and($exception->getMessage())->toContain('ran out of memory')
            ->and($this->fixture->layout->currentReleaseId())->toBe($first)
            ->and($record->outcome)->toBe('switched_back')
            ->and($record->error_code)->toBe('gateway.release_unexpected_failure')
            ->and($order->steps)->toBe(['handoff:'.substr($sha, 0, 12), 'web:restore', 'handoff:'.$first])
            ->and(Activity::query()->value('status'))->toBe('failed');
    });

    it('pauses when an unexpected error ends a release after migrations ran', function (): void {
        adopt_release($this->fixture);
        $sha = $this->fixture->commit('Migrated, then the handoff crashes');
        $order = new ReleaseSteps;
        $database = new OpenReleaseDatabase;
        $database->pending = ['2026_10_13_000000_create_gateway_releases_table'];
        $action = release_deployer($this->fixture, passing_verifier($order), recording_runtime($order, crashOn: substr($sha, 0, 12)), $database, recording_web($order), recording_smoke($order));

        release_failure(fn () => $action->execute($sha));

        expect(GatewayRelease::query()->sole()->outcome)->toBe('paused')
            ->and($this->fixture->layout->currentReleaseId())->toBe(substr($sha, 0, 12))
            ->and(is_file($this->fixture->base.'/home/gateway-release.paused'))->toBeTrue();
    });

    it('pauses when the switch fails after migrations ran, because the old code serves the new schema', function (): void {
        $first = adopt_release($this->fixture);
        $sha = $this->fixture->commit('Migrated, then the switch fails');
        $switcher = new GatewayReleaseSwitcher($this->fixture->layout, new readonly class($this->fixture) implements ProcessRunner
        {
            public function __construct(private GatewayReleaseFixture $fixture) {}

            public function run(ProcessInvocation $invocation): CommandResult
            {
                return ($invocation->arguments[0] ?? '') === 'mv'
                    ? new CommandResult(1, '', 'mv failed', 1, false)
                    : $this->fixture->run($invocation);
            }
        });
        $database = new OpenReleaseDatabase;
        $database->pending = ['2026_10_13_000000_create_gateway_releases_table'];
        $order = new ReleaseSteps;
        $action = release_deployer($this->fixture, passing_verifier(), recording_runtime($order), $database, recording_web($order), recording_smoke($order), $switcher);

        expect(release_failure(fn () => $action->execute($sha))->errorCode)->toBe('gateway.release_switch_failed')
            ->and($this->fixture->layout->currentReleaseId())->toBe($first)
            ->and(GatewayRelease::query()->sole()->outcome)->toBe('paused')
            ->and(is_file($this->fixture->base.'/home/gateway-release.paused'))->toBeTrue();
    });

    it('records a failure about the machine as retryable', function (): void {
        adopt_release($this->fixture);
        $sha = $this->fixture->commit('Unreadable migrations');
        $database = new OpenReleaseDatabase;
        $database->unreadable = new RuntimeException('database is locked');
        $order = new ReleaseSteps;
        $action = release_deployer($this->fixture, passing_verifier(), recording_runtime($order), $database, recording_web($order), recording_smoke($order));

        $exception = release_failure(fn () => $action->execute($sha));
        $record = GatewayRelease::query()->sole();

        expect($exception->errorCode)->toBe('gateway.release_unexpected_failure')
            ->and($exception->step)->toBe('snapshot')
            ->and($record->outcome)->toBe('failed')
            ->and($record->retryable)->toBeTrue()
            ->and($record->sha)->toBe($sha)
            ->and($order->steps)->toBe([]);
    });

    it('records a refused rollback as a failed Activity entry and changes nothing', function (): void {
        $first = adopt_release($this->fixture);
        $order = new ReleaseSteps;
        $rollback = release_rollback($this->fixture, passing_verifier(), recording_runtime($order), new OpenReleaseDatabase, recording_web($order), recording_smoke($order));

        $exception = release_failure(fn () => $rollback->execute('0123456789ab'));

        expect($exception->errorCode)->toBe('gateway.release_not_prepared')
            ->and($this->fixture->layout->currentReleaseId())->toBe($first)
            ->and(GatewayRelease::query()->count())->toBe(0)
            ->and(Activity::query()->value('command'))->toBe('gateway:release:rollback')
            ->and(Activity::query()->value('error_code'))->toBe('gateway.release_not_prepared');
    });

    it('caches the target release again from the shared env file before it goes current', function (): void {
        $first = adopt_release($this->fixture);
        $sha = $this->fixture->commit('Second release');
        $order = new ReleaseSteps;
        release_deployer($this->fixture, passing_verifier(), recording_runtime($order), new OpenReleaseDatabase, recording_web($order), recording_smoke($order))->execute($sha);
        file_put_contents($this->fixture->layout->environmentPath(), "APP_ENV=production\nORBIT_CHANGED=1\n");
        $rollback = release_rollback($this->fixture, passing_verifier(), recording_runtime($order), new OpenReleaseDatabase, recording_web($order), recording_smoke($order));

        $rollback->execute($first);
        $cache = $this->fixture->layout->releaseApplicationPath($first).'/bootstrap/cache';

        expect((require $cache.'/config.php')['env'])->toContain('ORBIT_CHANGED=1')
            ->and(glob($cache.'/config.next-*'))->toBe([]);
    });

    it('refreshes the current release configuration for an env change', function (): void {
        $first = adopt_release($this->fixture);
        file_put_contents($this->fixture->layout->environmentPath(), "APP_ENV=production\nORBIT_CHANGED=2\n");
        $this->app->instance(ConfigureGatewayReleaseAction::class, new ConfigureGatewayReleaseAction(
            new GatewayReleaseLock($this->fixture->base.'/home/gateway-release.lock'),
            $this->fixture->layout,
            $this->fixture->builder(),
        ));

        $this->artisan('gateway:release:configure')
            ->expectsOutputToContain('"release":"'.$first.'"')
            ->assertExitCode(0);

        expect((require $this->fixture->layout->releaseApplicationPath($first).'/bootstrap/cache/config.php')['env'])->toContain('ORBIT_CHANGED=2');
    });

    it('keeps the configured number of releases plus the current and previous one', function (): void {
        $order = new ReleaseSteps;
        $first = adopt_release($this->fixture);
        $ids = [$first];

        foreach (range(1, 3) as $index) {
            $sha = $this->fixture->commit('Release '.$index);
            $ids[] = release_deployer($this->fixture, passing_verifier(), recording_runtime($order), new OpenReleaseDatabase, recording_web($order), recording_smoke($order), keptReleases: 1)->execute($sha)->id;
            touch($this->fixture->layout->releasePath(end($ids)).'/REVISION', time() + $index);
        }

        expect($this->fixture->layout->retainedReleaseIds())->toEqualCanonicalizing([$ids[3], $ids[2]])
            ->and($this->fixture->layout->currentReleaseId())->toBe($ids[3]);
    });

    it('prints JSON for a failure the release code did not expect', function (): void {
        Schema::drop('gateway_releases');

        $this->artisan('gateway:release:list')
            ->expectsOutputToContain('"error_code":"gateway.release_unexpected_failure"')
            ->assertExitCode(1);
    });

    it('refuses a commit that does not descend from the current release unless forced', function (): void {
        $older = $this->fixture->commit('Older');
        $this->fixture->builder()->prepare($older);
        $current = adopt_release($this->fixture);
        $order = new ReleaseSteps;
        $action = release_deployer($this->fixture, passing_verifier(), recording_runtime($order), new OpenReleaseDatabase, recording_web($order), recording_smoke($order));

        $refused = release_failure(fn () => $action->execute($older));

        expect($refused->errorCode)->toBe('gateway.release_downgrade')
            ->and($this->fixture->layout->currentReleaseId())->toBe($current)
            ->and(GatewayRelease::query()->count())->toBe(0)
            ->and(Activity::query()->value('error_code'))->toBe('gateway.release_downgrade')
            ->and($action->execute($older, force: true)->outcome)->toBe('verified');
    });

    it('refuses a commit that does not know a migration the database applied', function (): void {
        $current = adopt_release($this->fixture);
        $sha = $this->fixture->commit('Forward, but missing a hotfix migration');
        $database = new OpenReleaseDatabase;
        $database->applied = ['2026_09_09_000000_hotfix'];
        $order = new ReleaseSteps;

        $refused = release_failure(fn () => release_deployer($this->fixture, passing_verifier(), recording_runtime($order), $database, recording_web($order), recording_smoke($order))->execute($sha));

        expect($refused->errorCode)->toBe('gateway.release_migration_crossed')
            ->and($refused->getMessage())->toContain('2026_09_09_000000_hotfix')
            ->and($this->fixture->layout->currentReleaseId())->toBe($current)
            ->and($order->steps)->toBe([]);
    });

    it('caches the configuration before it migrates, so a broken env file stops the release while nothing changed', function (): void {
        $current = adopt_release($this->fixture);
        $sha = $this->fixture->commit('Has a migration');
        $this->fixture->builder()->prepare($sha);
        $application = $this->fixture->layout->releaseApplicationPath(substr($sha, 0, 12));
        chmod($application, 0755);
        file_put_contents($application.'/config.fail', 'fail');
        $database = new OpenReleaseDatabase;
        $database->pending = ['2026_12_31_000000_marks'];
        $order = new ReleaseSteps;

        $exception = release_failure(fn () => release_deployer($this->fixture, passing_verifier(), recording_runtime($order), $database, recording_web($order), recording_smoke($order))->execute($sha));

        expect($exception->errorCode)->toBe('gateway.release_configuration_failed')
            ->and($database->migrated)->toBeFalse()
            ->and($this->fixture->layout->currentReleaseId())->toBe($current)
            ->and(GatewayRelease::query()->sole()->outcome)->toBe('failed')
            ->and(GatewayRelease::query()->sole()->migrations_ran)->toBeFalse();
    });

    it('pauses without switching when the migration fails', function (): void {
        $current = adopt_release($this->fixture);
        $sha = $this->fixture->commit('Broken migration');
        $database = new OpenReleaseDatabase;
        $database->pending = ['2026_12_31_000000_marks'];
        $database->failMigrate = true;
        $order = new ReleaseSteps;

        $exception = release_failure(fn () => release_deployer($this->fixture, passing_verifier(), recording_runtime($order), $database, recording_web($order), recording_smoke($order))->execute($sha));

        expect($exception->errorCode)->toBe('gateway.release_migrate_failed')
            ->and($this->fixture->layout->currentReleaseId())->toBe($current)
            ->and(GatewayRelease::query()->sole()->outcome)->toBe('paused')
            ->and(GatewayRelease::query()->sole()->retryable)->toBeFalse()
            ->and($order->steps)->toBe([]);
    });

    it('keeps naming the clean snapshot when a paused commit is tried again', function (): void {
        adopt_release($this->fixture);
        $sha = $this->fixture->commit('Half-failing migration');
        $database = new OpenReleaseDatabase;
        $database->pending = ['2026_12_31_000000_marks'];
        $database->failMigrate = true;
        $database->directory = $this->fixture->base.'/home';
        $order = new ReleaseSteps;
        $deploy = fn (): GatewayReleaseException => release_failure(fn () => release_deployer($this->fixture, passing_verifier(), recording_runtime($order), $database, recording_web($order), recording_smoke($order))->execute($sha));

        $deploy();
        $deploy();
        $records = GatewayRelease::query()->orderBy('id')->get();
        $marker = json_decode((string) file_get_contents($this->fixture->base.'/home/gateway-release.paused'), true);
        $clean = $this->fixture->base.'/home/pre-'.substr($sha, 0, 12).'-1.sqlite';

        expect($records->pluck('snapshot_path')->all())->toBe([$clean, $clean])
            ->and($records[1]->phases['snapshot']['path'])->toBe($this->fixture->base.'/home/pre-'.substr($sha, 0, 12).'-2.sqlite')
            ->and($marker['snapshot'])->toBe($clean);
    });

    it('gives a retryable failure three attempts per commit, then marks it final', function (): void {
        adopt_release($this->fixture);
        $sha = $this->fixture->commit('Fails verify every time');
        $order = new ReleaseSteps;
        $verifier = passing_verifier($order);
        $verifier->failSha = $sha;
        $action = release_deployer($this->fixture, $verifier, recording_runtime($order), new OpenReleaseDatabase, recording_web($order), recording_smoke($order));

        foreach (range(1, 3) as $attempt) {
            release_failure(fn () => $action->execute($sha));
        }

        expect(GatewayRelease::query()->orderBy('id')->pluck('retryable')->all())->toBe([true, true, false]);
    });

    it('records switch_back_failed when returning to the previous release fails too', function (): void {
        $first = adopt_release($this->fixture);
        $sha = $this->fixture->commit('Fails verify, and the way back fails');
        $switcher = new GatewayReleaseSwitcher($this->fixture->layout, new class($this->fixture) implements ProcessRunner
        {
            private int $moves = 0;

            public function __construct(private readonly GatewayReleaseFixture $fixture) {}

            public function run(ProcessInvocation $invocation): CommandResult
            {
                if (($invocation->arguments[0] ?? '') === 'mv' && ++$this->moves > 1) {
                    return new CommandResult(1, '', 'mv failed', 1, false);
                }

                return $this->fixture->run($invocation);
            }
        });
        $order = new ReleaseSteps;
        $verifier = passing_verifier($order);
        $verifier->failSha = $sha;

        $exception = release_failure(fn () => release_deployer($this->fixture, $verifier, recording_runtime($order), new OpenReleaseDatabase, recording_web($order), recording_smoke($order), $switcher)->execute($sha));
        $record = GatewayRelease::query()->sole();

        expect($exception->errorCode)->toBe('gateway.release_switch_back_failed')
            ->and($record->outcome)->toBe('failed')
            ->and($record->phases['switch_back']['outcome'])->toBe('failed')
            ->and($record->retryable)->toBeFalse()
            ->and($this->fixture->layout->currentReleaseId())->toBe(substr($sha, 0, 12))
            ->and($this->fixture->layout->currentReleaseId())->not->toBe($first);
    });

    it('refuses to switch when the current path links to something other than a release', function (): void {
        $sha = $this->fixture->commit('Release');
        $this->fixture->builder()->prepare($sha);
        exec('rm -rf '.escapeshellarg($this->live));
        mkdir($this->fixture->base.'/elsewhere', 0755);
        symlink($this->fixture->base.'/elsewhere', $this->live);

        $exception = release_failure(fn () => new GatewayReleaseSwitcher($this->fixture->layout, $this->fixture)->switchTo(substr($sha, 0, 12)));

        expect($exception->errorCode)->toBe('gateway.release_not_adopted')
            ->and(readlink($this->live))->toBe($this->fixture->base.'/elsewhere');
    });

    it('pauses instead of switching back when the applied migrations cannot be read', function (): void {
        $first = adopt_release($this->fixture);
        $sha = $this->fixture->commit('Fails verify while the schema cannot be read');
        $database = new OpenReleaseDatabase;
        $order = new ReleaseSteps;
        $verifier = new class($database) implements GatewayReleaseVerifier
        {
            public function __construct(private readonly OpenReleaseDatabase $database) {}

            public function verify(string $sha): array
            {
                // The database becomes unreadable between the deploy's own check and the switch-back.
                $this->database->appliedUnreadable = true;

                throw new GatewayReleaseException('verify', 'gateway.release_verify_failed', 'Version mismatch.');
            }

            public function serving(): array
            {
                return ['status' => 'ok', 'version' => 'dev'];
            }
        };

        release_failure(fn () => release_deployer($this->fixture, $verifier, recording_runtime($order), $database, recording_web($order), recording_smoke($order))->execute($sha));
        $record = GatewayRelease::query()->sole();

        expect($record->outcome)->toBe('paused')
            ->and($record->phases['pause']['reason'])->toContain('cannot be read')
            ->and($this->fixture->layout->currentReleaseId())->toBe(substr($sha, 0, 12))
            ->and($order->steps)->not->toContain('handoff:'.$first);
    });

    it('refuses a deploy and a rollback when the applied migrations cannot be read, unless forced', function (): void {
        $first = adopt_release($this->fixture);
        $sha = $this->fixture->commit('Second');
        $database = new OpenReleaseDatabase;
        $database->appliedUnreadable = true;
        $order = new ReleaseSteps;
        $deploy = release_deployer($this->fixture, passing_verifier(), recording_runtime($order), $database, recording_web($order), recording_smoke($order));

        $refused = release_failure(fn () => $deploy->execute($sha));
        $forced = $deploy->execute($sha, force: true);
        $rollback = release_rollback($this->fixture, passing_verifier(), recording_runtime($order), $database, recording_web($order), recording_smoke($order));

        expect($refused->errorCode)->toBe('gateway.release_migrations_unreadable')
            ->and($forced->outcome)->toBe('verified')
            ->and(release_failure(fn () => $rollback->execute($first))->errorCode)->toBe('gateway.release_migrations_unreadable')
            ->and($rollback->execute($first, force: true)->outcome)->toBe('verified');
    });

    it('hands the scheduler back when the scheduler phase fails after it started', function (): void {
        $first = adopt_release($this->fixture);
        $sha = $this->fixture->commit('Scheduler phase fails after the restart');
        $order = new ReleaseSteps;

        release_failure(fn () => release_deployer($this->fixture, passing_verifier($order), recording_runtime($order, scheduleCrashOn: substr($sha, 0, 12)), new OpenReleaseDatabase, recording_web($order), recording_smoke($order))->execute($sha));

        expect($order->steps)->toBe([
            'handoff:'.substr($sha, 0, 12), 'verify', 'schedule:'.substr($sha, 0, 12),
            'web:restore', 'handoff:'.$first, 'schedule:'.$first,
        ])
            ->and(GatewayRelease::query()->sole()->outcome)->toBe('switched_back');
    });

    it('pauses instead of switching back onto a release that lacks a migration the database applied', function (): void {
        $first = adopt_release($this->fixture);
        $sha = $this->fixture->commit('Fails verify after an earlier half-applied migration');
        $database = new OpenReleaseDatabase;
        // A paused release applied this migration; the previous release does not ship it.
        $database->applied = ['2026_12_31_000000_marks'];
        $database->files = [$this->fixture->layout->releasePath(substr($sha, 0, 12)) => ['2026_12_31_000000_marks.php']];
        $order = new ReleaseSteps;
        $verifier = passing_verifier($order);
        $verifier->failSha = $sha;

        $exception = release_failure(fn () => release_deployer($this->fixture, $verifier, recording_runtime($order), $database, recording_web($order), recording_smoke($order))->execute($sha));
        $record = GatewayRelease::query()->sole();

        expect($exception->errorCode)->toBe('gateway.release_verify_failed')
            ->and($record->outcome)->toBe('paused')
            ->and($record->phases['pause']['reason'])->toContain('2026_12_31_000000_marks')
            ->and($this->fixture->layout->currentReleaseId())->toBe(substr($sha, 0, 12))
            ->and($order->steps)->not->toContain('handoff:'.$first);
    });

    it('keeps the previous release when pruning, also when it is the oldest one', function (): void {
        $order = new ReleaseSteps;
        $oldest = adopt_release($this->fixture);
        touch($this->fixture->layout->releasePath($oldest).'/REVISION', time() - 1000);
        $deploy = fn (int $kept): DeployGatewayReleaseAction => release_deployer($this->fixture, passing_verifier(), recording_runtime($order), new OpenReleaseDatabase, recording_web($order), recording_smoke($order), keptReleases: $kept);
        $ids = [];

        foreach (['B', 'C'] as $index => $name) {
            $ids[$name] = $deploy(5)->execute($this->fixture->commit($name))->id;
            touch($this->fixture->layout->releasePath($ids[$name]).'/REVISION', time() - 500 + $index);
        }

        release_rollback($this->fixture, passing_verifier(), recording_runtime($order), new OpenReleaseDatabase, recording_web($order), recording_smoke($order))->execute($oldest);
        $latest = $deploy(1)->execute($this->fixture->commit('D'))->id;

        expect($this->fixture->layout->retainedReleaseIds())->toEqualCanonicalizing([$latest, $oldest])
            ->and($this->fixture->layout->currentReleaseId())->toBe($latest);
    });

    it('prints one JSON object for a deploy and refuses a branch name', function (): void {
        adopt_release($this->fixture);
        $sha = $this->fixture->commit('Command release');
        $this->app->instance(DeployGatewayReleaseAction::class, release_deployer(
            $this->fixture,
            passing_verifier(),
            recording_runtime($order = new ReleaseSteps),
            new OpenReleaseDatabase,
            recording_web($order),
            recording_smoke($order),
        ));

        $this->artisan('gateway:release:deploy', ['commit' => $sha])
            ->expectsOutputToContain('"outcome":"verified"')
            ->assertExitCode(0);
        $this->artisan('gateway:release:deploy', ['commit' => 'main'])
            ->expectsOutputToContain('"error_code":"gateway.release_commit_invalid"')
            ->assertExitCode(2);
        $this->artisan('gateway:release:list')
            ->expectsOutputToContain('"outcome":"verified"')
            ->assertExitCode(0);
        $this->artisan('gateway:release:show', ['release' => substr($sha, 0, 12)])
            ->expectsOutputToContain('"sha":"'.$sha.'"')
            ->assertExitCode(0);
        $this->artisan('gateway:release:show', ['release' => 'not-a-release'])
            ->expectsOutputToContain('"error_code":"gateway.release_id_invalid"')
            ->assertExitCode(2);
    });
});

function adopt_release(GatewayReleaseFixture $fixture): string
{
    $sha = $fixture->commit('Adopted release');
    $fixture->builder()->prepare($sha);
    $id = substr($sha, 0, 12);
    $current = $fixture->layout->currentPath();
    exec('rm -rf '.escapeshellarg($current));
    symlink($fixture->layout->linkTarget($id), $current);

    return $id;
}

function release_deployer(
    GatewayReleaseFixture $fixture,
    GatewayReleaseVerifier $verifier,
    GatewayReleaseRuntime $runtime,
    GatewayReleaseDatabase $database,
    GatewayReleaseWebBuild $web,
    GatewayReleaseSmoke $smoke,
    ?GatewayReleaseSwitcher $switcher = null,
    ?GatewayReleaseLock $lock = null,
    int $keptReleases = GatewayReleasePromoter::KeptReleases,
): DeployGatewayReleaseAction {
    $builder = $fixture->builder($web);
    $recorder = new GatewayReleaseRecorder($fixture->base.'/home');

    return new DeployGatewayReleaseAction(
        $lock ?? new GatewayReleaseLock($fixture->base.'/home/gateway-release.lock'),
        $builder,
        $database,
        new GatewayReleasePromoter(
            $fixture->layout,
            $switcher ?? new GatewayReleaseSwitcher($fixture->layout, $fixture),
            $runtime,
            $verifier,
            $web,
            $smoke,
            $recorder,
            $builder,
            new GatewayReleaseGuard($fixture->layout, $database, $fixture),
            $keptReleases,
        ),
        $recorder,
        new GatewayReleaseGuard($fixture->layout, $database, $fixture),
        new GatewayReleaseRetry,
    );
}

function release_rollback(
    GatewayReleaseFixture $fixture,
    GatewayReleaseVerifier $verifier,
    GatewayReleaseRuntime $runtime,
    GatewayReleaseDatabase $database,
    GatewayReleaseWebBuild $web,
    GatewayReleaseSmoke $smoke,
): RollbackGatewayReleaseAction {
    $builder = $fixture->builder($web);
    $recorder = new GatewayReleaseRecorder($fixture->base.'/home');

    return new RollbackGatewayReleaseAction(
        new GatewayReleaseLock($fixture->base.'/home/gateway-release.lock'),
        $fixture->layout,
        new GatewayReleasePromoter(
            $fixture->layout,
            new GatewayReleaseSwitcher($fixture->layout, $fixture),
            $runtime,
            $verifier,
            $web,
            $smoke,
            $recorder,
            $builder,
            new GatewayReleaseGuard($fixture->layout, $database, $fixture),
        ),
        $recorder,
        new GatewayReleaseGuard($fixture->layout, $database, $fixture),
    );
}

function passing_verifier(?ReleaseSteps $order = null): GatewayReleaseVerifier
{
    return new class($order) implements GatewayReleaseVerifier
    {
        public ?string $failSha = null;

        public function __construct(private readonly ?ReleaseSteps $order) {}

        public function verify(string $sha): array
        {
            if ($this->order instanceof ReleaseSteps) {
                $this->order->steps[] = 'verify';
            }

            if ($this->failSha === $sha) {
                throw new GatewayReleaseException('verify', 'gateway.release_verify_failed', "Version was not [{$sha}].");
            }

            return ['status' => 'ok', 'version' => $sha];
        }

        public function serving(): array
        {
            return ['status' => 'ok', 'version' => 'dev'];
        }
    };
}

function recording_runtime(ReleaseSteps $order, ?string $crashOn = null, ?string $scheduleCrashOn = null): GatewayReleaseRuntime
{
    return new readonly class($order, $crashOn, $scheduleCrashOn) implements GatewayReleaseRuntime
    {
        public function __construct(private ReleaseSteps $order, private ?string $crashOn, private ?string $scheduleCrashOn) {}

        public function handoff(string $id): array
        {
            $this->order->steps[] = 'handoff:'.$id;

            if ($this->crashOn === $id) {
                throw new RuntimeException('The handoff process ran out of memory.');
            }

            return ['caddy' => 'skipped', 'fpm' => 'skipped', 'agent_view' => 'skipped'];
        }

        public function schedule(string $id): array
        {
            $this->order->steps[] = 'schedule:'.$id;

            if ($this->scheduleCrashOn === $id) {
                throw new GatewayReleaseException('handoff', 'gateway.release_units_failed', 'A Process under the Gateway path could not restart.');
            }

            return ['scheduler' => 'skipped', 'cleanup' => 'skipped', 'cleanup_paused' => false];
        }
    };
}

function recording_web(ReleaseSteps $order): GatewayReleaseWebBuild
{
    return new readonly class($order) implements GatewayReleaseWebBuild
    {
        public function __construct(private ReleaseSteps $order) {}

        public function install(string $id, string $sha): bool
        {
            return false;
        }

        public function publish(string $id): void
        {
            $this->order->steps[] = 'web:publish';
        }

        public function restore(string $id): void
        {
            $this->order->steps[] = 'web:restore';
        }
    };
}

function recording_smoke(ReleaseSteps $order): GatewayReleaseSmoke
{
    return new class($order) implements GatewayReleaseSmoke
    {
        public bool $fail = false;

        public function __construct(private readonly ReleaseSteps $order) {}

        public function run(string $id, string $sha): array
        {
            $this->order->steps[] = 'smoke';

            if ($this->fail) {
                throw new GatewayReleaseException('smoke', 'gateway.release_smoke_failed', 'Smoke failed.');
            }

            return ['outcome' => 'passed', 'output' => '{}'];
        }
    };
}

final class ReleaseSteps
{
    /** @var list<string> */
    public array $steps = [];
}

final class OpenReleaseDatabase implements GatewayReleaseDatabase
{
    /** @var list<string> */
    public array $pending = [];

    /** @var array<string, list<string>> */
    public array $files = [];

    public ?Throwable $unreadable = null;

    /** @var list<string> */
    public array $applied = [];

    public bool $migrated = false;

    public bool $failMigrate = false;

    public bool $appliedUnreadable = false;

    public function applied(): array
    {
        if ($this->appliedUnreadable) {
            throw new GatewayReleaseException('snapshot', 'gateway.release_migrations_unreadable', 'The Gateway migrations table cannot be read.', 500);
        }

        return $this->applied;
    }

    public function pending(string $releasePath): array
    {
        if ($this->unreadable instanceof Throwable) {
            throw $this->unreadable;
        }

        return $this->pending;
    }

    public int $snapshots = 0;

    public ?string $directory = null;

    public function snapshot(string $id): string
    {
        if ($this->directory === null) {
            return '/var/tmp/orbit-pre-'.$id.'.sqlite';
        }

        $path = $this->directory.'/pre-'.$id.'-'.++$this->snapshots.'.sqlite';
        touch($path);

        return $path;
    }

    public function migrate(string $releasePath): void
    {
        $this->migrated = true;

        if ($this->failMigrate) {
            throw new GatewayReleaseException('migrate', 'gateway.release_migrate_failed', 'The release migrations failed.', 500);
        }
    }

    public function snapshotBytes(): int
    {
        return 0;
    }

    public function migrations(string $releasePath): array
    {
        return $this->files[$releasePath] ?? [];
    }
}
