<?php

declare(strict_types=1);

use App\E2E\State\AtomicJsonStore;
use App\E2E\State\StatePaths;
use App\E2E\TopologyManifestStore;
use App\E2E\Value\FeatureTopology;
use App\E2E\Value\SourceState;
use App\E2E\Value\StandbyGeneration;
use App\E2E\Value\TopologyTarget;
use App\E2E\Value\VerificationReport;

describe('TopologyManifestStore', function () {
    it('accepts and returns only a fully typed topology manifest', function () {
        $paths = new StatePaths(sys_get_temp_dir().'/orbit-topology-'.bin2hex(random_bytes(4)));
        $store = new TopologyManifestStore(new AtomicJsonStore($paths));
        $target = new TopologyTarget('NCK-321');
        $topology = topologyFixture($target);
        $store->write($topology);

        expect($store->read($target))->toEqual($topology);
    });

    it('rejects duplicate or loose resources and exact source and report schema errors', function () {
        $target = new TopologyTarget('NCK-321');
        $generation = new StandbyGeneration(
            'g1',
            str_repeat('a', 40),
            [
                'gateway' => 'main-gateway',
                'app-dev' => 'main-app-dev',
                'app-prod' => 'main-app-prod',
            ],
            str_repeat('c', 64),
            str_repeat('d', 64),
        );
        $source = new SourceState(str_repeat('b', 40), str_repeat('b', 40));
        $report = new VerificationReport(true, ['gateway.ready' => true]);

        expect(
            fn () => new FeatureTopology(
                $target,
                $generation,
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
            ->and(fn () => new SourceState('loose', str_repeat('b', 40)))
            ->toThrow(InvalidArgumentException::class)
            ->and(fn () => new VerificationReport(true, ['gateway.ready' => false]))
            ->toThrow(InvalidArgumentException::class);
    });
});

function topologyFixture(TopologyTarget $target): FeatureTopology
{
    $generation = new StandbyGeneration(
        'g1',
        str_repeat('a', 40),
        [
            'gateway' => 'main-gateway',
            'app-dev' => 'main-app-dev',
            'app-prod' => 'main-app-prod',
        ],
        str_repeat('c', 64),
        str_repeat('d', 64),
    );
    $instances = [
        'gateway' => $target->instance('gateway'),
        'app-dev' => $target->instance('app-dev'),
        'app-prod' => $target->instance('app-prod'),
    ];

    return new FeatureTopology(
        $target,
        $generation,
        $target->network(),
        $instances,
        new SourceState(str_repeat('b', 40), str_repeat('b', 40)),
        new VerificationReport(true, ['gateway.ready' => true]),
    );
}
