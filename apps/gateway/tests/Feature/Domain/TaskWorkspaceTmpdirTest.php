<?php

declare(strict_types=1);

use App\Domain\Tasks\TaskCheckReading;
use App\Infrastructure\AppDev\DevelopmentSshExecutor;
use App\Infrastructure\Ssh\HostKey;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Infrastructure\Tasks\RemoteTaskCheckRunner;
use App\Models\Instance;
use App\Models\Node;
use App\Models\Project;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Tests\Support\LocalShellSshExecutor;

beforeEach(function (): void {
    $this->directory = sys_get_temp_dir().'/orbit-workspace-tmpdir-'.bin2hex(random_bytes(8));
    File::ensureDirectoryExists($this->directory);
});

afterEach(function (): void {
    File::deleteDirectory($this->directory);
});

function tmpdir_runner(): RemoteTaskCheckRunner
{
    return new RemoteTaskCheckRunner(new DevelopmentSshExecutor(
        new LocalShellSshExecutor,
        new class implements SshKeyProvider
        {
            public function privateKeyPath(): string
            {
                return '/unused';
            }

            public function publicKey(): string
            {
                return 'unused';
            }
        },
        new class implements KnownHostsStore
        {
            public function path(): string
            {
                return '/unused';
            }

            public function put(string $host, int $port, HostKey $key): void {}
        },
    ));
}

function tmpdir_check_directory(string $checkout): string
{
    (new Process(['git', 'init', '-q', $checkout]))->mustRun();
    $metadata = new Process(['python3', resource_path('tasks/metadata'), $checkout, 'tmpdir']);
    $metadata->setInput('{}');

    return $metadata->mustRun()->getOutput();
}

describe('workspace TMPDIR', function (): void {
    it('gives test processes inheriting private TMPDIR a separate traversable fixture root', function (): void {
        $temporary = tmpdir_check_directory($this->directory);
        $probe = new Process([PHP_BINARY, '-r', <<<'PHP'
            require 'vendor/autoload.php';
            Tests\Support\TestTemporaryDirectory::bootstrap();
            echo json_encode(['path' => sys_get_temp_dir(), 'mode' => fileperms(sys_get_temp_dir()) & 0777]);
            PHP,
        ], base_path(), ['TMPDIR' => $temporary]);
        $fixture = json_decode($probe->mustRun()->getOutput(), true, flags: JSON_THROW_ON_ERROR);

        expect($fixture['path'])->toStartWith(realpath('/tmp').'/orbit-gateway-tests-')->not->toBe($temporary);
        expect($fixture['mode'])->toBe(0755);
        expect(fileperms($temporary) & 0777)->toBe(0700);
    });

    it('supports cross-user tests inheriting a private check TMPDIR', function (): void {
        $temporary = tmpdir_check_directory($this->directory);
        expect(fileperms($temporary) & 0777)->toBe(0700);
        $process = new Process([
            PHP_BINARY, 'vendor/bin/pest',
            'tests/Feature/Infrastructure/Tasks/RemoteTaskCheckRunnerTest.php',
            '--filter=isolates workspace TMPDIR when another Unix user',
        ], base_path(), ['TMPDIR' => $temporary]);
        $process->setTimeout(60);
        $process->run();

        expect($process->getExitCode())->toBe(0, $process->getOutput().$process->getErrorOutput());
        expect(fileperms($temporary) & 0777)->toBe(0700);
    });

    it('keeps restrictive prior shared caches out of setup and analyse in per-workspace tmp', function (): void {
        // Exercise the post-check sharing grant without needing another user's sudo access.
        config()->set('orbit.tasks.worker_user', posix_getpwuid(posix_geteuid())['name']);
        $shared = $this->directory.'/prior-shared';
        File::ensureDirectoryExists($shared.'/phpstan');
        chmod($shared.'/phpstan', 0500);
        $analyse = 'mkdir -p "$TMPDIR/phpstan" && printf analysed > "$TMPDIR/phpstan/result"';
        // The old layout really fails on permissions, not on an assertion about the new path.
        expect((new Process(['bash', '-c', $analyse], null, ['TMPDIR' => $shared]))->run())->not->toBe(0);
        $prior = getenv('TMPDIR');
        $priorServer = $_SERVER['TMPDIR'] ?? null;
        $priorEnvironment = $_ENV['TMPDIR'] ?? null;
        putenv('TMPDIR='.$shared);
        $_SERVER['TMPDIR'] = $_ENV['TMPDIR'] = $shared;

        try {
            $runner = tmpdir_runner();
            $directories = [];
            foreach (['first', 'second'] as $name) {
                $checkout = $this->directory.'/'.$name;
                (new Process(['git', 'init', '-q', $checkout]))->mustRun();
                (new Process(['git', '-c', 'user.name=t', '-c', 'user.email=t@t', 'commit', '-q', '--allow-empty', '-m', 'start'], $checkout))->mustRun();
                File::ensureDirectoryExists($checkout.'/.git/orbit/tmp');
                (new Process(['setfacl', '-m', 'd:u:nobody:rwX', $checkout.'/.git/orbit/tmp']))->mustRun();
                $project = Project::query()->create(['name' => $name, 'slug' => $name, 'repository_url' => 'git@github.com:acme/'.$name.'.git', 'default_branch' => 'main']);
                $node = Node::query()->create(['name' => $name, 'status' => 'active', 'platform' => 'linux', 'public_ssh_host' => '10.44.0.160', 'wireguard_ip' => $name === 'first' ? '10.44.0.160' : '10.44.0.161', 'user' => 'orbit']);
                $instance = Instance::query()->create([
                    'project_id' => $project->id,
                    'node_id' => $node->id,
                    'name' => 'task-'.$name,
                    'checkout_path' => $checkout,
                    'branch' => 'task-'.$name,
                    'status' => 'source_resolved',
                ]);
                $process = $runner->start($instance, $analyse.' && printf %s "$TMPDIR" > check-tmp', [
                    ['name' => 'setup', 'command' => $analyse.' && printf %s "$TMPDIR" > setup-tmp', 'timeout_seconds' => 10],
                ], ['start' => null, 'commands' => [
                    ['id' => 'tmp', 'command' => $analyse.' && printf %s "$TMPDIR" > deliverable-tmp', 'directory' => '.'],
                ]]);
                $reading = null;
                for ($attempt = 0; $attempt < 150; $attempt++) {
                    $reading = $runner->read($instance, $process);
                    if ($reading->state !== 'running') {
                        break;
                    }
                    usleep(100_000);
                }
                expect($reading)->toBeInstanceOf(TaskCheckReading::class);
                expect($reading->exitCode)->toBe(0);
                $expected = $checkout.'/.git/orbit/tmp/check-'.posix_geteuid();
                foreach (['setup-tmp', 'check-tmp', 'deliverable-tmp'] as $file) {
                    expect(file_get_contents($checkout.'/'.$file))->toBe($expected);
                }
                expect(file_get_contents($expected.'/phpstan/result'))->toBe('analysed');
                $permissions = (new Process(['getfacl', '-cp', $expected]))->mustRun()->getOutput();
                expect($permissions)->not->toContain('default:')->not->toContain('user:nobody:');
                $directories[] = $expected;
            }
            expect($directories[0])->not->toBe($directories[1]);
        } finally {
            chmod($shared.'/phpstan', 0700);
            putenv($prior === false ? 'TMPDIR' : 'TMPDIR='.$prior);
            if ($priorServer === null) {
                unset($_SERVER['TMPDIR']);
            } else {
                $_SERVER['TMPDIR'] = $priorServer;
            }
            if ($priorEnvironment === null) {
                unset($_ENV['TMPDIR']);
            } else {
                $_ENV['TMPDIR'] = $priorEnvironment;
            }
        }
    });
});
