<?php

declare(strict_types=1);

use App\Actions\Instances\DeployDefaultInstanceAction;
use App\Actions\Instances\SelectInstanceSeedAction;
use App\Domain\Instances\Deployment\DeploymentEvent;
use App\Domain\Instances\Deployment\DeploymentRequest;
use App\Domain\Instances\Deployment\DevelopmentDeployment;
use App\Domain\Instances\DevelopmentRouteProjector;
use App\Domain\Projects\DevelopmentDeployStep;
use App\Domain\Projects\ProjectDevelopmentDeployStepStore;
use App\Infrastructure\AppDev\DevelopmentSshExecutor;
use App\Infrastructure\Instances\DevelopmentReleaseProgram;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Infrastructure\Tasks\RemoteTaskCheckRunner;
use App\Models\Instance;
use App\Models\Route;
use Symfony\Component\Process\Process;
use Tests\Support\DevelopmentDeploymentFixture;
use Tests\Support\LocalShellSshExecutor;

beforeEach(function (): void {
    $this->fixture = new DevelopmentDeploymentFixture;
    app()->instance(DevelopmentDeployment::class, $this->fixture->deployment);
});

afterEach(function (): void {
    $this->fixture->cleanup();
});

function dev935_require_reflinks(DevelopmentDeploymentFixture $fixture): void
{
    if (! $fixture->supportsReflinks()) {
        test()->markTestSkipped('This filesystem does not support reflinks. Refusal is tested separately; beast ZFS block sharing and isolated writes remain a post-deploy operator check.');
    }
}

describe('real development release programs', function (): void {
    it('migrates without moving Git and keeps task bridges and registered T3 worktrees intact', function (): void {
        dev935_require_reflinks($this->fixture);
        $git = $this->fixture->home.'/.git';
        $inode = fileinode($git);
        $this->fixture->deployment->initialize($this->fixture->instance);
        $this->fixture->deployment->initialize($this->fixture->instance);
        $selected = $this->fixture->deployment->selected($this->fixture->instance);

        expect($selected->name)->toBe('initial')
            ->and($selected->commit)->toBe($this->fixture->initialCommit)
            ->and(fileinode($git))->toBe($inode)
            ->and(readlink($this->fixture->home.'/current'))->toBe('releases/initial')
            ->and(file_get_contents($selected->path.'/.cache/warm'))->toBe('previous-cache');
        foreach (['task-935-e2e', 't3code-ab12'] as $name) {
            $path = $this->fixture->sandbox.'/apps/dev935/'.$name;
            expect(trim(DevelopmentDeploymentFixture::command(['git', '-C', $path, 'rev-parse', '--path-format=absolute', '--git-common-dir'])))->toBe($git)
                ->and(trim(DevelopmentDeploymentFixture::command(['git', '-C', $path, 'rev-parse', '--abbrev-ref', 'HEAD'])))->toBe($name)
                ->and(trim(DevelopmentDeploymentFixture::command(['git', '-C', $path, 'status', '--porcelain'])))->toBe('');
        }
    });

    it('resumes owned initialization and publication at each interrupted boundary', function (string $needle): void {
        dev935_require_reflinks($this->fixture);
        $program = DevelopmentReleaseProgram::initialize();
        expect(substr_count($program, $needle))->toBe(1);
        $process = new Process(['bash', '-seu', '--', $this->fixture->home, $this->fixture->instance->project->repository_url, (string) $this->fixture->instance->id]);
        $process->setInput(str_replace($needle, $needle."\nexit 97", $program));
        expect($process->run())->toBe(97);
        $this->fixture->deployment->initialize($this->fixture->instance);
        $this->fixture->deployment->initialize($this->fixture->instance);

        expect(readlink($this->fixture->home.'/current'))->toBe('releases/initial')
            ->and(file_get_contents($this->fixture->home.'/current/.cache/warm'))->toBe('previous-cache')
            ->and(file_exists($this->fixture->home.'/.orbit-current-'.$this->fixture->instance->id.'.tmp'))->toBeFalse()
            ->and(glob($this->fixture->home.'/.git/orbit-development-releases/intent-*'))->toBe([]);
    })->with([
        'ownership claim before directories' => '    write_receipt "$claim" "$identity"',
        'state directory before identity' => 'if [ ! -e "$state" ] && [ ! -L "$state" ]; then mkdir -m 0700 -- "$state"; fi',
        'identity before releases directory' => 'write_receipt "$marker" "$identity"',
        'releases directory before worktree' => 'if [ ! -e "$releases" ] && [ ! -L "$releases" ]; then mkdir -- "$releases"; fi',
        'registered worktree before release marker' => '    git -C "$home" worktree add --detach -- "$releases/$intended_name" "$intended_commit" >/dev/null',
        'staged current before rename' => '    ln -s "releases/$next" "$temporary"',
        'published current before receipt cleanup' => '    mv -Tf -- "$temporary" "$current"',
    ]);

    it('recovers a registered candidate before its marker and prunes it on retry', function (): void {
        dev935_require_reflinks($this->fixture);
        $instance = $this->fixture->instance;
        $this->fixture->deployment->initialize($instance);
        $commit = $this->fixture->push('interrupted-prepare');
        $this->fixture->deployment->target($instance);
        $needle = '    git -C "$home" worktree add --detach -- "$releases/$intended_name" "$intended_commit" >/dev/null';
        $program = str_replace($needle, $needle."\nexit 97", DevelopmentReleaseProgram::prepare());
        $process = new Process(['bash', '-seu', '--', $this->fixture->home, $instance->project->repository_url, (string) $instance->id, 'interrupted', $commit]);
        expect($process->setInput($program)->run())->toBe(97)
            ->and(file_exists($this->fixture->home.'/.git/orbit-development-releases/release-interrupted'))->toBeFalse();
        $result = app(DeployDefaultInstanceAction::class)->execute($instance, onlyChanged: true);
        expect($result?->succeeded)->toBeTrue()
            ->and(file_exists($this->fixture->home.'/releases/interrupted'))->toBeFalse()
            ->and(readlink($this->fixture->home.'/current'))->toBe('releases/'.$result->release->name);
    });

    it('recovers a staged publication after the failed candidate was pruned without selecting it', function (): void {
        dev935_require_reflinks($this->fixture);
        $instance = $this->fixture->instance;
        $this->fixture->deployment->initialize($instance);
        $commit = $this->fixture->push('interrupted-switch');
        expect($this->fixture->deployment->target($instance))->toBe($commit);
        $candidate = $this->fixture->deployment->prepare($instance, $commit);
        $needle = '    ln -s "releases/$next" "$temporary"';
        $program = str_replace($needle, $needle."\nexit 97", DevelopmentReleaseProgram::activate());
        $process = new Process(['bash', '-seu', '--', $this->fixture->home, $instance->project->repository_url, (string) $instance->id, $candidate->name, $candidate->commit]);
        expect($process->setInput($program)->run())->toBe(97);
        $this->fixture->deployment->prune($instance, $this->fixture->deployment->selected($instance));
        expect(file_exists($candidate->path))->toBeFalse();
        $result = app(DeployDefaultInstanceAction::class)->execute($instance);
        expect($result?->succeeded)->toBeTrue()
            ->and(readlink($this->fixture->home.'/current'))->toBe('releases/'.$result->release->name);
    });

    it('refuses unclaimed partial state and publication paths without deleting them', function (string $kind): void {
        if ($kind === 'state') {
            mkdir($this->fixture->home.'/.git/orbit-development-releases', 0700);
            $path = $this->fixture->home.'/.git/orbit-development-releases';
        } else {
            dev935_require_reflinks($this->fixture);
            $this->fixture->deployment->initialize($this->fixture->instance);
            $path = $this->fixture->home.'/.orbit-current-'.$this->fixture->instance->id.'.tmp';
            symlink('releases/initial', $path);
        }
        $result = app(DeployDefaultInstanceAction::class)->execute($this->fixture->instance);
        expect($result?->succeeded)->toBeFalse()
            ->and(is_dir($path) || is_link($path))->toBeTrue();
    })->with(['state', 'publication']);

    it('builds from current dependencies at every depth and moves only tracked code to the newest target', function (): void {
        dev935_require_reflinks($this->fixture);
        $deployment = $this->fixture->deployment;
        $instance = $this->fixture->instance;
        $deployment->initialize($instance);
        $previous = $deployment->selected($instance);
        file_put_contents($this->fixture->home.'/.env', "APP_ENV=development\nVALUE=new\n");
        file_put_contents($this->fixture->home.'/.env.testing', "APP_ENV=testing\nDB_DATABASE=testing\n");
        $commit = $this->fixture->push('second');
        expect($deployment->target($instance))->toBe($commit);
        $release = $deployment->prepare($instance, $commit);

        expect($release->commit)->toBe($commit)
            ->and(readlink($this->fixture->home.'/current'))->toBe('releases/initial')
            ->and(file_exists($release->path.'/old.txt'))->toBeFalse()
            ->and(file_get_contents($release->path.'/.env'))->toBe("APP_ENV=development\nVALUE=new\n")
            ->and(file_get_contents($previous->path.'/.env'))->toBe("APP_ENV=development\n")
            ->and(file_get_contents($release->path.'/.env.testing'))->toBe("APP_ENV=testing\nDB_DATABASE=testing\n")
            ->and(file_exists($previous->path.'/.env.testing'))->toBeFalse()
            ->and(hash_file('sha256', $release->path.'/apps/gateway/vendor/dependency.bin'))->toBe(hash_file('sha256', $previous->path.'/apps/gateway/vendor/dependency.bin'))
            ->and(fileinode($release->path.'/apps/gateway/vendor/dependency.bin'))->not->toBe(fileinode($previous->path.'/apps/gateway/vendor/dependency.bin'));
        file_put_contents($release->path.'/.cache/warm', 'candidate-only');
        expect(file_get_contents($previous->path.'/.cache/warm'))->toBe('previous-cache');
        $deployment->activate($instance, $release);
        expect(readlink($this->fixture->home.'/current'))->toBe('releases/'.$release->name);
    });

    it('keeps current after a required failure and removes the failed candidate', function (): void {
        dev935_require_reflinks($this->fixture);
        $this->fixture->push('required-failure');
        app(ProjectDevelopmentDeployStepStore::class)->create($this->fixture->instance->project, new DevelopmentDeployStep('build', 'printf "required failure\n" >&2; exit 41'), null, null);
        $result = app(DeployDefaultInstanceAction::class)->execute($this->fixture->instance);

        expect($result?->succeeded)->toBeFalse()
            ->and($result?->commands[0]->result->exitCode)->toBe(41)
            ->and(readlink($this->fixture->home.'/current'))->toBe('releases/initial')
            ->and(glob($this->fixture->home.'/releases/*'))->toBe([$this->fixture->home.'/releases/initial']);
    });

    it('relocates absolute internal links during migration, preparation, and best-effort restoration without changing the source', function (): void {
        dev935_require_reflinks($this->fixture);
        $home = $this->fixture->home;
        $digest = hash_file('sha256', $home.'/apps/gateway/vendor/dependency.bin');
        mkdir($home.'/storage/app/public', 0777, true);
        mkdir($home.'/vendor');
        symlink($home.'/storage/app/public', $home.'/public/storage');
        symlink($home.'/apps/gateway/vendor', $home.'/vendor/backend');
        $this->fixture->deployment->initialize($this->fixture->instance);
        expect(readlink($home.'/public/storage'))->toBe($home.'/storage/app/public')
            ->and(readlink($home.'/current/public/storage'))->toBe('../storage/app/public');
        // A valid live release may contain absolute links created by a deploy command.
        unlink($home.'/current/vendor/backend');
        symlink($home.'/releases/initial/apps/gateway/vendor', $home.'/current/vendor/backend');
        $this->fixture->push('absolute-links');
        app(ProjectDevelopmentDeployStepStore::class)->create($this->fixture->instance->project, new DevelopmentDeployStep('warm', 'printf partial > vendor/backend/dependency.bin; exit 42', required: false), null, null);
        $result = app(DeployDefaultInstanceAction::class)->execute($this->fixture->instance);

        expect($result?->succeeded)->toBeTrue()
            ->and(readlink($home.'/current/vendor/backend'))->toBe('../apps/gateway/vendor')
            ->and(hash_file('sha256', $home.'/current/vendor/backend/dependency.bin'))->toBe($digest)
            ->and(hash_file('sha256', $home.'/releases/initial/vendor/backend/dependency.bin'))->toBe($digest)
            ->and(hash_file('sha256', $home.'/vendor/backend/dependency.bin'))->toBe($digest);
    });

    it('rebases links introduced by required steps while snapshotting a failing best-effort step', function (): void {
        dev935_require_reflinks($this->fixture);
        $this->fixture->push('step-created-link');
        $store = app(ProjectDevelopmentDeployStepStore::class);
        $store->create($this->fixture->instance->project, new DevelopmentDeployStep('link', 'ln -s "$PWD/apps/gateway/vendor" "$PWD/dependency-link"'), null, null);
        $store->create($this->fixture->instance->project, new DevelopmentDeployStep('warm', 'printf partial > dependency-link/dependency.bin; printf partial > .cache/warm; exit 42', required: false), null, null);
        $digest = hash_file('sha256', $this->fixture->home.'/apps/gateway/vendor/dependency.bin');
        $result = app(DeployDefaultInstanceAction::class)->execute($this->fixture->instance);

        expect($result?->succeeded)->toBeTrue()
            ->and(readlink($this->fixture->home.'/current/dependency-link'))->toBe('apps/gateway/vendor')
            ->and(hash_file('sha256', $this->fixture->home.'/current/dependency-link/dependency.bin'))->toBe($digest)
            ->and(hash_file('sha256', $this->fixture->home.'/releases/initial/apps/gateway/vendor/dependency.bin'))->toBe($digest)
            ->and(file_get_contents($this->fixture->home.'/current/.cache/warm'))->toBe('previous-cache')
            ->and(file_get_contents($this->fixture->home.'/releases/initial/.cache/warm'))->toBe('previous-cache');
    });

    it('restores caches after a best-effort command writes partial files and still switches and reports', function (): void {
        dev935_require_reflinks($this->fixture);
        $commit = $this->fixture->push('best-effort-failure');
        $store = app(ProjectDevelopmentDeployStepStore::class);
        $store->create($this->fixture->instance->project, new DevelopmentDeployStep('install', 'printf installed > apps/gateway/vendor/new'), null, null);
        $store->create($this->fixture->instance->project, new DevelopmentDeployStep('warm', 'printf partial > .cache/warm; printf partial > .cache/extra; rm apps/gateway/vendor/new; exit 42', required: false), null, null);
        $events = [];
        $result = app(DeployDefaultInstanceAction::class)->execute($this->fixture->instance, new DeploymentRequest(static function (DeploymentEvent $event) use (&$events): void {
            $events[] = $event;
        }), triggeredBy: 'proof-test');
        $selected = $this->fixture->deployment->selected($this->fixture->instance);

        expect($result?->succeeded)->toBeTrue()
            ->and($selected->commit)->toBe($commit)
            ->and($selected->name)->toBe('release-1')
            ->and(file_get_contents($selected->path.'/.cache/warm'))->toBe('previous-cache')
            ->and(file_get_contents($selected->path.'/apps/gateway/vendor/new'))->toBe('installed')
            ->and(file_exists($selected->path.'/.cache/extra'))->toBeFalse()
            ->and(implode('', array_map(static fn (DeploymentEvent $event): string => $event->value, $events)))->toContain('Best-effort step failed (exit 42)')
            ->and(glob($this->fixture->home.'/.git/orbit-development-releases/snapshot-*'))->toBe([]);
    });

    it('terminates a timed-out best-effort step and restores its cache before switching', function (): void {
        dev935_require_reflinks($this->fixture);
        $this->fixture->push('timeout');
        app(ProjectDevelopmentDeployStepStore::class)->create($this->fixture->instance->project, new DevelopmentDeployStep('warm', 'printf partial > .cache/warm; sleep 30; printf late > .cache/warm', 1, false), null, null);
        $started = microtime(true);
        $result = app(DeployDefaultInstanceAction::class)->execute($this->fixture->instance);
        $selected = $this->fixture->deployment->selected($this->fixture->instance);

        expect($result?->succeeded)->toBeTrue()
            ->and($result?->commands[0]->result->exitCode)->not->toBe(0)
            ->and(microtime(true) - $started)->toBeLessThan(8)
            ->and(file_get_contents($selected->path.'/.cache/warm'))->toBe('previous-cache');
    });

    it('retains a leased seed through deployments while asynchronous setup is pending and on retry', function (): void {
        dev935_require_reflinks($this->fixture);
        config()->set('orbit.tasks.worker_user', null);
        $default = $this->fixture->instance;
        $this->fixture->deployment->initialize($default);
        $default->update(['development_release_layout' => true]);
        $seed = $this->fixture->deployment->selected($default);
        $path = $this->fixture->sandbox.'/apps/dev935/task-pending-setup';
        $consumer = Instance::query()->create([
            'project_id' => $default->project_id, 'node_id' => $default->node_id, 'name' => 'task-pending-setup',
            'checkout_path' => $path, 'status' => 'reserved',
        ]);
        app(SelectInstanceSeedAction::class)->execute($consumer);
        DevelopmentDeploymentFixture::command(['git', '-C', $this->fixture->home, 'worktree', 'add', '-b', 'task-pending-setup', $path, $seed->commit]);
        $consumer->update(['starting_commit' => $seed->commit, 'source_layout' => 'worktree', 'status' => 'source_resolved']);
        $keys = Mockery::mock(SshKeyProvider::class)->shouldReceive('privateKeyPath')->andReturn('/unused')->getMock();
        $hosts = Mockery::mock(KnownHostsStore::class)->shouldReceive('path')->andReturn('/unused')->getMock();
        $runner = new RemoteTaskCheckRunner(new DevelopmentSshExecutor(new LocalShellSshExecutor, $keys, $hosts));
        $setup = [[
            'name' => 'copy from seed',
            'command' => 'touch setup-started; while [ ! -f allow-setup ]; do sleep 0.05; done; cat "$ORBIT_SEED_PATH/.cache/warm" > copied-cache',
            'timeout_seconds' => 30,
        ]];
        $process = $runner->start($consumer, 'true', $setup);
        try {
            for ($i = 0; $i < 100 && ! file_exists($path.'/setup-started'); $i++) {
                usleep(50_000);
            }
            expect(file_exists($path.'/setup-started'))->toBeTrue();
            foreach (['advance once', 'advance twice'] as $message) {
                $commit = $this->fixture->push($message);
                $this->fixture->deployment->target($default);
                $candidate = $this->fixture->deployment->prepare($default, $commit);
                $selected = $this->fixture->deployment->activate($default, $candidate);
                $this->fixture->deployment->prune($default, $selected);
            }
            expect(is_dir($seed->path))->toBeTrue();
            touch($path.'/allow-setup');
            for ($i = 0; $i < 150; $i++) {
                $reading = $runner->read($consumer, $process);
                if ($reading->state === 'finished') {
                    break;
                }
                usleep(50_000);
            }
            expect($reading->state)->toBe('finished')->and($reading->exitCode)->toBe(0)
                ->and(file_get_contents($path.'/copied-cache'))->toBe('previous-cache');
            $process = $runner->start($consumer, 'true', $setup);
            for ($i = 0; $i < 150; $i++) {
                $reading = $runner->read($consumer, $process);
                if ($reading->state === 'finished') {
                    break;
                }
                usleep(50_000);
            }
            expect($reading->exitCode)->toBe(0)->and($consumer->refresh()->seed_commit)->toBe($seed->commit);
        } finally {
            $runner->cancel($consumer, $process);
        }
        DevelopmentDeploymentFixture::command(['git', '-C', $this->fixture->home, 'worktree', 'remove', '--force', $path]);
        $consumer->delete();
        $this->fixture->deployment->prune($default, $selected);
        expect(is_dir($seed->path))->toBeFalse();
    });

    it('prunes only managed releases keeping live and previous while unrelated linked worktrees remain', function (): void {
        dev935_require_reflinks($this->fixture);
        $action = app(DeployDefaultInstanceAction::class);
        foreach (['one', 'two', 'three'] as $message) {
            $this->fixture->push($message);
            expect($action->execute($this->fixture->instance)?->succeeded)->toBeTrue();
        }
        expect(array_map(basename(...), glob($this->fixture->home.'/releases/*')))->toBe(['release-2', 'release-3'])
            ->and(readlink($this->fixture->home.'/current'))->toBe('releases/release-3')
            ->and(is_dir($this->fixture->sandbox.'/apps/dev935/t3code-ab12'))->toBeTrue()
            ->and(is_dir($this->fixture->sandbox.'/apps/dev935/task-935-e2e'))->toBeTrue();
        $listing = DevelopmentDeploymentFixture::command(['git', '-C', $this->fixture->home, 'worktree', 'list', '--porcelain']);
        expect($listing)->toContain('t3code-ab12', 'task-935-e2e', 'releases/release-2', 'releases/release-3')->not->toContain('releases/initial', 'releases/release-1');
    });

    it('refuses an invalid candidate Web root before switching a visitable default', function (): void {
        dev935_require_reflinks($this->fixture);
        $instance = $this->fixture->instance;
        $instance->update(['selected_php_version' => '8.5', 'source_is_laravel' => true]);
        $route = Route::query()->create(['project_id' => $instance->project_id, 'node_id' => $instance->node_id, 'domain' => 'dev935.example.test', 'provenance' => 'explicit', 'publication' => 'public', 'status' => 'pending']);
        $route->targets()->create(['instance_id' => $instance->id, 'position' => 0]);
        $route->update(['status' => 'active']);
        $projector = Mockery::mock(DevelopmentRouteProjector::class);
        $projector->shouldReceive('converge')->once();
        app()->instance(DevelopmentRouteProjector::class, $projector);
        app(ProjectDevelopmentDeployStepStore::class)->create($instance->project, new DevelopmentDeployStep('build', 'rm -rf public'), null, null);
        $this->fixture->push('invalid-web-root');
        $result = app(DeployDefaultInstanceAction::class)->execute($instance);

        expect($result?->succeeded)->toBeFalse()
            ->and($result?->failure?->errorCode)->toBe('app-dev.source_access_failed')
            ->and(readlink($this->fixture->home.'/current'))->toBe('releases/initial')
            ->and(file_get_contents($this->fixture->home.'/current/public/index.php'))->toBe(file_get_contents($this->fixture->home.'/public/index.php'));
    });

    it('refuses a non-reflink copy instead of selecting a partial migration', function (): void {
        $bin = $this->fixture->sandbox.'/bin';
        mkdir($bin);
        file_put_contents($bin.'/cp', <<<'BASH'
            #!/bin/bash
            for argument in "$@"; do
                if [ "$argument" = '--reflink=always' ]; then exit 95; fi
            done
            exec /usr/bin/cp "$@"
            BASH);
        chmod($bin.'/cp', 0700);
        $process = new Process(['bash', '-seu', '--', $this->fixture->home, $this->fixture->instance->project->repository_url, (string) $this->fixture->instance->id], env: ['PATH' => $bin.':'.getenv('PATH')]);
        $process->setInput(DevelopmentReleaseProgram::initialize());

        expect($process->run())->not->toBe(0)
            ->and(is_link($this->fixture->home.'/current'))->toBeFalse()
            ->and(file_get_contents($this->fixture->home.'/.cache/warm'))->toBe('previous-cache')
            ->and(is_dir($this->fixture->home.'/.git'))->toBeTrue();
    });

    it('refuses a candidate that links back into the live release', function (): void {
        dev935_require_reflinks($this->fixture);
        $this->fixture->push('unsafe-link');
        $command = 'ln -s '.escapeshellarg($this->fixture->home.'/current/.cache').' unsafe-cache';
        app(ProjectDevelopmentDeployStepStore::class)->create($this->fixture->instance->project, new DevelopmentDeployStep('build', $command), null, null);
        $result = app(DeployDefaultInstanceAction::class)->execute($this->fixture->instance);

        expect($result?->succeeded)->toBeFalse()
            ->and(readlink($this->fixture->home.'/current'))->toBe('releases/initial');
    });

    it('blocks switching when best-effort cache restoration cannot be confirmed', function (): void {
        dev935_require_reflinks($this->fixture);
        $this->fixture->push('failed-restore');
        // A snapshot creation failure is infrastructure failure, not a best-effort command failure.
        $snapshot = $this->fixture->home.'/.git/orbit-development-releases/snapshot-release-1';
        $command = 'mkdir -p '.escapeshellarg($snapshot).'; printf partial > .cache/warm; exit 42';
        $store = app(ProjectDevelopmentDeployStepStore::class);
        $store->create($this->fixture->instance->project, new DevelopmentDeployStep('prepare', 'mkdir -p '.escapeshellarg($snapshot)), null, null);
        $store->create($this->fixture->instance->project, new DevelopmentDeployStep('warm', $command, required: false), null, null);
        $result = app(DeployDefaultInstanceAction::class)->execute($this->fixture->instance);

        expect($result?->succeeded)->toBeFalse()
            ->and($result?->failure?->errorCode)->toBe('deployment.step_restore_failed')
            ->and(readlink($this->fixture->home.'/current'))->toBe('releases/initial')
            ->and(file_get_contents($this->fixture->home.'/current/.cache/warm'))->toBe('previous-cache');
    });

    it('does not restore a best-effort snapshot through a candidate symlink into the live release', function (): void {
        dev935_require_reflinks($this->fixture);
        $this->fixture->push('replaced-candidate');
        $command = 'candidate=$PWD; mv "$candidate" "$candidate-moved"; ln -s "$(dirname "$candidate")/initial" "$candidate"; exit 42';
        app(ProjectDevelopmentDeployStepStore::class)->create($this->fixture->instance->project, new DevelopmentDeployStep('warm', $command, required: false), null, null);
        $result = app(DeployDefaultInstanceAction::class)->execute($this->fixture->instance);

        expect($result?->succeeded)->toBeFalse()
            ->and($result?->failure?->errorCode)->toBe('deployment.step_restore_failed')
            ->and(readlink($this->fixture->home.'/current'))->toBe('releases/initial')
            ->and(file_get_contents($this->fixture->home.'/current/.cache/warm'))->toBe('previous-cache')
            ->and(file_get_contents($this->fixture->home.'/current/public/index.php'))->toBe(file_get_contents($this->fixture->home.'/public/index.php'));
    });

    it('refuses adoption of a foreign release directory without touching its content', function (): void {
        mkdir($this->fixture->home.'/releases');
        file_put_contents($this->fixture->home.'/releases/foreign', 'must-survive');
        $result = app(DeployDefaultInstanceAction::class)->execute($this->fixture->instance);

        expect($result?->succeeded)->toBeFalse()
            ->and(file_get_contents($this->fixture->home.'/releases/foreign'))->toBe('must-survive')
            ->and(is_link($this->fixture->home.'/current'))->toBeFalse();
    });

    it('does not claim an unrelated linked worktree under the release directory as a managed release', function (): void {
        dev935_require_reflinks($this->fixture);
        $this->fixture->deployment->initialize($this->fixture->instance);
        $foreign = $this->fixture->home.'/releases/t3code-foreign';
        DevelopmentDeploymentFixture::command(['git', '-C', $this->fixture->home, 'worktree', 'add', '-b', 'foreign', $foreign, 'HEAD']);
        $this->fixture->push('foreign-worktree');
        $result = app(DeployDefaultInstanceAction::class)->execute($this->fixture->instance);

        expect($result?->succeeded)->toBeFalse()
            ->and(is_dir($foreign))->toBeTrue()
            ->and(trim(DevelopmentDeploymentFixture::command(['git', '-C', $foreign, 'rev-parse', '--abbrev-ref', 'HEAD'])))->toBe('foreign')
            ->and(readlink($this->fixture->home.'/current'))->toBe('releases/initial');
    });

    it('retries an interrupted migration while preserving the original repository', function (): void {
        dev935_require_reflinks($this->fixture);
        $this->fixture->deployment->initialize($this->fixture->instance);
        unlink($this->fixture->home.'/current');
        file_put_contents($this->fixture->home.'/releases/initial/.cache/warm', 'partial-migration');
        $this->fixture->deployment->initialize($this->fixture->instance);

        expect(readlink($this->fixture->home.'/current'))->toBe('releases/initial')
            ->and(file_get_contents($this->fixture->home.'/current/.cache/warm'))->toBe('previous-cache')
            ->and(trim(DevelopmentDeploymentFixture::command(['git', '-C', $this->fixture->home, 'rev-parse', 'HEAD'])))->toBe($this->fixture->initialCommit);
    });

    it('fails closed when pruning encounters an unregistered path', function (): void {
        dev935_require_reflinks($this->fixture);
        $this->fixture->deployment->initialize($this->fixture->instance);
        mkdir($this->fixture->home.'/releases/foreign');
        file_put_contents($this->fixture->home.'/releases/foreign/protected', 'must-survive');
        $this->fixture->push('foreign-path');
        $result = app(DeployDefaultInstanceAction::class)->execute($this->fixture->instance);

        expect($result?->succeeded)->toBeFalse()
            ->and(readlink($this->fixture->home.'/current'))->toBe('releases/initial')
            ->and(file_get_contents($this->fixture->home.'/releases/foreign/protected'))->toBe('must-survive');
    });
});
