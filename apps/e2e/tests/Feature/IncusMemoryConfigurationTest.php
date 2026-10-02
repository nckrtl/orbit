<?php

declare(strict_types=1);

use App\E2E\IncusHost;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Process;

it('sizes newly created VMs by physical Node with the existing uniform override', function (?string $override, string $node, string $memory, int $address): void {
    if ($override !== null) {
        config(['e2e.incus.memory' => $override]);
    }
    Process::fake(['*' => Process::result()]);

    app(IncusHost::class)->initVms([$node => [
        'image' => 'orbit-base',
        'name' => 'orbit-memory-'.$node,
        'network' => 'oe-memory',
        'role' => $node,
        'address' => $address,
        'topology' => 'oe-memory',
        'slot' => 2,
        'metadata' => [],
    ]]);

    Process::assertRan(fn (PendingProcess $process): bool => (
        is_array($process->command)
        && in_array('init', $process->command, true)
        && in_array('limits.memory='.$memory, $process->command, true)
        && in_array('limits.cpu=1', $process->command, true)
    ));
})->with([
    'Gateway' => [null, 'gateway', '1536MiB', 10],
    'development' => [null, 'app-dev', '2GiB', 11],
    'production' => [null, 'app-prod', '1GiB', 12],
    'production extension' => [null, 'app-prod-2', '1GiB', 13],
    'cold operator' => [null, 'operator', '2GiB', 11],
    'cold extra' => [null, 'extra', '2GiB', 13],
    'uniform Gateway override' => ['3GiB', 'gateway', '3GiB', 10],
    'uniform production override' => ['3GiB', 'app-prod', '3GiB', 12],
]);

it('applies current memory defaults when cloning an older snapshot', function (?string $override, string $node, string $memory): void {
    if ($override !== null) {
        config(['e2e.incus.memory' => $override]);
    }
    $source = 'orbit-source-'.$node;
    Process::fake(function (PendingProcess $process) use ($source) {
        if (in_array('list', $process->command, true) && ! in_array('snapshot', $process->command, true)) {
            return Process::result(json_encode([[
                'name' => $source,
                'type' => 'virtual-machine',
                'status' => 'Stopped',
                'status_code' => 102,
                'config' => ['user.orbit.e2e.owner' => 'orbit-e2e', 'limits.memory' => '2GiB'],
                'devices' => ['root' => ['pool' => 'orbit-e2e']],
            ]], JSON_THROW_ON_ERROR));
        }
        if (in_array('snapshot', $process->command, true)) {
            return Process::result(json_encode([[
                'name' => 'prepared',
                'config' => ['user.orbit.e2e.owner' => 'orbit-e2e'],
            ]], JSON_THROW_ON_ERROR));
        }

        return Process::result();
    });

    new IncusHost(pool: 'orbit-e2e')->copySnapshots([$node => [
        'source' => $source,
        'snapshot' => 'prepared',
        'target' => 'orbit-memory-'.$node,
        'metadata' => [],
        'network' => 'oe-memory',
        'role' => $node,
        'topology' => 'oe-memory',
        'slot' => 2,
    ]]);

    Process::assertRan(fn (PendingProcess $process): bool => (
        is_array($process->command)
        && in_array('copy', $process->command, true)
        && in_array('limits.memory='.$memory, $process->command, true)
        && in_array('limits.cpu=1', $process->command, true)
    ));
})->with([
    'Gateway' => [null, 'gateway', '1536MiB'],
    'development' => [null, 'app-dev', '2GiB'],
    'production' => [null, 'app-prod', '1GiB'],
    'uniform override' => ['3GiB', 'gateway', '3GiB'],
]);

it('refuses a malformed Node memory limit before creating any VM', function (): void {
    config(['e2e.incus.memory.app-prod' => '1GB']);
    Process::fake();

    expect(fn () => app(IncusHost::class)->initVms(['app-prod' => [
        'image' => 'orbit-base',
        'name' => 'orbit-memory-app-prod',
        'network' => 'oe-memory',
        'role' => 'app-prod',
        'topology' => 'oe-memory',
        'slot' => 2,
        'metadata' => [],
    ]]))->toThrow(InvalidArgumentException::class, 'Incus memory limit must use MiB or GiB.');

    Process::assertNothingRan();
});
