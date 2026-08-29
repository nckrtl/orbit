<?php

declare(strict_types=1);

use App\E2E\State\AtomicJsonStore;
use App\E2E\State\StatePaths;
use App\E2E\TopologyManifestStore;
use App\E2E\Value\AttemptId;
use App\E2E\Value\AttemptPurpose;
use App\E2E\Value\FeatureTopology;
use App\E2E\Value\LaravelRelease;
use App\E2E\Value\SourceState;
use App\E2E\Value\StandbyGeneration;
use App\E2E\Value\TopologyTarget;
use App\E2E\Value\VerificationReport;

describe('TopologyManifestStore', function () {
    it('accepts and returns only a fully typed topology manifest', function () {
        $paths = new StatePaths(temporaryPath('orbit-topology-', 4));
        $store = new TopologyManifestStore(new AtomicJsonStore($paths), $paths);
        $attempt = new AttemptId(str_repeat('a', 32));
        $topology = topologyFixture(TopologyTarget::feature('NCK-321', $attempt));
        $store->writeActive($topology);

        expect($store->active('NCK-321'))
            ->toEqual($topology)
            ->and($store->read('NCK-321', $attempt))
            ->toEqual($topology);
    });

    it('serializes the attempt identity and purpose', function () {
        $attempt = new AttemptId(str_repeat('a', 32));
        $topology = topologyFixture(TopologyTarget::feature('NCK-321', $attempt), AttemptPurpose::Proof);

        expect($topology->toArray())
            ->toMatchArray([
                'schema' => 2,
                'issue' => 'NCK-321',
                'attempt_id' => str_repeat('a', 32),
                'purpose' => 'proof',
            ])
            ->and(FeatureTopology::fromArray($topology->toArray()))
            ->toEqual($topology);
    });

    it('stores exact attempts beside one active pointer', function () {
        $paths = new StatePaths(temporaryPath('orbit-topology-', 4));
        $store = new TopologyManifestStore(new AtomicJsonStore($paths), $paths);
        $attempt = new AttemptId(str_repeat('a', 32));
        $store->writeActive(topologyFixture(TopologyTarget::feature('NCK-321', $attempt)));

        expect($paths->path('topologies/NCK-321/'.str_repeat('a', 32).'.json'))
            ->toBeFile()
            ->and(json_decode((string) file_get_contents($paths->path('topologies/NCK-321/active.json')), true))
            ->toBe(['schema' => 2, 'issue' => 'NCK-321', 'attempt' => str_repeat('a', 32)]);
    });

    it('permits only one active topology per issue', function () {
        $paths = new StatePaths(temporaryPath('orbit-topology-', 4));
        $store = new TopologyManifestStore(new AtomicJsonStore($paths), $paths);
        $first = topologyFixture(TopologyTarget::feature('NCK-321', new AttemptId(str_repeat('a', 32))));
        $second = topologyFixture(TopologyTarget::feature('NCK-321', new AttemptId(str_repeat('b', 32))));
        $store->writeActive($first);

        expect(fn () => $store->writeActive($second))
            ->toThrow(RuntimeException::class, 'already has an active topology attempt')
            ->and($store->active('NCK-321'))
            ->toEqual($first);
    });

    it('updates the active attempt in place', function () {
        $paths = new StatePaths(temporaryPath('orbit-topology-', 4));
        $store = new TopologyManifestStore(new AtomicJsonStore($paths), $paths);
        $attempt = new AttemptId(str_repeat('a', 32));
        $store->writeActive(topologyFixture(TopologyTarget::feature('NCK-321', $attempt)));
        $updated = topologyFixture(TopologyTarget::feature('NCK-321', $attempt), AttemptPurpose::Proof);
        $store->writeActive($updated);

        expect($store->active('NCK-321'))->toEqual($updated);
    });

    it('reads an exact attempt only with its own issue and attempt', function () {
        $paths = new StatePaths(temporaryPath('orbit-topology-', 4));
        $store = new TopologyManifestStore(new AtomicJsonStore($paths), $paths);
        $attempt = new AttemptId(str_repeat('a', 32));
        $store->writeActive(topologyFixture(TopologyTarget::feature('NCK-321', $attempt)));

        expect($store->read('NCK-321', new AttemptId(str_repeat('b', 32))))
            ->toBeNull()
            ->and($store->read('NCK-322', $attempt))
            ->toBeNull();
    });

    it('refuses a manifest whose recorded identity does not match its path', function () {
        $paths = new StatePaths(temporaryPath('orbit-topology-', 4));
        $state = new AtomicJsonStore($paths);
        $store = new TopologyManifestStore($state, $paths);
        $topology = topologyFixture(TopologyTarget::feature('NCK-321', new AttemptId(str_repeat('a', 32))));
        $state->write('topologies/NCK-321/'.str_repeat('b', 32).'.json', $topology->toArray());

        expect(fn () => $store->read('NCK-321', new AttemptId(str_repeat('b', 32))))
            ->toThrow(RuntimeException::class, 'does not match its path');
    });

    it('refuses an active pointer without its exact attempt record', function () {
        $paths = new StatePaths(temporaryPath('orbit-topology-', 4));
        $state = new AtomicJsonStore($paths);
        $store = new TopologyManifestStore($state, $paths);
        $state->write('topologies/NCK-321/active.json', [
            'schema' => 2,
            'issue' => 'NCK-321',
            'attempt' => str_repeat('a', 32),
        ]);

        expect(fn () => $store->active('NCK-321'))
            ->toThrow(RuntimeException::class, 'active topology attempt record is missing');
    });

    it('refuses a malformed active pointer', function (array $pointer) {
        $paths = new StatePaths(temporaryPath('orbit-topology-', 4));
        $state = new AtomicJsonStore($paths);
        $store = new TopologyManifestStore($state, $paths);
        $state->write('topologies/NCK-321/active.json', $pointer);

        expect(fn () => $store->active('NCK-321'))
            ->toThrow(RuntimeException::class, 'active topology pointer is invalid');
    })->with([
        'wrong schema' => [['schema' => 1, 'issue' => 'NCK-321', 'attempt' => str_repeat('a', 32)]],
        'wrong issue' => [['schema' => 2, 'issue' => 'NCK-322', 'attempt' => str_repeat('a', 32)]],
        'loose attempt' => [['schema' => 2, 'issue' => 'NCK-321', 'attempt' => 'NCK-321']],
        'extra key' => [
            ['schema' => 2, 'issue' => 'NCK-321', 'attempt' => str_repeat('a', 32), 'extra' => 'x'],
        ],
    ]);

    it('forgets only the exact active attempt', function () {
        $paths = new StatePaths(temporaryPath('orbit-topology-', 4));
        $store = new TopologyManifestStore(new AtomicJsonStore($paths), $paths);
        $attempt = new AttemptId(str_repeat('a', 32));
        $active = topologyFixture(TopologyTarget::feature('NCK-321', $attempt));
        $store->writeActive($active);
        $other = topologyFixture(TopologyTarget::feature('NCK-321', new AttemptId(str_repeat('b', 32))));

        expect(fn () => $store->forgetActive($other))
            ->toThrow(RuntimeException::class, 'is not the active topology attempt')
            ->and($store->active('NCK-321'))
            ->toEqual($active);

        $store->forgetActive($active);

        expect($store->active('NCK-321'))
            ->toBeNull()
            ->and($store->read('NCK-321', $attempt))
            ->toBeNull()
            ->and(file_exists($paths->path('topologies/NCK-321/'.$attempt->value.'.json')))
            ->toBeFalse()
            ->and(file_exists($paths->path('topologies/NCK-321/active.json')))
            ->toBeFalse();
    });

    it('inventories the generation every active attempt pins', function () {
        $paths = new StatePaths(temporaryPath('orbit-topology-', 4));
        $store = new TopologyManifestStore(new AtomicJsonStore($paths), $paths);

        expect($store->activeGenerationIds())->toBeEmpty();

        $store->writeActive(topologyFixture(
            TopologyTarget::feature('NCK-321', new AttemptId(str_repeat('a', 32))),
        ));
        $store->writeActive(topologyFixture(
            TopologyTarget::feature('NCK-322', new AttemptId(str_repeat('b', 32))),
        ));

        expect($store->activeGenerationIds())->toBe(['g1', 'g1']);
    });

    it('refuses to inventory pinned generations beside a schema 1 manifest', function () {
        $paths = new StatePaths(temporaryPath('orbit-topology-', 4));
        $state = new AtomicJsonStore($paths);
        $store = new TopologyManifestStore($state, $paths);
        $state->write('topologies/NCK-321.json', ['schema' => 1, 'issue' => 'NCK-321']);

        expect($store->activeGenerationIds(...))
            ->toThrow(RuntimeException::class, 'schema 1 topology manifest');
    });

    it('refuses every attempt-scoped operation while a schema 1 manifest exists', function () {
        $paths = new StatePaths(temporaryPath('orbit-topology-', 4));
        $state = new AtomicJsonStore($paths);
        $store = new TopologyManifestStore($state, $paths);
        $state->write('topologies/NCK-321.json', ['schema' => 1, 'issue' => 'NCK-321']);
        $topology = topologyFixture(TopologyTarget::feature('NCK-321', new AttemptId(str_repeat('a', 32))));

        expect(fn () => $store->active('NCK-321'))
            ->toThrow(RuntimeException::class, 'schema 1 topology manifest')
            ->and(fn () => $store->writeActive($topology))
            ->toThrow(RuntimeException::class, 'schema 1 topology manifest');
    });

    it('rejects duplicate or loose resources and exact source and report schema errors', function () {
        $target = TopologyTarget::feature('NCK-321', new AttemptId(str_repeat('a', 32)));
        $source = new SourceState(str_repeat('b', 40), str_repeat('b', 40));
        $report = new VerificationReport(true, ['gateway.ready' => verificationProbeFixture(probe: 'gateway.ready')]);

        expect(
            fn () => new FeatureTopology(
                $target,
                AttemptPurpose::Discovery,
                standbyGenerationFixture(),
                $target->network(),
                [
                    'gateway' => $target->instance('gateway'),
                    'app-dev' => $target->instance('gateway'),
                    'app-prod' => $target->instance('app-prod'),
                ],
                $source,
                $report,
            ),
        )
            ->toThrow(InvalidArgumentException::class)
            ->and(
                fn () => new FeatureTopology(
                    TopologyTarget::standby(),
                    AttemptPurpose::Discovery,
                    standbyGenerationFixture(),
                    TopologyTarget::standby()->network(),
                    [
                        'gateway' => TopologyTarget::standby()->instance('gateway'),
                        'app-dev' => TopologyTarget::standby()->instance('app-dev'),
                        'app-prod' => TopologyTarget::standby()->instance('app-prod'),
                    ],
                    $source,
                    $report,
                ),
            )
            ->toThrow(InvalidArgumentException::class)
            ->and(fn () => new SourceState('loose', str_repeat('b', 40)))
            ->toThrow(InvalidArgumentException::class)
            ->and(
                fn () => new VerificationReport(true, [
                    'gateway.ready' => verificationProbeFixture(false, 'gateway.ready'),
                ]),
            )
            ->toThrow(InvalidArgumentException::class);
    });
});

function standbyGenerationFixture(): StandbyGeneration
{
    return new StandbyGeneration(
        'g1',
        str_repeat('a', 40),
        [
            'gateway' => 'main-gateway',
            'app-dev' => 'main-app-dev',
            'app-prod' => 'main-app-prod',
        ],
        str_repeat('c', 64),
        str_repeat('d', 64),
        new LaravelRelease('v13.10.1', '5aad4ddf34d5e21dfe6b4c07eeac67d5bd5e08b0'),
        str_repeat('e', 64),
        1,
        'ubuntu-26.04-amd64-v1',
        'orbit-base-ubuntu-26.04-runtime',
        'gateway_app-dev_app-prod',
        ['gateway', 'app-dev', 'app-prod'],
        ['gateway', 'app-dev'],
    );
}

function topologyFixture(
    TopologyTarget $target,
    AttemptPurpose $purpose = AttemptPurpose::Discovery,
): FeatureTopology {
    $instances = [
        'gateway' => $target->instance('gateway'),
        'app-dev' => $target->instance('app-dev'),
        'app-prod' => $target->instance('app-prod'),
    ];

    return new FeatureTopology(
        $target,
        $purpose,
        standbyGenerationFixture(),
        $target->network(),
        $instances,
        new SourceState(str_repeat('b', 40), str_repeat('b', 40)),
        new VerificationReport(true, ['gateway.ready' => verificationProbeFixture(probe: 'gateway.ready')]),
    );
}
