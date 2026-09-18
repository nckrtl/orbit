<?php

declare(strict_types=1);

use App\Actions\AppInstances\Dependencies\ReadBunDependencyGraphAction;
use App\Domain\AppInstances\Dependencies\DependencyEcosystem;
use App\Domain\AppInstances\Dependencies\DependencyParseException;
use App\Domain\AppInstances\Dependencies\DependencyRequirement;
use App\Domain\AppInstances\Dependencies\DependencyRequirementKind;
use App\Domain\AppInstances\Dependencies\DependencyResolution;
use App\Domain\AppInstances\Dependencies\DependencyScope;

/** @return array{string, string} */
function bunReaderFixture(): array
{
    $path = dirname(__DIR__, 4).'/Fixtures/Dependencies/Bun/';

    return [file_get_contents($path.'manifest.json'), file_get_contents($path.'bun.lock')];
}

/** @param array<string, mixed> $packages */
function bunReaderLock(string $root = '{}', array $packages = []): string
{
    return '{"lockfileVersion":1,"workspaces":{"":'.$root.'},"packages":'.json_encode((object) $packages, JSON_THROW_ON_ERROR).'}';
}

describe('Bun dependency reader', function (): void {
    it('preserves aliases nested resolutions cycles and independent development paths', function (): void {
        [$manifest, $lock] = bunReaderFixture();

        $graph = (new ReadBunDependencyGraphAction)->execute($manifest, $lock);

        expect($graph->ecosystem)->toBe(DependencyEcosystem::Npm);
        expect(array_map(fn (DependencyResolution $resolution): array => [
            $resolution->id, $resolution->package->name, $resolution->version, $resolution->regular, $resolution->development,
        ], $graph->resolutions))->toBe([
            ['alias', '@sample/actual', '3.1.0', true, false],
            ['app-one', 'app-one', '1.1.0', true, true],
            ['app-one/adapter', 'adapter', '1.0.0', true, true],
            ['app-two', 'app-two', '2.1.0', true, false],
            ['app-two/adapter', 'adapter', '1.0.0', true, false],
            ['app-two/shared', 'shared', '2.2.0', true, false],
            ['shared', 'shared', '1.2.0', true, true],
            ['tool', 'tool', '1.0.0', false, true],
        ]);
        expect($graph->requirements)->toHaveCount(15)->toContainEqual(
            new DependencyRequirement(null, 'alias', 'alias', 'npm:@sample/actual@^3', DependencyRequirementKind::Dependency, DependencyScope::Regular),
            new DependencyRequirement(null, 'tool', 'tool', '^1', DependencyRequirementKind::Dependency, DependencyScope::Development),
            new DependencyRequirement('app-one/adapter', 'shared', 'shared', '^1', DependencyRequirementKind::Peer, DependencyScope::Regular),
            new DependencyRequirement('app-two/adapter', 'app-two/shared', 'shared', '^2', DependencyRequirementKind::Peer, DependencyScope::Regular),
            new DependencyRequirement(null, null, 'unavailable', '^4', DependencyRequirementKind::Dependency, DependencyScope::Regular, true),
            new DependencyRequirement(null, null, 'root-peer', '*', DependencyRequirementKind::Peer, DependencyScope::Regular, true),
            new DependencyRequirement('app-one/adapter', null, 'optional-peer', '*', DependencyRequirementKind::Peer, DependencyScope::Regular, true),
        );
        expect(array_column($graph->requirements, 'name'))->not->toContain('not-installed');
        expect($graph->resolutions[0]->integrity)->toBe('sha512-YWJjZA==');
        expect(json_encode($graph, JSON_THROW_ON_ERROR))->not->toContain('fixture-secret', 'fixture-user', '127.0.0.1', 'must-never-execute');
    });

    it('preserves scoped paths and resolves peers below their requesting package first', function (): void {
        $manifest = '{"dependencies":{"@scope/plugin":"*","host":"^1"},"devDependencies":{"@scope/plugin":"*"}}';
        $lock = bunReaderLock($manifest, [
            '@scope/plugin' => ['@scope/plugin@1.0.0', '', (object) ['peerDependencies' => (object) ['host' => '*'], 'dependencies' => (object) ['@inner/lib' => '*']], ''],
            '@scope/plugin/host' => ['host@2.0.0', '', new stdClass, ''],
            '@scope/plugin/@inner/lib' => ['@inner/lib@3.0.0', '', (object) ['dependencies' => (object) ['host' => '*']], ''],
            'host' => ['host@1.0.0', '', new stdClass, ''],
        ]);

        $graph = (new ReadBunDependencyGraphAction)->execute($manifest, $lock);

        expect($graph->requirements)->toContainEqual(
            new DependencyRequirement('@scope/plugin', '@scope/plugin/host', 'host', '*', DependencyRequirementKind::Peer, DependencyScope::Regular),
            new DependencyRequirement('@scope/plugin/@inner/lib', '@scope/plugin/host', 'host', '*', DependencyRequirementKind::Dependency, DependencyScope::Regular),
        );
        expect(array_column($graph->resolutions, 'development'))->toBe([true, true, true, false]);
    });

    it('keeps transitive npm aliases and missing required peers truthful', function (): void {
        $root = '{"dependencies":{"one":"*"}}';
        $lock = bunReaderLock($root, [
            'one' => ['one@1.0.0', '', (object) ['dependencies' => (object) ['alias' => 'npm:@scope/actual@^2'], 'peerDependencies' => (object) ['missing' => '*']], ''],
            'alias' => ['@scope/actual@2.0.0', '', new stdClass, ''],
        ]);

        $graph = (new ReadBunDependencyGraphAction)->execute($root, $lock);

        expect($graph->resolutions[0]->package->name)->toBe('@scope/actual');
        expect($graph->requirements)->toContainEqual(
            new DependencyRequirement('one', 'alias', 'alias', 'npm:@scope/actual@^2', DependencyRequirementKind::Dependency, DependencyScope::Regular),
            new DependencyRequirement('one', null, 'missing', '*', DependencyRequirementKind::Peer, DependencyScope::Regular),
        );
    });

    it('preserves optional and development overlap and ignores lock-local development tools', function (): void {
        $root = '{"dependencies":{"one":"^1"},"optionalDependencies":{"one":"^2"},"devDependencies":{"one":"^2"}}';
        $lock = bunReaderLock($root, ['one' => ['one@2.0.0', '', (object) ['devDependencies' => (object) ['uninstalled' => '*']], '']]);

        $graph = (new ReadBunDependencyGraphAction)->execute($root, $lock);

        expect($graph->resolutions[0]->regular)->toBeTrue();
        expect($graph->resolutions[0]->development)->toBeTrue();
        expect($graph->requirements)->toEqual([
            new DependencyRequirement(null, 'one', 'one', '^2', DependencyRequirementKind::Dependency, DependencyScope::Regular, true),
            new DependencyRequirement(null, 'one', 'one', '^2', DependencyRequirementKind::Dependency, DependencyScope::Development),
        ]);
    });

    it('returns identical graphs across record order whitespace comments and BOM', function (): void {
        $root = '{"dependencies":{"JSONStream":"*","123":"*"},"private":true}';
        $packages = ['JSONStream' => ['JSONStream@1.2.3', '', new stdClass, ''], '123' => ['123@2.3.4', '', new stdClass, '']];
        $reader = new ReadBunDependencyGraphAction;
        $lock = bunReaderLock($root, $packages);
        $variant = "\xef\xbb\xbf/* header */\r\n".str_replace('"packages":', '"packages": /* inert */', bunReaderLock($root, array_reverse($packages, true))).'// end';

        $graph = $reader->execute($root, $lock);

        expect($reader->execute($root, $variant))->toEqual($graph);
        expect(array_column($graph->resolutions, 'id'))->toBe(['123', 'JSONStream']);
    });

    it('accepts empty root locks without inventing packages', function (string $lock): void {
        $graph = (new ReadBunDependencyGraphAction)->execute('{"private":true}', $lock);

        expect($graph->resolutions)->toBe([]);
        expect($graph->requirements)->toBe([]);
    })->with([
        'omitted packages' => '{"lockfileVersion":1,"workspaces":{"":{}}}',
        'empty packages' => '{"lockfileVersion":1,"workspaces":{"":{}},"packages":{}}',
        'JSONC primitives' => '{"lockfileVersion":1,"workspaces":{"":{}},"trustedDependencies":[],"other":{"count":123,"float":1.25,"null":null,"flag":false,"text":"literal // and /* and ,] and \\" quote"},}',
    ]);

    it('hashes opaque remote resolutions and constraints without claiming release versions', function (string $reference): void {
        $root = json_encode(['dependencies' => ['alias' => $reference]], JSON_THROW_ON_ERROR);
        $lock = bunReaderLock($root, ['alias' => ['actual@'.$reference, new stdClass]]);

        $graph = (new ReadBunDependencyGraphAction)->execute($root, $lock);

        expect($graph->resolutions[0]->package->name)->toBe('actual');
        expect($graph->resolutions[0]->version)->toBe('bun:sha256:'.hash('sha256', $reference));
        expect($graph->resolutions[0]->sourceReference)->toBeNull();
        expect($graph->requirements[0]->constraint)->toBe($graph->resolutions[0]->version);
        expect(json_encode($graph, JSON_THROW_ON_ERROR))->not->toContain('fixture-secret', 'fixture-user', 'example.test', '?token', 'https://', 'http://');
    })->with([
        'https' => 'https://example.test/pkg.tgz',
        'authenticated signed URL' => 'https://fixture-user:fixture-secret@example.test/pkg.tgz?token=fixture-secret',
        'http' => 'http://example.test/pkg.tgz',
    ]);

    it('retains Git revisions separately from opaque source references', function (string $reference): void {
        $root = json_encode(['devDependencies' => ['one' => $reference]], JSON_THROW_ON_ERROR);
        $lock = bunReaderLock($root, ['one' => ['one@'.$reference, new stdClass, 'untrusted-bun-tag']]);

        $graph = (new ReadBunDependencyGraphAction)->execute($root, $lock);

        expect($graph->resolutions[0]->version)->toStartWith('bun:sha256:');
        expect($graph->resolutions[0]->sourceReference)->toBe('0123456789abcdef0123456789abcdef01234567');
        expect($graph->resolutions[0]->development)->toBeTrue();
        expect(json_encode($graph, JSON_THROW_ON_ERROR))->not->toContain('untrusted-bun-tag', 'fixture-secret', 'example.test');
    })->with([
        'hosted Git' => 'github:sample/one#0123456789abcdef0123456789abcdef01234567',
        'authenticated Git' => 'git+https://user:fixture-secret@example.test/one.git#0123456789abcdef0123456789abcdef01234567',
    ]);

    it('keeps separate same-version paths and source distinctions', function (): void {
        $root = '{"dependencies":{"one":"*","two":"*"}}';
        $lock = bunReaderLock($root, [
            'one' => ['actual@https://example.test/a.tgz', new stdClass],
            'two' => ['actual@https://example.test/b.tgz', new stdClass],
        ]);

        $graph = (new ReadBunDependencyGraphAction)->execute($root, $lock);

        expect(array_column($graph->resolutions, 'id'))->toBe(['one', 'two']);
        expect($graph->resolutions[0]->package)->toEqual($graph->resolutions[1]->package);
        expect($graph->resolutions[0]->version)->not->toBe($graph->resolutions[1]->version);
    });

    it('rejects unsupported lock formats visibly', function (string $lock): void {
        expect(fn () => (new ReadBunDependencyGraphAction)->execute('{}', $lock))
            ->toThrow(DependencyParseException::class, 'dependencies.unsupported_format');
    })->with([
        'binary header' => "#!/usr/bin/env bun\nbun-lockfile-format-v0\n",
        'binary data' => "\x00\x01\x02",
        'version zero' => '{"lockfileVersion":0}',
        'future version' => '{"lockfileVersion":2}',
        'numeric string' => '{"lockfileVersion":"1"}',
        'missing version' => '{}',
        'Yarn metadata' => '{"__metadata":{"version":8}}',
    ]);

    it('rejects malformed JSONC instead of repairing it into a graph', function (string $lock): void {
        expect(fn () => (new ReadBunDependencyGraphAction)->execute('{}', $lock))
            ->toThrow(DependencyParseException::class, 'dependencies.invalid_bun_input');
    })->with([
        'empty' => '',
        'control whitespace' => "{\x0b\"lockfileVersion\":1,\"workspaces\":{\"\":{}}}",
        'unfinished comment' => '{/*',
        'unfinished string' => '{"lockfileVersion":1,"unterminated}',
        'duplicate version' => '{"lockfileVersion":2,"lockfileVersion":1,"workspaces":{"":{}}}',
        'escaped duplicate workspace' => '{"lockfileVersion":1,"workspaces":{"":{},"\\u0000":{},"\\u0000":{}}}',
        'duplicate root' => '{"lockfileVersion":1,"workspaces":{"":{},"":{}}}',
        'array document' => '[]',
        'leading object comma' => '{"lockfileVersion":1,"workspaces":{"":{,}}}',
        'leading array comma' => '{"lockfileVersion":1,"workspaces":{"":{}},"extra":[,]}',
        'double comma' => '{"lockfileVersion":1,,"workspaces":{"":{}}}',
        'unquoted key' => '{lockfileVersion:1}',
        'single quotes' => "{'lockfileVersion':1}",
        'expression' => '{"lockfileVersion":(()=>1)()}',
        'second document' => '{"lockfileVersion":1,"workspaces":{"":{}}} {}',
        'Yarn text' => "# yarn lockfile v1\n",
        'broken primitive' => '{"lockfileVersion":1,"workspaces":{"":{}},"extra":t/**/rue}',
    ]);

    it('rejects malformed root layouts and inconsistent declarations', function (string $manifest, string $lock): void {
        expect(fn () => (new ReadBunDependencyGraphAction)->execute($manifest, $lock))
            ->toThrow(DependencyParseException::class, 'dependencies.invalid_bun_input');
    })->with([
        'manifest JSONC' => ['{/* comment */}', '{"lockfileVersion":1,"workspaces":{"":{}}}'],
        'duplicate manifest key' => ['{"dependencies":{},"dependenc\\u0069es":{}}', '{"lockfileVersion":1,"workspaces":{"":{}}}'],
        'missing workspace' => ['{}', '{"lockfileVersion":1}'],
        'null workspace' => ['{}', '{"lockfileVersion":1,"workspaces":null}'],
        'no root' => ['{}', '{"lockfileVersion":1,"workspaces":{}}'],
        'null root' => ['{}', '{"lockfileVersion":1,"workspaces":{"":null}}'],
        'packages array' => ['{}', '{"lockfileVersion":1,"workspaces":{"":{}},"packages":[]}'],
        'missing required package' => ['{"dependencies":{"one":"*"}}', '{"lockfileVersion":1,"workspaces":{"":{"dependencies":{"one":"*"}}}}'],
        'dropped optional root' => ['{"optionalDependencies":{"one":"*"}}', '{"lockfileVersion":1,"workspaces":{"":{}}}'],
        'dropped peer root' => ['{"peerDependencies":{"one":"*"}}', '{"lockfileVersion":1,"workspaces":{"":{}}}'],
        'name conflict' => ['{"name":"one"}', '{"lockfileVersion":1,"workspaces":{"":{"name":"two"}}}'],
        'numeric constraint' => ['{"dependencies":{"one":1}}', '{"lockfileVersion":1,"workspaces":{"":{}}}'],
    ]);

    it('rejects malformed package tuples and unsafe retained fields', function (mixed $tuple): void {
        $root = '{"dependencies":{"one":"*"}}';
        $lock = bunReaderLock($root, ['one' => $tuple]);

        expect(fn () => (new ReadBunDependencyGraphAction)->execute($root, $lock))
            ->toThrow(DependencyParseException::class, 'dependencies.invalid_bun_input');
    })->with([
        'not tuple' => [new stdClass],
        'empty tuple' => [[]],
        'missing descriptor' => [[null, '', new stdClass, '']],
        'missing version' => [['one', '', new stdClass, '']],
        'invalid name' => [['../one@1.0.0', '', new stdClass, '']],
        'unsafe version' => [['one@secret?token', '', new stdClass, '']],
        'missing integrity' => [['one@1.0.0', '', new stdClass]],
        'extra tuple entry' => [['one@1.0.0', '', new stdClass, '', 'extra']],
        'invalid registry' => [['one@1.0.0', null, new stdClass, '']],
        'invalid info' => [['one@1.0.0', '', [], '']],
        'invalid integrity' => [['one@1.0.0', '', new stdClass, 'https://user:secret@example.test']],
        'invalid dependencies' => [['one@1.0.0', '', (object) ['dependencies' => []], '']],
        'missing dependency' => [['one@1.0.0', '', (object) ['dependencies' => (object) ['absent' => '*']], '']],
        'invalid optional peers' => [['one@1.0.0', '', (object) ['optionalPeers' => new stdClass], '']],
        'unknown optional peer' => [['one@1.0.0', '', (object) ['optionalPeers' => ['absent']], '']],
        'duplicate optional peer' => [['one@1.0.0', '', (object) ['peerDependencies' => (object) ['peer' => '*'], 'optionalPeers' => ['peer', 'peer']], '']],
        'invalid peer metadata' => [['one@1.0.0', '', (object) ['peerDependencies' => (object) ['peer' => '*'], 'peerDependenciesMeta' => (object) ['peer' => (object) ['optional' => 'yes']]], '']],
        'invalid source URL' => [['one@https://', new stdClass]],
        'missing Git tag' => [['one@github:sample/one#revision', new stdClass]],
    ]);

    it('rejects excluded layouts', function (string $manifest, string $lock): void {
        expect(fn () => (new ReadBunDependencyGraphAction)->execute($manifest, $lock))
            ->toThrow(DependencyParseException::class, 'dependencies.unsupported_layout');
    })->with([
        'manifest workspaces' => ['{"workspaces":[]}', '{"lockfileVersion":1,"workspaces":{"":{}}}'],
        'additional workspace' => ['{}', '{"lockfileVersion":1,"workspaces":{"":{},"packages/one":{"name":"one"}}}'],
        'workspace tuple' => ['{}', '{"lockfileVersion":1,"workspaces":{"":{}},"packages":{"one":["one@workspace:packages/one"]}}'],
        'local directory' => ['{}', '{"lockfileVersion":1,"workspaces":{"":{}},"packages":{"one":["one@file:../one",{}]}}'],
        'link tuple' => ['{}', '{"lockfileVersion":1,"workspaces":{"":{}},"packages":{"one":["one@link:../one",{}]}}'],
        'root link' => ['{}', '{"lockfileVersion":1,"workspaces":{"":{}},"packages":{"one":["one@root:",{}]}}'],
        'local root reference' => ['{"dependencies":{"one":"./one"}}', '{"lockfileVersion":1,"workspaces":{"":{}}}'],
    ]);

    it('rejects orphan paths unreachable records and incorrect alias targets', function (array $packages, string $root): void {
        expect(fn () => (new ReadBunDependencyGraphAction)->execute($root, bunReaderLock($root, $packages)))
            ->toThrow(DependencyParseException::class, 'dependencies.invalid_bun_input');
    })->with([
        'orphan parent' => [['missing/one' => ['one@1.0.0', '', new stdClass, '']], '{}'],
        'unreachable record' => [['one' => ['one@1.0.0', '', new stdClass, '']], '{}'],
        'wrong alias identity' => [['alias' => ['wrong@1.0.0', '', new stdClass, '']], '{"dependencies":{"alias":"npm:actual@^1"}}'],
        'malformed scope path' => [['@scope' => ['one@1.0.0', '', new stdClass, '']], '{}'],
        'empty path' => [['' => ['one@1.0.0', '', new stdClass, '']], '{}'],
        'traversal path' => [['../one' => ['one@1.0.0', '', new stdClass, '']], '{}'],
    ]);

    it('does not expose malformed source contents or previous exceptions', function (): void {
        $root = '{"dependencies":{"one":"*"}}';
        $lock = bunReaderLock($root, ['one' => ['one@1.0.0', '', new stdClass, 'https://fixture-user:fixture-secret@example.test']]);

        try {
            (new ReadBunDependencyGraphAction)->execute($root, $lock);
            test()->fail('Malformed integrity must fail.');
        } catch (DependencyParseException $exception) {
            expect($exception->getMessage())->toBe('dependencies.invalid_bun_input');
            expect($exception->getPrevious())->toBeNull();
        }
    });

    it('rejects duplicate package paths and strict root constraint mismatches', function (string $root, string $lock): void {
        expect(fn () => (new ReadBunDependencyGraphAction)->execute($root, $lock))
            ->toThrow(DependencyParseException::class, 'dependencies.invalid_bun_input');
    })->with([
        'duplicate path' => ['{}', '{"lockfileVersion":1,"workspaces":{"":{}},"packages":{"one":["one@1.0.0","",{},""],"o\\u006ee":["one@2.0.0","",{},""]}}'],
        'different numeric-looking constraint' => ['{"dependencies":{"one":"1"}}', '{"lockfileVersion":1,"workspaces":{"":{"dependencies":{"one":"1.0"}}},"packages":{"one":["one@1.0.0","",{},""]}}'],
        'development scope mismatch' => ['{"devDependencies":{"one":"*"}}', '{"lockfileVersion":1,"workspaces":{"":{"dependencies":{"one":"*"}}},"packages":{"one":["one@1.0.0","",{},""]}}'],
        'optional peer mismatch' => ['{"peerDependencies":{"one":"*"}}', '{"lockfileVersion":1,"workspaces":{"":{"peerDependencies":{"one":"*"},"optionalPeers":["one"]}}}'],
    ]);

    it('retains development paths when Bun replaces them with an optional root declaration', function (bool $present): void {
        $manifest = '{"devDependencies":{"one":"^1"},"optionalDependencies":{"one":"^2"}}';
        $root = '{"optionalDependencies":{"one":"^2"}}';
        $packages = $present ? ['one' => ['one@2.0.0', '', new stdClass, '']] : [];

        $graph = (new ReadBunDependencyGraphAction)->execute($manifest, bunReaderLock($root, $packages));

        expect($graph->requirements)->toEqual([
            new DependencyRequirement(null, $present ? 'one' : null, 'one', '^2', DependencyRequirementKind::Dependency, DependencyScope::Regular, true),
            new DependencyRequirement(null, $present ? 'one' : null, 'one', '^1', DependencyRequirementKind::Dependency, DependencyScope::Development, true),
        ]);
        expect(array_column($graph->resolutions, 'development'))->toBe($present ? [true] : []);
        expect(array_column($graph->resolutions, 'regular'))->toBe($present ? [true] : []);
    })->with(['present optional package' => true, 'absent optional package' => false]);
});
