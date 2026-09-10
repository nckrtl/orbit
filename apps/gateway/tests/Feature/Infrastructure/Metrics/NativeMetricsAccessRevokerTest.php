<?php

declare(strict_types=1);

use App\Domain\Nodes\RoleName;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Metrics\MetricsCaddyPublisher;
use App\Infrastructure\Metrics\NativeMetricsAccessRevoker;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Processes\ProcessInvocation;
use App\Infrastructure\Processes\ProcessRunner;
use App\Models\Node;

it('reloads Caddy whenever a Metrics route may remain published', function (
    LifecycleStatus $roleStatus,
    LifecycleStatus $nodeStatus,
): void {
    $metrics = metricsAccessRevokerNode('metrics', $nodeStatus);
    $metrics->roles()->create([
        'role' => RoleName::Metrics,
        'status' => $roleStatus,
    ]);
    $processes = new MetricsAccessRevokerProcessRunner;

    new NativeMetricsAccessRevoker(new MetricsCaddyPublisher($processes))->revoke();

    expect($processes->invocations)
        ->toHaveCount(1)
        ->and($processes->invocations[0]->arguments)
        ->toBe(['sudo', 'systemctl', 'reload', 'caddy']);
})->with([
    'active assignment' => [LifecycleStatus::Active, LifecycleStatus::Active],
    'provisioning assignment' => [LifecycleStatus::Provisioning, LifecycleStatus::Active],
    'failed assignment' => [LifecycleStatus::Failed, LifecycleStatus::Active],
    'removing assignment' => [LifecycleStatus::Removing, LifecycleStatus::Active],
    'inactive Metrics node' => [LifecycleStatus::Active, LifecycleStatus::Failed],
]);

it('does not touch Caddy without an active Metrics assignment', function (): void {
    $processes = new MetricsAccessRevokerProcessRunner;

    new NativeMetricsAccessRevoker(new MetricsCaddyPublisher($processes))->revoke();

    expect($processes->invocations)->toBeEmpty();
});

it('fails closed when the stream-closing reload fails', function (): void {
    $metrics = metricsAccessRevokerNode('metrics');
    $metrics->roles()->create([
        'role' => RoleName::Metrics,
        'status' => LifecycleStatus::Active,
    ]);
    $processes = new MetricsAccessRevokerProcessRunner(fail: true);

    expect(fn () => new NativeMetricsAccessRevoker(new MetricsCaddyPublisher($processes))->revoke())
        ->toThrow(ResourceOperationException::class, 'Metrics Caddy publication did not complete.');
});

function metricsAccessRevokerNode(
    string $name,
    LifecycleStatus $status = LifecycleStatus::Active,
): Node {
    return Node::query()->create([
        'name' => $name,
        'status' => $status,
        'platform' => 'linux',
        'public_ssh_host' => $name.'.example.test',
        'user' => 'orbit',
        'wireguard_ip' => '10.44.0.3',
    ]);
}

final class MetricsAccessRevokerProcessRunner implements ProcessRunner
{
    /** @var list<ProcessInvocation> */
    public array $invocations = [];

    public function __construct(
        private bool $fail = false,
    ) {}

    public function run(ProcessInvocation $invocation): CommandResult
    {
        $this->invocations[] = $invocation;

        return new CommandResult($this->fail ? 1 : 0, '', '', 1, false);
    }
}
