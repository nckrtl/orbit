<?php

declare(strict_types=1);

use App\Actions\GatewayReleases\PrepareGatewayReleaseAction;
use App\Domain\GatewayReleases\GatewayReleaseException;
use App\Domain\GatewayReleases\GatewayReleaseWebBuild;
use App\Infrastructure\GatewayReleases\GatewayReleaseLock;
use App\Infrastructure\Processes\NativeProcessRunner;
use App\Infrastructure\Processes\ProcessInvocation;
use Tests\Support\GatewayReleaseFixture;

beforeEach(function (): void {
    $this->fixture = new GatewayReleaseFixture;
    $this->live = $this->fixture->layout->currentPath();
    mkdir($this->live.'/apps/gateway', 0755, true);
    file_put_contents($this->live.'/apps/gateway/live.txt', 'serving');
});

afterEach(function (): void {
    $this->fixture->cleanup();
});

function release_failure(Closure $operation): GatewayReleaseException
{
    try {
        $operation();
    } catch (GatewayReleaseException $exception) {
        return $exception;
    }

    throw new RuntimeException('The release step did not fail.');
}

describe('gateway:release:prepare', function (): void {
    it('builds an immutable worktree of the exact commit with shared env, storage, dependencies, and REVISION', function (): void {
        $sha = $this->fixture->commit('Second commit');
        $this->fixture->write('apps/gateway/later.txt', 'later');
        $this->fixture->commit('Later commit');

        $release = $this->fixture->builder()->prepare(substr($sha, 0, 9));
        $path = $this->fixture->layout->releasePath(substr($sha, 0, 12));
        $application = $path.'/apps/gateway';

        expect($release->sha)->toBe($sha)
            ->and($release->id)->toBe(substr($sha, 0, 12))
            ->and($release->reused)->toBeFalse()
            ->and(trim((string) file_get_contents($path.'/REVISION')))->toBe($sha)
            ->and((require $application.'/bootstrap/cache/config.php')['app']['version'])->toBe($sha)
            ->and(trim((string) shell_exec('git -C '.escapeshellarg($path).' rev-parse HEAD')))->toBe($sha)
            ->and(file_exists($application.'/later.txt'))->toBeFalse()
            ->and(readlink($application.'/.env'))->toBe($this->fixture->layout->environmentPath())
            ->and(readlink($application.'/storage'))->toBe($this->fixture->layout->storagePath())
            ->and(is_dir($this->fixture->layout->storagePath().'/framework/cache/data'))->toBeTrue()
            ->and(is_file($application.'/vendor/autoload.php'))->toBeTrue()
            ->and(is_file($path.'/apps/cli/vendor/autoload.php'))->toBeTrue()
            ->and(trim((string) shell_exec('git -C '.escapeshellarg($path).' status --porcelain')))->toBe('')
            ->and(is_writable($application))->toBeFalse()
            ->and(is_writable($application.'/bootstrap/cache'))->toBeTrue()
            ->and($this->fixture->privileged)->toContain(['grant-access', $application])
            ->and(file_get_contents($this->live.'/apps/gateway/live.txt'))->toBe('serving')
            ->and(is_link($this->live))->toBeFalse();
    });

    it('reuses a complete release without running a build step again', function (): void {
        $sha = $this->fixture->commit('Second commit');
        $this->fixture->builder()->prepare($sha);
        $this->fixture->commands = [];

        $again = $this->fixture->builder()->prepare($sha);

        expect($again->reused)->toBeTrue()
            ->and(collect($this->fixture->commands)->map(fn (array $command): string => implode(' ', array_slice($command, 0, 4))))
            ->each->not->toContain('worktree add')
            ->and(collect($this->fixture->commands)->flatten()->contains($this->fixture->composer))->toBeFalse();
    });

    it('leaves the live release untouched when a dependency install fails and finishes on the next run', function (): void {
        $this->fixture->write('apps/gateway/composer.fail', 'fail');
        $broken = $this->fixture->commit('Broken dependencies');
        $exception = release_failure(fn () => $this->fixture->builder()->prepare($broken));
        $path = $this->fixture->layout->releasePath(substr($broken, 0, 12));

        expect($exception->errorCode)->toBe('gateway.release_dependencies_failed')
            ->and($exception->step)->toBe('dependencies')
            ->and(file_exists($path.'/REVISION'))->toBeFalse()
            ->and($this->fixture->layout->preparedCommit(substr($broken, 0, 12)))->toBeNull()
            ->and($this->fixture->layout->retainedReleaseIds())->toBe([])
            ->and(file_get_contents($this->live.'/apps/gateway/live.txt'))->toBe('serving');

        unlink($this->fixture->origin.'/apps/gateway/composer.fail');
        $fixed = $this->fixture->commit('Fixed dependencies');
        $release = $this->fixture->builder()->prepare($fixed);

        expect($this->fixture->layout->preparedCommit($release->id))->toBe($fixed);
    });

    it('rebuilds a partial release left by an interrupted prepare', function (): void {
        $sha = $this->fixture->commit('Second commit');
        $web = new class implements GatewayReleaseWebBuild
        {
            public bool $fail = true;

            public function install(string $id, string $sha): bool
            {
                if ($this->fail) {
                    throw new GatewayReleaseException('web', 'gateway.release_web_build_missing', 'No web build.');
                }

                return true;
            }

            public function publish(string $id): void {}

            public function restore(string $id): void {}
        };

        expect(release_failure(fn () => $this->fixture->builder($web)->prepare($sha))->errorCode)->toBe('gateway.release_web_build_missing');

        $web->fail = false;
        $release = $this->fixture->builder($web)->prepare($sha);

        expect($release->reused)->toBeFalse()
            ->and($this->fixture->layout->preparedCommit($release->id))->toBe($sha);
    });

    it('leaves the release unprepared when the configuration cannot be cached', function (): void {
        $this->fixture->write('apps/gateway/config.fail', 'fail');
        $sha = $this->fixture->commit('Broken configuration');

        $exception = release_failure(fn () => $this->fixture->builder()->prepare($sha));

        expect($exception->errorCode)->toBe('gateway.release_configuration_failed')
            ->and($this->fixture->layout->preparedCommit(substr($sha, 0, 12)))->toBeNull();
    });

    it('refuses revision syntax that is not a hex SHA before running Git', function (string $revision): void {
        $exception = release_failure(fn () => $this->fixture->builder()->prepare($revision));

        expect($exception->errorCode)->toBe('gateway.release_commit_invalid')
            ->and($exception->status)->toBe(422)
            ->and($this->fixture->commands)->toBe([]);
    })->with(['main', 'HEAD~1', 'abc12', '--upload-pack=x', 'abcdef1 ']);

    it('refuses a commit the repository does not have', function (): void {
        $exception = release_failure(fn () => $this->fixture->builder()->prepare(str_repeat('a', 40)));

        expect($exception->errorCode)->toBe('gateway.release_commit_unknown')
            ->and(is_dir($this->fixture->layout->releasesPath().'/aaaaaaaaaaaa'))->toBeFalse();
    });

    it('refuses before writing when the releases directory is low on space', function (): void {
        $sha = $this->fixture->commit('Second commit');
        $exception = release_failure(fn () => $this->fixture->builder(freeBytes: 1_048_576)->prepare($sha));

        expect($exception->errorCode)->toBe('gateway.release_disk_low')
            ->and(file_exists($this->fixture->layout->releasePath(substr($sha, 0, 12))))->toBeFalse();
    });

    it('refuses below the configured free-space floor', function (): void {
        $sha = $this->fixture->commit('Second commit');
        $exception = release_failure(fn () => $this->fixture->builder(freeBytes: 2 * 1_073_741_824, minimumFreeBytes: 3 * 1_073_741_824)->prepare($sha));

        expect($exception->errorCode)->toBe('gateway.release_disk_low')
            ->and($exception->getMessage())->toContain('needs at least 3072 MiB')
            ->and(file_exists($this->fixture->layout->releasePath(substr($sha, 0, 12))))->toBeFalse();
    });

    it('refuses without the shared repository that adoption creates', function (): void {
        exec('rm -rf '.escapeshellarg($this->fixture->layout->repositoryPath()));

        expect(release_failure(fn () => $this->fixture->builder()->prepare(str_repeat('b', 40)))->errorCode)
            ->toBe('gateway.release_layout_missing');
    });

    it('runs one release step at a time', function (): void {
        $lock = new GatewayReleaseLock($this->fixture->base.'/home/gateway-release.lock');
        $action = new PrepareGatewayReleaseAction($lock, $this->fixture->builder());

        $exception = $lock->run(fn (): GatewayReleaseException => release_failure(fn () => $action->execute(str_repeat('c', 40))));

        expect($exception->errorCode)->toBe('gateway.release_in_progress')
            ->and($this->fixture->commands)->toBe([]);
    });

    it('does not hand the lock to the commands a release step runs', function (): void {
        $lock = new GatewayReleaseLock($this->fixture->base.'/home/gateway-release.lock');

        $descriptors = $lock->run(fn (): string => new NativeProcessRunner()->run(
            new ProcessInvocation(['sh', '-c', 'ls -l /proc/$$/fd'], timeout: 10.0),
        )->stdout);

        expect($descriptors)->not->toContain('gateway-release.lock');
    });

    it('prints the prepared release as one JSON object', function (): void {
        $sha = $this->fixture->commit('Second commit');
        $this->app->instance(PrepareGatewayReleaseAction::class, new PrepareGatewayReleaseAction(
            new GatewayReleaseLock($this->fixture->base.'/home/gateway-release.lock'),
            $this->fixture->builder(),
        ));

        $this->artisan('gateway:release:prepare', ['commit' => $sha])
            ->expectsOutputToContain('"release":"'.substr($sha, 0, 12).'"')
            ->assertExitCode(0);
        $this->artisan('gateway:release:prepare', ['commit' => 'main'])
            ->expectsOutputToContain('"error_code":"gateway.release_commit_invalid"')
            ->assertExitCode(2);
    });
});
