<?php

declare(strict_types=1);

use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\AppDev\AppDevSshExecutor;
use App\Infrastructure\Ssh\HostKey;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Infrastructure\Tasks\RemoteTaskCheckRunner;
use App\Models\App as OrbitApp;
use App\Models\AppInstance;
use App\Models\Node;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Tests\Support\LocalShellSshExecutor;

function per_project_baseline_checkout(): string
{
    $checkout = test()->directory.'/'.bin2hex(random_bytes(6));
    (new Process(['git', 'init', '--quiet', $checkout]))->mustRun();
    (new Process(['git', '-c', 'user.name=t', '-c', 'user.email=t@t', 'commit', '--quiet', '--allow-empty', '-m', 'start'], $checkout))->mustRun();

    return $checkout;
}

function per_project_baseline_runner(SshExecutor $transport): RemoteTaskCheckRunner
{
    return new RemoteTaskCheckRunner(new AppDevSshExecutor(
        $transport,
        new class implements SshKeyProvider
        {
            public function privateKeyPath(): string
            {
                return '/home/orbit/.orbit/ssh/id_ed25519';
            }

            public function publicKey(): string
            {
                return 'ssh-ed25519 AAAA';
            }
        },
        new class implements KnownHostsStore
        {
            public function path(): string
            {
                return '/home/orbit/.orbit/ssh/known_hosts';
            }

            public function put(string $host, int $port, HostKey $key): void {}
        },
    ));
}

beforeEach(function (): void {
    $this->directory = sys_get_temp_dir().'/orbit-per-project-baseline-'.bin2hex(random_bytes(6));
    mkdir($this->directory, 0755, true);
});

afterEach(function (): void {
    File::deleteDirectory($this->directory);
});

it('per-project baseline skips an unset command and preserves custom command failure output', function (): void {
    $checkout = per_project_baseline_checkout();
    $project = OrbitApp::query()->create([
        'name' => 'Project',
        'slug' => 'project',
        'repository_url' => 'git@github.com:acme/project.git',
        'task_check' => 'printf "custom baseline failed\\n"; exit 7',
    ]);
    expect($project->taskCheckCommand())->toBe('printf "custom baseline failed\\n"; exit 7');

    $node = Node::query()->create([
        'name' => 'baseline-node',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '10.44.0.222',
        'wireguard_ip' => '10.44.0.222',
        'user' => 'orbit',
    ]);
    $instance = AppInstance::query()->create([
        'app_id' => $project->id,
        'node_id' => $node->id,
        'name' => 'task-85',
        'checkout_path' => $checkout,
        'branch' => 'task-85',
        'status' => 'source_resolved',
    ]);
    $runner = per_project_baseline_runner(new LocalShellSshExecutor);

    $failed = $runner->start($instance, command: $project->taskCheckCommand());
    $reading = null;
    for ($attempt = 0; $attempt < 100; $attempt++) {
        $reading = $runner->read($instance, $failed);
        if ($reading->state !== 'running') {
            break;
        }
        usleep(100_000);
    }
    expect($reading?->exitCode)->toBe(7)
        ->and($reading?->output)->toContain('custom baseline failed');

    $unsetProject = OrbitApp::query()->create([
        'name' => 'No check',
        'slug' => 'no-check',
        'repository_url' => 'git@github.com:acme/no-check.git',
    ]);
    expect($unsetProject->taskCheckCommand())->toBeNull();
    $instance->app()->associate($unsetProject);
    $instance->save();
    $noCheck = $runner->start($instance, command: $unsetProject->taskCheckCommand());
    $noOp = null;
    for ($attempt = 0; $attempt < 100; $attempt++) {
        $noOp = $runner->read($instance, $noCheck);
        if ($noOp->state !== 'running') {
            break;
        }
        usleep(100_000);
    }
    expect($noOp?->exitCode)->toBe(0)
        ->and($noOp?->output)->not->toContain('composer check');

    $handoff = $runner->start($instance, null, [], [
        'start' => null,
        'tests' => [],
        'commands' => [['id' => 'hello', 'command' => 'printf "deliverable ran\\n"', 'directory' => null]],
    ]);
    $verified = null;
    for ($attempt = 0; $attempt < 100; $attempt++) {
        $verified = $runner->read($instance, $handoff);
        if ($verified->state !== 'running') {
            break;
        }
        usleep(100_000);
    }
    expect($verified?->exitCode)->toBe(0)
        ->and($verified?->deliverables['commands']['hello']['exit_code'] ?? null)->toBe(0)
        ->and($verified?->output)->not->toContain('composer check');
});
