<?php

declare(strict_types=1);

use App\Actions\AppInstances\Dependencies\ReadYarnClassicDependencyGraphAction;
use App\Domain\AppInstances\Dependencies\DependencyEcosystem;
use App\Domain\AppInstances\Dependencies\DependencyParseException;
use App\Domain\AppInstances\Dependencies\DependencyRequirement;
use App\Domain\AppInstances\Dependencies\DependencyRequirementKind;
use App\Domain\AppInstances\Dependencies\DependencyScope;

/** @return array{string, string} */
function yarnClassicInputs(): array
{
    $directory = dirname(__DIR__, 4).'/Fixtures/Dependencies/YarnClassic/';

    return [file_get_contents($directory.'manifest.json'), file_get_contents($directory.'yarn.lock')];
}

describe('Yarn Classic dependency reader', function (): void {
    it('preserves grouped selectors, multiple versions, cycles and independent root scopes', function (): void {
        [$manifest, $lock] = yarnClassicInputs();

        $graph = (new ReadYarnClassicDependencyGraphAction)->execute($manifest, $lock);
        $packages = [];
        foreach ($graph->resolutions as $resolution) {
            $packages[$resolution->package->name.'@'.$resolution->version] = $resolution;
        }
        $shared = $packages['shared@1.2.3']->id;
        $app = $packages['app@1.0.0']->id;
        $tool = $packages['tool@1.0.0']->id;

        expect($graph->ecosystem)->toBe(DependencyEcosystem::Npm);
        expect($graph->resolutions)->toHaveCount(7);
        expect($graph->requirements)->toHaveCount(14);
        expect($packages['shared@1.2.3']->regular)->toBeTrue();
        expect($packages['shared@1.2.3']->development)->toBeTrue();
        expect($packages['shared@2.0.0']->regular)->toBeFalse();
        expect($packages['shared@2.0.0']->development)->toBeTrue();
        expect($packages['cycle@1.0.0']->development)->toBeTrue();
        expect($packages['native@1.0.0']->development)->toBeTrue();
        expect($packages['@scope/real@3.1.0']->sourceReference)->toBe('abcdef1234567');
        expect($packages['@scope/real@3.1.0']->integrity)->toBe('sha512-YWJjZA==');
        expect($graph->requirements)->toContainEqual(
            new DependencyRequirement(null, $shared, 'shared', '^1', DependencyRequirementKind::Dependency, DependencyScope::Regular),
            new DependencyRequirement(null, $shared, 'shared', '~1.2', DependencyRequirementKind::Dependency, DependencyScope::Development),
            new DependencyRequirement($app, $shared, 'shared', '~1.2', DependencyRequirementKind::Dependency, DependencyScope::Regular),
            new DependencyRequirement($tool, $packages['shared@2.0.0']->id, 'shared', '^2', DependencyRequirementKind::Dependency, DependencyScope::Regular),
            new DependencyRequirement(null, null, 'host', '^2', DependencyRequirementKind::Peer, DependencyScope::Regular, true),
            new DependencyRequirement(null, null, 'unavailable', '^1', DependencyRequirementKind::Dependency, DependencyScope::Regular, true),
        );
        expect(json_encode($graph, JSON_THROW_ON_ERROR))->not->toContain('fixture-user', 'fixture-secret', '127.0.0.1', 'must-never-execute', 'ignored-prebuilt-metadata', 'build');
    });

    it('reads name-only selectors while preserving root identities and declaration scopes', function (string $name, string $header, string $section): void {
        $manifest = json_encode([$section => [$name => '^1'], 'scripts' => ['install' => 'must-never-execute']], JSON_THROW_ON_ERROR);
        $lock = "# yarn lockfile v1\n".$header.":\n  version \"1.0.0\"\n";
        $scope = $section === 'devDependencies' ? DependencyScope::Development : DependencyScope::Regular;

        $graph = (new ReadYarnClassicDependencyGraphAction)->execute($manifest, $lock);

        expect($graph->resolutions)->toHaveCount(1);
        expect($graph->resolutions[0]->package->name)->toBe($name);
        expect($graph->resolutions[0]->version)->toBe('1.0.0');
        expect($graph->resolutions[0]->regular)->toBe($scope === DependencyScope::Regular);
        expect($graph->resolutions[0]->development)->toBe($scope === DependencyScope::Development);
        expect($graph->requirements)->toEqual([
            new DependencyRequirement(null, $graph->resolutions[0]->id, $name, '^1', DependencyRequirementKind::Dependency, $scope, $section === 'optionalDependencies'),
        ]);
        expect(json_encode($graph, JSON_THROW_ON_ERROR))->not->toContain('must-never-execute');
    })->with([
        'bare regular key' => ['one', 'one', 'dependencies'],
        'scoped development key' => ['@scope/one', '"@scope/one"', 'devDependencies'],
        'bare optional key' => ['one', 'one', 'optionalDependencies'],
        'grouped bare and ranged keys' => ['one', 'one, one@^1', 'dependencies'],
        'grouped scoped keys' => ['@scope/one', '"@scope/one@^1", "@scope/one"', 'devDependencies'],
    ]);

    it('prefers a root name-only key while transitive requirements select a competing ranged key', function (): void {
        $manifest = '{"dependencies":{"one":"^1"},"devDependencies":{"tool":"^1"}}';
        $lock = <<<'LOCK'
# yarn lockfile v1
one:
  version "2.0.0"
one@^1:
  version "1.0.0"
tool@^1:
  version "1.0.0"
  dependencies:
    one "^1"
LOCK;

        $graph = (new ReadYarnClassicDependencyGraphAction)->execute($manifest, $lock);
        $packages = [];
        foreach ($graph->resolutions as $resolution) {
            $packages[$resolution->package->name.'@'.$resolution->version] = $resolution;
        }

        expect($graph->resolutions)->toHaveCount(3);
        expect($packages['one@2.0.0']->regular)->toBeTrue();
        expect($packages['one@2.0.0']->development)->toBeFalse();
        expect($packages['one@1.0.0']->regular)->toBeFalse();
        expect($packages['one@1.0.0']->development)->toBeTrue();
        expect($graph->requirements)->toEqual([
            new DependencyRequirement(null, $packages['one@2.0.0']->id, 'one', '^1', DependencyRequirementKind::Dependency, DependencyScope::Regular),
            new DependencyRequirement(null, $packages['tool@1.0.0']->id, 'tool', '^1', DependencyRequirementKind::Dependency, DependencyScope::Development),
            new DependencyRequirement($packages['tool@1.0.0']->id, $packages['one@1.0.0']->id, 'one', '^1', DependencyRequirementKind::Dependency, DependencyScope::Regular),
        ]);
    });

    it('links grouped bare and ranged selectors to one shared resolution', function (): void {
        $manifest = '{"dependencies":{"one":"^9"},"devDependencies":{"tool":"^1"}}';
        $lock = "one, one@^1:\n  version \"1.0.0\"\ntool@^1:\n  version \"1.0.0\"\n  dependencies:\n    one \"^1\"\n";

        $graph = (new ReadYarnClassicDependencyGraphAction)->execute($manifest, $lock);

        expect($graph->resolutions)->toHaveCount(2);
        expect($graph->requirements[0]->constraint)->toBe('^9');
        expect($graph->requirements[2]->constraint)->toBe('^1');
        expect($graph->requirements[0]->to)->toBe($graph->requirements[2]->to);
        $one = array_values(array_filter($graph->resolutions, fn ($resolution) => $resolution->package->name === 'one'))[0];
        expect($one->regular)->toBeTrue();
        expect($one->development)->toBeTrue();
    });

    it('does not use a name-only fallback for missing required transitive selectors', function (): void {
        $manifest = '{"dependencies":{"one":"^1","tool":"^1"}}';
        $lock = "one:\n  version \"1.0.0\"\ntool@^1:\n  version \"1.0.0\"\n  dependencies:\n    one \"^1\"\n";

        expect(fn () => (new ReadYarnClassicDependencyGraphAction)->execute($manifest, $lock))
            ->toThrow(DependencyParseException::class, 'dependencies.invalid_yarn_classic_input');
    });

    it('keeps missing optional transitive selectors unresolved when a name-only root key exists', function (): void {
        $manifest = '{"dependencies":{"one":"^1","tool":"^1"}}';
        $lock = "one:\n  version \"1.0.0\"\ntool@^1:\n  version \"1.0.0\"\n  optionalDependencies:\n    one \"^1\"\n";

        $graph = (new ReadYarnClassicDependencyGraphAction)->execute($manifest, $lock);

        expect($graph->resolutions)->toHaveCount(2);
        expect($graph->requirements[0]->to)->not->toBeNull();
        expect($graph->requirements[2])->toEqual(new DependencyRequirement($graph->requirements[1]->to, null, 'one', '^1', DependencyRequirementKind::Dependency, DependencyScope::Regular, true));
    });

    it('returns deterministic graphs across record, selector and manifest order and CRLF', function (): void {
        [$manifest, $lock] = yarnClassicInputs();
        $reader = new ReadYarnClassicDependencyGraphAction;
        $blocks = explode("\n\n", trim($lock));
        $header = array_shift($blocks);
        $reordered = $header."\n\n".implode("\n\n", array_reverse($blocks))."\n";
        $reordered = str_replace('shared@^1, "shared@~1.2"', '"shared@~1.2", shared@^1', $reordered);
        $root = json_decode($manifest, flags: JSON_THROW_ON_ERROR);
        $root->dependencies = (object) array_reverse(get_object_vars($root->dependencies), true);

        $graph = $reader->execute($manifest, $lock);
        $again = $reader->execute(json_encode($root, JSON_THROW_ON_ERROR), "\xEF\xBB\xBF".str_replace("\n", "\r\n", $reordered));

        expect($again)->toEqual($graph);
    });

    it('keeps same-version resolutions from separate selector groups distinct', function (): void {
        $manifest = '{"dependencies":{"app":"^1"},"devDependencies":{"tool":"^1"}}';
        $lock = "app@^1:\n  version \"1.0.0\"\n  dependencies:\n    one \"^1\"\ntool@^1:\n  version \"1.0.0\"\n  dependencies:\n    one \"~1\"\none@^1:\n  version \"1.0.0\"\none@~1:\n  version \"1.0.0\"\n";

        $graph = (new ReadYarnClassicDependencyGraphAction)->execute($manifest, $lock);
        $ones = array_values(array_filter($graph->resolutions, fn ($resolution) => $resolution->package->name === 'one'));

        expect($ones)->toHaveCount(2);
        expect(array_column($ones, 'version'))->toBe(['1.0.0', '1.0.0']);
        expect($ones[0]->id)->not->toBe($ones[1]->id);
        expect($ones[0]->regular)->not->toBe($ones[1]->regular);
        expect($ones[0]->development)->not->toBe($ones[1]->development);
    });

    it('uses Classic root normalization while preserving duplicate development declarations', function (string $manifest, string $selected): void {
        $lock = '"one@'.$selected.'":'."\n  version \"1.0.0\"\n";

        $graph = (new ReadYarnClassicDependencyGraphAction)->execute($manifest, $lock);

        expect($graph->resolutions)->toHaveCount(1);
        expect($graph->resolutions[0]->regular)->toBeTrue();
        expect($graph->resolutions[0]->development)->toBeTrue();
        expect($graph->requirements)->toHaveCount(2);
        expect($graph->requirements[0]->to)->toBe($graph->requirements[1]->to);
        expect($graph->requirements[1]->scope)->toBe(DependencyScope::Development);
    })->with([
        'regular before development' => ['{"dependencies":{"one":"^1"},"devDependencies":{"one":"^2"}}', '^1'],
        'optional before development' => ['{"optionalDependencies":{"one":"^1"},"devDependencies":{"one":"^2"}}', '^1'],
        'nontrivial development reference' => ['{"dependencies":{"one":"*"},"devDependencies":{"one":"^2"}}', '^2'],
        'nontrivial regular reference' => ['{"optionalDependencies":{"one":"*"},"dependencies":{"one":"^2"},"devDependencies":{"one":"^3"}}', '^2'],
        'empty regular reference' => ['{"dependencies":{"one":""},"devDependencies":{"one":"^2"}}', '^2'],
    ]);

    it('retains remote and hosted Git references without treating them as versions or executing code', function (string $reference): void {
        $manifest = json_encode(['dependencies' => ['one' => $reference], 'scripts' => ['install' => 'must-never-execute']], JSON_THROW_ON_ERROR);
        $lock = json_encode('one@'.$reference, JSON_THROW_ON_ERROR).":\n  version \"release-opaque\"\n  resolved ".json_encode($reference, JSON_THROW_ON_ERROR)."\n";

        $graph = (new ReadYarnClassicDependencyGraphAction)->execute($manifest, $lock);

        expect($graph->resolutions[0]->package->name)->toBe('one');
        expect($graph->resolutions[0]->version)->toBe('release-opaque');
        expect($graph->requirements[0]->constraint)->toStartWith('yarn-classic:sha256:');
        expect(json_encode($graph, JSON_THROW_ON_ERROR))->not->toContain($reference, 'fixture-secret', 'must-never-execute');
    })->with([
        'tarball' => 'https://example.test/one.tgz',
        'authenticated tarball' => 'http://fixture-user:fixture-secret@example.test/one.tgz?token=fixture-secret',
        'git HTTPS' => 'git+https://example.test/repo.git#abcdef1234567',
        'git SSH' => 'git+ssh://git@example.test/repo.git#abcdef1234567',
        'git protocol' => 'git://example.test/repo.git#main',
        'scp Git' => 'git@example.test:repo.git#main',
        'GitHub' => 'github:owner/repo#main',
        'GitLab' => 'gitlab:owner/repo#main',
        'Bitbucket' => 'bitbucket:owner/repo#main',
        'gist' => 'gist:abcdef1234567',
        'shorthand' => 'owner/repo#main',
        'comma inside quoted reference' => 'https://example.test/one.tgz?key=a,b',
    ]);

    it('uses explicit locked names for non-registry aliases and preserves exact transitive links', function (): void {
        $url = 'https://example.test/one.tgz';
        $manifest = json_encode(['dependencies' => ['app' => '^1']], JSON_THROW_ON_ERROR);
        $lock = "app@^1:\n  version \"1.0.0\"\n  dependencies:\n    renamed \"$url\"\n\"renamed@$url\":\n  name actual\n  version \"2.0.0\"\n";

        $graph = (new ReadYarnClassicDependencyGraphAction)->execute($manifest, $lock);
        $names = array_column(array_column($graph->resolutions, 'package'), 'name');
        $target = $graph->resolutions[array_search('actual', $names, true)]->id;

        expect($graph->requirements[1]->name)->toBe('renamed');
        expect($graph->requirements[1]->to)->toBe($target);
        expect($names)->toContain('actual')->not->toContain('renamed');
    });

    it('keeps same-version remote sources distinct and resolves transitive npm aliases', function (): void {
        $manifest = '{"dependencies":{"app":"^1"}}';
        $lock = <<<'LOCK'
app@^1:
  version "1.0.0"
  dependencies:
    one "https://fixture-user:fixture-secret@example.test/one.tgz"
    two "https://example.test/two.tgz?token=fixture-secret"
    alias "npm:@scope/actual@^1"
"one@https://fixture-user:fixture-secret@example.test/one.tgz":
  name actual
  version "1.0.0"
"two@https://example.test/two.tgz?token=fixture-secret":
  name actual
  version "1.0.0"
"alias@npm:@scope/actual@^1":
  version "1.0.0"
LOCK;

        $graph = (new ReadYarnClassicDependencyGraphAction)->execute($manifest, $lock);
        $remote = array_values(array_filter($graph->resolutions, fn ($resolution) => $resolution->package->name === 'actual'));
        $aliases = array_values(array_filter($graph->resolutions, fn ($resolution) => $resolution->package->name === '@scope/actual'));
        $edges = array_column($graph->requirements, null, 'name');

        expect($remote)->toHaveCount(2);
        expect(array_column($remote, 'version'))->toBe(['1.0.0', '1.0.0']);
        expect($edges['one']->to)->not->toBe($edges['two']->to);
        expect($edges['one']->constraint)->not->toBe($edges['two']->constraint);
        expect($edges['alias']->to)->toBe($aliases[0]->id);
        expect($edges['alias']->constraint)->toBe('npm:@scope/actual@^1');
        expect(json_encode($graph, JSON_THROW_ON_ERROR))->not->toContain('https:', 'example.test', 'fixture-user', 'fixture-secret');
    });

    it('preserves missing optional targets after Classic root normalization', function (): void {
        $graph = (new ReadYarnClassicDependencyGraphAction)->execute(
            '{"optionalDependencies":{"one":"^1"},"devDependencies":{"one":"^2"}}',
            "# yarn lockfile v1\n",
        );

        expect($graph->resolutions)->toBe([]);
        expect(array_column($graph->requirements, 'to'))->toBe([null, null]);
        expect(array_column($graph->requirements, 'constraint'))->toBe(['^1', '^2']);
        expect(array_column($graph->requirements, 'scope'))->toBe([DependencyScope::Regular, DependencyScope::Development]);
    });

    it('retains root peers as unresolved even when a matching package is also a dependency', function (): void {
        $graph = (new ReadYarnClassicDependencyGraphAction)->execute(
            '{"dependencies":{"host":"^1"},"peerDependencies":{"host":"^1"}}',
            "host@^1:\n  version \"1.0.0\"\n",
        );

        expect($graph->requirements[0]->to)->not->toBeNull();
        expect($graph->requirements[1])->toEqual(new DependencyRequirement(null, null, 'host', '^1', DependencyRequirementKind::Peer, DependencyScope::Regular));
    });

    it('lets optional declarations override regular duplicates while retaining development paths', function (): void {
        $graph = (new ReadYarnClassicDependencyGraphAction)->execute(
            '{"dependencies":{"one":"^9"},"optionalDependencies":{"one":"^1"},"devDependencies":{"one":"^1"}}',
            "one@^1:\n  version \"1.0.0\"\n",
        );

        expect($graph->requirements)->toHaveCount(2);
        expect($graph->requirements[0]->optional)->toBeTrue();
        expect($graph->requirements[1]->scope)->toBe(DependencyScope::Development);
        expect($graph->resolutions[0]->regular)->toBeTrue();
        expect($graph->resolutions[0]->development)->toBeTrue();
    });

    it('accepts empty v1 projects and retains unresolved optional and peer declarations', function (): void {
        $reader = new ReadYarnClassicDependencyGraphAction;

        $empty = $reader->execute('{}', "# yarn lockfile v1\n");
        $declared = $reader->execute('{"optionalDependencies":{"one":"*"},"peerDependencies":{"two":"*"}}', "# yarn lockfile v1\n");

        expect($empty->resolutions)->toBe([]);
        expect($empty->requirements)->toBe([]);
        expect($declared->resolutions)->toBe([]);
        expect(array_column($declared->requirements, 'to'))->toBe([null, null]);
    });

    it('accepts quoted numeric and mixed-case names with exact numeric-looking constraints', function (): void {
        $graph = (new ReadYarnClassicDependencyGraphAction)->execute(
            '{"dependencies":{"123":"1.0","JSONStream":"*"}}',
            "\"123@1.0\":\n  version \"1.0.0\"\nJSONStream@*:\n  version \"1.0.0\"\n",
        );

        expect(array_column($graph->requirements, 'name'))->toBe(['123', 'JSONStream']);
        expect($graph->requirements[0]->constraint)->toBe('1.0');
    });

    it('rejects malformed syntax and incomplete graphs with only a stable error code', function (string $manifest, string $lock): void {
        try {
            (new ReadYarnClassicDependencyGraphAction)->execute($manifest, $lock);
            test()->fail('Invalid input was accepted.');
        } catch (DependencyParseException $exception) {
            expect($exception->getMessage())->toBe('dependencies.invalid_yarn_classic_input');
            expect($exception->getPrevious())->toBeNull();
        }
    })->with([
        'malformed JSON' => ['{', '# yarn lockfile v1'],
        'manifest list' => ['[]', '# yarn lockfile v1'],
        'duplicate manifest key' => ['{"dependencies":{},"\u0064ependencies":{}}', '# yarn lockfile v1'],
        'null dependencies' => ['{"dependencies":null}', '# yarn lockfile v1'],
        'numeric constraint' => ['{"dependencies":{"one":1}}', '# yarn lockfile v1'],
        'missing root target' => ['{"dependencies":{"one":"^1"}}', '# yarn lockfile v1'],
        'missing dev target' => ['{"devDependencies":{"one":"^1"}}', '# yarn lockfile v1'],
        'missing transitive target' => ['{"dependencies":{"one":"^1"}}', "one@^1:\n  version \"1.0.0\"\n  dependencies:\n    two \"^1\""],
        'unreachable record' => ['{}', "one@^1:\n  version \"1.0.0\""],
        'empty input' => ['{}', ''],
        'comment only' => ['{}', '# ordinary comment'],
        'duplicate selector' => ['{}', "one@^1:\n  version \"1\"\none@^1:\n  version \"2\""],
        'duplicate grouped selector' => ['{}', "one@^1, one@^1:\n  version \"1\""],
        'escaped duplicate selector' => ['{}', "one@^1, \"one@\\u005e1\":\n  version \"1\""],
        'missing colon' => ['{}', "one@^1\n  version \"1\""],
        'trailing comma' => ['{}', "one@^1,\n  version \"1\""],
        'extra separator' => ['{}', "one@^1:,\n  version \"1\""],
        'unterminated quote' => ['{}', "\"one@^1:\n  version \"1\""],
        'bad escape' => ['{}', '"one@\x":'],
        'odd indent' => ['{}', "one@^1:\n   version \"1\""],
        'tab indent' => ['{}', "one@^1:\n\tversion \"1\""],
        'orphan field' => ['{}', '  version "1"'],
        'orphan nested field' => ['{}', "one@^1:\n    two \"1\""],
        'deep map' => ['{}', "one@^1:\n  dependencies:\n    two:\n      version \"1\""],
        'duplicate field' => ['{}', "one@^1:\n  version \"1\"\n  version \"1\""],
        'duplicate nested key' => ['{}', "one@^1:\n  dependencies:\n    two \"1\"\n    two \"2\""],
        'merge conflict' => ['{}', "<<<<<<< HEAD\none@^1:\n=======\n>>>>>>> other"],
        'YAML object tag' => ['{}', 'one@^1: !php/object fixture-secret'],
        'YAML alias' => ['{}', 'one@^1: *fixture-secret'],
        'YAML flow object' => ['{}', 'one@^1: {version: "1"}'],
        'numeric locked version' => ['{"dependencies":{"one":"^1"}}', "one@^1:\n  version 1"],
        'missing version' => ['{"dependencies":{"one":"^1"}}', 'one@^1:'],
        'mismatched identity group' => ['{"dependencies":{"one":"^1"}}', "one@^1, two@^1:\n  version \"1\""],
        'conflicting explicit name' => ['{"dependencies":{"one":"^1"}}', "one@^1:\n  name two\n  version \"1\""],
        'unsafe locked version' => ['{"dependencies":{"one":"^1"}}', "one@^1:\n  version \"https://fixture-secret@example.test\""],
        'invalid integrity' => ['{"dependencies":{"one":"^1"}}', "one@^1:\n  version \"1\"\n  integrity fixture-secret"],
        'invalid metadata map' => ['{"dependencies":{"one":"^1"}}', "one@^1:\n  version \"1\"\n  permissions false"],
        'unsupported record field' => ['{"dependencies":{"one":"^1"}}', "one@^1:\n  version \"1\"\n  peerDependencies:\n    host \"*\""],
        'orphan peer metadata' => ['{"peerDependenciesMeta":{"one":{"optional":true}}}', '# yarn lockfile v1'],
        'nonboolean optional peer' => ['{"peerDependencies":{"one":"*"},"peerDependenciesMeta":{"one":{"optional":"true"}}}', '# yarn lockfile v1'],
        'malformed remote URL' => ['{"dependencies":{"one":"https:///one.tgz"}}', '# yarn lockfile v1'],
        'credential shaped range' => ['{"dependencies":{"one":"fixture-user:fixture-secret"}}', '# yarn lockfile v1'],
        'alias missing identity' => ['{"dependencies":{"one":"npm:"}}', '# yarn lockfile v1'],
        'unsafe alias reference' => ['{"dependencies":{"one":"npm:two@https://fixture-secret@example.test"}}', '# yarn lockfile v1'],
    ]);

    it('rejects modern Yarn and unsupported format markers', function (string $lock): void {
        expect(fn () => (new ReadYarnClassicDependencyGraphAction)->execute('{}', $lock))
            ->toThrow(DependencyParseException::class, 'dependencies.unsupported_format');
    })->with(["# yarn lockfile v2\n", "__metadata:\n  version: 8\n", "# yarn lockfile v1\n\"__metadata\":\n  version: 6"]);

    it('rejects workspace and local layouts', function (string $manifest, string $lock): void {
        expect(fn () => (new ReadYarnClassicDependencyGraphAction)->execute($manifest, $lock))
            ->toThrow(DependencyParseException::class, 'dependencies.unsupported_layout');
    })->with([
        ['{"workspaces":[]}', '# yarn lockfile v1'],
        ['{"dependencies":{"one":"file:../one"}}', '# yarn lockfile v1'],
        ['{"dependencies":{"one":"link:../one"}}', '# yarn lockfile v1'],
        ['{"dependencies":{"one":"workspace:*"}}', '# yarn lockfile v1'],
        ['{"dependencies":{"one":"../one"}}', '# yarn lockfile v1'],
        ['{}', "\"one@file:../one\":\n  version \"1\""],
        ['{"dependencies":{"one":"^1"}}', "one@^1:\n  version \"1\"\n  resolved \"file:../one\""],
        ['{"dependencies":{"one":"^1"}}', "one@^1:\n  version \"1\"\n  dependencies:\n    two \"link:../two\""],
    ]);
});
