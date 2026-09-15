<?php

declare(strict_types=1);

use App\Domain\AppInstances\Dependencies\DependencyEcosystem;
use App\Domain\AppInstances\Dependencies\DependencyGraph;
use App\Domain\AppInstances\Dependencies\DependencyIdentity;
use App\Domain\AppInstances\Dependencies\DependencyRequirement;
use App\Domain\AppInstances\Dependencies\DependencyRequirementKind;
use App\Domain\AppInstances\Dependencies\DependencyResolution;
use App\Domain\AppInstances\Dependencies\DependencyScope;

describe('dependency graphs', function (): void {
    it('preserves multiple resolutions of a shared identity and overlapping paths and scopes', function (): void {
        $package = new DependencyIdentity(DependencyEcosystem::Npm, '@sample/shared');
        $rootCopy = new DependencyResolution('node_modules/@sample/shared', $package, '1.2.3', true, true);
        $nestedCopy = new DependencyResolution('node_modules/tool/node_modules/@sample/shared', $package, '2.0.0', false, true);
        $peerCopy = new DependencyResolution('@sample/shared@2.0.0(peer@3)', $package, '2.0.0', false, true);
        $tool = new DependencyResolution('node_modules/tool', new DependencyIdentity(DependencyEcosystem::Npm, 'tool'), '3.0.0', false, true);

        $graph = new DependencyGraph(DependencyEcosystem::Npm, [$rootCopy, $nestedCopy, $peerCopy, $tool], [
            new DependencyRequirement(null, $rootCopy->id, '@sample/shared', '^1', DependencyRequirementKind::Dependency, DependencyScope::Regular),
            new DependencyRequirement(null, $tool->id, 'tool', '^3', DependencyRequirementKind::Dependency, DependencyScope::Development),
            new DependencyRequirement($tool->id, $rootCopy->id, '@sample/shared', '^1', DependencyRequirementKind::Dependency, DependencyScope::Regular),
            new DependencyRequirement($tool->id, $nestedCopy->id, 'alias', 'npm:@sample/shared@^2', DependencyRequirementKind::Dependency, DependencyScope::Regular),
            new DependencyRequirement($tool->id, $peerCopy->id, '@sample/shared', '^2', DependencyRequirementKind::Peer, DependencyScope::Regular),
            new DependencyRequirement($tool->id, null, 'optional-peer', '^4', DependencyRequirementKind::Peer, DependencyScope::Regular, true),
        ]);

        expect($graph->resolutions)->toHaveCount(4);
        expect($graph->resolutions[0]->package)->toBe($graph->resolutions[1]->package);
        expect($graph->resolutions[1]->version)->toBe($graph->resolutions[2]->version);
        expect($graph->resolutions[0]->regular)->toBeTrue();
        expect($graph->resolutions[0]->development)->toBeTrue();
        expect($graph->resolutions[1]->regular)->toBeFalse();
        expect($graph->requirements[0]->from)->toBeNull();
        expect($graph->requirements[2]->from)->toBe('node_modules/tool');
        expect($graph->requirements[3]->name)->toBe('alias');
        expect($graph->requirements[3]->constraint)->toBe('npm:@sample/shared@^2');
        expect($graph->requirements[4]->kind)->toBe(DependencyRequirementKind::Peer);
        expect($graph->requirements[5]->to)->toBeNull();
        expect($graph->requirements[5]->optional)->toBeTrue();
    });

    it('preserves Composer branch versions and source provenance without semver conversion', function (): void {
        $package = new DependencyIdentity(DependencyEcosystem::Composer, 'sample/shared');
        $resolution = new DependencyResolution('sample/shared@dev-main#abc123', $package, 'dev-main', true, false, 'abc123', 'sha256-example');

        $graph = new DependencyGraph(DependencyEcosystem::Composer, [$resolution], [
            new DependencyRequirement(null, $resolution->id, 'sample/shared', 'dev-main as 1.0.x-dev', DependencyRequirementKind::Dependency, DependencyScope::Regular),
            new DependencyRequirement($resolution->id, null, 'php', '^8.5', DependencyRequirementKind::Dependency, DependencyScope::Regular),
            new DependencyRequirement($resolution->id, null, 'psr/log-implementation', '^3', DependencyRequirementKind::Dependency, DependencyScope::Regular),
        ]);

        expect($graph->resolutions[0]->version)->toBe('dev-main');
        expect($graph->resolutions[0]->sourceReference)->toBe('abc123');
        expect($graph->resolutions[0]->integrity)->toBe('sha256-example');
        expect($graph->requirements[0]->constraint)->toBe('dev-main as 1.0.x-dev');
        expect($graph->requirements[1]->to)->toBeNull();
        expect($graph->requirements[2]->to)->toBeNull();
        expect($package)->not->toEqual(new DependencyIdentity(DependencyEcosystem::Npm, 'sample/shared'));
    });

    it('rejects duplicate or empty resolution locators', function (string $secondId): void {
        $package = new DependencyIdentity(DependencyEcosystem::Npm, 'sample');

        expect(fn () => new DependencyGraph(DependencyEcosystem::Npm, [
            new DependencyResolution('sample@1', $package, '1', true, false),
            new DependencyResolution($secondId, $package, '2', true, false),
        ], []))->toThrow(InvalidArgumentException::class, 'Resolution IDs must be nonempty and unique');
    })->with(['duplicate' => 'sample@1', 'empty' => '']);

    it('rejects dangling endpoints', function (?string $from, ?string $to): void {
        expect(fn () => new DependencyGraph(DependencyEcosystem::Npm, [], [
            new DependencyRequirement($from, $to, 'sample', '*', DependencyRequirementKind::Dependency, DependencyScope::Regular),
        ]))->toThrow(InvalidArgumentException::class, 'Requirement endpoints must reference graph resolutions');
    })->with(['source' => ['missing', null], 'target' => [null, 'missing']]);

    it('rejects resolutions from another ecosystem', function (): void {
        expect(fn () => new DependencyGraph(DependencyEcosystem::Composer, [
            new DependencyResolution('sample', new DependencyIdentity(DependencyEcosystem::Npm, 'sample'), '1', true, false),
        ], []))->toThrow(InvalidArgumentException::class, 'A resolution must belong to the graph ecosystem');
    });
});
