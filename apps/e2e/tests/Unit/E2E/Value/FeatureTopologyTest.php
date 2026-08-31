<?php

declare(strict_types=1);

use App\E2E\Value\AttemptId;
use App\E2E\Value\AttemptPurpose;
use App\E2E\Value\FeatureTopology;
use App\E2E\Value\IncusInstance;
use App\E2E\Value\LaravelRelease;
use App\E2E\Value\MountPath;
use App\E2E\Value\SourceState;
use App\E2E\Value\StandbyGeneration;
use App\E2E\Value\TopologyProfile;
use App\E2E\Value\TopologyTarget;
use App\E2E\Value\VerificationReport;

function mountedTopologyFixture(bool $mounted = true, ?array $mounts = null): FeatureTopology
{
    $target = TopologyTarget::feature('NCK-321', new AttemptId(str_repeat('a', 32)));
    $mounts ??= $mounted
        ? [
            'gateway' => ['device' => 'orbit-source', 'source' => '/srv/wt', 'path' => '/home/orbit/orbit'],
            'app-dev' => ['device' => 'orbit-source', 'source' => '/srv/wt', 'path' => '/home/orbit/orbit'],
        ]
        : [];

    return new FeatureTopology(
        $target,
        AttemptPurpose::Discovery,
        new StandbyGeneration(
            'g1',
            str_repeat('a', 40),
            ['gateway' => 'main-gateway', 'app-dev' => 'main-app-dev', 'app-prod' => 'main-app-prod'],
            str_repeat('c', 64),
            str_repeat('d', 64),
            new LaravelRelease('v13.10.1', str_repeat('e', 40)),
            str_repeat('f', 64),
            2,
            'ubuntu-26.04-amd64-v1',
            'orbit-base-ubuntu-26.04-runtime',
            TopologyProfile::NAME,
            TopologyProfile::ROLES,
            TopologyProfile::CHECKOUT_ROLES,
            standbyNamespace: '',
        ),
        $target->network(),
        array_combine(TopologyProfile::ROLES, array_map($target->instance(...), TopologyProfile::ROLES)),
        new SourceState(
            str_repeat('b', 40),
            str_repeat('b', 40),
            mounted: $mounted,
            pointerHash: $mounted ? str_repeat('e', 64) : null,
        ),
        new VerificationReport(true, ['fixture' => verificationProbeFixture()]),
        $mounts,
    );
}

describe('feature topology mounts', function () {
    it('round-trips the mounts and the mounted source state', function () {
        $topology = mountedTopologyFixture();

        expect($topology->toArray()['mounts'])
            ->toBe([
                'gateway' => ['device' => 'orbit-source', 'source' => '/srv/wt', 'path' => '/home/orbit/orbit'],
                'app-dev' => ['device' => 'orbit-source', 'source' => '/srv/wt', 'path' => '/home/orbit/orbit'],
            ])
            ->and($topology->toArray()['source']['mounted'])
            ->toBeTrue()
            ->and(FeatureTopology::fromArray($topology->toArray())->toArray())
            ->toBe($topology->toArray())
            ->and(mountedTopologyFixture(false)->toArray()['mounts'])
            ->toBe([]);
    });

    it('requires one identical mount per checkout role exactly when the source is mounted', function () {
        expect(fn () => mountedTopologyFixture(true, []))
            ->toThrow(InvalidArgumentException::class, 'do not match the source state')
            ->and(fn () => mountedTopologyFixture(false, [
                'gateway' => ['device' => 'orbit-source', 'source' => '/srv/wt', 'path' => '/home/orbit/orbit'],
                'app-dev' => ['device' => 'orbit-source', 'source' => '/srv/wt', 'path' => '/home/orbit/orbit'],
            ]))
            ->toThrow(InvalidArgumentException::class, 'do not match the source state')
            ->and(fn () => mountedTopologyFixture(true, [
                'gateway' => ['device' => 'orbit-source', 'source' => '/srv/wt', 'path' => '/home/orbit/orbit'],
                'app-prod' => ['device' => 'orbit-source', 'source' => '/srv/wt', 'path' => '/home/orbit/orbit'],
            ]))
            ->toThrow(InvalidArgumentException::class, 'do not match the source state')
            ->and(fn () => mountedTopologyFixture(true, [
                'gateway' => ['device' => 'orbit-source', 'source' => '/srv/wt', 'path' => '/home/orbit/orbit'],
                'app-dev' => ['device' => 'orbit-source', 'source' => '/srv/other', 'path' => '/home/orbit/orbit'],
            ]))
            ->toThrow(InvalidArgumentException::class, 'share one source')
            ->and(fn () => mountedTopologyFixture(true, [
                'gateway' => ['device' => 'other', 'source' => '/srv/wt', 'path' => '/home/orbit/orbit'],
                'app-dev' => ['device' => 'other', 'source' => '/srv/wt', 'path' => '/home/orbit/orbit'],
            ]))
            ->toThrow(InvalidArgumentException::class, 'mount is invalid')
            ->and(fn () => mountedTopologyFixture(true, [
                'gateway' => ['device' => 'orbit-source', 'source' => 'relative', 'path' => '/home/orbit/orbit'],
                'app-dev' => ['device' => 'orbit-source', 'source' => 'relative', 'path' => '/home/orbit/orbit'],
            ]))
            ->toThrow(InvalidArgumentException::class, 'mount is invalid')
            ->and(fn () => mountedTopologyFixture(true, [
                'gateway' => ['device' => 'orbit-source', 'source' => '/srv/a,b', 'path' => '/home/orbit/orbit'],
                'app-dev' => ['device' => 'orbit-source', 'source' => '/srv/a,b', 'path' => '/home/orbit/orbit'],
            ]))
            ->toThrow(InvalidArgumentException::class, 'mount is invalid');
    });

    it('refuses a manifest without the mounts field', function () {
        $value = mountedTopologyFixture()->toArray();
        unset($value['mounts']);

        expect(fn () => FeatureTopology::fromArray($value))
            ->toThrow(InvalidArgumentException::class, 'schema is invalid');
    });

    it('names the previous harness for any other manifest schema', function (mixed $schema) {
        $value = mountedTopologyFixture()->toArray();
        $value['schema'] = $schema;
        $version = json_encode($schema, JSON_THROW_ON_ERROR);

        expect(FeatureTopology::SCHEMA)
            ->toBe(3)
            ->and(fn () => FeatureTopology::fromArray($value))
            ->toThrow(
                InvalidArgumentException::class,
                "schema {$version} is not supported; release with the previous harness",
            );
    })->with(['schema 2' => [2], 'schema 1' => [1], 'string schema' => ['3']]);
});

describe('feature topology generations', function () {
    it('round-trips an unbound schema-4 generation without changing its schema', function () {
        $value = mountedTopologyFixture(false)->toArray();
        $value['generation']['schema'] = 4;
        unset($value['generation']['standby_namespace']);
        $topology = FeatureTopology::fromArray($value);

        $serialized = $topology->toArray();
        $reloaded = FeatureTopology::fromArray($serialized);

        expect($serialized['generation'])->toBe($value['generation']);
        expect($reloaded->generation->standbyNamespace)->toBeNull();
    });
});

describe('mount paths', function () {
    it('accepts only absolute separator-free paths', function (string $path, bool $safe) {
        expect(MountPath::isSafe($path))->toBe($safe);
    })->with([
        'absolute' => ['/srv/wt', true],
        'relative' => ['srv/wt', false],
        'comma' => ['/srv/a,b', false],
        'equals' => ['/srv/a=b', false],
        'newline' => ["/srv/a\nb", false],
        'nul' => ["/srv/a\0b", false],
        'trailing slash' => ['/srv/wt/', false],
    ]);

    it('requires a mountable source to be a real directory', function () {
        $root = temporaryPath('orbit-mount-path-', 4);
        mkdir($root.'/tree', 0o700, true);
        symlink($root.'/tree', $root.'/link');
        file_put_contents($root.'/file', '');

        try {
            expect(MountPath::isMountableDirectory($root.'/tree'))
                ->toBeTrue()
                ->and(MountPath::isMountableDirectory($root.'/link'))
                ->toBeFalse()
                ->and(MountPath::isMountableDirectory($root.'/file'))
                ->toBeFalse()
                ->and(MountPath::isMountableDirectory($root.'/missing'))
                ->toBeFalse()
                ->and(MountPath::isMountableDirectory($root.'/tree/'))
                ->toBeFalse();
        } finally {
            new Illuminate\Filesystem\Filesystem()->deleteDirectory($root);
        }
    });
});

describe('source state', function () {
    it('serializes and validates the mounted flag', function () {
        $state = new SourceState(
            str_repeat('b', 40),
            str_repeat('b', 40),
            mounted: true,
            pointerHash: str_repeat('e', 64),
        );
        $value = $state->toArray();

        expect($value['mounted'])
            ->toBeTrue()
            ->and($value['git_pointer_sha256'])
            ->toBe(str_repeat('e', 64))
            ->and(SourceState::fromArray($value)->mounted)
            ->toBeTrue()
            ->and(SourceState::fromArray($value)->pointerHash)
            ->toBe(str_repeat('e', 64))
            ->and(new SourceState(str_repeat('b', 40), str_repeat('b', 40))->mounted)
            ->toBeFalse();

        $value['mounted'] = 'yes';
        expect(fn () => SourceState::fromArray($value))
            ->toThrow(InvalidArgumentException::class, 'schema is invalid');
        unset($value['mounted']);
        expect(fn () => SourceState::fromArray($value))
            ->toThrow(InvalidArgumentException::class, 'schema is invalid');
    });

    it('binds the git pointer hash to the mounted flag', function () {
        $value = new SourceState(
            str_repeat('b', 40),
            str_repeat('b', 40),
            mounted: true,
            pointerHash: str_repeat('e', 64),
        )->toArray();
        unset($value['git_pointer_sha256']);

        expect(fn () => new SourceState(str_repeat('b', 40), str_repeat('b', 40), mounted: true))
            ->toThrow(InvalidArgumentException::class, 'exactly one git pointer hash')
            ->and(fn () => new SourceState(str_repeat('b', 40), str_repeat('b', 40), pointerHash: str_repeat('e', 64)))
            ->toThrow(InvalidArgumentException::class, 'exactly one git pointer hash')
            ->and(fn () => new SourceState(
                str_repeat('b', 40),
                str_repeat('b', 40),
                mounted: true,
                pointerHash: 'short',
            ))
            ->toThrow(InvalidArgumentException::class, 'git pointer hash is invalid')
            ->and(fn () => SourceState::fromArray($value))
            ->toThrow(InvalidArgumentException::class, 'schema is invalid');
    });
});

describe('incus instance disks', function () {
    it('keeps validated non-root disk devices', function () {
        $instance = new IncusInstance('local', 'default', 'vm', 'default', disks: [
            'orbit-source' => ['source' => '/srv/wt', 'path' => '/home/orbit/orbit'],
        ]);

        expect($instance->disk('orbit-source'))
            ->toBe(['source' => '/srv/wt', 'path' => '/home/orbit/orbit'])
            ->and(fn () => new IncusInstance('local', 'default', 'vm', 'default', disks: [
                'root' => ['source' => '/srv/wt', 'path' => '/home/orbit/orbit'],
            ]))
            ->toThrow(InvalidArgumentException::class, 'disk device')
            ->and(fn () => new IncusInstance('local', 'default', 'vm', 'default', disks: [
                'orbit-source' => ['source' => 'relative', 'path' => '/home/orbit/orbit'],
            ]))
            ->toThrow(InvalidArgumentException::class, 'disk device');
    });
});
