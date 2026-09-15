<?php

declare(strict_types=1);

use App\Actions\AppInstances\Dependencies\ReadComposerDependencyGraphAction;
use App\Domain\AppInstances\Dependencies\DependencyEcosystem;
use App\Domain\AppInstances\Dependencies\DependencyParseException;
use App\Domain\AppInstances\Dependencies\DependencyRequirement;
use App\Domain\AppInstances\Dependencies\DependencyRequirementKind;
use App\Domain\AppInstances\Dependencies\DependencyResolution;
use App\Domain\AppInstances\Dependencies\DependencyScope;

/** @return array{string, string} */
function composerReaderFixture(string $format): array
{
    $path = dirname(__DIR__, 4).'/Fixtures/Dependencies/Composer/'.$format;

    return [file_get_contents($path.'-manifest.json'), file_get_contents($path.'-lock.json')];
}

describe('Composer dependency reader', function (): void {
    it('preserves direct transitive and development paths through cycles and aliases', function (): void {
        [$manifest, $lock] = composerReaderFixture('composer2');

        $graph = (new ReadComposerDependencyGraphAction)->execute($manifest, $lock);

        expect($graph->ecosystem)->toBe(DependencyEcosystem::Composer);
        expect(array_map(fn (DependencyResolution $resolution): array => [
            $resolution->id, $resolution->package->name, $resolution->version, $resolution->regular, $resolution->development,
        ], $graph->resolutions))->toBe([
            ['sample/app', 'sample/app', 'v2.1.0', true, false],
            ['sample/cycle', 'sample/cycle', '1.0.0', true, true],
            ['sample/logger', 'sample/logger', '3.0.1', true, false],
            ['sample/shared', 'sample/shared', 'dev-main', true, true],
            ['sample/tool', 'sample/tool', '1.4.0', false, true],
        ]);
        expect($graph->requirements)->toContainEqual(
            new DependencyRequirement(null, 'sample/shared', 'sample/shared', 'dev-main#abc123 as 1.0.x-dev', DependencyRequirementKind::Dependency, DependencyScope::Regular),
            new DependencyRequirement(null, 'sample/shared', 'sample/shared', '^1.0@dev', DependencyRequirementKind::Dependency, DependencyScope::Development),
            new DependencyRequirement('sample/app', 'sample/shared', 'sample/shared', '^1.0@dev', DependencyRequirementKind::Dependency, DependencyScope::Regular),
            new DependencyRequirement('sample/tool', 'sample/shared', 'sample/shared', '^1.0@dev', DependencyRequirementKind::Dependency, DependencyScope::Regular),
            new DependencyRequirement(null, 'sample/logger', 'psr/log-implementation', '^3.0', DependencyRequirementKind::Dependency, DependencyScope::Regular),
        );
        expect($graph->resolutions[3]->sourceReference)->toBe('abc123');
        expect($graph->resolutions[3]->integrity)->toBe('0123456789abcdef0123456789abcdef01234567');
        expect(array_column($graph->requirements, 'name'))->not->toContain('sample/uninstalled-test-tool');
    });

    it('keeps platform requirements out of the catalog', function (): void {
        [$manifest, $lock] = composerReaderFixture('composer2');

        $graph = (new ReadComposerDependencyGraphAction)->execute($manifest, $lock);

        $unresolved = array_values(array_filter($graph->requirements, fn (DependencyRequirement $edge): bool => $edge->to === null));
        expect(array_column($unresolved, 'name'))->toBe(['php', 'lib-libxml', 'ext-json', 'composer-plugin-api', 'composer-runtime-api']);
        expect(array_map(fn (DependencyResolution $resolution): string => $resolution->package->name, $graph->resolutions))
            ->not->toContain('php', 'lib-libxml', 'ext-json', 'composer-plugin-api', 'composer-runtime-api');
    });

    it('reads Composer 1 replacements root providers metapackages and distribution references', function (): void {
        [$manifest, $lock] = composerReaderFixture('composer1');

        $graph = (new ReadComposerDependencyGraphAction)->execute($manifest, $lock);

        expect($graph->requirements)->toContainEqual(
            new DependencyRequirement(null, 'sample/bundle', 'sample/bundle', '^1.0', DependencyRequirementKind::Dependency, DependencyScope::Regular),
            new DependencyRequirement(null, 'sample/bundle', 'sample/old', '^1.0', DependencyRequirementKind::Dependency, DependencyScope::Regular),
            new DependencyRequirement('sample/bundle', null, 'sample/root-api', '^1.0', DependencyRequirementKind::Dependency, DependencyScope::Regular),
        );
        expect($graph->resolutions[0]->sourceReference)->toBeNull();
        expect($graph->resolutions[1]->version)->toBe('dev-feature/inventory');
        expect($graph->resolutions[1]->sourceReference)->toBe('refs/heads/feature/inventory');
        expect($graph->resolutions[1]->integrity)->toBeNull();
        expect($graph->resolutions[1]->regular)->toBeFalse();
        expect($graph->resolutions[1]->development)->toBeTrue();
    });

    it('preserves a development dependency on the root package without an explicit root provider', function (): void {
        $manifest = '{"name":"Sample/Root","version":"1.0.0","require-dev":{"sample/tool":"^1.0"}}';
        $lock = '{"packages":[],"packages-dev":[{"name":"sample/tool","version":"1.0.0","require":{"sample/root":"^1.0"}}],"aliases":[]}';

        $graph = (new ReadComposerDependencyGraphAction)->execute($manifest, $lock);

        expect($graph->resolutions)->toHaveCount(1);
        expect($graph->resolutions[0]->package->name)->toBe('sample/tool');
        expect($graph->resolutions[0]->regular)->toBeFalse();
        expect($graph->resolutions[0]->development)->toBeTrue();
        expect($graph->requirements)->toEqual([
            new DependencyRequirement(null, 'sample/tool', 'sample/tool', '^1.0', DependencyRequirementKind::Dependency, DependencyScope::Development),
            new DependencyRequirement('sample/tool', null, 'sample/root', '^1.0', DependencyRequirementKind::Dependency, DependencyScope::Regular),
        ]);
    });

    it('accepts named and anonymous repository-disable entries', function (string $repositories): void {
        $graph = (new ReadComposerDependencyGraphAction)->execute(
            '{"repositories":'.$repositories.'}',
            '{"packages":[],"packages-dev":[]}',
        );

        expect($graph->resolutions)->toBe([]);
        expect($graph->requirements)->toBe([]);
    })->with([
        'named' => '{"packagist.org":false}',
        'anonymous' => '[{"packagist.org":false}]',
    ]);

    it('returns a deterministic graph regardless of record or map ordering without exposing executable metadata', function (): void {
        [$manifest, $lock] = composerReaderFixture('composer2');
        $reordered = json_decode($lock, flags: JSON_THROW_ON_ERROR);
        $reordered->packages = array_reverse($reordered->packages);
        $root = json_decode($manifest, flags: JSON_THROW_ON_ERROR);
        $root->require = (object) array_reverse(get_object_vars($root->require), true);
        $reader = new ReadComposerDependencyGraphAction;

        $graph = $reader->execute($manifest, $lock);
        $again = $reader->execute(json_encode($root, JSON_THROW_ON_ERROR), json_encode($reordered, JSON_THROW_ON_ERROR));

        expect($again)->toEqual($graph);
        expect(json_encode($graph, JSON_THROW_ON_ERROR))->not->toContain('fixture-secret', 'fixture-user', '127.0.0.1', 'MustNeverLoad', 'must-not-load.php', 'post-install-cmd');
    });

    it('returns a present empty graph for an empty locked project', function (): void {
        $graph = (new ReadComposerDependencyGraphAction)->execute('{}', '{"packages": [], "packages-dev": []}');

        expect($graph->resolutions)->toBe([]);
        expect($graph->requirements)->toBe([]);
    });

    it('canonicalizes supported Composer package names with consecutive hyphens', function (): void {
        $graph = (new ReadComposerDependencyGraphAction)->execute(
            '{"require":{"Sample/My--Package":"*"}}',
            '{"packages":[{"name":"Sample/My--Package","version":"1.0"}],"packages-dev":[]}',
        );

        expect($graph->resolutions[0]->package->name)->toBe('sample/my--package');
        expect($graph->requirements[0]->name)->toBe('sample/my--package');
        expect($graph->requirements[0]->to)->toBe($graph->resolutions[0]->id);
    });

    it('does not add a second resolution for a normalized numeric lock alias', function (): void {
        $manifest = '{"require":{"sample/lib":"1.0 as 2.0"}}';
        $lock = '{"packages":[{"name":"sample/lib","version":"1.0"}],"packages-dev":[],"aliases":[{"package":"sample/lib","version":"1.0.0.0","alias":"2.0","alias_normalized":"2.0.0.0"}]}';

        $graph = (new ReadComposerDependencyGraphAction)->execute($manifest, $lock);

        expect($graph->resolutions)->toHaveCount(1);
        expect($graph->resolutions[0]->version)->toBe('1.0');
        expect($graph->requirements[0]->constraint)->toBe('1.0 as 2.0');
    });

    it('rejects invalid source without exposing it in the error or its cause', function (string $manifest, string $lock): void {
        try {
            (new ReadComposerDependencyGraphAction)->execute($manifest, $lock);
            test()->fail('Expected invalid Composer input to fail.');
        } catch (DependencyParseException $exception) {
            expect($exception->errorCode)->toBe('dependencies.invalid_composer_input');
            expect($exception->getMessage())->toBe('dependencies.invalid_composer_input');
            expect($exception->getPrevious())->toBeNull();
        }
    })->with([
        'malformed manifest' => ['{"secret":', '{"packages":[],"packages-dev":[]}'],
        'malformed lock' => ['{}', '{"secret":'],
        'manifest list' => ['[]', '{"packages":[],"packages-dev":[]}'],
        'lock scalar' => ['{}', 'false'],
        'missing packages' => ['{}', '{"packages-dev":[]}'],
        'missing development packages' => ['{}', '{"packages":[]}'],
        'nonlist packages' => ['{}', '{"packages":{},"packages-dev":[]}'],
        'null development packages' => ['{}', '{"packages":[],"packages-dev":null}'],
        'nonobject package' => ['{}', '{"packages":[false],"packages-dev":[]}'],
        'missing package version' => ['{"require":{"sample/lib":"*"}}', '{"packages":[{"name":"sample/lib"}],"packages-dev":[]}'],
        'platform package' => ['{"require":{"php":"*"}}', '{"packages":[{"name":"php","version":"8.5"}],"packages-dev":[]}'],
        'missing root target' => ['{"require":{"sample/missing":"*"}}', '{"packages":[],"packages-dev":[]}'],
        'missing transitive target' => ['{"require":{"sample/lib":"*"}}', '{"packages":[{"name":"sample/lib","version":"1","require":{"sample/missing":"*"}}],"packages-dev":[]}'],
        'unreachable package' => ['{}', '{"packages":[{"name":"sample/lib","version":"1"}],"packages-dev":[]}'],
        'duplicate package name across scopes' => ['{"require":{"sample/lib":"*"}}', '{"packages":[{"name":"sample/lib","version":"1"}],"packages-dev":[{"name":"Sample/Lib","version":"2"}]}'],
        'nonobject requirements' => ['{"require":[]}', '{"packages":[],"packages-dev":[]}'],
        'null requirements' => ['{"require":null}', '{"packages":[],"packages-dev":[]}'],
        'nonstring constraint' => ['{"require":{"php":8}}', '{"packages":[],"packages-dev":[]}'],
        'numeric requirement name' => ['{"require":{"123":"*"}}', '{"packages":[],"packages-dev":[]}'],
        'invalid root name' => ['{"name":"invalid"}', '{"packages":[],"packages-dev":[]}'],
        'null root name' => ['{"name":null}', '{"packages":[],"packages-dev":[]}'],
        'invalid constraint' => ['{"require":{"php":"not a constraint"}}', '{"packages":[],"packages-dev":[]}'],
        'credential constraint' => ['{"require":{"php":"https://user:secret@example.test"}}', '{"packages":[],"packages-dev":[]}'],
        'missing alias target' => ['{}', '{"packages":[],"packages-dev":[],"aliases":[{"package":"sample/missing","version":"dev-main","alias":"1.x-dev","alias_normalized":"1.9999999.9999999.9999999-dev"}]}'],
        'malformed aliases' => ['{}', '{"packages":[],"packages-dev":[],"aliases":{}}'],
        'null aliases' => ['{}', '{"packages":[],"packages-dev":[],"aliases":null}'],
        'duplicate root section' => ['{"require":{"sample/missing":"*"},"require":{}}', '{"packages":[],"packages-dev":[]}'],
        'duplicate lock section' => ['{}', '{"packages":[{"name":"sample/lib","version":"1"}],"packages":[],"packages-dev":[]}'],
        'duplicate package property' => ['{"require":{"sample/lib":"*"}}', '{"packages":[{"name":"sample/lib","version":"1","version":"2"}],"packages-dev":[]}'],
        'duplicate escaped key' => ['{"require":{"php":"8", "ph\\u0070":"9"}}', '{"packages":[],"packages-dev":[]}'],
        'invalid repository record' => ['{"repositories":[true]}', '{"packages":[],"packages-dev":[]}'],
        'anonymous repository enabled without type' => ['{"repositories":[{"packagist.org":true}]}', '{"packages":[],"packages-dev":[]}'],
        'anonymous repository with multiple disable keys' => ['{"repositories":[{"packagist.org":false,"other":false}]}', '{"packages":[],"packages-dev":[]}'],
    ]);

    it('rejects malformed or unsafe locked metadata', function (Closure $change): void {
        [$manifest, $contents] = composerReaderFixture('composer2');
        $lock = json_decode($contents, flags: JSON_THROW_ON_ERROR);
        $change($lock);

        expect(fn () => (new ReadComposerDependencyGraphAction)->execute($manifest, json_encode($lock, JSON_THROW_ON_ERROR)))
            ->toThrow(DependencyParseException::class, 'dependencies.invalid_composer_input');
    })->with([
        'alias version differs' => [fn (stdClass $lock) => $lock->aliases[0]->version = 'dev-other'],
        'alias normalization differs' => [fn (stdClass $lock) => $lock->aliases[0]->alias_normalized = '2.0.0.0'],
        'source is a list' => [fn (stdClass $lock) => $lock->packages[0]->source = []],
        'credential source reference' => [fn (stdClass $lock) => $lock->packages[0]->source->reference = 'https://user:secret@example.test'],
        'credential distribution reference' => [fn (stdClass $lock) => $lock->packages[0]->dist->reference = 'user@host'],
        'nonstring source reference' => [fn (stdClass $lock) => $lock->packages[0]->source->reference = 123],
        'nonstring version' => [fn (stdClass $lock) => $lock->packages[0]->version = 123],
        'credential version' => [fn (stdClass $lock) => $lock->packages[0]->version = 'https://user:secret@example.test'],
        'control character version' => [fn (stdClass $lock) => $lock->packages[0]->version = "dev-main\nsecret"],
        'unsafe checksum' => [fn (stdClass $lock) => $lock->packages[0]->dist->shasum = 'secret@example.test'],
        'nonobject providers' => [fn (stdClass $lock) => $lock->packages[3]->provide = []],
        'ambiguous virtual providers' => [fn (stdClass $lock) => $lock->packages[1]->provide = (object) ['psr/log-implementation' => '3.0']],
    ]);

    it('rejects unsupported local layouts', function (string $manifest, string $lock): void {
        expect(fn () => (new ReadComposerDependencyGraphAction)->execute($manifest, $lock))
            ->toThrow(DependencyParseException::class, 'dependencies.unsupported_layout');
    })->with([
        'root workspace' => ['{"workspaces":["packages/*"]}', '{"packages":[],"packages-dev":[]}'],
        'path repository' => ['{"repositories":[{"type":"path","url":"../other"}]}', '{"packages":[],"packages-dev":[]}'],
        'named path repository' => ['{"repositories":{"local":{"type":"path","url":"../other"}}}', '{"packages":[],"packages-dev":[]}'],
        'path after anonymous disable entry' => ['{"repositories":[{"packagist.org":false},{"type":"path","url":"../other"}]}', '{"packages":[],"packages-dev":[]}'],
        'locked path distribution' => ['{"require":{"sample/lib":"*"}}', '{"packages":[{"name":"sample/lib","version":"1","dist":{"type":"path","url":"../other"}}],"packages-dev":[]}'],
    ]);
});
