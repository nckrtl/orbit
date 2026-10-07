<?php

declare(strict_types=1);

use App\Infrastructure\AppDev\DevelopmentSshExecutor;
use App\Infrastructure\Compute\TaskSandboxDrivers;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Infrastructure\Tasks\IncusSandboxHost;
use App\Infrastructure\Tasks\RemoteTaskWorkspaceStateReader;
use App\Infrastructure\Tasks\TaskWorkspaceExecutor;
use App\Models\Instance;
use App\Models\Node;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

use function Pest\Laravel\mock;

describe('task workspace Git state', function (): void {
    it('reads HEAD and branch at the repository root regardless of the application root', function (string $root): void {
        $repository = sys_get_temp_dir().'/orbit-task-state-'.Str::uuid();
        mkdir($repository.'/apps/site', 0o700, true);
        new Process(['git', 'init', '--initial-branch=task-test', $repository])->mustRun();
        new Process(['git', '-C', $repository, '-c', 'user.name=Orbit Test', '-c', 'user.email=test@example.test', 'commit', '--allow-empty', '-m', 'fixture'])->mustRun();
        $head = trim(new Process(['git', '-C', $repository, 'rev-parse', 'HEAD'])->mustRun()->getOutput());
        $instance = new Instance(['root' => $root, 'checkout_path' => $repository, 'source_is_laravel' => true]);
        $instance->setRelation('node', new Node(['user' => 'orbit', 'wireguard_ip' => '10.44.0.2']));
        mock(SshKeyProvider::class)->shouldReceive('privateKeyPath')->andReturn('/keys/private');
        mock(KnownHostsStore::class)->shouldReceive('path')->andReturn('/keys/known_hosts');
        mock(SshExecutor::class)->shouldReceive('execute')->twice()->andReturnUsing(function (SshConnection $connection, RemoteCommand $command) use ($repository): CommandResult {
            expect($command->arguments[array_key_last($command->arguments)])->toBe($repository);
            $process = new Process(['bash', '-seu', '--', $repository]);
            $process->setInput($command->input);
            $process->mustRun();

            return new CommandResult(0, $process->getOutput(), '', 1, false);
        });
        $reader = new RemoteTaskWorkspaceStateReader(new TaskWorkspaceExecutor(app(DevelopmentSshExecutor::class), app(IncusSandboxHost::class), app(TaskSandboxDrivers::class)));

        try {
            expect($reader->headCommit($instance))->toBe($head);
            expect($reader->currentBranch($instance))->toBe('task-test');
        } finally {
            new Filesystem()->deleteDirectory($repository);
        }
    })->with(['root public' => 'public', 'nested app' => 'apps/site/public']);
});
