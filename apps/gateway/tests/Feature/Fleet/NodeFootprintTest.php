<?php

declare(strict_types=1);

use App\Domain\Fleet\NodeFootprint;
use App\Domain\Nodes\RoleName;
use App\Domain\Shared\ResourceOperationException;
use App\Models\Instance;
use App\Models\NodeFootprint as NodeFootprintRecord;
use Tests\Support\Fleet\FakeFootprintArtifact;
use Tests\Support\Fleet\FleetFixtures;

describe('Node footprint', function (): void {
    it('applies every artifact the first time and records their digests', function (): void {
        $caddy = new FakeFootprintArtifact('caddy', 'c1');
        $agent = new FakeFootprintArtifact('agent', 'a1', changes: null);
        $proxy = new FakeFootprintArtifact('proxycli', 'p1', applies: false);
        $node = FleetFixtures::node('dev', [RoleName::AppDev]);

        $result = new NodeFootprint([$caddy, $agent, $proxy])->converge($node);

        expect($result->artifacts)->toBe(['agent' => 'applied', 'caddy' => 'applied'])
            ->and($result->changed())->toBeTrue()
            ->and($proxy->applied)->toBe([])
            ->and(NodeFootprintRecord::query()->sole()->artifacts)->toBe(['agent' => 'a1', 'caddy' => 'c1'])
            ->and(NodeFootprintRecord::query()->sole()->digest)->toBe(new NodeFootprint([$caddy, $agent])->expected($node)->digest());
    });

    it('makes no call to the Node when every digest matches', function (): void {
        $caddy = new FakeFootprintArtifact('caddy', 'c1');
        $agent = new FakeFootprintArtifact('agent', 'a1');
        $node = FleetFixtures::node('dev', [RoleName::AppDev]);
        $footprint = new NodeFootprint([$caddy, $agent]);
        $footprint->converge($node);
        $caddy->applied = $agent->applied = [];

        $result = $footprint->converge($node);

        expect($result->artifacts)->toBe(['agent' => 'unchanged', 'caddy' => 'unchanged'])
            ->and($result->changed())->toBeFalse()
            ->and($caddy->applied)->toBe([])
            ->and($agent->applied)->toBe([])
            ->and($footprint->drifted($node))->toBeFalse();
    });

    it('re-applies only the artifact whose digest changed', function (): void {
        $caddy = new FakeFootprintArtifact('caddy', 'c1');
        $agent = new FakeFootprintArtifact('agent', 'a1');
        $node = FleetFixtures::node('dev', [RoleName::AppDev]);
        $footprint = new NodeFootprint([$caddy, $agent]);
        $footprint->converge($node);
        $caddy->applied = $agent->applied = [];
        $caddy->digest = 'c2';

        expect($footprint->drifted($node))->toBeTrue();

        $result = $footprint->converge($node);

        expect($result->artifacts)->toBe(['agent' => 'unchanged', 'caddy' => 'applied'])
            ->and($caddy->applied)->toBe(['dev'])
            ->and($agent->applied)->toBe([]);
    });

    it('reports an artifact that found the live copy current as unchanged', function (): void {
        $caddy = new FakeFootprintArtifact('caddy', 'c1', changes: false);

        expect(new NodeFootprint([$caddy])->converge(FleetFixtures::node('dev', [RoleName::AppDev]))->changed())->toBeFalse();
    });

    it('re-applies every artifact when forced', function (): void {
        $caddy = new FakeFootprintArtifact('caddy', 'c1');
        $node = FleetFixtures::node('dev', [RoleName::AppDev]);
        $footprint = new NodeFootprint([$caddy]);
        $footprint->converge($node);

        $footprint->converge($node, force: true);

        expect($caddy->applied)->toBe(['dev', 'dev']);
    });

    it('names the failed artifact and applies it again next time', function (): void {
        $caddy = new FakeFootprintArtifact('caddy', 'c1');
        $agent = new FakeFootprintArtifact('agent', 'a1', fails: true);
        $node = FleetFixtures::node('dev', [RoleName::AppDev]);
        $footprint = new NodeFootprint([$caddy, $agent]);

        expect(fn () => $footprint->converge($node))->toThrow(function (ResourceOperationException $exception): void {
            expect($exception->errorCode)->toBe('fake.artifact_failed')
                ->and($exception->details['artifact'])->toBe('agent');
        });

        $agent->fails = false;
        $agent->applied = [];
        $footprint->converge($node);

        expect($agent->applied)->toBe(['dev']);
    });

    it('never changes an Instance', function (): void {
        $node = FleetFixtures::node('dev', [RoleName::AppDev]);
        $before = Instance::query()->count();

        new NodeFootprint([new FakeFootprintArtifact('caddy', 'c1')])->converge($node);

        expect(Instance::query()->count())->toBe($before);
    });
});
