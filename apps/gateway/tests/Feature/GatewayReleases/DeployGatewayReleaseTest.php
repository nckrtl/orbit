<?php

declare(strict_types=1);

use App\Actions\GatewayReleases\ConfigureGatewayReleaseAction;
use App\Actions\GatewayReleases\DeployGatewayReleaseAction;
use App\Actions\GatewayReleases\RollbackGatewayReleaseAction;
use App\Actions\GatewayReleases\SmokeGatewayReleaseAction;
use App\Domain\Fleet\FleetConvergeUnits;
use App\Domain\GatewayReleases\GatewayReleaseDatabase;
use App\Domain\GatewayReleases\GatewayReleaseException;
use App\Domain\GatewayReleases\GatewayReleaseLayout;
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
use App\Infrastructure\GatewayReleases\ScriptGatewayReleaseSmoke;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Processes\ProcessInvocation;
use App\Infrastructure\Processes\ProcessRunner;
use App\Models\Activity;
use App\Models\GatewayRelease;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Tests\Support\Fleet\FakeFleetConvergeUnits;
use Tests\Support\GatewayReleaseFixture;
use Tests\Support\GatewayReleasePipeline;

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

    it('starts the fleet rollout unit after a verified release, and not after a failed one', function (): void {
        $first = adopt_release($this->fixture);
        $fleet = new FakeFleetConvergeUnits;
        $this->fixture->write('apps/gateway/'.GatewayReleasePromoter::FleetCommand, "<?php\n");
        $sha = $this->fixture->commit('Fleet follows');
        $order = new ReleaseSteps;

        release_deployer($this->fixture, passing_verifier($order), recording_runtime($order), new OpenReleaseDatabase, recording_web($order), recording_smoke($order), fleet: $fleet)->execute($sha);

        expect($fleet->started)->toBe(1);

        $broken = $this->fixture->commit('Fleet stays');
        $verifier = passing_verifier($order);
        $verifier->failSha = $broken;
        release_failure(fn () => release_deployer($this->fixture, $verifier, recording_runtime($order), new OpenReleaseDatabase, recording_web($order), recording_smoke($order), fleet: $fleet)->execute($broken));

        expect($fleet->started)->toBe(1)
            ->and($first)->not->toBe('');
    });

    it('does not start the fleet rollout for a verified release without a desired fleet state', function (): void {
        adopt_release($this->fixture);
        $fleet = new FakeFleetConvergeUnits;
        $order = new ReleaseSteps;
        $predates = $this->fixture->commit('Predates the fleet rollout');

        $deployed = release_deployer($this->fixture, passing_verifier($order), recording_runtime($order), new OpenReleaseDatabase, recording_web($order), recording_smoke($order), fleet: $fleet)->execute($predates);

        expect($deployed->outcome)->toBe('verified')
            ->and($fleet->started)->toBe(0);
    });

    it('ships the command the fleet start looks for', function (): void {
        expect(is_file(base_path(GatewayReleasePromoter::FleetCommand)))->toBeTrue();
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

    it('stores the smoke report on the release record and smokes against the handoff time', function (): void {
        adopt_release($this->fixture);
        $sha = $this->fixture->commit('Smoked release');
        $order = new ReleaseSteps;
        $smoke = recording_smoke($order);
        $before = new DateTimeImmutable('now', new DateTimeZone('UTC'));
        $action = release_deployer($this->fixture, passing_verifier($order), recording_runtime($order), new OpenReleaseDatabase, recording_web($order), $smoke);

        $deployed = $action->execute($sha);
        $phases = GatewayRelease::query()->first()->phases;

        expect($deployed->outcome)->toBe('verified')
            ->and($phases['smoke'])->toBe(['outcome' => 'passed', 'report' => ['passed' => true, 'expected_sha' => $sha]])
            ->and($smoke->since)->toBeInstanceOf(DateTimeImmutable::class)
            ->and($smoke->since >= $before)->toBeTrue()
            ->and($smoke->since <= new DateTimeImmutable('now', new DateTimeZone('UTC')))->toBeTrue();
    });

    it('keeps the smoke report of a failed smoke on the switched-back record', function (): void {
        $first = adopt_release($this->fixture);
        $sha = $this->fixture->commit('Smoke fails without migrations');
        $order = new ReleaseSteps;
        $smoke = recording_smoke($order);
        $smoke->fail = true;
        $action = release_deployer($this->fixture, passing_verifier($order), recording_runtime($order), new OpenReleaseDatabase, recording_web($order), $smoke);

        $exception = release_failure(fn () => $action->execute($sha));
        $record = GatewayRelease::query()->first();

        expect($exception->errorCode)->toBe('gateway.release_smoke_failed')
            ->and($record->outcome)->toBe('switched_back')
            ->and($record->migrations_ran)->toBeFalse()
            ->and($record->phases['smoke'])->toBe([
                'report' => ['passed' => false, 'failed_checks' => ['web']],
                'outcome' => 'failed',
                'error_code' => 'gateway.release_smoke_failed',
            ])
            ->and($this->fixture->layout->currentReleaseId())->toBe($first)
            ->and(array_slice($order->steps, -3))->toBe(['web:restore', 'handoff:'.$first, 'schedule:'.$first]);
    });

    it('pauses on the new release when smoke fails after migrations ran', function (): void {
        $first = adopt_release($this->fixture);
        $sha = $this->fixture->commit('Smoke fails after migrations');
        $order = new ReleaseSteps;
        $database = new OpenReleaseDatabase;
        $database->pending = ['2026_10_13_000000_create_gateway_releases_table'];
        $smoke = recording_smoke($order);
        $smoke->fail = true;
        $action = release_deployer($this->fixture, passing_verifier($order), recording_runtime($order), $database, recording_web($order), $smoke);

        $exception = release_failure(fn () => $action->execute($sha));
        $record = GatewayRelease::query()->first();

        expect($exception->step)->toBe('smoke')
            ->and($record->outcome)->toBe('paused')
            ->and($record->migrations_ran)->toBeTrue()
            ->and($record->phases['smoke']['report'])->toBe(['passed' => false, 'failed_checks' => ['web']])
            ->and($this->fixture->layout->currentReleaseId())->toBe(substr($sha, 0, 12))
            ->and(is_file($this->fixture->base.'/home/gateway-release.paused'))->toBeTrue()
            ->and($order->steps)->toBe(['handoff:'.substr($sha, 0, 12), 'verify', 'schedule:'.substr($sha, 0, 12), 'web:publish', 'smoke'])
            ->and($order->steps)->not->toContain('handoff:'.$first);
    });

    it('switches back when the release smoke command runs past its limit', function (): void {
        $first = adopt_release($this->fixture);
        $this->fixture->write('bin/gateway-smoke', "#!/usr/bin/env bash\nsleep 60 &\nwait\n");
        chmod($this->fixture->origin.'/bin/gateway-smoke', 0755);
        $sha = $this->fixture->commit('Smoke hangs');
        $order = new ReleaseSteps;
        $smoke = new ScriptGatewayReleaseSmoke(
            layout: $this->fixture->layout,
            processes: $this->fixture,
            origin: 'https://gateway.orbit',
            webRoot: $this->fixture->base.'/web',
            timeoutSeconds: 1,
            graceSeconds: 0,
        );
        $action = release_deployer($this->fixture, passing_verifier($order), recording_runtime($order), new OpenReleaseDatabase, recording_web($order), $smoke);

        $exception = release_failure(fn () => $action->execute($sha));
        $record = GatewayRelease::query()->first();

        expect($exception->errorCode)->toBe('gateway.release_smoke_timeout')
            ->and($record->outcome)->toBe('switched_back')
            ->and($record->phases['smoke']['error_code'])->toBe('gateway.release_smoke_timeout')
            ->and($record->phases['smoke']['timeout_seconds'])->toBe(1)
            ->and($this->fixture->layout->currentReleaseId())->toBe($first);
    });

    it('removes the web build of a release it prunes', function (): void {
        $first = adopt_release($this->fixture);
        $sha = $this->fixture->commit('Next release');
        $order = new ReleaseSteps;
        $builder = $this->fixture->builder(recording_web($order));
        $builder->prepare($sha);

        $builder->remove(substr($sha, 0, 12));

        expect($order->steps)->toBe(['web:remove:'.substr($sha, 0, 12)])
            ->and(release_failure(fn () => $builder->remove($first))->errorCode)->toBe('gateway.release_current');
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

    it('marks a commit failed when its web build is missing, and retries when GitHub cannot be read', function (string $errorCode, bool $retryable): void {
        $first = adopt_release($this->fixture);
        $sha = $this->fixture->commit('Web build fails');
        $order = new ReleaseSteps;
        $web = new readonly class($errorCode) implements GatewayReleaseWebBuild
        {
            public function __construct(private string $errorCode) {}

            public function install(string $id, string $sha): bool
            {
                throw new GatewayReleaseException('web', $this->errorCode, 'No web build.', sha: $sha);
            }

            public function publish(string $id): void {}

            public function restore(string $id): void {}

            public function remove(string $id): void {}

            public function prune(array $retained): void {}
        };

        $exception = release_failure(fn () => release_deployer($this->fixture, passing_verifier(), recording_runtime($order), new OpenReleaseDatabase, $web, recording_smoke($order))->execute($sha));

        expect($exception->errorCode)->toBe($errorCode)
            ->and($this->fixture->layout->currentReleaseId())->toBe($first)
            ->and($order->steps)->toBe([])
            ->and(GatewayRelease::query()->count())->toBe(1)
            ->and(GatewayRelease::query()->first()->outcome)->toBe('failed')
            ->and(GatewayRelease::query()->first()->retryable)->toBe($retryable)
            ->and(GatewayRelease::query()->first()->error_code)->toBe($errorCode);
    })->with([
        'missing artifact' => ['gateway.release_web_build_missing', false],
        'invalid artifact' => ['gateway.release_web_build_invalid', false],
        'GitHub unreadable' => ['gateway.release_web_build_unavailable', true],
        'web directory missing' => ['gateway.release_web_directory_missing', true],
    ]);

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

    it('prunes web builds that belong to no retained release after a verified deploy', function (): void {
        $first = adopt_release($this->fixture);
        $sha = $this->fixture->commit('Release that prunes');
        $order = new ReleaseSteps;

        release_deployer($this->fixture, passing_verifier(), recording_runtime($order), new OpenReleaseDatabase, recording_web($order), recording_smoke($order))->execute($sha);

        expect($order->pruned)->toEqualCanonicalizing([$first, substr($sha, 0, 12)]);
    });

    it('removes a stale release directory without REVISION and its web build, and keeps a fresh one', function (): void {
        adopt_release($this->fixture);
        $sha = $this->fixture->commit('Release that prunes a stopped prepare');
        $order = new ReleaseSteps;
        $stale = $this->fixture->layout->releasePath('aaaaaaaaaaaa');
        $fresh = $this->fixture->layout->releasePath('bbbbbbbbbbbb');
        $conflict = $this->fixture->layout->releasePath('cccccccccccc');

        foreach ([$stale, $fresh, $conflict] as $path) {
            mkdir($path.'/apps/gateway/vendor', 0755, true);
            file_put_contents($path.'/apps/gateway/vendor/autoload.php', '<?php');
        }

        file_put_contents($conflict.'/REVISION', str_repeat('d', 40)."\n");
        // A prepare that stopped can leave read-only files behind.
        chmod($stale.'/apps/gateway/vendor', 0o555);
        touch($stale, time() - GatewayReleasePromoter::IncompleteReleaseSeconds - 60);
        touch($conflict, time() - GatewayReleasePromoter::IncompleteReleaseSeconds - 60);
        touch($fresh, time() - GatewayReleasePromoter::IncompleteReleaseSeconds + 600);

        $deployed = release_deployer($this->fixture, passing_verifier(), recording_runtime($order), new OpenReleaseDatabase, recording_web($order), recording_smoke($order))->execute($sha);

        expect($deployed->outcome)->toBe('verified')
            ->and(file_exists($stale))->toBeFalse()
            ->and(is_dir($fresh))->toBeTrue()
            ->and(is_dir($conflict))->toBeTrue()
            ->and($order->steps)->toContain('web:remove:aaaaaaaaaaaa')
            ->and($order->steps)->not->toContain('web:remove:bbbbbbbbbbbb')
            ->and($order->steps)->not->toContain('web:remove:cccccccccccc')
            ->and($this->fixture->layout->incompleteReleaseIds())->toBe(['bbbbbbbbbbbb']);
    });

    it('keeps an incomplete release directory that is current', function (): void {
        adopt_release($this->fixture);
        $sha = $this->fixture->commit('Release over an incomplete current one');
        $order = new ReleaseSteps;
        $smoke = new readonly class($this->fixture->layout) implements GatewayReleaseSmoke
        {
            public function __construct(private GatewayReleaseLayout $layout) {}

            public function run(string $id, string $sha, ?DateTimeImmutable $since = null, array $skip = []): array
            {
                // The switch made this release current; take its REVISION away and age it, as a broken repair would.
                chmod($this->layout->releasePath($id), 0o755);
                unlink($this->layout->releasePath($id).'/REVISION');
                touch($this->layout->releasePath($id), time() - GatewayReleasePromoter::IncompleteReleaseSeconds - 60);

                return ['outcome' => 'passed', 'report' => ['passed' => true]];
            }
        };

        $deployed = release_deployer($this->fixture, passing_verifier(), recording_runtime($order), new OpenReleaseDatabase, recording_web($order), $smoke)->execute($sha);

        expect($deployed->outcome)->toBe('verified')
            ->and($this->fixture->layout->currentReleaseId())->toBe(substr($sha, 0, 12))
            ->and(is_dir($this->fixture->layout->releasePath(substr($sha, 0, 12))))->toBeTrue()
            ->and($this->fixture->layout->incompleteReleaseIds())->toBe([substr($sha, 0, 12)])
            ->and($order->steps)->not->toContain('web:remove:'.substr($sha, 0, 12));
    });

    it('skips pruning web builds when the releases directory cannot be read', function (): void {
        adopt_release($this->fixture);
        $sha = $this->fixture->commit('Release while the releases directory turns unreadable');
        $order = new ReleaseSteps;
        $releases = $this->fixture->layout->releasesPath();
        $smoke = new readonly class($releases) implements GatewayReleaseSmoke
        {
            public function __construct(private string $releases) {}

            public function run(string $id, string $sha, ?DateTimeImmutable $since = null, array $skip = []): array
            {
                chmod($this->releases, 0o300);

                return ['outcome' => 'passed', 'report' => ['passed' => true]];
            }
        };

        try {
            $deployed = release_deployer($this->fixture, passing_verifier(), recording_runtime($order), new OpenReleaseDatabase, recording_web($order), $smoke)->execute($sha);
        } finally {
            chmod($releases, 0o755);
        }

        expect($deployed->outcome)->toBe('verified')
            ->and($order->pruned)->toBeNull();
    });

    it('installs a missing web build from CI when it rolls back', function (): void {
        $first = adopt_release($this->fixture);
        $sha = $this->fixture->commit('Release to roll back from');
        $order = new ReleaseSteps;
        release_deployer($this->fixture, passing_verifier(), recording_runtime($order), new OpenReleaseDatabase, recording_web($order), recording_smoke($order))->execute($sha);
        $web = new RollbackWebBuild($order);
        $smoke = recording_smoke($order);

        $rolled = release_rollback($this->fixture, passing_verifier(), recording_runtime($order), new OpenReleaseDatabase, $web, $smoke)->execute($first);

        expect($rolled->outcome)->toBe('verified')
            ->and($rolled->phases['web'])->toBe(['outcome' => 'published', 'installed' => true])
            ->and($web->installed)->toBe([$first])
            ->and($smoke->skip)->toBe([]);
    });

    it('keeps the web app and rolls the code back when CI no longer has the web build', function (): void {
        $first = adopt_release($this->fixture);
        $sha = $this->fixture->commit('Release to roll back from');
        $order = new ReleaseSteps;
        release_deployer($this->fixture, passing_verifier(), recording_runtime($order), new OpenReleaseDatabase, recording_web($order), recording_smoke($order))->execute($sha);
        $web = new RollbackWebBuild($order);
        $web->installFails = true;
        $smoke = recording_smoke($order);

        $rolled = release_rollback($this->fixture, passing_verifier(), recording_runtime($order), new OpenReleaseDatabase, $web, $smoke)->execute($first);

        expect($rolled->outcome)->toBe('verified')
            ->and($rolled->phases['web']['outcome'])->toBe('kept')
            ->and($rolled->phases['web']['error_code'])->toBe('gateway.release_web_build_missing')
            ->and($rolled->phases['web']['warning'])->toContain('stays as it was')
            ->and($this->fixture->layout->currentReleaseId())->toBe($first)
            ->and($smoke->skip)->toBe(['web'])
            ->and($order->steps)->not->toContain('web:restore');
    });

    it('still switches a deploy back when its web build is missing', function (): void {
        $first = adopt_release($this->fixture);
        $sha = $this->fixture->commit('Deploy without a web build');
        $order = new ReleaseSteps;
        $web = new RollbackWebBuild($order);
        $web->vanishes = true;

        $exception = release_failure(fn () => release_deployer($this->fixture, passing_verifier(), recording_runtime($order), new OpenReleaseDatabase, $web, recording_smoke($order))->execute($sha));

        expect($exception->errorCode)->toBe('gateway.release_web_build_missing')
            ->and($web->installs)->toBe(1)
            ->and($this->fixture->layout->currentReleaseId())->toBe($first)
            ->and(GatewayRelease::query()->latest('id')->first()->outcome)->toBe('switched_back');
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

    it('ends a refused rollback as a failed retryable record with no commit and changes nothing', function (): void {
        $first = adopt_release($this->fixture);
        $order = new ReleaseSteps;
        $rollback = release_rollback($this->fixture, passing_verifier(), recording_runtime($order), new OpenReleaseDatabase, recording_web($order), recording_smoke($order));

        $exception = release_failure(fn () => $rollback->execute('0123456789ab'));

        expect($exception->errorCode)->toBe('gateway.release_not_prepared')
            ->and($this->fixture->layout->currentReleaseId())->toBe($first)
            ->and(GatewayRelease::query()->sole())
            ->outcome->toBe('failed')
            ->retryable->toBeTrue()
            ->sha->toBeNull()
            ->trigger->toBe('rollback')
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
            ->and(GatewayRelease::query()->sole())
            ->outcome->toBe('failed')
            ->retryable->toBeTrue()
            ->error_code->toBe('gateway.release_downgrade')
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

describe('gateway:release:smoke', function (): void {
    it('prints the smoke report of the live release and changes nothing', function (): void {
        $id = adopt_release($this->fixture);
        $sha = (string) $this->fixture->layout->preparedCommit($id);
        $this->app->instance(SmokeGatewayReleaseAction::class, new SmokeGatewayReleaseAction(
            $this->fixture->layout,
            command_smoke($this->fixture, passed: true),
        ));

        $exitCode = Artisan::call('gateway:release:smoke');
        $printed = json_decode(Artisan::output(), true);

        expect($exitCode)->toBe(0)
            ->and($printed)->toBe(['release' => $id, 'sha' => $sha, 'outcome' => 'passed', 'report' => ['schema' => 1, 'passed' => true, 'checks' => []]]);

        $this->artisan('gateway:release:smoke', ['commit' => substr($sha, 0, 7), '--since' => '2026-10-07T06:00:00Z'])
            ->expectsOutputToContain('"sha":"'.substr($sha, 0, 7).'"')
            ->assertExitCode(0);

        expect(GatewayRelease::query()->count())->toBe(0)
            ->and(file_get_contents($this->fixture->base.'/smoke-args'))->toContain("--since\n2026-10-07T06:00:00Z");
    });

    it('prints the failed report and exits 1, and refuses a time without a zone', function (): void {
        adopt_release($this->fixture);
        $this->app->instance(SmokeGatewayReleaseAction::class, new SmokeGatewayReleaseAction(
            $this->fixture->layout,
            command_smoke($this->fixture, passed: false),
        ));

        $exitCode = Artisan::call('gateway:release:smoke');
        $printed = json_decode(Artisan::output(), true);

        expect($exitCode)->toBe(1)
            ->and($printed['error_code'])->toBe('gateway.release_smoke_failed')
            ->and($printed['step'])->toBe('smoke')
            ->and($printed['detail']['report']['failed_checks'])->toBe(['web']);

        $this->artisan('gateway:release:smoke', ['--since' => '2026-10-07 06:00'])
            ->expectsOutputToContain('"error_code":"gateway.release_since_invalid"')
            ->assertExitCode(2);
    });
});

/** A smoke runner whose `bin/gateway-smoke` in the current release is a stand-in that records its arguments. */
function command_smoke(GatewayReleaseFixture $fixture, bool $passed): ScriptGatewayReleaseSmoke
{
    $script = $fixture->layout->currentPath().'/bin/gateway-smoke';
    $release = (string) realpath($fixture->layout->currentPath());
    exec('chmod u+w '.escapeshellarg($release).' && mkdir -p '.escapeshellarg($release.'/bin'));
    $report = $passed
        ? '{"schema":1,"passed":true,"checks":{}}'
        : '{"schema":1,"passed":false,"error":"checks_failed","failed_checks":["web"],"message":"1 of 7 checks did not pass: web."}';
    file_put_contents($script, "#!/usr/bin/env bash\nprintf '%s\\n' \"\$@\" > ".escapeshellarg($fixture->base.'/smoke-args')."\necho '".$report."'\n".($passed ? '' : "exit 1\n"));
    chmod($script, 0755);

    return new ScriptGatewayReleaseSmoke(
        layout: $fixture->layout,
        processes: $fixture,
        origin: 'https://gateway.orbit',
        webRoot: $fixture->base.'/web',
    );
}

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
    ?FleetConvergeUnits $fleet = null,
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
            fleet: $fleet,
        ),
        $recorder,
        new GatewayReleaseGuard($fixture->layout, $database, $fixture),
        new GatewayReleaseRetry,
        GatewayReleasePipeline::newestGreen(),
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

        public function remove(string $id): void
        {
            $this->order->steps[] = 'web:remove:'.$id;
        }

        public function prune(array $retained): void
        {
            $this->order->pruned = $retained;
        }
    };
}

function recording_smoke(ReleaseSteps $order): GatewayReleaseSmoke
{
    return new class($order) implements GatewayReleaseSmoke
    {
        public bool $fail = false;

        public function __construct(private readonly ReleaseSteps $order) {}

        public ?DateTimeImmutable $since = null;

        /** @var list<string> */
        public array $skip = [];

        public function run(string $id, string $sha, ?DateTimeImmutable $since = null, array $skip = []): array
        {
            $this->order->steps[] = 'smoke';
            $this->since = $since;
            $this->skip = $skip;

            if ($this->fail) {
                throw new GatewayReleaseException('smoke', 'gateway.release_smoke_failed', 'Smoke failed.', phase: ['report' => ['passed' => false, 'failed_checks' => ['web']]]);
            }

            return ['outcome' => 'passed', 'report' => ['passed' => true, 'expected_sha' => $sha]];
        }
    };
}

final class ReleaseSteps
{
    /** @var list<string> */
    public array $steps = [];

    /** @var list<string>|null */
    public ?array $pruned = null;
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

/** A web build with no build installed yet: publish fails until install ran, and install can fail as CI would. */
final class RollbackWebBuild implements GatewayReleaseWebBuild
{
    public bool $installFails = false;

    /** The installed build disappears before publish, as if something removed it after prepare. */
    public bool $vanishes = false;

    /** @var list<string> */
    public array $installed = [];

    public int $installs = 0;

    public function __construct(private readonly ReleaseSteps $order) {}

    public function install(string $id, string $sha): bool
    {
        if ($this->installFails) {
            throw new GatewayReleaseException('web', 'gateway.release_web_build_missing', 'The CI artifact has expired.', 422);
        }

        $this->installs++;

        if (! $this->vanishes) {
            $this->installed[] = $id;
        }

        return false;
    }

    public function publish(string $id): void
    {
        if (! in_array($id, $this->installed, true)) {
            throw new GatewayReleaseException('web', 'gateway.release_web_build_missing', "The web build [{$id}] is not installed.", 422);
        }

        $this->order->steps[] = 'web:publish';
    }

    public function restore(string $id): void
    {
        $this->order->steps[] = 'web:restore';
    }

    public function remove(string $id): void {}

    public function prune(array $retained): void {}
}
