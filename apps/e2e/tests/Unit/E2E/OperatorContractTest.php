<?php

declare(strict_types=1);

use App\E2E\IncusHost;
use App\E2E\Value\LaravelRelease;
use App\E2E\Value\TopologyRecipe;
use App\E2E\Value\TopologySnapshotGeneration;
use Illuminate\Container\Container;
use Illuminate\Process\Factory as ProcessFactory;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Process;

beforeEach(function (): void {
    $container = new Container;
    $container->instance(ProcessFactory::class, new ProcessFactory);
    Facade::clearResolvedInstances();
    Facade::setFacadeApplication($container);
});

it('always declares a separate roleless operator with a checkout and no VM reservation', function (): void {
    foreach ([TopologyRecipe::registered(), TopologyRecipe::extendedAppProd(), TopologyRecipe::coldAcceptance()] as $recipe) {
        $operator = $recipe->node('operator');
        expect($operator->roles)->toBe([])
            ->and($operator->checkout)->toBeTrue()
            ->and($operator->image)->toBe(TopologyRecipe::OPERATOR_IMAGE)
            ->and($operator->wireGuardAddress())->toBe('10.44.0.5')
            ->and($recipe->vmCount())->toBe(count($recipe->nodes) - 1)
            ->and($recipe->nodeForRole('app-dev')->key)->toBe('app-dev');
    }
});

it('requires exactly one correctly typed local image for each base without a remote fallback', function (string $alias, string $type, array $images, bool $valid): void {
    Process::fake(function (PendingProcess $process) use ($images): mixed {
        return Process::result(json_encode($images, JSON_THROW_ON_ERROR));
    });
    $host = new IncusHost;
    if ($valid) {
        expect($host->imageFingerprint($alias, $type))->toBe(str_repeat('b', 64));
    } else {
        expect(fn () => $host->imageFingerprint($alias, $type))->toThrow(RuntimeException::class);
    }
    Process::assertRan(['incus', '--project', 'default', 'image', 'list', 'local:', $alias, '--format=json']);
    Process::assertNotRan(fn (PendingProcess $process): bool => in_array('images:', $process->command, true));
})->with(function (): array {
    $vm = ['type' => 'virtual-machine', 'fingerprint' => str_repeat('b', 64), 'aliases' => [['name' => TopologyRecipe::BASE_IMAGE]]];
    $container = ['type' => 'container', 'fingerprint' => str_repeat('b', 64), 'aliases' => [['name' => TopologyRecipe::OPERATOR_IMAGE]]];

    return [
        'VM' => [TopologyRecipe::BASE_IMAGE, 'virtual-machine', [$vm], true],
        'operator' => [TopologyRecipe::OPERATOR_IMAGE, 'container', [$container], true],
        'missing operator' => [TopologyRecipe::OPERATOR_IMAGE, 'container', [], false],
        'wrong operator type' => [TopologyRecipe::OPERATOR_IMAGE, 'container', [[...$container, 'type' => 'virtual-machine']], false],
        'wrong VM type' => [TopologyRecipe::BASE_IMAGE, 'virtual-machine', [[...$vm, 'type' => 'container']], false],
        'ambiguous operator' => [TopologyRecipe::OPERATOR_IMAGE, 'container', [$container, $container], false],
        'invalid operator fingerprint' => [TopologyRecipe::OPERATOR_IMAGE, 'container', [[...$container, 'fingerprint' => 'bad']], false],
    ];
});

it('initializes the operator as an unprivileged container from its pinned local fingerprint', function (): void {
    Process::fake();
    $fingerprint = str_repeat('b', 64);
    new IncusHost()->initVms(['operator' => [
        'image' => $fingerprint,
        'name' => 'orbit-e2e-test-operator',
        'network' => 'oe-test',
        'role' => 'operator',
        'address' => 14,
        'topology' => 'oe-test',
        'slot' => 2,
        'metadata' => [],
    ]]);
    Process::assertRan(fn (PendingProcess $process): bool => (
        is_array($process->command)
        && in_array('init', $process->command, true)
        && in_array('local:'.$fingerprint, $process->command, true)
        && ! in_array('--vm', $process->command, true)
        && in_array('security.privileged=false', $process->command, true)
        && in_array('security.nesting=true', $process->command, true)
        && in_array('eth0,ipv4.address=10.232.2.14', $process->command, true)
    ));
});

it('records both bases in the coordinated snapshot and rejects absent or malformed operator provenance', function (): void {
    $recipe = TopologyRecipe::registered();
    $generation = new TopologySnapshotGeneration(
        'generation', str_repeat('a', 40), array_fill_keys($recipe->nodeKeys(), 'main-generation'),
        str_repeat('b', 64), str_repeat('c', 64), new LaravelRelease('v13.0.0', str_repeat('d', 40)),
        str_repeat('e', 64), 2, 'ubuntu-26.04-amd64-v1', TopologyRecipe::BASE_IMAGE,
        $recipe->id, $recipe->nodeKeys(), $recipe->checkoutNodeKeys(),
        operatorBaseImageFingerprint: str_repeat('b', 64),
    );
    $value = $generation->toArray();
    expect($value['operator_base_image'])->toBe(['alias' => TopologyRecipe::OPERATOR_IMAGE, 'fingerprint' => str_repeat('b', 64)])
        ->and($value['base_image_alias'])->toBe(TopologyRecipe::BASE_IMAGE)
        ->and(array_keys($value['snapshots']))->toContain('operator')
        ->and(TopologySnapshotGeneration::fromArray($value)->toArray())->toBe($value);
    unset($value['operator_base_image']);
    expect(fn () => TopologySnapshotGeneration::fromArray($value))->toThrow(InvalidArgumentException::class);
    $value = $generation->toArray();
    $value['operator_base_image']['fingerprint'] = 'invalid';
    expect(fn () => TopologySnapshotGeneration::fromArray($value))->toThrow(InvalidArgumentException::class);
    $value = $generation->toArray();
    $value['operator_base_image']['alias'] = TopologyRecipe::BASE_IMAGE;
    expect(fn () => TopologySnapshotGeneration::fromArray($value))->toThrow(InvalidArgumentException::class);
    $value = $generation->toArray();
    unset($value['snapshots']['operator']);
    expect(fn () => TopologySnapshotGeneration::fromArray($value))->toThrow(InvalidArgumentException::class);
});
