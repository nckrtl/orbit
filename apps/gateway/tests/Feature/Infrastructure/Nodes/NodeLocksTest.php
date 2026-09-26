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
use Illuminate\Support\Facades\Cache;
use Tests\Support\RecordingProcessRunner;

function node_locks_another_process(): NodeLocks
{
    return new NodeLocks(Cache::store('array'));
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
