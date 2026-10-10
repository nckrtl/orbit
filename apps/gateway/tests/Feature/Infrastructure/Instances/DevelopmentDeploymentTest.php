<?php

declare(strict_types=1);

use App\Actions\Instances\DeployDefaultInstanceAction;
use App\Actions\Instances\SelectInstanceSeedAction;
use App\Domain\Instances\Deployment\DeploymentRequest;
use App\Domain\Instances\Deployment\DevelopmentDeployment;
use App\Domain\Projects\DevelopmentDeployStep;
use App\Domain\Projects\ProjectDevelopmentDeployStepStore;
use App\Models\Instance;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Log;
use Tests\Support\DevelopmentDeploymentFixture;

beforeEach(function (): void {
    $this->fixture = new DevelopmentDeploymentFixture;
    app()->instance(DevelopmentDeployment::class, $this->fixture->deployment);
});

afterEach(function (): void {
    $this->fixture->cleanup();
});

/** @param list<DevelopmentDeployStep> $steps */
function plain_steps(Instance $instance, array $steps): void
{
    foreach ($steps as $step) {
        app(ProjectDevelopmentDeployStepStore::class)->create($instance->project, $step, null, null);
    }
}

function plain_git(string $directory, string ...$arguments): string
{
    return trim(DevelopmentDeploymentFixture::command(['git', '-C', $directory, ...$arguments]));
}

describe('development defaults in place', function (): void {
    it('checks out the newest commit on the branch and keeps untracked files and linked worktrees', function (): void {
        $instance = $this->fixture->instance;
        $home = $this->fixture->home;
        plain_steps($instance, [new DevelopmentDeployStep('install', 'printf "%s" "$PWD" > step-ran-in')]);
        $commit = $this->fixture->push('second');

        $result = app(DeployDefaultInstanceAction::class)->execute($instance, onlyChanged: true, triggeredBy: 'schedule');

        expect($result?->succeeded)->toBeTrue()
            ->and($result?->commit)->toBe($commit)
            ->and(plain_git($home, 'rev-parse', 'HEAD'))->toBe($commit)
            ->and(plain_git($home, 'symbolic-ref', '--short', 'HEAD'))->toBe('main')
            ->and(file_get_contents($home.'/public/index.php'))->toContain('second')
            ->and(file_exists($home.'/old.txt'))->toBeFalse()
            ->and(file_exists($home.'/packages'))->toBeFalse()
            ->and(file_get_contents($home.'/step-ran-in'))->toBe($home)
            ->and(file_get_contents($home.'/.cache/warm'))->toBe('previous-cache')
            ->and(file_get_contents($home.'/.env'))->toBe("APP_ENV=development\n")
            ->and(file_exists($home.'/releases'))->toBeFalse()
            ->and(file_exists($home.'/current'))->toBeFalse()
            ->and(plain_git($this->fixture->sandbox.'/apps/dev935/t3code-ab12', 'rev-parse', 'HEAD'))->toBe($this->fixture->initialCommit)
            ->and($instance->fresh()->seed_path)->toBe($home)
            ->and($instance->fresh()->seed_commit)->toBe($commit);
        expect(app(DeployDefaultInstanceAction::class)->execute($instance, onlyChanged: true, triggeredBy: 'schedule'))->toBeNull();

        $consumer = Instance::query()->create([
            'project_id' => $instance->project_id, 'node_id' => $instance->node_id, 'name' => 'feature',
            'checkout_path' => $this->fixture->sandbox.'/apps/dev935/feature', 'status' => 'reserved',
        ]);
        app(SelectInstanceSeedAction::class)->execute($consumer);
        expect($consumer->fresh()->seed_path)->toBe($home)
            ->and($consumer->fresh()->seed_commit)->toBe($commit)
            ->and($consumer->fresh()->seed_repository)->toBe($home);
    });

    it('leaves the checkout at the new commit after a failed step and retries the steps on the next tick', function (): void {
        $instance = $this->fixture->instance;
        $home = $this->fixture->home;
        plain_steps($instance, [new DevelopmentDeployStep('install', 'test -f allow-install; touch installed')]);
        $commit = $this->fixture->push('second');

        $failed = app(DeployDefaultInstanceAction::class)->execute($instance, onlyChanged: true, triggeredBy: 'schedule');

        expect($failed?->failure?->errorCode)->toBe('deployment.step_failed')
            ->and(plain_git($home, 'rev-parse', 'HEAD'))->toBe($commit)
            ->and($instance->fresh()->seed_commit)->toBeNull();

        touch($home.'/allow-install');
        $retried = app(DeployDefaultInstanceAction::class)->execute($instance, onlyChanged: true, triggeredBy: 'schedule');

        expect($retried?->succeeded)->toBeTrue()
            ->and(file_exists($home.'/installed'))->toBeTrue()
            ->and($instance->fresh()->seed_commit)->toBe($commit);
    });

    it('refuses a checkout with uncommitted changes to tracked files and changes nothing', function (): void {
        $home = $this->fixture->home;
        file_put_contents($home.'/public/index.php', '<?php echo "local edit";');
        $this->fixture->push('second');
        $events = [];

        $result = app(DeployDefaultInstanceAction::class)->execute($this->fixture->instance, new DeploymentRequest(static function ($event) use (&$events): void {
            $events[] = $event->value;
        }));

        expect($result?->failure?->errorCode)->toBe('deployment.checkout_dirty')
            ->and(plain_git($home, 'rev-parse', 'HEAD'))->toBe($this->fixture->initialCommit)
            ->and(file_get_contents($home.'/public/index.php'))->toBe('<?php echo "local edit";')
            ->and(implode('', $events))->toContain('public/index.php');
    });

    it('refuses a local branch with commits that the target commit does not contain', function (): void {
        $home = $this->fixture->home;
        plain_git($home, '-c', 'user.email=dev935@example.test', '-c', 'user.name=Local', 'commit', '--allow-empty', '-m', 'local only');
        $local = plain_git($home, 'rev-parse', 'HEAD');
        $this->fixture->push('second');

        $result = app(DeployDefaultInstanceAction::class)->execute($this->fixture->instance);

        expect($result?->failure?->errorCode)->toBe('deployment.branch_diverged')
            ->and(plain_git($home, 'rev-parse', 'HEAD'))->toBe($local)
            ->and(plain_git($home, 'rev-parse', 'main'))->toBe($local);
    });

    it('runs back-to-back steps when an earlier step leaves a process behind', function (): void {
        plain_steps($this->fixture->instance, [
            new DevelopmentDeployStep('daemon', 'setsid -f sleep 5 >/dev/null 2>&1 </dev/null'),
            ...array_map(static fn (int $step): DevelopmentDeployStep => new DevelopmentDeployStep('step-'.$step, 'true'), range(1, 8)),
        ]);

        $result = app(DeployDefaultInstanceAction::class)->execute($this->fixture->instance);

        expect($result?->succeeded)->toBeTrue()
            ->and($result?->commands)->toHaveCount(9);
    });

    it('waits for a setup step that runs in the checkout', function (): void {
        $this->fixture->push('second');
        $lock = DevelopmentDeploymentFixture::holdLifecycleLock($this->fixture->home);

        $result = app(DeployDefaultInstanceAction::class)->execute($this->fixture->instance);
        fclose($lock);

        expect($result?->failure?->errorCode)->toBe('instance.lifecycle_busy')
            ->and(plain_git($this->fixture->home, 'rev-parse', 'HEAD'))->toBe($this->fixture->initialCommit);
    });

    it('stops a step at its timeout', function (): void {
        plain_steps($this->fixture->instance, [new DevelopmentDeployStep('hang', 'sleep 60', timeoutSeconds: 1)]);
        $started = microtime(true);

        $result = app(DeployDefaultInstanceAction::class)->execute($this->fixture->instance);

        expect($result?->failure?->errorCode)->toBe('deployment.step_failed')
            ->and($result?->commands[0]->result->exitCode)->toBe(143)
            ->and(microtime(true) - $started)->toBeLessThan(20.0);
    });
});

describe('converting an old release layout', function (): void {
    beforeEach(function (): void {
        $fixture = $this->fixture;
        $this->first = $fixture->initialCommit;
        $this->second = $fixture->push('second');
        DevelopmentDeploymentFixture::command(['git', '-C', $fixture->home, 'fetch', $fixture->origin, 'main']);
        $fixture->releaseLayout(['20261005112105-a200f174bf2565c7' => $this->first, '20261009164518-56c418b8ebd18e71' => $this->second], '20261009164518-56c418b8ebd18e71');
        $this->release = $fixture->home.'/releases/20261009164518-56c418b8ebd18e71';
        $this->leased = $fixture->home.'/releases/20261005112105-a200f174bf2565c7';
        // The served release holds newer dependencies and runtime data than the checkout.
        file_put_contents($this->release.'/apps/gateway/vendor/dependency.bin', 'release-dependency');
        file_put_contents($this->release.'/.cache/warm', 'release-cache');
        file_put_contents($this->release.'/.env', "APP_ENV=stale-release-copy\n");
        file_put_contents($this->release.'/.env.testing', "APP_ENV=testing\n");
        file_put_contents($this->release.'/database.sqlite', 'live-data');
        file_put_contents($fixture->home.'/database.sqlite', 'stale-data');
        file_put_contents($fixture->home.'/home-only.log', 'kept');
        new Filesystem()->makeDirectory($this->release.'/storage/app/public', 0o755, true);
        file_put_contents($this->release.'/storage/app/public/upload.txt', 'upload');
        symlink($this->release.'/storage/app/public', $this->release.'/public/storage');
        $this->consumer = Instance::query()->create([
            'project_id' => $fixture->instance->project_id, 'node_id' => $fixture->instance->node_id, 'name' => 't3code-ab12',
            'checkout_path' => $fixture->sandbox.'/apps/dev935/t3code-ab12', 'source_layout' => 'worktree', 'status' => 'active',
            'seed_selected' => true, 'seed_path' => $this->leased, 'seed_commit' => $this->first, 'seed_repository' => $fixture->home,
        ]);
    });

    it('makes the checkout serve what the selected release served and removes the releases', function (): void {
        $home = $this->fixture->home;
        $instance = $this->fixture->instance;

        $result = app(DeployDefaultInstanceAction::class)->execute($instance, onlyChanged: true, triggeredBy: 'schedule');
        $instance->refresh();

        expect($result)->toBeNull()
            ->and(plain_git($home, 'rev-parse', 'HEAD'))->toBe($this->second)
            ->and(plain_git($home, 'symbolic-ref', '--short', 'HEAD'))->toBe('main')
            ->and(plain_git($home, 'status', '--porcelain', '--untracked-files=no'))->toBe('')
            ->and(file_get_contents($home.'/public/index.php'))->toContain('second')
            ->and(file_exists($home.'/old.txt'))->toBeFalse()
            ->and(file_get_contents($home.'/apps/gateway/vendor/dependency.bin'))->toBe('release-dependency')
            ->and(file_get_contents($home.'/.cache/warm'))->toBe('release-cache')
            ->and(file_get_contents($home.'/database.sqlite'))->toBe('live-data')
            ->and(file_get_contents($home.'/.env'))->toBe("APP_ENV=development\n")
            ->and(file_get_contents($home.'/.env.testing'))->toBe("APP_ENV=testing\n")
            ->and(file_get_contents($home.'/home-only.log'))->toBe('kept')
            ->and(file_exists($home.'/packages'))->toBeFalse()
            ->and(readlink($home.'/public/storage'))->toBe($home.'/storage/app/public')
            ->and(file_get_contents($home.'/public/storage/upload.txt'))->toBe('upload')
            ->and(file_exists($home.'/releases'))->toBeFalse()
            ->and(is_link($home.'/current'))->toBeFalse()
            ->and(file_exists($home.'/.git/orbit-development-releases'))->toBeFalse()
            ->and(file_exists($home.'/.git/orbit-development-releases-owner'))->toBeFalse()
            ->and(array_map(basename(...), glob($home.'/.git/worktrees/*')))->toBe(['t3code-ab12', 'task-935-e2e'])
            ->and(plain_git($this->consumer->checkout_path, 'rev-parse', 'HEAD'))->toBe($this->first)
            ->and($instance->development_release_layout)->toBeFalse()
            ->and($instance->seed_path)->toBe($home)
            ->and($instance->seed_commit)->toBe($this->second)
            ->and($this->consumer->fresh()->seed_path)->toBe($home)
            ->and($this->consumer->fresh()->seed_commit)->toBe($this->first);

        $next = $this->fixture->push('third');
        expect(app(DeployDefaultInstanceAction::class)->execute($instance, onlyChanged: true, triggeredBy: 'schedule')?->succeeded)->toBeTrue()
            ->and(plain_git($home, 'rev-parse', 'HEAD'))->toBe($next)
            ->and(file_get_contents($home.'/database.sqlite'))->toBe('live-data');
    });

    it('converts once and keeps the releases while a seeded Instance runs a setup step', function (): void {
        $home = $this->fixture->home;
        $lock = DevelopmentDeploymentFixture::holdLifecycleLock($this->consumer->checkout_path);
        Log::spy();

        $first = app(DeployDefaultInstanceAction::class)->execute($this->fixture->instance, onlyChanged: true, triggeredBy: 'schedule');

        expect($first)->toBeNull()
            ->and($this->fixture->instance->fresh()->development_release_layout)->toBeFalse()
            ->and($this->consumer->fresh()->seed_path)->toBe($home)
            ->and(is_dir($this->leased))->toBeTrue()
            ->and(readlink($home.'/current'))->toBe('releases/20261009164518-56c418b8ebd18e71');
        Log::shouldHaveReceived('warning')->once()->with('The old development releases remain; a later deployment retries their removal.', [
            'instance_id' => $this->fixture->instance->id, 'error' => 'instance.lifecycle_busy',
        ]);

        // A repeated conversion does not copy the release over the checkout again.
        file_put_contents($home.'/database.sqlite', 'written-after-conversion');
        expect($this->fixture->deployment->convert($this->fixture->instance->refresh()))->toBe($this->second)
            ->and(file_get_contents($home.'/database.sqlite'))->toBe('written-after-conversion');

        fclose($lock);
        expect(app(DeployDefaultInstanceAction::class)->execute($this->fixture->instance, onlyChanged: true, triggeredBy: 'schedule'))->toBeNull()
            ->and(file_exists($home.'/releases'))->toBeFalse()
            ->and(file_exists($home.'/current'))->toBeFalse()
            ->and(file_get_contents($home.'/database.sqlite'))->toBe('written-after-conversion');
    });

    it('keeps a release folder without an ownership receipt, names it once, and ends the layout', function (): void {
        $home = $this->fixture->home;
        mkdir($home.'/releases/foreign');
        file_put_contents($home.'/releases/foreign/data', 'must-survive');
        Log::spy();

        expect(app(DeployDefaultInstanceAction::class)->execute($this->fixture->instance, onlyChanged: true, triggeredBy: 'schedule'))->toBeNull()
            ->and(app(DeployDefaultInstanceAction::class)->execute($this->fixture->instance, onlyChanged: true, triggeredBy: 'schedule'))->toBeNull()
            ->and(file_get_contents($home.'/releases/foreign/data'))->toBe('must-survive')
            ->and(is_dir($this->leased))->toBeFalse()
            ->and(is_dir($this->release))->toBeFalse()
            ->and(file_exists($home.'/current'))->toBeFalse()
            ->and(file_exists($home.'/.git/orbit-development-releases'))->toBeFalse()
            ->and(file_exists($home.'/.git/orbit-development-releases-owner'))->toBeFalse()
            ->and(array_map(basename(...), glob($home.'/.git/worktrees/*')))->toBe(['t3code-ab12', 'task-935-e2e']);
        Log::shouldHaveReceived('warning')->once()->with('Orbit kept release folders it does not own; remove them by hand.', [
            'instance_id' => $this->fixture->instance->id, 'releases' => ['foreign'],
        ]);
    });

    it('stops the conversion before it changes anything when the release cannot be listed', function (): void {
        $home = $this->fixture->home;
        $admin = plain_git($this->release, 'rev-parse', '--absolute-git-dir');
        file_put_contents($admin.'/index', 'not an index');

        $result = app(DeployDefaultInstanceAction::class)->execute($this->fixture->instance, onlyChanged: true, triggeredBy: 'schedule');

        expect($result?->failure?->errorCode)->toBe('deployment.convert_failed')
            ->and(plain_git($home, 'rev-parse', 'HEAD'))->toBe($this->first)
            ->and(file_get_contents($home.'/database.sqlite'))->toBe('stale-data')
            ->and(file_exists($home.'/.git/orbit-development-releases/converted'))->toBeFalse()
            ->and(readlink($home.'/current'))->toBe('releases/20261009164518-56c418b8ebd18e71')
            ->and(is_dir($this->leased))->toBeTrue()
            ->and($this->fixture->instance->fresh()->development_release_layout)->toBeTrue();
    });

    it('refuses to convert a checkout with uncommitted changes to tracked files', function (): void {
        $home = $this->fixture->home;
        file_put_contents($home.'/public/index.php', '<?php echo "local edit";');

        $result = app(DeployDefaultInstanceAction::class)->execute($this->fixture->instance, onlyChanged: true, triggeredBy: 'schedule');

        expect($result?->failure?->errorCode)->toBe('deployment.checkout_dirty')
            ->and(readlink($home.'/current'))->toBe('releases/20261009164518-56c418b8ebd18e71')
            ->and(file_get_contents($home.'/public/index.php'))->toBe('<?php echo "local edit";')
            ->and(file_get_contents($home.'/database.sqlite'))->toBe('stale-data')
            ->and($this->fixture->instance->fresh()->development_release_layout)->toBeTrue()
            ->and($this->consumer->fresh()->seed_path)->toBe($this->leased);
    });
});
