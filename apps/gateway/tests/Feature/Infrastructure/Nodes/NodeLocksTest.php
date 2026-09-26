<?php

declare(strict_types=1);

use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Nodes\NodeLocks;
use App\Infrastructure\Nodes\Roles\NodeRoleConvergeLock;
use App\Infrastructure\Processes\LockRenewingProcessRunner;
use App\Infrastructure\Processes\ProcessInvocation;
use App\Infrastructure\Processes\ProcessRunner;
use App\Models\Node;
use Illuminate\Cache\CacheManager;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Sleep;
use Symfony\Component\Process\Process;
use Tests\Support\RecordingProcessRunner;

function node_locks_another_process(): NodeLocks
{
    return new NodeLocks(Cache::store('array'));
}

/** @return array{NodeLocks, string} A Node lock store in its own temporary ORBIT_HOME, and that home. */
function node_locks_file_store(): array
{
    $home = sys_get_temp_dir().'/orbit-node-locks-'.bin2hex(random_bytes(4));

    return [new NodeLocks(app(CacheManager::class)->build(NodeLocks::storeConfiguration($home))), $home];
}

/** The file that holds the named Node lock in the file store under the given home. */
function node_locks_file(string $home, string $name): string
{
    $hash = sha1('file-store-lock:orbit:'.$name);

    return $home.'/cache/node-locks/'.substr($hash, 0, 2).'/'.substr($hash, 2, 2).'/'.$hash;
}

function node_locks_command(): ProcessInvocation
{
    return new ProcessInvocation(['true']);
}

describe(NodeLocks::class, function (): void {
    it('uses the request term in a Gateway request and a longer term in an Artisan command', function (): void {
        expect(new NodeLocks(Cache::store('array'))->operationSeconds())->toBe(600)
            ->and(new NodeLocks(Cache::store('array'), console: true)->operationSeconds())->toBe(1200)
            ->and(NodeLocks::ConsoleSeconds)->toBeGreaterThan((int) new ProcessInvocation(['true'])->timeout);
    });

    it('builds an Artisan-term lock store and renews locks before every command in the running app', function (): void {
        app()->forgetInstance(NodeLocks::class);

        expect(app(NodeLocks::class)->operationSeconds())->toBe(NodeLocks::ConsoleSeconds)
            ->and(app(ProcessRunner::class))->toBeInstanceOf(LockRenewingProcessRunner::class);
    });

    it('keeps a held lock for as long as its operation runs commands', function (): void {
        $locks = new NodeLocks(Cache::store('array'));
        $lock = $locks->lock('renew-held', 600);
        $runner = new LockRenewingProcessRunner($inner = new RecordingProcessRunner, $locks);

        expect($lock->get())->toBeTrue();

        foreach (range(1, 3) as $step) {
            $this->travel(500)->seconds();
            $runner->run(node_locks_command());
        }

        $this->travel(500)->seconds();

        expect(node_locks_another_process()->lock('renew-held', 600)->get())->toBeFalse()
            ->and($inner->ran)->toHaveCount(3);

        $lock->release();

        expect(node_locks_another_process()->lock('renew-held', 600)->get())->toBeTrue();
    });

    it('lets a lock expire when its operation runs no command for a full term', function (): void {
        $locks = new NodeLocks(Cache::store('array'));

        expect($locks->lock('idle', 600)->get())->toBeTrue();

        $this->travel(601)->seconds();

        expect(node_locks_another_process()->lock('idle', 600)->get())->toBeTrue();
    });

    it('refuses every later command once another operation took the expired lock', function (): void {
        $locks = new NodeLocks(Cache::store('array'));
        $lock = $locks->lock('lost', 600);
        $runner = new LockRenewingProcessRunner($inner = new RecordingProcessRunner, $locks);

        expect($lock->get())->toBeTrue();

        $this->travel(601)->seconds();
        $taker = node_locks_another_process()->lock('lost', 600);

        expect($taker->get())->toBeTrue();

        foreach (range(1, 2) as $attempt) {
            try {
                $runner->run(node_locks_command());
                test()->fail('Expected the lost lock to refuse the command.');
            } catch (ResourceOperationException $exception) {
                expect($exception->errorCode)->toBe('node.lock_lost')
                    ->and($exception->status)->toBe(409);
            }
        }

        expect($inner->ran)->toBe([])
            ->and($lock->release())->toBeFalse()
            ->and(node_locks_another_process()->lock('lost', 600)->get())->toBeFalse();

        $runner->run(node_locks_command());

        expect($inner->ran)->toHaveCount(1);
    });

    it('refuses the next command once the lock expired, even when no other operation took it', function (): void {
        $locks = new NodeLocks(Cache::store('array'));
        $runner = new LockRenewingProcessRunner($inner = new RecordingProcessRunner, $locks);

        expect($locks->lock('expired', 600)->get())->toBeTrue();

        $this->travel(601)->seconds();

        expect(fn () => $runner->run(node_locks_command()))->toThrow(ResourceOperationException::class, 'expired before it could be renewed')
            ->and($inner->ran)->toBe([]);
    });

    it('retries a renewal while another process briefly holds the lock file', function (): void {
        [$locks, $home] = node_locks_file_store();

        try {
            $lock = $locks->lock('contended', 600);
            expect($lock->get())->toBeTrue();

            // A shared hold refuses the store's non-blocking exclusive renewal, as a polling operation's
            // brief exclusive hold does, and still lets this process read the lock's owner.
            $file = fopen(node_locks_file($home, 'contended'), 'r');
            expect(flock($file, LOCK_SH))->toBeTrue();
            Sleep::whenFakingSleep(static function () use ($file): void {
                flock($file, LOCK_UN);
            });

            $locks->renewHeld();

            Sleep::assertSleptTimes(1);
            expect($lock->refresh())->toBeTrue();
            fclose($file);
        } finally {
            (new Filesystem)->deleteDirectory($home);
        }
    });

    it('keeps a lock it still owns when the lock file stays busy for every retry', function (): void {
        [$locks, $home] = node_locks_file_store();

        try {
            $lock = $locks->lock('busy', 600);
            expect($lock->get())->toBeTrue();

            $file = fopen(node_locks_file($home, 'busy'), 'r');
            expect(flock($file, LOCK_SH))->toBeTrue();

            $locks->renewHeld();
            Sleep::assertSleptTimes(49);

            flock($file, LOCK_UN);
            fclose($file);

            expect($lock->refresh())->toBeTrue()
                ->and($lock->release())->toBeTrue();
        } finally {
            (new Filesystem)->deleteDirectory($home);
        }
    });

    it('keeps renewing while another process polls for the same lock', function (): void {
        [$locks, $home] = node_locks_file_store();
        $runner = new LockRenewingProcessRunner(new RecordingProcessRunner, $locks);

        try {
            $lock = $locks->lock('polled', 600);
            expect($lock->get())->toBeTrue();

            // The other process asks for the lock every millisecond for three seconds, like an operation
            // that waits for it, and prints how many of its attempts found the lock taken.
            $poller = new Process([PHP_BINARY, '-r', <<<'PHP'
                require $argv[1].'/vendor/autoload.php';
                $store = (new Illuminate\Cache\FileStore(new Illuminate\Filesystem\Filesystem, $argv[2]))->setLockDirectory($argv[2]);
                $refused = 0;
                $until = microtime(true) + 3;
                while (microtime(true) < $until) {
                    $store->lock('orbit:polled', 600)->get() ? exit(1) : $refused++;
                    usleep(1000);
                }
                echo $refused;
                PHP, base_path(), $home.'/cache/node-locks']);
            $poller->start();

            $commands = 0;
            while ($poller->isRunning()) {
                $runner->run(node_locks_command());
                $commands++;
            }

            expect($poller->getExitCode())->toBe(0)
                ->and((int) $poller->getOutput())->toBeGreaterThan(100)
                ->and($commands)->toBeGreaterThan(100)
                ->and($lock->release())->toBeTrue();
        } finally {
            (new Filesystem)->deleteDirectory($home);
        }
    });

    it('tells whether any process holds a lock', function (): void {
        [$locks, $home] = node_locks_file_store();

        try {
            $lock = $locks->lock('observed', 600);
            $other = new NodeLocks(app(CacheManager::class)->build(NodeLocks::storeConfiguration($home)));

            expect($other->lock('observed', 1)->isLocked())->toBeFalse()
                ->and($lock->get())->toBeTrue()
                ->and($other->lock('observed', 1)->isLocked())->toBeTrue();

            $lock->release();

            expect($other->lock('observed', 1)->isLocked())->toBeFalse();
        } finally {
            (new Filesystem)->deleteDirectory($home);
        }
    });

    it('stops renewing a lock once it is released', function (): void {
        $locks = new NodeLocks(Cache::store('array'));
        $lock = $locks->lock('released', 600);
        $runner = new LockRenewingProcessRunner(new RecordingProcessRunner, $locks);

        expect($lock->get())->toBeTrue();
        $lock->release();
        $runner->run(node_locks_command());

        expect(node_locks_another_process()->lock('released', 600)->get())->toBeTrue();
    });

    it('holds a role lock through an Artisan role operation longer than any single term', function (): void {
        $node = Node::query()->create([
            'name' => 'app-prod',
            'status' => LifecycleStatus::Active,
            'platform' => 'linux',
            'public_ssh_host' => '192.0.2.20',
            'user' => 'orbit',
            'wireguard_ip' => '10.44.0.3',
        ]);
        $locks = new NodeLocks(Cache::store('array'), console: true);
        $runner = new LockRenewingProcessRunner($inner = new RecordingProcessRunner, $locks);
        $inner->during = fn () => $this->travel(900)->seconds();
        $name = "node-role:id:{$node->id}";

        new NodeRoleConvergeLock($locks, waitSeconds: 0)->run($node, function () use ($runner, $name): void {
            foreach (range(1, 4) as $step) {
                $runner->run(node_locks_command());

                expect(node_locks_another_process()->lock($name, 600)->get())->toBeFalse();
            }
        });

        expect($inner->ran)->toHaveCount(4)
            ->and(node_locks_another_process()->lock($name, 600)->get())->toBeTrue();
    });
});
