<?php

declare(strict_types=1);

use App\Domain\Nodes\NodeRoleOperationException;
use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\Nodes\NodeLocks;
use App\Infrastructure\Nodes\Roles\NodeRoleConvergeLock;
use App\Models\Node;
use Illuminate\Filesystem\Filesystem;

function node_role_lock_node(string $name = 'app-prod', string $address = '10.44.0.3'): Node
{
    return Node::query()->create([
        'name' => $name,
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.20',
        'user' => 'orbit',
        'wireguard_ip' => $address,
    ]);
}

describe(NodeRoleConvergeLock::class, function (): void {
    it('refuses a second role operation on the same Node with the caller error code', function (string $errorCode): void {
        $node = node_role_lock_node();
        $lock = new NodeRoleConvergeLock(app(NodeLocks::class), waitSeconds: 0);
        $held = app(NodeLocks::class)->lock("node-role:id:{$node->id}", 60);
        $held->get();
        $ran = false;

        try {
            $lock->run($node, function () use (&$ran): void {
                $ran = true;
            }, $errorCode);
            test()->fail('Expected the held lock to refuse the operation.');
        } catch (NodeRoleOperationException $exception) {
            expect($exception->step)->toBe('node-lock')
                ->and($exception->errorCode)->toBe($errorCode)
                ->and($exception->underlyingErrorCode)->toBe('node_role.node_busy');
        } finally {
            $held->release();
        }

        expect($ran)->toBeFalse();
    })->with(['node_role.convergence_failed', 'node_role.remove_failed']);

    it('lets other Nodes run, re-enters for the same Node, and releases after a failure', function (): void {
        $node = node_role_lock_node();
        $other = node_role_lock_node('app-dev', '10.44.0.2');
        $lock = new NodeRoleConvergeLock(app(NodeLocks::class), waitSeconds: 0);

        $nested = $lock->run($node, fn (): string => $lock->run($node, fn (): string => $lock->run($other, fn (): string => 'nested')));

        expect($nested)->toBe('nested')
            ->and(fn () => $lock->run($node, static fn () => throw new RuntimeException('baseline failed')))->toThrow(RuntimeException::class)
            ->and($lock->run($node, static fn (): string => 'again'))->toBe('again');
    });

    it('keeps the lock in the pinned file store, so another PHP process sees it', function (): void {
        $home = sys_get_temp_dir().'/orbit-node-role-lock-'.bin2hex(random_bytes(4));
        config(['orbit.home' => $home, 'cache.default' => 'database']);
        app()->forgetInstance(NodeLocks::class);
        app()->forgetInstance(NodeRoleConvergeLock::class);
        $node = node_role_lock_node();

        try {
            app(NodeRoleConvergeLock::class)->run($node, function () use ($node): void {
                expect(app(NodeLocks::class)->lock("node-role:id:{$node->id}", 5)->get())->toBeFalse()
                    ->and(glob(sys_get_temp_dir().'/'.basename((string) config('orbit.home')).'/cache/node-locks/*/*/*') ?: [])->not->toBe([]);
            });

            expect(app(NodeLocks::class)->lock("node-role:id:{$node->id}", 5)->get())->toBeTrue();
        } finally {
            (new Filesystem)->deleteDirectory($home);
        }
    });
});
