<?php

declare(strict_types=1);

use App\Actions\AppInstances\Dependencies\ReadNpmDependencyGraphAction;
use App\Domain\AppInstances\Dependencies\DependencyEcosystem;
use App\Domain\AppInstances\Dependencies\DependencyParseException;
use App\Domain\AppInstances\Dependencies\DependencyRequirement;
use App\Domain\AppInstances\Dependencies\DependencyRequirementKind;
use App\Domain\AppInstances\Dependencies\DependencyResolution;
use App\Domain\AppInstances\Dependencies\DependencyScope;

/** @return array{string, string} */
function npmReaderFixture(int $version): array
{
    $path = dirname(__DIR__, 4).'/Fixtures/Dependencies/Npm/';

    return [file_get_contents($path.'manifest.json'), file_get_contents($path.'package-lock-v'.$version.'.json')];
}

describe('npm dependency reader', function (): void {
    it('rejects excluded Yarn lockfiles instead of returning an empty inventory', function (string $lock, string $errorCode): void {
        expect(fn () => (new ReadNpmDependencyGraphAction)->execute('{"private":true}', $lock))
            ->toThrow(DependencyParseException::class, $errorCode);
    })->with([
        'Classic empty lock' => ["# yarn lockfile v1\n", 'dependencies.invalid_npm_input'],
        'Classic package record' => ["# yarn lockfile v1\none@^1:\n  version \"1.0.0\"\n", 'dependencies.invalid_npm_input'],
        'modern v4' => ["__metadata:\n  version: 4\n", 'dependencies.invalid_npm_input'],
        'modern v6' => ["__metadata:\n  version: 6\n", 'dependencies.invalid_npm_input'],
        'modern v8 root only' => ["__metadata:\n  version: 8\n\"root@workspace:.\":\n  version: 0.0.0-use.local\n  resolution: \"root@workspace:.\"\n  linkType: soft\n", 'dependencies.invalid_npm_input'],
        'modern metadata as JSON' => ['{"__metadata":{"version":8}}', 'dependencies.unsupported_format'],
    ]);

    it('preserves locations aliases cycles and independent root reachability', function (int $version): void {
        [$manifest, $lock] = npmReaderFixture($version);

        $graph = (new ReadNpmDependencyGraphAction)->execute($manifest, $lock);

        expect($graph->ecosystem)->toBe(DependencyEcosystem::Npm);
        expect(array_map(fn (DependencyResolution $resolution): array => [
            $resolution->id, $resolution->package->name, $resolution->version, $resolution->regular, $resolution->development,
        ], $graph->resolutions))->toBe([
            ['node_modules/alias', '@sample/actual', '3.1.0', true, false],
            ['node_modules/app-one', 'app-one', '1.1.0', true, true],
            ['node_modules/app-one/node_modules/adapter', 'adapter', '1.0.0', true, true],
            ['node_modules/app-two', 'app-two', '2.1.0', true, false],
            ['node_modules/app-two/node_modules/adapter', 'adapter', '1.0.0', true, false],
            ['node_modules/app-two/node_modules/shared', 'shared', '2.2.0', true, false],
            ['node_modules/shared', 'shared', '1.2.0', true, true],
            ['node_modules/tool', 'tool', '1.0.0', false, true],
        ]);
        expect($graph->requirements)->toHaveCount(16)->toContainEqual(
            new DependencyRequirement(null, 'node_modules/alias', 'alias', 'npm:@sample/actual@^3.0', DependencyRequirementKind::Dependency, DependencyScope::Regular),
            new DependencyRequirement(null, 'node_modules/tool', 'tool', '^1.0', DependencyRequirementKind::Dependency, DependencyScope::Development),
            new DependencyRequirement('node_modules/app-one', 'node_modules/shared', 'shared', '^1.0', DependencyRequirementKind::Dependency, DependencyScope::Regular),
            new DependencyRequirement('node_modules/app-two', 'node_modules/app-two/node_modules/shared', 'shared', '^2.0', DependencyRequirementKind::Dependency, DependencyScope::Regular),
            new DependencyRequirement('node_modules/app-one/node_modules/adapter', 'node_modules/shared', 'shared', '^1.0', DependencyRequirementKind::Peer, DependencyScope::Regular),
            new DependencyRequirement('node_modules/app-two/node_modules/adapter', 'node_modules/app-two/node_modules/shared', 'shared', '^2.0', DependencyRequirementKind::Peer, DependencyScope::Regular),
        );
        expect(array_column($graph->requirements, 'name'))->not->toContain('not-installed', 'legacy-only');
        expect($graph->resolutions[0]->integrity)->toBe('sha512-YWJjZA==');
    })->with([2, 3]);

    it('keeps missing optional dependencies and peers without inventing resolutions', function (int $version): void {
        [$manifest, $lock] = npmReaderFixture($version);

        $graph = (new ReadNpmDependencyGraphAction)->execute($manifest, $lock);

        expect(array_values(array_filter($graph->requirements, fn (DependencyRequirement $edge): bool => $edge->to === null)))->toEqual([
            new DependencyRequirement(null, null, 'unavailable', '^4.0', DependencyRequirementKind::Dependency, DependencyScope::Regular, true),
            new DependencyRequirement(null, null, 'root-peer', '^9.0', DependencyRequirementKind::Peer, DependencyScope::Regular),
            new DependencyRequirement('node_modules/app-one/node_modules/adapter', null, 'optional-peer', '*', DependencyRequirementKind::Peer, DependencyScope::Regular, true),
        ]);
    })->with([2, 3]);

    it('returns deterministic results without retaining download credentials or executable metadata', function (int $version): void {
        [$manifest, $lock] = npmReaderFixture($version);
        $root = json_decode($manifest, flags: JSON_THROW_ON_ERROR);
        $root->dependencies = (object) array_reverse(get_object_vars($root->dependencies), true);
        $reordered = json_decode($lock, flags: JSON_THROW_ON_ERROR);
        $reordered->packages = (object) array_reverse(get_object_vars($reordered->packages), true);
        $reader = new ReadNpmDependencyGraphAction;

        $graph = $reader->execute($manifest, $lock);
        $again = $reader->execute(json_encode($root, JSON_THROW_ON_ERROR), json_encode($reordered, JSON_THROW_ON_ERROR));

        expect($again)->toEqual($graph);
        expect(json_encode($graph, JSON_THROW_ON_ERROR))->not->toContain('fixture-secret', 'fixture-user', '127.0.0.1', 'must-never-execute', 'hasInstallScript');
        expect(array_column($graph->resolutions, 'sourceReference'))->toBe(array_fill(0, 8, null));
    })->with([2, 3]);

    it('preserves scoped locations and direct regular development and peer overlap', function (): void {
        $manifest = '{"dependencies":{"@sample/package":"^1"},"devDependencies":{"@sample/package":"^1"},"peerDependencies":{"@sample/package":"*"}}';
        $lock = '{"lockfileVersion":3,"packages":{"":'.$manifest.',"node_modules/@sample/package":{"version":"1.0.0","dependencies":{"nested":"*"}},"node_modules/@sample/package/node_modules/nested":{"version":"2.0.0"}}}';

        $graph = (new ReadNpmDependencyGraphAction)->execute($manifest, $lock);

        expect($graph->resolutions)->toHaveCount(2);
        expect(array_column($graph->resolutions, 'regular'))->toBe([true, true]);
        expect(array_column($graph->resolutions, 'development'))->toBe([true, true]);
        expect(array_column($graph->requirements, 'to'))->toBe(['node_modules/@sample/package', 'node_modules/@sample/package', 'node_modules/@sample/package', 'node_modules/@sample/package/node_modules/nested']);
        expect(array_column($graph->requirements, 'kind'))->toBe([DependencyRequirementKind::Dependency, DependencyRequirementKind::Peer, DependencyRequirementKind::Dependency, DependencyRequirementKind::Dependency]);
    });

    it('lets optional declarations override duplicate regular declarations', function (): void {
        $manifest = '{"dependencies":{"optional":"^1"},"optionalDependencies":{"optional":"^2"}}';
        $lock = '{"lockfileVersion":3,"packages":{"":{"optionalDependencies":{"optional":"^2"}}}}';

        $graph = (new ReadNpmDependencyGraphAction)->execute($manifest, $lock);

        expect($graph->resolutions)->toBe([]);
        expect($graph->requirements)->toEqual([
            new DependencyRequirement(null, null, 'optional', '^2', DependencyRequirementKind::Dependency, DependencyScope::Regular, true),
        ]);
    });

    it('resolves a peer from the parent context instead of a private child dependency', function (): void {
        $manifest = '{"dependencies":{"plugin":"*","host":"*"}}';
        $lock = '{"lockfileVersion":3,"packages":{"":'.$manifest.',"node_modules/plugin":{"version":"1","dependencies":{"host":"^2"},"peerDependencies":{"host":"^1"}},"node_modules/plugin/node_modules/host":{"version":"2"},"node_modules/host":{"version":"1"}}}';

        $graph = (new ReadNpmDependencyGraphAction)->execute($manifest, $lock);

        expect($graph->requirements)->toContainEqual(
            new DependencyRequirement('node_modules/plugin', 'node_modules/plugin/node_modules/host', 'host', '^2', DependencyRequirementKind::Dependency, DependencyScope::Regular),
            new DependencyRequirement('node_modules/plugin', 'node_modules/host', 'host', '^1', DependencyRequirementKind::Peer, DependencyScope::Regular),
        );
    });

    it('retains opaque versions and only the revision from a git download URL', function (): void {
        $manifest = '{"dependencies":{"example":"github:sample/example#release"}}';
        $lock = '{"lockfileVersion":3,"packages":{"":'.$manifest.',"node_modules/example":{"version":"release-opaque","resolved":"git+https://fixture-user:fixture-secret@example.test/repo.git#0123456789abcdef0123456789abcdef01234567"}}}';

        $graph = (new ReadNpmDependencyGraphAction)->execute($manifest, $lock);

        expect($graph->resolutions[0]->version)->toBe('release-opaque');
        expect($graph->resolutions[0]->sourceReference)->toBe('0123456789abcdef0123456789abcdef01234567');
        expect($graph->resolutions[0]->integrity)->toBeNull();
        expect(json_encode($graph, JSON_THROW_ON_ERROR))->not->toContain('fixture-user', 'fixture-secret');
    });

    it('preserves historical mixed-case and numeric package identities', function (): void {
        $manifest = '{"dependencies":{"JSONStream":"*","123":"*"}}';
        $lock = '{"lockfileVersion":3,"packages":{"":'.$manifest.',"node_modules/JSONStream":{"version":"1"},"node_modules/123":{"version":"2"}}}';

        $graph = (new ReadNpmDependencyGraphAction)->execute($manifest, $lock);

        expect(array_map(fn (DependencyResolution $resolution): string => $resolution->package->name, $graph->resolutions))->toBe(['123', 'JSONStream']);
        expect(array_column($graph->requirements, 'name'))->toBe(['123', 'JSONStream']);
    });

    it('uses the explicit locked identity for a package installed under another name', function (): void {
        $manifest = '{"dependencies":{"local-name":"https://example.test/archive.tgz"}}';
        $lock = '{"lockfileVersion":3,"packages":{"":'.$manifest.',"node_modules/local-name":{"name":"actual-name","version":"1"}}}';

        $graph = (new ReadNpmDependencyGraphAction)->execute($manifest, $lock);

        expect($graph->resolutions[0]->package->name)->toBe('actual-name');
        expect($graph->requirements[0]->name)->toBe('local-name');
        expect($graph->requirements[0]->to)->toBe('node_modules/local-name');
    });

    it('retains a development path when the same package is an optional regular dependency', function (): void {
        $manifest = '{"devDependencies":{"one":"*"},"optionalDependencies":{"one":"*"}}';
        $lock = '{"lockfileVersion":3,"packages":{"":'.$manifest.',"node_modules/one":{"version":"1"}}}';

        $graph = (new ReadNpmDependencyGraphAction)->execute($manifest, $lock);

        expect($graph->resolutions[0]->regular)->toBeTrue();
        expect($graph->resolutions[0]->development)->toBeTrue();
        expect(array_column($graph->requirements, 'optional'))->toBe([true, false]);
        expect(array_column($graph->requirements, 'scope'))->toBe([DependencyScope::Regular, DependencyScope::Development]);
    });

    it('reads empty root projects in both supported formats', function (int $version): void {
        $graph = (new ReadNpmDependencyGraphAction)->execute('{}', '{"lockfileVersion":'.$version.',"packages":{"":{}}}');

        expect($graph->resolutions)->toBe([]);
        expect($graph->requirements)->toBe([]);
    })->with([2, 3]);

    it('accepts npm locks that omit a dependency-free root record', function (int $version, string $manifest): void {
        $lock = '{"name":"gateway","requires":true,"packages":{},"lockfileVersion":'.$version.'}';

        $graph = (new ReadNpmDependencyGraphAction)->execute($manifest, $lock);

        expect($graph->ecosystem)->toBe(DependencyEcosystem::Npm);
        expect($graph->resolutions)->toBe([]);
        expect($graph->requirements)->toBe([]);
    })->with([2, 3])->with([
        'private project with inert scripts' => '{"private":true,"scripts":{"test":"never-executed"}}',
        'empty manifest' => '{}',
        'empty requirement maps' => '{"dependencies":{},"devDependencies":{},"optionalDependencies":{},"peerDependencies":{}}',
    ]);

    it('reports an omitted root as stale when the manifest declares requirements', function (int $version, string $manifest): void {
        $lock = '{"lockfileVersion":'.$version.',"packages":{}}';

        expect(fn () => (new ReadNpmDependencyGraphAction)->execute($manifest, $lock))
            ->toThrow(DependencyParseException::class, 'dependencies.stale_npm_lockfile');
    })->with([2, 3])->with([
        'required dependency' => '{"dependencies":{"one":"*"}}',
        'development dependency' => '{"devDependencies":{"one":"*"}}',
        'optional dependency' => '{"optionalDependencies":{"one":"*"}}',
        'peer requirement' => '{"peerDependencies":{"one":"*"}}',
        'optional peer requirement' => '{"peerDependencies":{"one":"*"},"peerDependenciesMeta":{"one":{"optional":true}}}',
    ]);

    it('rejects malformed omitted roots', function (int $version, string $manifest, string $packages): void {
        $lock = '{"lockfileVersion":'.$version.',"packages":'.$packages.'}';

        expect(fn () => (new ReadNpmDependencyGraphAction)->execute($manifest, $lock))
            ->toThrow(DependencyParseException::class, 'dependencies.invalid_npm_input');
    })->with([2, 3])->with([
        'matching package without root metadata' => ['{"dependencies":{"one":"*"}}', '{"node_modules/one":{"version":"1"}}'],
        'explicit null root' => ['{}', '{"":null}'],
    ]);

    it('reports a lock whose root record no longer matches package.json as stale', function (string $manifest, string $lock): void {
        try {
            (new ReadNpmDependencyGraphAction)->execute($manifest, $lock);
            test()->fail('A stale lock was accepted.');
        } catch (DependencyParseException $exception) {
            expect($exception->errorCode)->toBe('dependencies.stale_npm_lockfile');
            expect($exception->getMessage())->toBe('dependencies.stale_npm_lockfile');
        }
    })->with([
        // A renamed package added to package.json without npm install, as in a real checkout.
        'manifest dependency missing from lock' => ['{"dependencies":{"@nckrtl/launch-ui":"0.0.x","react":"^19.1.0"}}', '{"lockfileVersion":3,"packages":{"":{"dependencies":{"@hardimpactdev/launch-ui":"0.0.x","react":"^19.1.0"}},"node_modules/@hardimpactdev/launch-ui":{"version":"0.0.1"},"node_modules/react":{"version":"19.1.0"}}}'],
        'lock dependency removed from manifest' => ['{}', '{"lockfileVersion":3,"packages":{"":{"dependencies":{"one":"*"}},"node_modules/one":{"version":"1"}}}'],
        'root constraint mismatch' => ['{"optionalDependencies":{"missing":"^1"}}', '{"lockfileVersion":3,"packages":{"":{"optionalDependencies":{"missing":"^2"}}}}'],
        'numeric-looking root constraint mismatch' => ['{"optionalDependencies":{"missing":"1"}}', '{"lockfileVersion":3,"packages":{"":{"optionalDependencies":{"missing":"1.0"}}}}'],
        'root scope mismatch' => ['{"devDependencies":{"one":"*"}}', '{"lockfileVersion":3,"packages":{"":{"dependencies":{"one":"*"}},"node_modules/one":{"version":"1"}}}'],
        'optional peer flag mismatch' => ['{"peerDependencies":{"one":"*"},"peerDependenciesMeta":{"one":{"optional":true}}}', '{"lockfileVersion":3,"packages":{"":{"peerDependencies":{"one":"*"}}}}'],
        'root name mismatch' => ['{"name":"one"}', '{"lockfileVersion":3,"packages":{"":{"name":"two"}}}'],
        'root version mismatch' => ['{"version":"1"}', '{"lockfileVersion":3,"packages":{"":{"version":"2"}}}'],
    ]);

    it('accepts an alias target that npm would accept for the locked package', function (string $spec, bool $override): void {
        // Shape of a real Vite+ checkout: npm 11.19 ci installs plain vite 8.0.2 for each of these specs.
        $constraint = 'npm:@voidzero-dev/vite-plus-core'.$spec;
        $overrides = $override ? ',"overrides":{"vite":"'.$constraint.'"}' : '';
        $manifest = '{"devDependencies":{"vite":"'.$constraint.'"}'.$overrides.'}';
        $lock = '{"lockfileVersion":3,"requires":true,"packages":{"":{"devDependencies":{"vite":"'.$constraint.'"}},"node_modules/vite":{"version":"8.0.2","resolved":"https://registry.npmjs.org/vite/-/vite-8.0.2.tgz","integrity":"sha512-1gFhNi+bHhRE/qKZOJXACm6tX4bA3Isy9KuKF15AgSRuRazNBOJfdDemPBU16/mpMxApDPrWvZ08DcLPEoRnuA==","license":"MIT","peerDependencies":{"esbuild":"^0.27.0"},"peerDependenciesMeta":{"esbuild":{"optional":true}}}}}';

        $graph = (new ReadNpmDependencyGraphAction)->execute($manifest, $lock);

        expect($graph->resolutions)->toHaveCount(1);
        expect($graph->resolutions[0]->package->name)->toBe('vite');
        expect($graph->resolutions[0]->version)->toBe('8.0.2');
        expect($graph->resolutions[0]->development)->toBeTrue();
        expect($graph->requirements[0])->toEqual(new DependencyRequirement(null, 'node_modules/vite', 'vite', $constraint, DependencyRequirementKind::Dependency, DependencyScope::Development, false));
    })->with([
        'dist-tag without override' => ['@latest', false],
        'dist-tag with matching override' => ['@latest', true],
        'satisfied range' => ['@^8.0.0', false],
        'any version' => ['@*', false],
        'no spec' => ['', false],
    ]);

    it('accepts a bare alias for a locked prerelease like npm does', function (string $spec): void {
        // npm 11.19 ci installs this lock for `npm:ms` and `npm:ms@*` and refuses it for `npm:ms@^3.0.0`.
        $manifest = '{"name":"root","dependencies":{"x":"npm:ms'.$spec.'"}}';
        $lock = '{"name":"root","lockfileVersion":3,"requires":true,"packages":{"":{"name":"root","dependencies":{"x":"npm:ms'.$spec.'"}},"node_modules/x":{"version":"3.0.0-canary.1","resolved":"https://registry.npmjs.org/ms/-/ms-3.0.0-canary.1.tgz","integrity":"sha512-kh8ARjh8rMN7Du2igDRO9QJnqCb2xYTJxyQYK7vJJS4TvLLmsbyhiKpSW+t+y26gyOyMd0riphX0GeWKU3ky5g==","license":"MIT","engines":{"node":">=12.13"}}}}';

        if ($spec === '@^3.0.0') {
            expect(fn () => (new ReadNpmDependencyGraphAction)->execute($manifest, $lock))
                ->toThrow(DependencyParseException::class, 'dependencies.invalid_npm_input');

            return;
        }

        expect((new ReadNpmDependencyGraphAction)->execute($manifest, $lock)->resolutions[0]->version)->toBe('3.0.0-canary.1');
    })->with(['bare alias' => '', 'any version' => '@*', 'range excluding prereleases' => '@^3.0.0']);

    it('rejects an alias target that npm ci would refuse', function (string $constraint, string $record): void {
        $manifest = '{"devDependencies":{"vite":"'.$constraint.'"},"overrides":{"vite":"'.$constraint.'"}}';
        $lock = '{"lockfileVersion":3,"packages":{"":{"devDependencies":{"vite":"'.$constraint.'"}},"node_modules/vite":'.$record.'}}';

        expect(fn () => (new ReadNpmDependencyGraphAction)->execute($manifest, $lock))
            ->toThrow(DependencyParseException::class, 'dependencies.invalid_npm_input');
    })->with([
        // npm 11.19: "lock file's vite@8.0.2 does not satisfy vite@0.2.9".
        'unsatisfied range' => ['npm:@voidzero-dev/vite-plus-core@^0.2.7', '{"version":"8.0.2","resolved":"https://registry.npmjs.org/vite/-/vite-8.0.2.tgz"}'],
        'unsatisfied version' => ['npm:@voidzero-dev/vite-plus-core@0.2.7', '{"version":"8.0.2","resolved":"https://registry.npmjs.org/vite/-/vite-8.0.2.tgz"}'],
        'dist-tag without a registry tarball' => ['npm:@voidzero-dev/vite-plus-core@latest', '{"version":"8.0.2"}'],
    ]);

    it('accepts bundled dependency names without separate lock entries', function (): void {
        $manifest = '{"dependencies":{"parent":"1.0.0"}}';
        $lock = <<<'JSON'
{"lockfileVersion":3,"packages":{"":{"dependencies":{"parent":"1.0.0"}},"node_modules/parent":{"version":"1.0.0","bundleDependencies":["nested"],"dependencies":{"nested":"1.0.0"}}}}
JSON;

        $graph = (new ReadNpmDependencyGraphAction)->execute($manifest, $lock);

        expect($graph->resolutions)->toHaveCount(1);
        expect($graph->requirements)->toEqualCanonicalizing([
            new DependencyRequirement(null, 'node_modules/parent', 'parent', '1.0.0', DependencyRequirementKind::Dependency, DependencyScope::Regular, false),
            new DependencyRequirement('node_modules/parent', null, 'nested', '1.0.0', DependencyRequirementKind::Dependency, DependencyScope::Regular, true),
        ]);
    });

    it('ignores peer metadata for names without a peer declaration', function (): void {
        // debug and follow-redirects publish optional peer metadata without declaring the peer.
        $manifest = '{"devDependencies":{"debug":"^4.4.3"},"peerDependenciesMeta":{"root-only":{"optional":true}}}';
        $lock = <<<'JSON'
{"lockfileVersion":3,"packages":{"":{"devDependencies":{"debug":"^4.4.3"},"peerDependenciesMeta":{"root-only":{"optional":true}}},"node_modules/debug":{"version":"4.4.3","dev":true,"dependencies":{"ms":"^2.1.3"},"peerDependenciesMeta":{"supports-color":{"optional":true}}},"node_modules/ms":{"version":"2.1.3","dev":true}}}
JSON;

        $graph = (new ReadNpmDependencyGraphAction)->execute($manifest, $lock);

        expect($graph->resolutions)->toHaveCount(2);
        expect($graph->requirements)->toEqualCanonicalizing([
            new DependencyRequirement(null, 'node_modules/debug', 'debug', '^4.4.3', DependencyRequirementKind::Dependency, DependencyScope::Development, false),
            new DependencyRequirement('node_modules/debug', 'node_modules/ms', 'ms', '^2.1.3', DependencyRequirementKind::Dependency, DependencyScope::Regular, false),
        ]);
    });

    it('ignores workspaces metadata on transitive package records', function (): void {
        $manifest = '{"devDependencies":{"lib":"1.0.0"}}';
        $lock = <<<'JSON'
{"lockfileVersion":3,"packages":{"":{"devDependencies":{"lib":"1.0.0"}},"node_modules/lib":{"version":"1.0.0","workspaces":["docs"]}}}
JSON;

        $graph = (new ReadNpmDependencyGraphAction)->execute($manifest, $lock);

        expect($graph->resolutions)->toHaveCount(1);
        expect($graph->resolutions[0]->development)->toBeTrue();
    });

    it('rejects unsupported format versions explicitly', function (string $version): void {
        expect(fn () => (new ReadNpmDependencyGraphAction)->execute('{}', '{"lockfileVersion":'.$version.',"packages":{"":{}}}'))
            ->toThrow(DependencyParseException::class, 'dependencies.unsupported_format');
    })->with(['1', '4', '"3"', 'null']);

    it('rejects malformed and inconsistent inputs with safe errors', function (string $manifest, string $lock): void {
        try {
            (new ReadNpmDependencyGraphAction)->execute($manifest, $lock);
            test()->fail('Invalid input was accepted.');
        } catch (DependencyParseException $exception) {
            expect($exception->errorCode)->toBe('dependencies.invalid_npm_input');
            expect($exception->getMessage())->toBe('dependencies.invalid_npm_input');
            expect($exception->getPrevious())->toBeNull();
        }
    })->with([
        'invalid JSON' => ['{', '{}'],
        'non object manifest' => ['[]', '{}'],
        'non object lock' => ['{}', '[]'],
        'duplicate escaped keys' => ['{"dependencies":{},"\u0064ependencies":{}}', '{}'],
        'missing packages map' => ['{}', '{"lockfileVersion":3}'],
        'packages list' => ['{}', '{"lockfileVersion":3,"packages":[]}'],
        'missing root in nonempty map' => ['{}', '{"lockfileVersion":3,"packages":{"node_modules/one":{"version":"1"}}}'],
        'array root' => ['{}', '{"lockfileVersion":3,"packages":{"":[]}}'],
        'missing required resolution' => ['{"dependencies":{"missing":"*"}}', '{"lockfileVersion":3,"packages":{"":{"dependencies":{"missing":"*"}}}}'],
        'invalid root version' => ['{"version":1}', '{"lockfileVersion":3,"packages":{"":{"version":1}}}'],
        'alias identity mismatch' => ['{"dependencies":{"one":"npm:actual@^2"}}', '{"lockfileVersion":3,"packages":{"":{"dependencies":{"one":"npm:actual@^2"}},"node_modules/one":{"name":"different","version":"1.0.0"}}}'],
        'alias credentials' => ['{"dependencies":{"one":"npm:actual@https://fixture-user:fixture-secret@example.test"}}', '{"lockfileVersion":3,"packages":{"":{}}}'],
        'dependency list' => ['{"dependencies":[]}', '{"lockfileVersion":3,"packages":{"":{}}}'],
        'null map' => ['{"optionalDependencies":null}', '{"lockfileVersion":3,"packages":{"":{}}}'],
        'nonstring requirement' => ['{"dependencies":{"one":false}}', '{"lockfileVersion":3,"packages":{"":{}}}'],
        'unsafe constraint' => ['{"dependencies":{"one":"https://fixture-user:fixture-secret@example.test/a.tgz"}}', '{"lockfileVersion":3,"packages":{"":{}}}'],
        'unsafe query' => ['{"optionalDependencies":{"one":"https://example.test/a.tgz?token=fixture-secret"}}', '{"lockfileVersion":3,"packages":{"":{}}}'],
        'malformed alias' => ['{"optionalDependencies":{"one":"npm:@invalid"}}', '{"lockfileVersion":3,"packages":{"":{}}}'],
        'invalid undeclared peer metadata' => ['{"peerDependenciesMeta":{"missing":{"optional":"yes"}}}', '{"lockfileVersion":3,"packages":{"":{}}}'],
        'nonobject peer metadata' => ['{"peerDependenciesMeta":[]}', '{"lockfileVersion":3,"packages":{"":{}}}'],
        'nonobject undeclared peer metadata value' => ['{"peerDependenciesMeta":{"missing":true}}', '{"lockfileVersion":3,"packages":{"":{}}}'],
        'unreachable record' => ['{}', '{"lockfileVersion":3,"packages":{"":{},"node_modules/unused":{"version":"1"}}}'],
        'missing parent record' => ['{}', '{"lockfileVersion":3,"packages":{"":{},"node_modules/missing/node_modules/one":{"version":"1"}}}'],
        'malformed package record' => ['{}', '{"lockfileVersion":3,"packages":{"":{},"node_modules/one":[]}}'],
        'duplicate location' => ['{}', '{"lockfileVersion":3,"packages":{"":{},"node_modules/one":{},"node_modules/one":{}}}'],
    ]);

    it('rejects invalid locked package metadata', function (string $record): void {
        $manifest = '{"dependencies":{"one":"*"}}';
        $lock = '{"lockfileVersion":3,"packages":{"":'.$manifest.',"node_modules/one":'.$record.'}}';

        expect(fn () => (new ReadNpmDependencyGraphAction)->execute($manifest, $lock))
            ->toThrow(DependencyParseException::class, 'dependencies.invalid_npm_input');
    })->with([
        'missing version' => '{}',
        'numeric version' => '{"version":1}',
        'unsafe version' => '{"version":"https://fixture-secret@example.test"}',
        'invalid optional metadata' => '{"version":"1","peerDependencies":{"peer":"*"},"peerDependenciesMeta":{"peer":{"optional":"yes"}}}',
        'null peer record' => '{"version":"1","peerDependencies":{"peer":"*"},"peerDependenciesMeta":{"peer":null}}',
        'missing transitive target' => '{"version":"1","dependencies":{"missing":"*"}}',
        'malformed dev requirements' => '{"version":"1","devDependencies":[]}',
        'malformed flag' => '{"version":"1","dev":"true"}',
        'malformed resolved' => '{"version":"1","resolved":42}',
        'malformed integrity' => '{"version":"1","integrity":"fixture-secret"}',
    ]);

    it('rejects workspace and local link layouts explicitly', function (string $manifest, string $lock): void {
        expect(fn () => (new ReadNpmDependencyGraphAction)->execute($manifest, $lock))
            ->toThrow(DependencyParseException::class, 'dependencies.unsupported_layout');
    })->with([
        'manifest workspaces' => ['{"workspaces":[]}', '{"lockfileVersion":3,"packages":{"":{}}}'],
        'root workspaces' => ['{}', '{"lockfileVersion":3,"packages":{"":{"workspaces":["packages/*"]}}}'],
        'lock workspaces' => ['{}', '{"lockfileVersion":3,"workspaces":["packages/*"],"packages":{"":{}}}'],
        'workspace package entry' => ['{}', '{"lockfileVersion":3,"packages":{"":{},"packages/one":{"version":"1"}}}'],
        'linked package' => ['{"dependencies":{"one":"*"}}', '{"lockfileVersion":3,"packages":{"":{"dependencies":{"one":"*"}},"node_modules/one":{"link":true,"resolved":"../one"}}}'],
        'file requirement' => ['{"dependencies":{"one":"file:../one"}}', '{"lockfileVersion":3,"packages":{"":{}}}'],
        'workspace requirement' => ['{"dependencies":{"one":"workspace:*"}}', '{"lockfileVersion":3,"packages":{"":{}}}'],
        'relative requirement' => ['{"dependencies":{"one":"../one"}}', '{"lockfileVersion":3,"packages":{"":{}}}'],
        'file distribution' => ['{"dependencies":{"one":"*"}}', '{"lockfileVersion":3,"packages":{"":{"dependencies":{"one":"*"}},"node_modules/one":{"version":"1","resolved":"file:../one"}}}'],
    ]);
});
