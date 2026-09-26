<?php

declare(strict_types=1);

use App\Domain\Nodes\NodeLockLoss;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Domain\WebSocket\WebSocketCredentialManager;
use App\Domain\WebSocket\WebSocketCredentials;
use App\Domain\WebSocket\WebSocketPublicationManager;
use App\Domain\WebSocket\WebSocketRuntimeLifecycle;
use App\Infrastructure\Nodes\Roles\WebSocketRoleBaseline;
use App\Models\Node;
use App\Models\NodeRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('generates credentials, then converges runtime before publication', function (): void {
    [$node, $assignment] = websocketBaselineTopology();
    $events = [];
    $baseline = websocketBaseline($events);

    $baseline->converge($node, $assignment);

    expect($events)->toBe([
        'publication:check',
        'credentials:ensure',
        'runtime:converge',
        'publication:converge',
    ]);
});

it('rolls the runtime back when publication fails', function (): void {
    [$node, $assignment] = websocketBaselineTopology();
    $events = [];
    $baseline = websocketBaseline($events, failure: 'publication:converge');

    expect(fn () => $baseline->converge($node, $assignment))
        ->toThrow(RuntimeException::class, 'publication:converge failed')
        ->and($events)
        ->toBe([
            'publication:check',
            'credentials:ensure',
            'runtime:converge',
            'publication:converge',
            'runtime:remove',
        ]);
});

it('refuses a missing listen address before it touches a running Reverb', function (): void {
    [$node, $assignment] = websocketBaselineTopology();
    $events = [];
    $baseline = websocketBaseline($events, failure: 'publication:check');

    expect(fn () => $baseline->converge($node, $assignment))
        ->toThrow(RuntimeException::class, 'publication:check failed')
        ->and($events)
        ->toBe(['publication:check']);
});

it('does not roll back a runtime that never converged', function (): void {
    [$node, $assignment] = websocketBaselineTopology();
    $events = [];
    $baseline = websocketBaseline($events, failure: 'runtime:converge');

    expect(fn () => $baseline->converge($node, $assignment))
        ->toThrow(RuntimeException::class, 'runtime:converge failed')
        ->and($events)
        ->toBe(['publication:check', 'credentials:ensure', 'runtime:converge']);
});

it('fails closed when convergence rollback does not complete', function (): void {
    [$node, $assignment] = websocketBaselineTopology();
    $events = [];
    $baseline = websocketBaseline(
        $events,
        failure: 'publication:converge',
        rollbackFailure: 'runtime:remove',
    );

    try {
        $baseline->converge($node, $assignment);
        $exception = null;
    } catch (ResourceOperationException $caught) {
        $exception = $caught;
    }

    expect($exception)
        ->not
        ->toBeNull()
        ->and($exception?->errorCode)
        ->toBe('websocket.rollback_failed');
});

it('reports a lost Node lock instead of the rollback failure it causes', function (): void {
    [$node, $assignment] = websocketBaselineTopology();
    $events = [];
    $baseline = websocketBaseline($events, failure: 'publication:converge', rollbackFailure: 'runtime:remove', lockLost: true);

    expect(fn () => $baseline->converge($node, $assignment))
        ->toThrow(fn (ResourceOperationException $exception) => expect($exception->errorCode)->toBe(NodeLockLoss::ErrorCode));
});

it('removes publication and runtime before purging credentials', function (): void {
    [$node, $assignment] = websocketBaselineTopology();
    $events = [];
    $baseline = websocketBaseline($events);

    $baseline->remove($node, $assignment, true);

    expect($events)->toBe([
        'publication:remove',
        'runtime:remove:purge',
        'publication:retire',
        'credentials:purge',
    ]);
});

it('keeps publishing to the Node when its withdrawal or its Reverb stop fails', function (string $failure, array $expected): void {
    [$node, $assignment] = websocketBaselineTopology();
    $events = [];
    $baseline = websocketBaseline($events, failure: $failure);

    expect(fn () => $baseline->remove($node, $assignment, false))->toThrow(RuntimeException::class);

    expect($events)->toBe($expected)
        ->and($events)->not->toContain('publication:retire');
})->with([
    'withdrawal build fails' => ['publication:remove', ['publication:remove']],
    'Reverb stop fails' => ['runtime:remove', ['publication:remove', 'runtime:remove']],
]);

it('touches only the Gateway-side publication and credentials when the node is unreachable', function (): void {
    [$node, $assignment] = websocketBaselineTopology();
    $events = [];
    $baseline = websocketBaseline($events);

    $baseline->removeUnreachable($node, $assignment);

    expect($events)->toBe([
        'publication:removeUnreachable',
        'credentials:purge',
    ]);
});

/** @return array{Node, NodeRole} */
function websocketBaselineTopology(): array
{
    $node = Node::query()->create([
        'name' => 'websocket',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.9',
        'ssh_user' => 'orbit',
        'wireguard_ip' => '10.44.0.9',
    ]);
    $assignment = $node->roles()->create([
        'role' => RoleName::WebSocket,
        'status' => LifecycleStatus::Provisioning,
    ]);

    return [$node, $assignment];
}

function websocketBaseline(
    array &$events,
    ?string $failure = null,
    ?string $rollbackFailure = null,
    bool $lockLost = false,
): WebSocketRoleBaseline {
    return new WebSocketRoleBaseline(
        runtime: new WebSocketBaselineRuntime($events, $failure, $rollbackFailure, $lockLost),
        publication: new WebSocketBaselinePublication($events, $failure, $rollbackFailure, $lockLost),
        credentials: new WebSocketBaselineCredentials($events, $failure, $rollbackFailure, $lockLost),
    );
}

final class WebSocketBaselineRuntime implements WebSocketRuntimeLifecycle
{
    public function __construct(
        private array &$events,
        private ?string $failure,
        private ?string $rollbackFailure,
        private bool $lockLost = false,
    ) {}

    public function converge(Node $node, NodeRole $assignment, WebSocketCredentials $credentials): void
    {
        $this->record('runtime:converge');
    }

    public function remove(Node $node, NodeRole $assignment, bool $purgeData): void
    {
        $this->record($purgeData ? 'runtime:remove:purge' : 'runtime:remove');
    }

    public function health(Node $node): bool
    {
        return true;
    }

    private function record(string $event): void
    {
        $this->events[] = $event;

        if ($this->failure === $event || $this->rollbackFailure === $event) {
            throw $this->lockLost ? NodeLockLoss::exception('node-role:id:1') : new RuntimeException("{$event} failed");
        }
    }
}

final class WebSocketBaselinePublication implements WebSocketPublicationManager
{
    public function __construct(
        private array &$events,
        private ?string $failure,
        private ?string $rollbackFailure,
        private bool $lockLost = false,
    ) {}

    public function converge(Node $node): void
    {
        $this->record('publication:converge');
    }

    public function retire(Node $node): void
    {
        $this->record('publication:retire');
    }

    public function checkListenAddresses(Node $node): void
    {
        $this->record('publication:check');
    }

    public function remove(Node $node): void
    {
        $this->record('publication:remove');
    }

    public function removeUnreachable(Node $node): void
    {
        $this->record('publication:removeUnreachable');
    }

    private function record(string $event): void
    {
        $this->events[] = $event;

        if ($this->failure === $event || $this->rollbackFailure === $event) {
            throw $this->lockLost ? NodeLockLoss::exception('node-role:id:1') : new RuntimeException("{$event} failed");
        }
    }
}

final class WebSocketBaselineCredentials implements WebSocketCredentialManager
{
    public function __construct(
        private array &$events,
        private ?string $failure,
        private ?string $rollbackFailure,
        private bool $lockLost = false,
    ) {}

    public function ensure(Node $node): WebSocketCredentials
    {
        $this->record('credentials:ensure');

        return new WebSocketCredentials('id', 'key', 'secret', 'base64:'.base64_encode('key'));
    }

    public function current(): ?WebSocketCredentials
    {
        return null;
    }

    public function purge(Node $node): void
    {
        $this->record('credentials:purge');
    }

    private function record(string $event): void
    {
        $this->events[] = $event;

        if ($this->failure === $event || $this->rollbackFailure === $event) {
            throw $this->lockLost ? NodeLockLoss::exception('node-role:id:1') : new RuntimeException("{$event} failed");
        }
    }
}
