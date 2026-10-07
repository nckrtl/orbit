<?php

declare(strict_types=1);

use App\Actions\GatewayReleases\AdoptGatewayReleaseAction;
use App\Domain\GatewayReleases\GatewayReleaseException;
use App\Domain\GatewayReleases\GatewayReleaseRuntime;
use App\Domain\GatewayReleases\GatewayReleaseVerifier;
use App\Infrastructure\GatewayReleases\GatewayReleaseAdopter;
use App\Infrastructure\GatewayReleases\GatewayReleaseExchange;
use App\Infrastructure\GatewayReleases\GatewayReleaseLock;
use App\Infrastructure\GatewayReleases\GatewayReleaseRecorder;
use App\Models\Activity;
use App\Models\GatewayRelease;
use Tests\Support\GatewayReleaseFixture;

beforeEach(function (): void {
    $this->fixture = new GatewayReleaseFixture;
    $this->current = $this->fixture->layout->currentPath();
    $this->sha = $this->fixture->inPlaceCheckout();
    $this->id = substr($this->sha, 0, 12);
    $this->steps = new AdoptionSteps;
    config(['orbit.home' => $this->fixture->base.'/home']);
});

afterEach(function (): void {
    $this->fixture->cleanup();
});

describe('gateway:release:adopt', function (): void {
    it('converts the in-place checkout into a release, swaps it for the link in one step, and keeps the checkout', function (): void {
        $result = adoption($this->fixture, $this->steps)->execute();
        $layout = $this->fixture->layout;
        $kept = $layout->basePath().'/orbit.pre-adopt-20261007T120000Z';
        $storage = $layout->storagePath();

        expect($result['release'])->toBe($this->id)
            ->and($result['switch']['method'])->toBe('exchange')
            ->and($result['switch']['gap_us'])->toBe(0)
            ->and($result['pre_adopt_path'])->toBe($kept)
            ->and($layout->currentReleaseId())->toBe($this->id)
            ->and(readlink($this->current))->toBe('releases/'.$this->id)
            ->and(is_dir($kept.'/.git'))->toBeTrue()
            ->and(file_get_contents($kept.'/apps/gateway/.env'))->toBe("APP_ENV=production\nAPP_VERSION=0.1.0\n")
            ->and(file_get_contents($layout->environmentPath()))->toStartWith("APP_ENV=production\n# APP_VERSION=0.1.0 (left out by gateway:release:adopt")
            ->and($result['shared']['app_version_removed'])->toBeTrue()
            ->and(fileperms($layout->environmentPath()) & 0o777)->toBe(0o600)
            ->and(file_get_contents($layout->sharedPath().'/env-backups/.env.bak-deploy-1'))->toBe("APP_ENV=old\n")
            ->and(file_exists($kept.'/apps/gateway/.env.bak-deploy-1'))->toBeTrue()
            ->and(readlink($kept.'/apps/gateway/storage'))->toBe($storage)
            ->and(file_get_contents($storage.'/framework/cache/data/aa/lock'))->toBe('held')
            ->and(fileperms($storage.'/logs/laravel.log') & 0o777)->toBe(0o640)
            ->and(trim($this->fixture->git($kept, 'status', '--porcelain', '--untracked-files=no')))->toBe('')
            ->and(trim($this->fixture->git($layout->repositoryPath(), 'rev-parse', 'refs/orbit/adopted')))->toBe($this->sha)
            ->and(trim($this->fixture->git($layout->repositoryPath(), 'remote', 'get-url', 'origin')))->toBe('https://github.com/nckrtl/orbit.git')
            ->and(trim($this->fixture->git($layout->repositoryPath(), 'rev-parse', 'refs/remotes/origin/main')))->toBe($this->sha)
            ->and($layout->preparedCommit($this->id))->toBe($this->sha)
            ->and(readlink($layout->releaseApplicationPath($this->id).'/storage'))->toBe($storage)
            ->and($this->steps->steps)->toBe(['handoff:'.$this->id, 'verify:'.$this->sha])
            ->and(GatewayRelease::query()->sole()->trigger)->toBe('adopt')
            ->and(GatewayRelease::query()->sole()->outcome)->toBe('verified')
            ->and(Activity::query()->value('command'))->toBe('gateway:release:adopt');
    });

    it('does nothing the second time', function (): void {
        adoption($this->fixture, $this->steps)->execute();

        $again = adoption($this->fixture, $this->steps)->execute();

        expect($again)->toMatchArray(['adopted' => true, 'already' => true, 'release' => $this->id])
            ->and(GatewayRelease::query()->count())->toBe(1);
    });

    it('refuses a checkout with tracked changes before it changes anything', function (): void {
        file_put_contents($this->current.'/apps/gateway/public/index.php', "<?php // hotfix\n");

        $exception = release_failure(fn () => adoption($this->fixture, $this->steps)->execute());

        expect($exception->errorCode)->toBe('gateway.release_adopt_local_changes')
            ->and($exception->getMessage())->toContain('apps/gateway/public/index.php')
            ->and(file_exists($this->fixture->layout->sharedPath()))->toBeFalse()
            ->and(is_dir($this->current.'/apps/gateway/storage'))->toBeTrue()
            ->and(is_link($this->current))->toBeFalse()
            ->and(Activity::query()->value('error_code'))->toBe('gateway.release_adopt_local_changes');
    });

    it('puts the checkout back when verification fails, and finishes on the next run', function (): void {
        $this->steps->failVerify = true;

        $exception = release_failure(fn () => adoption($this->fixture, $this->steps)->execute());

        expect($exception->errorCode)->toBe('gateway.release_verify_failed')
            ->and(is_link($this->current))->toBeFalse()
            ->and(is_dir($this->current.'/.git'))->toBeTrue()
            ->and(glob($this->fixture->layout->basePath().'/orbit.pre-adopt-*'))->toBe([])
            ->and(readlink($this->current.'/apps/gateway/storage'))->toBe($this->fixture->layout->storagePath())
            ->and(GatewayRelease::query()->sole()->outcome)->toBe('switched_back')
            ->and($this->fixture->commands)->toContain([PHP_BINARY, $this->current.'/apps/gateway/artisan', 'gateway:release:handoff', '--no-interaction']);

        $this->steps->failVerify = false;
        $result = adoption($this->fixture, $this->steps)->execute();

        expect($result['shared'])->toMatchArray(['repository' => 'existing', 'env' => 'existing', 'storage' => 'existing'])
            ->and($this->fixture->layout->currentReleaseId())->toBe($this->id)
            ->and(GatewayRelease::query()->latest('id')->value('outcome'))->toBe('verified');
    });

    it('falls back to two renames where an atomic swap is not available', function (): void {
        $result = adoption($this->fixture, $this->steps, python: '/nonexistent/python3')->execute();

        expect($result['switch']['method'])->toBe('rename')
            ->and($result['switch']['gap_us'])->toBeInt()
            ->and($this->fixture->layout->currentReleaseId())->toBe($this->id);
    });

    it('refuses when the shared env file differs from the checkout', function (): void {
        mkdir($this->fixture->layout->sharedPath(), 0700, true);
        file_put_contents($this->fixture->layout->environmentPath(), "APP_ENV=other\n");

        expect(release_failure(fn () => adoption($this->fixture, $this->steps)->execute())->errorCode)->toBe('gateway.release_adopt_env_conflict')
            ->and(is_link($this->current))->toBeFalse();
    });

    it('prints the adoption as one JSON object', function (): void {
        $this->app->instance(AdoptGatewayReleaseAction::class, adoption($this->fixture, $this->steps));

        $this->artisan('gateway:release:adopt')
            ->expectsOutputToContain('"release":"'.$this->id.'"')
            ->assertExitCode(0);
    });
});

function adoption(GatewayReleaseFixture $fixture, AdoptionSteps $steps, string $python = 'python3'): AdoptGatewayReleaseAction
{
    return new AdoptGatewayReleaseAction(
        new GatewayReleaseLock($fixture->base.'/home/gateway-release.lock'),
        new GatewayReleaseAdopter(
            layout: $fixture->layout,
            processes: $fixture,
            builder: $fixture->builder(),
            exchange: new GatewayReleaseExchange($fixture, $python),
            runtime: new readonly class($steps) implements GatewayReleaseRuntime
            {
                public function __construct(private AdoptionSteps $steps) {}

                public function handoff(string $id): array
                {
                    $this->steps->steps[] = 'handoff:'.$id;

                    return ['caddy' => 'reloaded', 'fpm' => 'unchanged', 'scheduler' => 'restarted', 'cleanup' => 'skipped', 'agent_view' => 'restarted', 'cleanup_paused' => false];
                }
            },
            verifier: new readonly class($steps) implements GatewayReleaseVerifier
            {
                public function __construct(private AdoptionSteps $steps) {}

                public function verify(string $sha): array
                {
                    $this->steps->steps[] = 'verify:'.$sha;

                    if ($this->steps->failVerify) {
                        throw new GatewayReleaseException('verify', 'gateway.release_verify_failed', 'Gateway status is [ok] at version [0.1.0].');
                    }

                    return ['status' => 'ok', 'version' => $sha];
                }
            },
            recorder: new GatewayReleaseRecorder($fixture->base.'/home'),
            clock: static fn (): string => '20261007T120000Z',
        ),
    );
}

final class AdoptionSteps
{
    /** @var list<string> */
    public array $steps = [];

    public bool $failVerify = false;
}
