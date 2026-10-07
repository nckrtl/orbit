<?php

declare(strict_types=1);

use App\Actions\GatewayReleases\DeployGatewayReleaseAction;
use App\Actions\GatewayReleases\RollbackGatewayReleaseAction;
use App\Domain\GatewayReleases\GatewayReleaseDatabase;
use App\Domain\GatewayReleases\GatewayReleaseException;
use App\Domain\GatewayReleases\GatewayReleaseRuntime;
use App\Domain\GatewayReleases\GatewayReleaseSmoke;
use App\Domain\GatewayReleases\GatewayReleaseVerifier;
use App\Domain\GatewayReleases\GatewayReleaseWebBuild;
use App\Infrastructure\GatewayReleases\GatewayReleaseLock;
use App\Infrastructure\GatewayReleases\GatewayReleasePromoter;
use App\Infrastructure\GatewayReleases\GatewayReleaseRecorder;
use App\Infrastructure\GatewayReleases\GatewayReleaseSwitcher;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Processes\ProcessInvocation;
use App\Infrastructure\Processes\ProcessRunner;
use App\Models\Activity;
use App\Models\GatewayRelease;
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
            ->and($order->steps)->toBe(['handoff:'.$deployed->id, 'verify', 'web:publish', 'smoke'])
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
            ->and($order->steps[0])->toBe('handoff:'.substr($sha, 0, 12))
            ->and($order->steps[1])->toBe('verify')
            ->and($order->steps[2])->toBe('web:publish')
            ->and($order->steps[3])->toBe('smoke')
            ->and($order->steps)->toContain('web:restore')
            ->and($order->steps)->toContain('handoff:'.$first);
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
        $switcher = new GatewayReleaseSwitcher($this->fixture->layout, new class($this->fixture) implements ProcessRunner
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
            ->and(GatewayRelease::query()->first()->retryable)->toBeFalse()
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
            ->and($refused->getMessage())->toContain('pre-'.$deployed->id.'.sqlite')
            ->and($this->fixture->layout->currentReleaseId())->toBe($deployed->id);

        $forced = $rollback->execute($first, force: true);

        expect($forced->outcome)->toBe('verified')
            ->and($forced->trigger)->toBe('rollback')
            ->and($this->fixture->layout->currentReleaseId())->toBe($first);
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
        ),
        $recorder,
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
        $database,
        new GatewayReleasePromoter(
            $fixture->layout,
            new GatewayReleaseSwitcher($fixture->layout, $fixture),
            $runtime,
            $verifier,
            $web,
            $smoke,
            $recorder,
            $builder,
        ),
    );
}

function passing_verifier(?ReleaseSteps $order = null): GatewayReleaseVerifier
{
    return new class($order) implements GatewayReleaseVerifier
    {
        public ?string $failSha = null;

        public function __construct(private ?ReleaseSteps $order) {}

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
    };
}

function recording_runtime(ReleaseSteps $order): GatewayReleaseRuntime
{
    return new class($order) implements GatewayReleaseRuntime
    {
        public function __construct(private ReleaseSteps $order) {}

        public function handoff(string $id): array
        {
            $this->order->steps[] = 'handoff:'.$id;

            return [
                'caddy' => 'skipped',
                'fpm' => 'skipped',
                'scheduler' => 'skipped',
                'cleanup' => 'skipped',
                'agent_view' => 'skipped',
                'cleanup_paused' => false,
            ];
        }
    };
}

function recording_web(ReleaseSteps $order): GatewayReleaseWebBuild
{
    return new class($order) implements GatewayReleaseWebBuild
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

        public function __construct(private ReleaseSteps $order) {}

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

    public function pending(string $releasePath): array
    {
        return $this->pending;
    }

    public function snapshot(string $id): string
    {
        return '/var/tmp/orbit-pre-'.$id.'.sqlite';
    }

    public function migrate(string $releasePath): void {}

    public function migrations(string $releasePath): array
    {
        return $this->files[$releasePath] ?? [];
    }
}
