<?php

declare(strict_types=1);

use App\Actions\AppInstances\Dependencies\ReadPnpmDependencyGraphAction;
use App\Domain\AppInstances\Dependencies\DependencyEcosystem;
use App\Domain\AppInstances\Dependencies\DependencyParseException;
use App\Domain\AppInstances\Dependencies\DependencyRequirement;
use App\Domain\AppInstances\Dependencies\DependencyRequirementKind;
use App\Domain\AppInstances\Dependencies\DependencyResolution;
use App\Domain\AppInstances\Dependencies\DependencyScope;
use Symfony\Component\Yaml\Yaml;

/** @return array{string, string} */
function pnpmReaderFixture(): array
{
    $directory = dirname(__DIR__, 4).'/Fixtures/Dependencies/Pnpm/';

    return [file_get_contents($directory.'manifest.json'), file_get_contents($directory.'pnpm-lock.yaml')];
}

/** @return array{stdClass, stdClass} */
function pnpmReaderRecords(): array
{
    [$manifest, $lock] = pnpmReaderFixture();

    return [json_decode($manifest, flags: JSON_THROW_ON_ERROR), Yaml::parse($lock, Yaml::PARSE_OBJECT_FOR_MAP)];
}

describe('pnpm v9 dependency graph reader', function (): void {
    it('rejects excluded Yarn lockfiles instead of returning an empty inventory', function (string $lock, string $errorCode): void {
        expect(fn () => (new ReadPnpmDependencyGraphAction)->execute('{"private":true}', $lock))
            ->toThrow(DependencyParseException::class, $errorCode);
    })->with([
        'Classic empty lock' => ["# yarn lockfile v1\n", 'dependencies.invalid_pnpm_input'],
        'Classic package record' => ["# yarn lockfile v1\none@^1:\n  version \"1.0.0\"\n", 'dependencies.unsupported_format'],
        'modern v4' => ["__metadata:\n  version: 4\n", 'dependencies.unsupported_format'],
        'modern v6' => ["__metadata:\n  version: 6\n", 'dependencies.unsupported_format'],
        'modern v8 root only' => ["__metadata:\n  version: 8\n\"root@workspace:.\":\n  version: 0.0.0-use.local\n  resolution: \"root@workspace:.\"\n  linkType: soft\n", 'dependencies.unsupported_format'],
        'modern metadata as JSON' => ['{"__metadata":{"version":8}}', 'dependencies.unsupported_format'],
    ]);

    it('preserves peer contexts and versions with independent root reachability through cycles', function (): void {
        [$manifest, $lock] = pnpmReaderFixture();

        $graph = (new ReadPnpmDependencyGraphAction)->execute($manifest, $lock);

        expect($graph->ecosystem)->toBe(DependencyEcosystem::Npm);
        expect(array_map(fn (DependencyResolution $resolution): array => [
            $resolution->id, $resolution->package->name, $resolution->version, $resolution->regular, $resolution->development,
        ], $graph->resolutions))->toBe([
            ['@sample/core@3.0.0', '@sample/core', '3.0.0', true, true],
            ['app-one@1.0.0', 'app-one', '1.0.0', true, true],
            ['app-two@1.0.0', 'app-two', '1.0.0', true, false],
            ['host@1.0.0', 'host', '1.0.0', true, true],
            ['host@2.0.0', 'host', '2.0.0', true, false],
            ['native@1.0.0', 'native', '1.0.0', true, true],
            ['plugin@1.0.0(host@1.0.0)', 'plugin', '1.0.0', true, true],
            ['plugin@1.0.0(host@2.0.0)', 'plugin', '1.0.0', true, false],
            ['tool@1.0.0', 'tool', '1.0.0', false, true],
        ]);
        expect($graph->requirements)->toHaveCount(17);
        expect($graph->requirements)->toContainEqual(
            new DependencyRequirement('app-one@1.0.0', 'plugin@1.0.0(host@1.0.0)', 'plugin', '1.0.0(host@1.0.0)', DependencyRequirementKind::Dependency, DependencyScope::Regular),
            new DependencyRequirement('app-two@1.0.0', 'plugin@1.0.0(host@2.0.0)', 'plugin', '1.0.0(host@2.0.0)', DependencyRequirementKind::Dependency, DependencyScope::Regular),
            new DependencyRequirement('plugin@1.0.0(host@1.0.0)', 'host@1.0.0', 'host', '>=1', DependencyRequirementKind::Peer, DependencyScope::Regular),
            new DependencyRequirement('plugin@1.0.0(host@2.0.0)', 'host@2.0.0', 'host', '>=1', DependencyRequirementKind::Peer, DependencyScope::Regular),
        );
    });

    it('preserves aliases and optional development overlap with unresolved peers', function (): void {
        [$manifest, $lock] = pnpmReaderFixture();

        $graph = (new ReadPnpmDependencyGraphAction)->execute($manifest, $lock);

        expect($graph->requirements)->toContainEqual(
            new DependencyRequirement(null, '@sample/core@3.0.0', 'renamed', 'npm:@sample/core@^3', DependencyRequirementKind::Dependency, DependencyScope::Regular),
            new DependencyRequirement('app-one@1.0.0', '@sample/core@3.0.0', 'renamed-transitive', '@sample/core@3.0.0', DependencyRequirementKind::Dependency, DependencyScope::Regular),
            new DependencyRequirement(null, 'native@1.0.0', 'native', '^1', DependencyRequirementKind::Dependency, DependencyScope::Regular, true),
            new DependencyRequirement(null, 'native@1.0.0', 'native', '^1', DependencyRequirementKind::Dependency, DependencyScope::Development),
            new DependencyRequirement('plugin@1.0.0(host@1.0.0)', 'native@1.0.0', 'native', '1.0.0', DependencyRequirementKind::Dependency, DependencyScope::Regular, true),
            new DependencyRequirement(null, null, 'root-peer', '^9', DependencyRequirementKind::Peer, DependencyScope::Regular),
            new DependencyRequirement('plugin@1.0.0(host@1.0.0)', null, 'missing-peer', '*', DependencyRequirementKind::Peer, DependencyScope::Regular, true),
        );
        expect(array_column($graph->requirements, 'constraint'))->not->toContain('^0');
    });

    it('returns deterministic graphs and omits executable metadata and download credentials', function (): void {
        [$manifest, $lock] = pnpmReaderFixture();
        [$root, $reordered] = pnpmReaderRecords();
        $root->dependencies = (object) array_reverse(get_object_vars($root->dependencies), true);
        $reordered->packages = (object) array_reverse(get_object_vars($reordered->packages), true);
        $reordered->snapshots = (object) array_reverse(get_object_vars($reordered->snapshots), true);
        $reader = new ReadPnpmDependencyGraphAction;

        $graph = $reader->execute($manifest, $lock);
        $again = $reader->execute(json_encode($root, JSON_THROW_ON_ERROR), json_encode($reordered, JSON_THROW_ON_ERROR));

        expect($again)->toEqual($graph);
        expect($graph->resolutions[0]->integrity)->toBe('sha512-YWJjZA==');
        expect(array_column($graph->resolutions, 'sourceReference'))->toBe(array_fill(0, 9, null));
        expect(json_encode($graph, JSON_THROW_ON_ERROR))->not->toContain('fixture-user', 'fixture-secret', '127.0.0.1', 'must-never-execute', 'hasBin');
    });

    it('accepts empty projects with omitted or empty package maps', function (string $lock): void {
        $graph = (new ReadPnpmDependencyGraphAction)->execute('{"private":true}', $lock);

        expect($graph->resolutions)->toBe([]);
        expect($graph->requirements)->toBe([]);
    })->with([
        "lockfileVersion: '9.0'\nimporters:\n  .: {}\n",
        "lockfileVersion: 9.0\nimporters: {.: {}}\npackages: {}\nsnapshots: {}\n",
    ]);

    it('preserves nested scoped peer suffixes and patch hashes', function (): void {
        $id = 'plugin@1.0.0(@scope/host@2.0.0(other@3.0.0))(patch_hash=abc123)';
        $manifest = '{"dependencies":{"plugin":"*"}}';
        $lock = json_encode([
            'lockfileVersion' => '9.0',
            'importers' => ['.' => ['dependencies' => ['plugin' => ['specifier' => '*', 'version' => substr($id, 7)]]]],
            'packages' => ['plugin@1.0.0' => (object) []],
            'snapshots' => [$id => (object) []],
        ], JSON_THROW_ON_ERROR);

        $graph = (new ReadPnpmDependencyGraphAction)->execute($manifest, $lock);

        expect($graph->resolutions[0]->id)->toBe($id);
        expect($graph->requirements[0]->to)->toBe($id);
        expect($graph->resolutions[0]->version)->toBe('1.0.0');
    });

    it('retains root peer relationships when pnpm automatically installs peers', function (): void {
        $manifest = '{"peerDependencies":{"host":"^1"},"peerDependenciesMeta":{"host":{"optional":true}}}';
        $lock = '{"lockfileVersion":"9.0","importers":{".":{"dependencies":{"host":{"specifier":"^1","version":"1.0.0"}}}},"packages":{"host@1.0.0":{}},"snapshots":{"host@1.0.0":{}}}';

        $graph = (new ReadPnpmDependencyGraphAction)->execute($manifest, $lock);

        expect($graph->requirements)->toEqual([
            new DependencyRequirement(null, 'host@1.0.0', 'host', '^1', DependencyRequirementKind::Peer, DependencyScope::Regular, true),
        ]);
        expect($graph->resolutions[0]->regular)->toBeTrue();
        expect($graph->resolutions[0]->development)->toBeFalse();
    });

    it('preserves mixed-case and numeric package names and opaque versions', function (): void {
        $manifest = '{"dependencies":{"JSONStream":"*","123":"*"}}';
        $lock = '{"lockfileVersion":"9.0","importers":{".":{"dependencies":{"JSONStream":{"specifier":"*","version":"release-opaque"},"123":{"specifier":"*","version":"1.0.0"}}}},"packages":{"JSONStream@release-opaque":{},"123@1.0.0":{}},"snapshots":{"JSONStream@release-opaque":{},"123@1.0.0":{}}}';

        $graph = (new ReadPnpmDependencyGraphAction)->execute($manifest, $lock);

        expect(array_column($graph->resolutions, 'id'))->toBe(['123@1.0.0', 'JSONStream@release-opaque']);
        expect(array_column($graph->requirements, 'name'))->toBe(['123', 'JSONStream']);
    });

    it('reads remote tarball locators with their metadata version and safe graph references', function (string $url): void {
        $manifest = json_encode(['dependencies' => ['is-number' => $url]], JSON_THROW_ON_ERROR);
        $id = 'is-number@'.$url;
        $lock = json_encode([
            'lockfileVersion' => '9.0',
            'importers' => ['.' => ['dependencies' => ['is-number' => ['specifier' => $url, 'version' => $url]]]],
            'packages' => [$id => ['version' => '7.0.0', 'resolution' => ['tarball' => $url, 'integrity' => 'sha512-YWJjZA==']]],
            'snapshots' => [$id => (object) []],
        ], JSON_THROW_ON_ERROR);

        $graph = (new ReadPnpmDependencyGraphAction)->execute($manifest, $lock);

        expect($graph->resolutions)->toHaveCount(1);
        expect($graph->resolutions[0]->package->name)->toBe('is-number');
        expect($graph->resolutions[0]->version)->toBe('7.0.0');
        expect($graph->resolutions[0]->integrity)->toBe('sha512-YWJjZA==');
        expect($graph->resolutions[0]->regular)->toBeTrue();
        expect($graph->resolutions[0]->development)->toBeFalse();
        expect($graph->requirements)->toEqual([
            new DependencyRequirement(null, $graph->resolutions[0]->id, 'is-number', 'pnpm:sha256:'.hash('sha256', $url), DependencyRequirementKind::Dependency, DependencyScope::Regular),
        ]);
        expect(json_encode($graph, JSON_THROW_ON_ERROR))->not->toContain($url, 'https:', 'http:', 'registry.npmjs.org', 'fixture-secret', 'fixture-user', 'access_token');
    })->with([
        'review reproduction' => 'https://registry.npmjs.org/is-number/-/is-number-7.0.0.tgz',
        'http tarball' => 'http://example.test/is-number.tgz',
        'authenticated tarball' => 'https://fixture-user:fixture-secret@example.test/is-number.tgz',
        'signed tarball' => 'https://example.test/is-number.tgz?access_token=fixture-secret',
    ]);

    it('keeps remote sources and peer contexts distinct through aliases and transitive links', function (): void {
        $url = 'https://example.test/plugin.tgz';
        $otherUrl = 'https://example.test/another-plugin.tgz';
        $base = '@sample/plugin@'.$url;
        $one = $base.'(host@1.0.0)';
        $two = $base.'(host@2.0.0)';
        $other = '@sample/plugin@'.$otherUrl;
        $manifest = json_encode(['dependencies' => ['renamed' => $url], 'devDependencies' => ['tool' => '*'], 'optionalDependencies' => ['another' => $otherUrl]], JSON_THROW_ON_ERROR);
        $lock = (object) [
            'lockfileVersion' => '9.0',
            'importers' => ['.' => [
                'dependencies' => ['renamed' => ['specifier' => $url, 'version' => $one]],
                'devDependencies' => ['tool' => ['specifier' => '*', 'version' => '1.0.0']],
                'optionalDependencies' => ['another' => ['specifier' => $otherUrl, 'version' => $other]],
            ]],
            'packages' => (object) [
                $base => ['version' => '3.0.0', 'peerDependencies' => ['host' => '*']],
                $other => ['version' => '3.0.0'],
                'host@1.0.0' => (object) [], 'host@2.0.0' => (object) [], 'tool@1.0.0' => (object) [],
            ],
            'snapshots' => (object) [
                $one => ['dependencies' => ['host' => '1.0.0']],
                $two => ['dependencies' => ['host' => '2.0.0']],
                $other => (object) [],
                'host@1.0.0' => (object) [], 'host@2.0.0' => (object) [],
                'tool@1.0.0' => ['dependencies' => ['aliased-plugin' => $two]],
            ],
        ];
        $reader = new ReadPnpmDependencyGraphAction;

        $graph = $reader->execute($manifest, json_encode($lock, JSON_THROW_ON_ERROR));
        $lock->packages = (object) array_reverse(get_object_vars($lock->packages), true);
        $lock->snapshots = (object) array_reverse(get_object_vars($lock->snapshots), true);
        $reordered = $reader->execute($manifest, json_encode($lock, JSON_THROW_ON_ERROR));

        expect($reordered)->toEqual($graph);
        $plugins = array_values(array_filter($graph->resolutions, fn (DependencyResolution $resolution): bool => $resolution->package->name === '@sample/plugin'));
        expect($plugins)->toHaveCount(3);
        expect(array_unique(array_column($plugins, 'id')))->toHaveCount(3);
        expect(array_column($plugins, 'version'))->toBe(['3.0.0', '3.0.0', '3.0.0']);
        expect($graph->requirements)->toContainEqual(
            new DependencyRequirement(null, 'pnpm:sha256:'.hash('sha256', $one), 'renamed', 'pnpm:sha256:'.hash('sha256', $url), DependencyRequirementKind::Dependency, DependencyScope::Regular),
            new DependencyRequirement('tool@1.0.0', 'pnpm:sha256:'.hash('sha256', $two), 'aliased-plugin', 'pnpm:sha256:'.hash('sha256', $two), DependencyRequirementKind::Dependency, DependencyScope::Regular),
            new DependencyRequirement('pnpm:sha256:'.hash('sha256', $one), 'host@1.0.0', 'host', '*', DependencyRequirementKind::Peer, DependencyScope::Regular),
            new DependencyRequirement('pnpm:sha256:'.hash('sha256', $two), 'host@2.0.0', 'host', '*', DependencyRequirementKind::Peer, DependencyScope::Regular),
        );
        expect(json_encode($graph, JSON_THROW_ON_ERROR))->not->toContain('https:', 'example.test', '.tgz');
    });

    it('keeps registry snapshots with remote peers linked without retaining peer URLs', function (): void {
        $url = 'https://fixture-user:fixture-secret@example.test/host.tgz?key=fixture-secret';
        $plugin = 'plugin@1.0.0(host@'.$url.')';
        $host = 'host@'.$url;
        $manifest = '{"dependencies":{"plugin":"^1"}}';
        $lock = json_encode([
            'lockfileVersion' => '9.0',
            'importers' => ['.' => ['dependencies' => ['plugin' => ['specifier' => '^1', 'version' => substr($plugin, 7)]]]],
            'packages' => ['plugin@1.0.0' => ['peerDependencies' => ['host' => '^2']], $host => ['version' => '2.0.0']],
            'snapshots' => [$plugin => ['dependencies' => ['host' => $url]], $host => (object) []],
        ], JSON_THROW_ON_ERROR);

        $graph = (new ReadPnpmDependencyGraphAction)->execute($manifest, $lock);

        expect($graph->requirements)->toContainEqual(
            new DependencyRequirement(null, 'pnpm:sha256:'.hash('sha256', $plugin), 'plugin', '^1', DependencyRequirementKind::Dependency, DependencyScope::Regular),
            new DependencyRequirement('pnpm:sha256:'.hash('sha256', $plugin), 'pnpm:sha256:'.hash('sha256', $host), 'host', '^2', DependencyRequirementKind::Peer, DependencyScope::Regular),
        );
        expect(array_column($graph->resolutions, 'version'))->toBe(['2.0.0', '1.0.0']);
        expect(json_encode($graph, JSON_THROW_ON_ERROR))->not->toContain('https:', 'fixture-user', 'fixture-secret', 'example.test', '.tgz');
    });

    it('rejects malformed remote URLs without returning source text', function (string $url): void {
        $manifest = json_encode(['dependencies' => ['one' => $url]], JSON_THROW_ON_ERROR);
        $lock = json_encode([
            'lockfileVersion' => '9.0',
            'importers' => ['.' => ['dependencies' => ['one' => ['specifier' => $url, 'version' => $url]]]],
            'packages' => ['one@'.$url => ['version' => '1.0.0']],
            'snapshots' => ['one@'.$url => (object) []],
        ], JSON_THROW_ON_ERROR);

        try {
            (new ReadPnpmDependencyGraphAction)->execute($manifest, $lock);
            test()->fail('Invalid input was accepted.');
        } catch (DependencyParseException $exception) {
            expect($exception->getMessage())->toBe('dependencies.invalid_pnpm_input');
            expect($exception->getPrevious())->toBeNull();
        }
    })->with(['https:///one.tgz', 'https://example.test:invalid/one.tgz', 'https://example.test/one two.tgz', "https://example.test/one\ntwo.tgz", 'https://example.test/one\\two.tgz']);

    it('keeps ordinary peer-context guards when adding remote locator support', function (string $suffix): void {
        $id = 'plugin@1.0.0'.$suffix;
        $lock = json_encode([
            'lockfileVersion' => '9.0',
            'importers' => ['.' => ['dependencies' => ['plugin' => ['specifier' => '*', 'version' => '1.0.0'.$suffix]]]],
            'packages' => ['plugin@1.0.0' => (object) []],
            'snapshots' => [$id => (object) []],
        ], JSON_THROW_ON_ERROR);

        expect(fn () => (new ReadPnpmDependencyGraphAction)->execute('{"dependencies":{"plugin":"*"}}', $lock))
            ->toThrow(DependencyParseException::class, 'dependencies.invalid_pnpm_input');
    })->with(['(peer@1.0.0?token=fixture-secret)', '(peer@fixture-user:fixture-secret)']);

    it('rejects remote locators without a valid metadata version', function (string $metadata): void {
        $url = 'https://example.test/one.tgz';
        $manifest = json_encode(['dependencies' => ['one' => $url]], JSON_THROW_ON_ERROR);
        $lock = json_encode([
            'lockfileVersion' => '9.0',
            'importers' => ['.' => ['dependencies' => ['one' => ['specifier' => $url, 'version' => $url]]]],
            'packages' => ['one@'.$url => json_decode($metadata, flags: JSON_THROW_ON_ERROR)],
            'snapshots' => ['one@'.$url => (object) []],
        ], JSON_THROW_ON_ERROR);

        expect(fn () => (new ReadPnpmDependencyGraphAction)->execute($manifest, $lock))
            ->toThrow(DependencyParseException::class, 'dependencies.invalid_pnpm_input');
    })->with(['{}', '{"version":null}', '{"version":""}', '{"version":7}', '{"version":"https://fixture-secret@example.test"}', '{"version":"7.0.0\\n"}']);

    it('rejects unsupported versions visibly', function (string $version): void {
        expect(fn () => (new ReadPnpmDependencyGraphAction)->execute('{}', 'lockfileVersion: '.$version."\nimporters: {.: {}}\n"))
            ->toThrow(DependencyParseException::class, 'dependencies.unsupported_format');
    })->with(["'6.0'", "'9.1'", "'10.0'", '9', 'null']);

    it('rejects malformed serialization without including source text in errors', function (string $manifest, string $lock): void {
        try {
            (new ReadPnpmDependencyGraphAction)->execute($manifest, $lock);
            test()->fail('Invalid input was accepted.');
        } catch (DependencyParseException $exception) {
            expect($exception->errorCode)->toBe('dependencies.invalid_pnpm_input');
            expect($exception->getMessage())->toBe('dependencies.invalid_pnpm_input');
            expect($exception->getPrevious())->toBeNull();
        }
    })->with([
        'invalid JSON' => ['{', '{}'],
        'manifest list' => ['[]', '{}'],
        'escaped duplicate JSON key' => ['{"dependencies":{},"\u0064ependencies":{}}', '{}'],
        'YAML syntax' => ['{}', "lockfileVersion: '9.0'\nimporters: [fixture-secret"],
        'YAML list' => ['{}', '- fixture-secret'],
        'empty YAML' => ['{}', ''],
        'duplicate YAML key' => ['{}', "lockfileVersion: '9.0'\nlockfileVersion: '9.0'\nimporters: {.: {}}"],
        'duplicate flow key' => ['{}', "{lockfileVersion: '9.0', importers: {.: {}, .: {}}}"],
        'object tag' => ['{}', "lockfileVersion: '9.0'\nimporters: !php/object fixture-secret"],
        'constant tag' => ['{}', "lockfileVersion: '9.0'\nimporters: !php/const PHP_VERSION"],
        'custom tag' => ['{}', "lockfileVersion: '9.0'\nimporters: !custom fixture-secret"],
        'multiple YAML documents' => ['{}', "lockfileVersion: '9.0'\nimporters: {.: {}}\n---\nfixture-secret"],
    ]);

    it('reports an importer that no longer matches package.json as stale', function (Closure $mutate): void {
        [$manifest, $lock] = pnpmReaderRecords();
        $mutate($manifest, $lock);

        try {
            (new ReadPnpmDependencyGraphAction)->execute(json_encode($manifest, JSON_THROW_ON_ERROR), json_encode($lock, JSON_THROW_ON_ERROR));
            test()->fail('A stale lock was accepted.');
        } catch (DependencyParseException $exception) {
            expect($exception->errorCode)->toBe('dependencies.stale_pnpm_lockfile');
            expect($exception->getMessage())->toBe('dependencies.stale_pnpm_lockfile');
        }
    })->with([
        'missing root declaration' => fn ($manifest, $lock) => unsetPnpmProperty($manifest->dependencies, 'app-one'),
        'missing importer declaration' => fn ($manifest, $lock) => unsetPnpmProperty($lock->importers->{'.'}->dependencies, 'app-one'),
        'mismatched scope' => function ($manifest, $lock): void {
            unset($manifest->dependencies->{'app-two'});
            $manifest->devDependencies->{'app-two'} = '^1';
        },
        'mismatched specifier' => fn ($manifest, $lock) => $lock->importers->{'.'}->dependencies->{'app-one'}->specifier = '^2',
        'unsafe specifier' => fn ($manifest, $lock) => $manifest->dependencies->{'app-one'} = 'https://fixture-user:fixture-secret@example.test/a.tgz',
    ]);

    it('rejects inconsistent graph records', function (Closure $mutate): void {
        [$manifest, $lock] = pnpmReaderRecords();
        $mutate($manifest, $lock);

        expect(fn () => (new ReadPnpmDependencyGraphAction)->execute(json_encode($manifest, JSON_THROW_ON_ERROR), json_encode($lock, JSON_THROW_ON_ERROR)))
            ->toThrow(DependencyParseException::class, 'dependencies.invalid_pnpm_input');
    })->with([
        'missing importers' => fn ($manifest, $lock) => unsetPnpmProperty($lock, 'importers'),
        'importers list' => fn ($manifest, $lock) => $lock->importers = [],
        'null importer' => fn ($manifest, $lock) => $lock->importers->{'.'} = null,
        'null packages' => fn ($manifest, $lock) => $lock->packages = null,
        'snapshots list' => fn ($manifest, $lock) => $lock->snapshots = [],
        'null metadata' => fn ($manifest, $lock) => $lock->packages->{'app-one@1.0.0'} = null,
        'null snapshot' => fn ($manifest, $lock) => $lock->snapshots->{'app-one@1.0.0'} = null,
        'missing metadata' => fn ($manifest, $lock) => unsetPnpmProperty($lock->packages, 'app-one@1.0.0'),
        'missing snapshot' => fn ($manifest, $lock) => unsetPnpmProperty($lock->snapshots, 'app-one@1.0.0'),
        'metadata identity mismatch' => fn ($manifest, $lock) => $lock->packages->{'app-one@1.0.0'}->name = 'other',
        'metadata version mismatch' => fn ($manifest, $lock) => $lock->packages->{'app-one@1.0.0'}->version = '2.0.0',
        'numeric specifier' => fn ($manifest, $lock) => $lock->importers->{'.'}->dependencies->{'app-one'}->specifier = 1,
        'wrong alias identity' => fn ($manifest, $lock) => $lock->importers->{'.'}->dependencies->renamed->version = 'app-one@1.0.0',
        'numeric resolved version' => fn ($manifest, $lock) => $lock->importers->{'.'}->dependencies->{'app-one'}->version = 1,
        'malformed root dependencies' => fn ($manifest, $lock) => $manifest->dependencies = [],
        'malformed importer dependencies' => fn ($manifest, $lock) => $lock->importers->{'.'}->dependencies = [],
        'malformed importer entry' => fn ($manifest, $lock) => $lock->importers->{'.'}->dependencies->{'app-one'} = '1.0.0',
        'missing transitive target' => fn ($manifest, $lock) => $lock->snapshots->{'app-one@1.0.0'}->dependencies->plugin = '2.0.0',
        'wrong peer identity' => fn ($manifest, $lock) => $lock->snapshots->{'plugin@1.0.0(host@1.0.0)'}->dependencies->host = 'app-one@1.0.0',
        'missing optional target' => fn ($manifest, $lock) => $lock->snapshots->{'plugin@1.0.0(host@1.0.0)'}->optionalDependencies->native = '2.0.0',
        'invalid peer declaration' => fn ($manifest, $lock) => $lock->packages->{'plugin@1.0.0'}->peerDependencies->host = 1,
        'invalid peer optional flag' => fn ($manifest, $lock) => $lock->packages->{'plugin@1.0.0'}->peerDependenciesMeta->{'missing-peer'}->optional = 'true',
        'orphan peer metadata' => fn ($manifest, $lock) => $manifest->peerDependenciesMeta = (object) ['unknown' => (object) []],
        'invalid integrity' => fn ($manifest, $lock) => $lock->packages->{'app-one@1.0.0'}->resolution->integrity = 'fixture-secret',
        'null resolution metadata' => fn ($manifest, $lock) => $lock->packages->{'app-one@1.0.0'}->resolution = null,
        'invalid tarball metadata' => fn ($manifest, $lock) => $lock->packages->{'app-one@1.0.0'}->resolution->tarball = 1,
        'unreachable package' => function ($manifest, $lock): void {
            $lock->packages->{'orphan@1.0.0'} = (object) [];
            $lock->snapshots->{'orphan@1.0.0'} = (object) [];
        },
    ]);

    it('rejects malformed snapshot keys', function (string $id): void {
        $lock = json_encode(['lockfileVersion' => '9.0', 'importers' => ['.' => (object) []], 'snapshots' => [$id => (object) []]], JSON_THROW_ON_ERROR);

        expect(fn () => (new ReadPnpmDependencyGraphAction)->execute('{}', $lock))
            ->toThrow(DependencyParseException::class, 'dependencies.invalid_pnpm_input');
    })->with(['plugin@1.0.0(peer@2.0.0', 'plugin@1.0.0()', 'plugin@1.0.0(peer@2.0.0))', 'plugin@1.0.0(peer@2.0.0)garbage', 'plugin@https://fixture-secret@example.test', 'not-a-locator']);

    it('rejects workspace and local layouts', function (Closure $mutate): void {
        [$manifest, $lock] = pnpmReaderRecords();
        $mutate($manifest, $lock);

        expect(fn () => (new ReadPnpmDependencyGraphAction)->execute(json_encode($manifest, JSON_THROW_ON_ERROR), json_encode($lock, JSON_THROW_ON_ERROR)))
            ->toThrow(DependencyParseException::class, 'dependencies.unsupported_layout');
    })->with([
        'manifest workspaces' => fn ($manifest, $lock) => $manifest->workspaces = [],
        'multiple importers' => fn ($manifest, $lock) => $lock->importers->{'packages/one'} = (object) [],
        'no root importer' => fn ($manifest, $lock) => $lock->importers = (object) ['packages/one' => (object) []],
        'workspace constraint' => fn ($manifest, $lock) => $manifest->dependencies->{'app-one'} = 'workspace:*',
        'catalog reference without embedded catalogs' => fn ($manifest, $lock) => $manifest->dependencies->{'app-one'} = 'catalog:',
        'local package key' => fn ($manifest, $lock) => $lock->packages->{'one@file:../one'} = (object) [],
        'catalog constraint' => fn ($manifest, $lock) => $lock->catalogs = (object) [],
        'local root link' => fn ($manifest, $lock) => $lock->importers->{'.'}->dependencies->{'app-one'}->version = 'link:../one',
        'local transitive link' => fn ($manifest, $lock) => $lock->snapshots->{'app-one@1.0.0'}->dependencies->plugin = 'file:../one',
        'local distribution' => fn ($manifest, $lock) => $lock->packages->{'app-one@1.0.0'}->resolution->tarball = 'file:../one.tgz',
        'directory resolution' => fn ($manifest, $lock) => $lock->packages->{'app-one@1.0.0'}->resolution->directory = '../one',
    ]);
});

function unsetPnpmProperty(stdClass $record, string $name): void
{
    unset($record->{$name});
}
