<?php

declare(strict_types=1);

use App\Domain\Instances\InstancePhpVersionCatalog;
use App\Domain\Nodes\RoleName;
use App\Infrastructure\MachineSoftware\ArtifactPin;
use App\Infrastructure\MachineSoftware\MachineRecipe;
use App\Infrastructure\MachineSoftware\RecipeLoader;
use App\Infrastructure\MachineSoftware\RecipeValidator;
use App\Infrastructure\MachineSoftware\SnapshotAvailability;
use App\Infrastructure\MachineSoftware\ToolPin;
use App\Infrastructure\Nodes\CaddyPackageProgram;
use App\Infrastructure\Nodes\NodeAgentFootprint;
use App\Infrastructure\Nodes\NodeBootstrapPackageCatalog;
use App\Infrastructure\Nodes\RemotePhpPackageManager;
use App\Infrastructure\TaskVms\TaskVmCloudInit;
use App\Models\Node;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

uses(TestCase::class);

function machine_recipe_document(): array
{
    return json_decode(file_get_contents(resource_path('machine-software/recipe.json')), true, flags: JSON_THROW_ON_ERROR);
}

function machine_recipe_decode(array $document): MachineRecipe
{
    return new RecipeLoader()->decode(json_encode($document, JSON_THROW_ON_ERROR));
}

function machine_recipe_snapshot_probe(?string $failure = null): SnapshotAvailability
{
    return new class($failure) implements SnapshotAvailability
    {
        public bool $called = false;

        public function __construct(private readonly ?string $failure) {}

        public function verify(MachineRecipe $recipe): void
        {
            $this->called = true;
            if ($this->failure !== null) {
                $document = json_decode($recipe->canonicalJson, true, flags: JSON_THROW_ON_ERROR);
                $release = $document['ubuntu']['release'];
                $architecture = $recipe->architectures[0];
                throw new RuntimeException("Snapshot [{$recipe->snapshot}] release [{$release}] architecture [{$architecture}]: {$this->failure}");
            }
        }
    };
}

function artifact_only_recipe(ToolPin $pin): MachineRecipe
{
    return new MachineRecipe('sha256:fixture', '20261014T000000Z', ['amd64'], ['fixture' => $pin], ['base' => ['fixture']], '{}');
}

describe('machine recipe structure and identity', function (): void {
    it('owns one git-core Git build in every profile and architecture', function (): void {
        $recipe = new RecipeLoader()->load();

        foreach (array_keys($recipe->profiles) as $profile) {
            expect($recipe->profile($profile))->toContain('git', 'git-man');
        }
        foreach (['git', 'git-man'] as $name) {
            expect($recipe->tools[$name]->source)->toBe('artifact')
                ->and($recipe->tools[$name]->version)->toBe('1:2.55.0-0ppa1~ubuntu26.04.2')
                ->and(array_keys($recipe->tools[$name]->artifacts))->toBe(['amd64', 'arm64']);
        }
    });

    it('refuses an incomplete or foreign Git build', function (Closure $change, string $error): void {
        $document = machine_recipe_document();
        $change($document);

        expect(fn () => machine_recipe_decode($document))->toThrow(InvalidArgumentException::class, $error);
    })->with([
        'missing companion policy' => [function (&$document): void {
            $document['git']['packages'] = ['git'];
        }, 'git.packages: expected git and its companion git-man'],
        'missing grouped artifact' => [function (&$document): void {
            $document['git']['packages'][] = 'gitk';
        }, 'git.packages: missing artifact tool [gitk]'],
        'invalid notice review date' => [function (&$document): void {
            $document['git']['usn_since'] = '2026-02-30';
        }, 'git.usn_since: expected a real date'],
        'split versions' => [function (&$document): void {
            $document['tools']['git-man']['version'] = str_replace('2.55.0', '2.54.0', $document['tools']['git-man']['version']);
            foreach ($document['tools']['git-man']['artifacts'] as &$artifact) {
                $artifact['url'] = str_replace('2.55.0', '2.54.0', $artifact['url']);
            }
        }, 'all Git packages must pin the same build'],
        'another origin' => [function (&$document): void {
            $document['tools']['git']['artifacts']['amd64']['url'] = str_replace('ppa.launchpadcontent.net', 'example.org', $document['tools']['git']['artifacts']['amd64']['url']);
        }, 'expected a SHA-256-pinned git-core PPA Debian artifact'],
        'not common' => [function (&$document): void {
            $document['common'] = array_values(array_diff($document['common'], ['git-man']));
            $document['roles']['app-dev'][] = 'git-man';
        }, 'common: missing git-man'],
        'different all bytes' => [function (&$document): void {
            $document['tools']['git-man']['artifacts']['arm64']['sha256'] = str_repeat('a', 64);
        }, 'architecture-independent artifacts must have the same digest'],
    ]);

    it('loads the repository seed and exposes explicit profile membership', function (): void {
        Http::preventStrayRequests();
        $recipe = new RecipeLoader()->load();
        expect($recipe->identity)->toMatch('/^sha256:[a-f0-9]{64}$/')
            ->and($recipe->tools['caddy']->version)->toBe('2.11.7')
            ->and($recipe->tools['orbit-agent']->version)->toBe('0.4.2')
            ->and($recipe->profile('operator'))->toContain('curl')->not->toContain('caddy', 'pi')
            ->and($recipe->profile('app-prod'))->not->toContain('rust', 'php8.4-pcov', 'pi')
            ->and($recipe->profile('base'))->toContain('php8.4-pcov', 'php8.5-pcov', 'rust', 'pi')
            ->and($recipe->supportedArchitectures('base'))->toBe(['amd64']);
        Http::assertNothingSent();
    });

    it('pins the Sury dependencies needed to install both PHP versions on a stock image', function (): void {
        $recipe = new RecipeLoader()->load();

        expect($recipe->profile('base'))->toContain('php-common', 'php8.4-igbinary', 'php8.5-igbinary');
        expect($recipe->profile('app-dev'))->toContain('php-common', 'php8.4-igbinary', 'php8.5-igbinary');
        foreach (['php-common', 'php8.4-igbinary', 'php8.5-igbinary'] as $name) {
            expect($recipe->tools[$name]->source)->toBe('artifact');
            foreach (['amd64', 'arm64'] as $architecture) {
                expect($recipe->tools[$name]->artifacts[$architecture]->location)->toStartWith('https://packages.sury.org/php/');
            }
        }
        expect($recipe->tools['php-common']->version)->toStartWith('2:');
    });

    it('installs OpenTelemetry packages only for app-dev and excludes it from every CLI module check', function (): void {
        $recipe = new RecipeLoader()->load();
        $document = machine_recipe_document();

        foreach (['8.4', '8.5'] as $version) {
            $name = "php{$version}-opentelemetry";
            expect($recipe->profile('app-dev'))->toContain($name);
            expect($recipe->phpExtensions($recipe->profile('app-dev'))[$version])->not->toContain('opentelemetry');
            expect($recipe->tools[$name]->version)->toBe($document['tools'][$name]['version']);
            foreach (['app-prod', 'ci-runner', 'base', 'gateway'] as $profile) {
                expect($recipe->profile($profile))->not->toContain($name);
                foreach ($recipe->phpExtensions($recipe->profile($profile)) as $extensions) {
                    expect($extensions)->not->toContain('opentelemetry');
                }
            }
        }
    });

    it('keeps the app-dev CLI extension set equal to CI for their shared PHPStan cache', function (): void {
        $recipe = new RecipeLoader()->load();
        $development = $recipe->phpExtensions($recipe->profile('app-dev'));
        $ci = $recipe->phpExtensions($recipe->profile('ci-runner'));

        expect($development[$recipe->ciPhp])->toBe($ci[$recipe->ciPhp]);
    });

    it('rejects a wrong Debian release version even when the epoch is omitted in the filename', function (): void {
        $data = machine_recipe_document();
        $data['tools']['php-common']['version'] = '2:999';

        expect(fn () => machine_recipe_decode($data))->toThrow(InvalidArgumentException::class, 'immutable release URL');
    });

    it('seeds the existing enrollment and image-build packages without adding base-only software to roles', function (): void {
        $recipe = new RecipeLoader()->load();
        $node = new Node;
        $packages = new NodeBootstrapPackageCatalog;
        $common = [...$packages->forNode($node), 'git', 'git-man'];
        $programs = new RemotePhpPackageManager()->appDevPrograms(new InstancePhpVersionCatalog()->versions());
        [, $json] = explode("\0", $programs['install']->input ?? '', 2);
        $input = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        $php = [];
        foreach ($input['versions'] as $set) {
            $php = [...$php, ...array_column($set['packages'], 'name')];
        }
        // Every machine with a PHP set also gets the tool that repacks its build for a rollback.
        $php = [...array_values(array_unique($php)), 'dpkg-repack'];
        $php85 = [...$recipe->php('8.5')->packagesFor(false), 'dpkg-repack'];
        $javascript = ['vp', 'vp-cli', 'node', 'pnpm', 'bun'];
        $production = array_values(array_unique([...$common, ...$packages->forRole($node, RoleName::AppProd), 'containerd', ...$javascript]));
        $development = [...$production, ...$php];
        $imagePackages = ['openssh-server', ...TaskVmCloudInit::WorkspaceTools, ...TaskVmCloudInit::ChromiumLibraries];
        $base = [...array_diff($development, ['php8.4-opentelemetry', 'php8.5-opentelemetry']), ...$imagePackages, 'rust', 'rust-std', 'cargo', 'clippy', 'rustfmt', 'orbit-agent', 'pi'];
        $profiles = [
            'operator' => $common,
            'gateway' => [...$common, 'caddy', ...$php85],
            'app-prod' => [...$production, ...$php85],
            'app-dev' => $development,
            'base' => $base,
            'ci-runner' => [...$common, 'acl', 'attr', 'git', 'unzip', ...$php85],
        ];
        expect($php)->toBe([...array_values(array_unique([...$recipe->php('8.5')->packages, ...$recipe->php('8.4')->packages])), 'dpkg-repack']);
        foreach ($profiles as $role => $expected) {
            $expected = array_values(array_unique($expected));
            sort($expected, SORT_STRING);
            expect($recipe->profile($role))->toBe($expected);
        }
        foreach ($imagePackages as $package) {
            expect($recipe->tools[$package]->source)->toBe('ubuntu')
                ->and($recipe->tools[$package]->package)->toBe($package);
            foreach (array_keys($recipe->profiles) as $role) {
                if ($role !== 'base') {
                    expect($recipe->profile($role))->not->toContain($package);
                }
            }
        }
        expect($recipe->tools['caddy']->version)->toBe(CaddyPackageProgram::RELEASE)
            ->and($recipe->tools['caddy']->artifacts['amd64']->digest)->toBe(CaddyPackageProgram::AMD64_SHA512)
            ->and($recipe->tools['caddy']->artifacts['arm64']->digest)->toBe(CaddyPackageProgram::ARM64_SHA512)
            ->and($recipe->tools['orbit-agent']->version)->toBe(NodeAgentFootprint::Version)
            ->and($recipe->tools['orbit-agent']->artifacts['amd64']->digest)->toBe(NodeAgentFootprint::checksum('x86_64'))
            ->and($recipe->tools['orbit-agent']->artifacts['arm64']->digest)->toBe(NodeAgentFootprint::checksum('aarch64'));
    });

    it('names the rollback tool, the PCOV profiles, the only purging profile, and the mirror', function (): void {
        $policy = new RecipeLoader()->load()->phpPolicy;

        expect($policy->rollbackTool)->toBe('dpkg-repack')
            ->and($policy->pcovProfiles)->toBe(['app-dev', 'base', 'ci-runner'])
            ->and($policy->opentelemetryProfiles)->toBe(['app-dev'])
            ->and($policy->purgeProfiles)->toBe(['ci-runner'])
            ->and(new RecipeLoader()->load()->artifactMirror)->toBe('https://github.com/nckrtl/orbit/releases/download/php-build-mirror');
    });

    it('names one exact PHP build per version for every environment', function (): void {
        $recipe = new RecipeLoader()->load();
        $document = machine_recipe_document();
        $build = $recipe->php('8.5');

        expect($recipe->ciPhp)->toBe('8.5')
            ->and($build->packages)->toContain('php-common', 'libgd3', 'php8.5-cli', 'php8.5-fpm', 'php8.5-pcov')
            ->not->toContain('php8.4-cli')
            ->and($build->extensions)->toContain('core', 'pcov', 'zend opcache', 'lexbor', 'uri')
            ->and($recipe->php('8.4')->extensions)->not->toContain('lexbor', 'uri')
            ->and(array_keys($recipe->php))->toBe(new InstancePhpVersionCatalog()->versions());
        foreach ($recipe->php as $version => $phpBuild) {
            $coreVersion = $document['tools']["php{$version}-common"]['version'];
            expect($phpBuild->phpVersion)->toBe(explode('-', $coreVersion, 2)[0]);
        }
        foreach (['gateway', 'app-prod', 'websocket', 'ci-runner', 'app-dev', 'base'] as $profile) {
            expect($recipe->profile($profile))->toContain(...$build->packagesFor($profile === 'app-dev'));
        }
        foreach ($build->packages as $name) {
            expect($recipe->tools[$name]->version)->toContain('+ubuntu26.04');
            if (str_starts_with($recipe->tools[$name]->version, '8.5.')) {
                expect($recipe->tools[$name]->version)->toBe($recipe->tools['php8.5-common']->version);
            }
        }
    });

    it('rejects a PHP build that is not one exact build', function (Closure $mutate, string $message): void {
        $document = machine_recipe_document();
        $mutate($document);
        expect(fn () => machine_recipe_decode($document))->toThrow(InvalidArgumentException::class, $message);
    })->with([
        'one core pin of another patch' => [function (&$d): void {
            $pin = &$d['tools']['php8.5-cli'];
            $current = $pin['version'];
            $pin['version'] = preg_replace_callback('/^8\.5\.([0-9]+)/', static fn (array $match): string => '8.5.'.((int) $match[1] + 1), $current);
            foreach ($pin['artifacts'] as &$artifact) {
                $artifact['url'] = str_replace($current, $pin['version'], $artifact['url']);
            }
        }, 'Change every pin of one PHP version together'],
        'a pin for another Ubuntu release' => [function (&$d): void {
            $pin = &$d['tools']['php8.5-redis'];
            $pin['version'] = str_replace('ubuntu26.04', 'ubuntu24.04', $pin['version']);
            foreach ($pin['artifacts'] as &$artifact) {
                $artifact['url'] = str_replace('ubuntu26.04', 'ubuntu24.04', $artifact['url']);
            }
        }, 'is not built for Ubuntu 26.04'],
        'a PHP pin named directly' => [function (&$d): void {
            $d['roles']['gateway'][] = 'php8.5-cli';
        }, 'names the PHP pin [php8.5-cli]'],
        'a PHP pin outside every set' => [function (&$d): void {
            $d['tools']['php8.5-soap'] = $d['tools']['php8.5-cli'];
        }, 'tools.php8.5-soap: unknown tool'],
        'a set without its CLI' => [function (&$d): void {
            $d['php']['versions']['8.5']['packages'] = array_values(array_diff($d['php']['versions']['8.5']['packages'], ['php8.5-cli']));
        }, 'missing php8.5-cli'],
        'unsorted extensions' => [function (&$d): void {
            $d['php']['versions']['8.5']['extensions'] = array_reverse($d['php']['versions']['8.5']['extensions']);
        }, 'expected a sorted list'],
        'extensions without PCOV' => [function (&$d): void {
            $d['php']['versions']['8.5']['extensions'] = array_values(array_diff($d['php']['versions']['8.5']['extensions'], ['pcov']));
        }, 'expected core and pcov'],
        'an unknown set' => [function (&$d): void {
            $d['roles']['gateway'][] = 'php:9.9';
        }, 'unknown PHP set [php:9.9]'],
        'an unused version' => [function (&$d): void {
            $d['roles']['app-dev'] = array_values(array_diff($d['roles']['app-dev'], ['php:8.4']));
            $d['base'] = array_values(array_diff($d['base'], ['php:8.4']));
        }, 'no profile names php:8.4'],
        'an undeclared CI version' => [function (&$d): void {
            $d['php']['ci'] = '8.3';
        }, 'php.ci: [8.3] is not a declared PHP version'],
        'purging on a site profile' => [function (&$d): void {
            $d['php']['purge_extras'][] = 'app-prod';
        }, 'only the ci-runner profile may purge PHP packages'],
        'PCOV for an unknown profile' => [function (&$d): void {
            $d['php']['pcov'][] = 'typo';
        }, 'php.pcov: expected recipe profiles'],
        'OpenTelemetry without its module' => [function (&$d): void {
            $d['php']['versions']['8.5']['extensions'] = array_values(array_diff($d['php']['versions']['8.5']['extensions'], ['opentelemetry']));
        }, 'OpenTelemetry package and extension must match'],
        'OpenTelemetry on production' => [function (&$d): void {
            $d['php']['opentelemetry'][] = 'app-prod';
        }, 'only the app-dev profile'],
        'a mirror without HTTPS' => [function (&$d): void {
            $d['artifact_mirror'] = 'http://example.com/php';
        }, 'artifact_mirror: expected an HTTPS base URL'],
        'a rollback tool that is not an Ubuntu package' => [function (&$d): void {
            $d['php']['rollback'] = 'caddy';
        }, 'php.rollback'],
    ]);

    it('canonicalizes object key order and whitespace but preserves meaningful changes', function (): void {
        $document = machine_recipe_document();
        $first = machine_recipe_decode($document);
        $reordered = array_reverse($document, true);
        $reordered['tools'] = array_reverse($document['tools'], true);
        expect(machine_recipe_decode($reordered)->identity)->toBe($first->identity);
        expect(new RecipeLoader()->decode(json_encode($reordered, JSON_PRETTY_PRINT))->identity)->toBe($first->identity);
        $document['ubuntu']['snapshot'] = new DateTimeImmutable($first->snapshot)->modify('+1 day')->format('Ymd\THis\Z');
        expect(machine_recipe_decode($document)->identity)->not->toBe($first->identity);
        $document = machine_recipe_document();
        $document['tools']['pi']['artifacts']['amd64']['filename'] = 'same-bytes-another-location';
        expect(machine_recipe_decode($document)->identity)->toBe($first->identity);
        $document['tools']['pi']['artifacts']['amd64']['sha256'] = str_repeat('1', 64);
        expect(machine_recipe_decode($document)->identity)->not->toBe($first->identity);
        $document = machine_recipe_document();
        $document['common'] = array_reverse($document['common']);
        expect(machine_recipe_decode($document)->identity)->not->toBe($first->identity);
    });

    it('rejects invalid documents with a field-specific error', function (Closure $mutate, string $message): void {
        $document = machine_recipe_document();
        $mutate($document);
        expect(fn () => machine_recipe_decode($document))->toThrow(InvalidArgumentException::class, $message);
    })->with([
        'schema version' => [function (&$d): void {
            $d['schema_version'] = 2;
        }, 'schema_version'],
        'unknown field' => [function (&$d): void {
            $d['shell'] = 'run';
        }, 'expected exactly fields'],
        'unknown role' => [function (&$d): void {
            $d['roles']['typo'] = [];
        }, 'roles'],
        'unknown tool' => [function (&$d): void {
            $d['tools']['typo'] = $d['tools']['curl'];
        }, 'tools.typo: unknown tool'],
        'unknown reference' => [function (&$d): void {
            $d['roles']['gateway'][] = 'typo';
        }, 'unknown or missing tool [typo]'],
        'missing entry' => [function (&$d): void {
            unset($d['tools']['curl']);
        }, 'missing catalog tools'],
        'channel pin' => [function (&$d): void {
            $d['tools']['node']['version'] = 'lts';
        }, 'tools.node.version'],
        'constraint pin' => [function (&$d): void {
            $d['tools']['curl']['version'] = '^8.0';
        }, 'tools.curl.version'],
        'version substring' => [function (&$d): void {
            $d['tools']['node']['version'] = '24';
        }, 'expected the exact version'],
        'prerelease labeled as final' => [function (&$d): void {
            $d['tools']['node']['artifacts']['amd64']['url'] = 'https://example.com/node-24.22.0-rc.1-linux-x64.tar.xz';
        }, 'expected the exact version'],
        'digest' => [function (&$d): void {
            $d['tools']['pi']['artifacts']['amd64']['sha256'] = 'invented';
        }, 'sha256'],
        'path traversal' => [function (&$d): void {
            $d['tools']['pi']['artifacts']['amd64']['filename'] = '../live';
        }, 'filename'],
        'wrong source' => [function (&$d): void {
            $d['tools']['curl']['source'] = 'internal';
        }, 'tools.curl.source'],
        'wrong type' => [function (&$d): void {
            $d['common'] = 'curl';
        }, 'common: expected a list'],
        'duplicate list' => [function (&$d): void {
            $d['common'][] = 'curl';
        }, 'duplicate list entry'],
        'impossible date' => [function (&$d): void {
            $d['ubuntu']['snapshot'] = '20260230T000000Z';
        }, 'ubuntu.snapshot'],
        'unversioned URL' => [function (&$d): void {
            $d['tools']['node']['artifacts']['amd64']['url'] = 'https://nodejs.org/latest';
        }, 'exact version'],
        'invented Pi arm64' => [function (&$d): void {
            $d['tools']['pi']['artifacts']['arm64'] = $d['tools']['pi']['artifacts']['amd64'];
        }, 'amd64 only'],
    ]);

    it('rejects a missing Git pin with a recipe validation exception', function (): void {
        $data = json_decode(file_get_contents(resource_path('machine-software/recipe.json')), true, flags: JSON_THROW_ON_ERROR);
        unset($data['tools']['git']);
        $data['common'] = array_values(array_diff($data['common'], ['git']));

        expect(fn () => new RecipeLoader()->decode(json_encode($data, JSON_THROW_ON_ERROR)))
            ->toThrow(InvalidArgumentException::class, 'tools: missing catalog tools: git');
    });

    it('rejects duplicate and escaped-equivalent object keys before hashing', function (): void {
        $json = file_get_contents(resource_path('machine-software/recipe.json'));
        $json = preg_replace('/"schema_version": 1/', '"schema_version": 1, "schema_\\u0076ersion": 1', $json);
        expect(fn () => new RecipeLoader()->decode($json))->toThrow(InvalidArgumentException::class, 'Duplicate object key [schema_version]');
    });

    it('rejects malformed JSON and reports the candidate file', function (): void {
        $file = tempnam(sys_get_temp_dir(), 'recipe-invalid-');
        try {
            file_put_contents($file, '{bad');
            expect(fn () => new RecipeLoader()->load($file))->toThrow(InvalidArgumentException::class, $file);
        } finally {
            unlink($file);
        }
    });

    it('rejects unknown profiles and Pi on arm64', function (): void {
        $recipe = new RecipeLoader()->load();
        expect(fn () => $recipe->profile('typo'))->toThrow(InvalidArgumentException::class, 'Unknown role/profile')
            ->and(fn () => $recipe->profile('base', 'arm64'))->toThrow(InvalidArgumentException::class, 'requires tool [pi]')
            ->and($recipe->profile('app-dev', 'arm64'))->toContain('caddy');
    });
});

describe('recipe availability and artifact integrity', function (): void {
    beforeEach(function (): void {
        Http::preventStrayRequests();
    });

    it('verifies Git from the durable mirror and falls back to the PPA when absent', function (bool $present): void {
        $url = 'https://ppa.example/git_2.55.0_amd64.deb';
        $digest = hash('sha256', 'exact Git bytes');
        $mirror = 'https://mirror.example/git_'.$digest.'.deb';
        Http::fake([$mirror => Http::response($present ? 'exact Git bytes' : '', $present ? 200 : 404), $url => Http::response('exact Git bytes')]);
        $pin = new ToolPin('artifact', '2.55.0', artifacts: ['amd64' => new ArtifactPin($url, 'sha256', $digest)]);
        $recipe = new MachineRecipe('fixture', '20261014T000000Z', ['amd64'], ['git' => $pin], [], '{}', artifactMirror: 'https://mirror.example');

        new RecipeValidator(machine_recipe_snapshot_probe())->validate($recipe, '/unused');

        Http::assertSentCount($present ? 1 : 2);
    })->with([true, false]);

    it('refuses a corrupt mirror without falling back for any Debian artifact', function (string $name, string $algorithm): void {
        $url = 'https://origin.example/'.$name.'_1.0.deb';
        $digest = hash($algorithm, 'exact bytes');
        $mirror = 'https://mirror.example/'.$name.'_'.$digest.'.deb';
        Http::fake([$mirror => Http::response('corrupt bytes'), $url => Http::response('exact bytes')]);
        $pin = new ToolPin('artifact', '1.0', artifacts: ['amd64' => new ArtifactPin($url, $algorithm, $digest)]);
        $recipe = new MachineRecipe('fixture', '20261014T000000Z', ['amd64'], [$name => $pin], [], '{}', artifactMirror: 'https://mirror.example');

        expect(fn () => new RecipeValidator(machine_recipe_snapshot_probe())->validate($recipe, '/unused'))
            ->toThrow(RuntimeException::class, $algorithm.' mismatch');
        Http::assertSentCount(1);
    })->with([['git', 'sha256'], ['php8.5-cli', 'sha256'], ['caddy', 'sha512']]);

    it('fails an unavailable snapshot with a fake availability probe before artifact downloads', function (): void {
        $recipe = new RecipeLoader()->load();
        $document = json_decode($recipe->canonicalJson, true, flags: JSON_THROW_ON_ERROR);
        $release = $document['ubuntu']['release'];
        $architecture = $recipe->architectures[0];
        $probe = machine_recipe_snapshot_probe('metadata URL returned 404');
        expect(fn () => new RecipeValidator($probe)->validate($recipe, '/unused'))
            ->toThrow(RuntimeException::class, "Snapshot [{$recipe->snapshot}] release [{$release}] architecture [{$architecture}]: metadata URL returned 404");
        expect($probe->called)->toBeTrue();
        Http::assertNothingSent();
    });

    it('verifies external artifact bytes instead of trusting HTTP success', function (): void {
        $url = 'https://example.test/releases/1.2.3/tool';
        Http::fake([$url => Http::response('exact bytes')]);
        $pin = new ToolPin('artifact', '1.2.3', artifacts: ['amd64' => new ArtifactPin($url, 'sha512', hash('sha512', 'exact bytes'))]);
        new RecipeValidator(machine_recipe_snapshot_probe())->validate(artifact_only_recipe($pin), '/unused');
        Http::assertSentCount(1);
    });

    it('rejects an unavailable exact tool version', function (): void {
        $url = 'https://example.test/releases/1.2.3/tool';
        Http::fake([$url => Http::response('', 404)]);
        $pin = new ToolPin('artifact', '1.2.3', artifacts: ['amd64' => new ArtifactPin($url, 'sha256', str_repeat('a', 64))]);
        expect(fn () => new RecipeValidator(machine_recipe_snapshot_probe())->validate(artifact_only_recipe($pin), '/unused'))
            ->toThrow(RuntimeException::class, 'version [1.2.3] architecture [amd64] source [artifact]');
    });

    it('rejects an external digest mismatch', function (): void {
        $url = 'https://example.test/releases/1.2.3/tool';
        Http::fake([$url => Http::response('wrong bytes')]);
        $pin = new ToolPin('artifact', '1.2.3', artifacts: ['amd64' => new ArtifactPin($url, 'sha256', str_repeat('a', 64))]);
        expect(fn () => new RecipeValidator(machine_recipe_snapshot_probe())->validate(artifact_only_recipe($pin), '/unused'))
            ->toThrow(RuntimeException::class, 'sha256 mismatch');
    });

    it('validates local internal bytes and fails clearly on missing files or mismatches', function (): void {
        $directory = sys_get_temp_dir().'/recipe-local-'.bin2hex(random_bytes(6));
        mkdir($directory);
        $pin = new ToolPin('internal', 'e5933d4c8696', artifacts: ['amd64' => new ArtifactPin('pi-server-fixture', 'sha256', hash('sha256', 'fixture'))]);
        $validator = new RecipeValidator(machine_recipe_snapshot_probe());
        $recipe = artifact_only_recipe($pin);
        try {
            expect(fn () => $validator->validate($recipe, $directory))->toThrow(RuntimeException::class, 'Missing or unreadable artifact file');
            file_put_contents($directory.'/pi-server-fixture', 'wrong');
            expect(fn () => $validator->validate($recipe, $directory))->toThrow(RuntimeException::class, 'sha256 mismatch');
            file_put_contents($directory.'/pi-server-fixture', 'fixture');
            $validator->validate($recipe, $directory);
            Http::assertNothingSent();
        } finally {
            @unlink($directory.'/pi-server-fixture');
            rmdir($directory);
        }
    });
});

describe('machine recipe command', function (): void {
    it('shows structure without implying availability or integrity and without any HTTP', function (): void {
        Http::preventStrayRequests();
        $this->artisan('orbit:machine-recipe', ['--role' => 'operator'])
            ->expectsOutputToContain('Identity: sha256:')
            ->expectsOutputToContain('structure only; availability and integrity NOT checked')
            ->expectsOutputToContain('operator (architectures: amd64, arm64)')
            ->assertSuccessful();
        Http::assertNothingSent();
    });

    it('prints all pins and provenance in JSON without claiming integrity', function (): void {
        Http::preventStrayRequests();
        expect(Artisan::call('orbit:machine-recipe', ['--json' => true, '--role' => 'base']))->toBe(0);
        $output = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
        expect($output['validation'])->toBe('structure_only')
            ->and($output['identity'])->toBe(new RecipeLoader()->load()->identity)
            ->and($output['recipe'])->toBe(machine_recipe_document())
            ->and($output['recipe']['tools']['pi']['source_commit'])->toBe('e5933d4c8696')
            ->and($output['recipe']['tools']['pi']['artifacts']['amd64']['sha256'])->toBe('286620c586b13b60fce4ce71f286d282da185fd95f7971c4109fb52bc8f0a78c')
            ->and($output['profiles']['base']['architectures'])->toBe(['amd64']);
        expect(Artisan::call('orbit:machine-recipe', ['--json' => true, '--role' => 'typo']))->toBe(1);
        expect(json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR)['error'])->toContain('Unknown role/profile');
        Http::assertNothingSent();
    });

    it('reports complete online success only after checking every declared artifact and the local Pi bytes', function (): void {
        $document = machine_recipe_document();
        $requests = 0;
        foreach ($document['tools'] as &$tool) {
            if ($tool['source'] === 'ubuntu') {
                continue;
            }
            foreach ($tool['artifacts'] as &$artifact) {
                $algorithm = isset($artifact['sha256']) ? 'sha256' : 'sha512';
                $artifact[$algorithm] = hash($algorithm, 'fixture');
                if ($tool['source'] === 'internal') {
                    $artifact['filename'] = 'pi-server-fixture';
                } else {
                    $requests++;
                }
            }
            unset($artifact);
        }
        unset($tool);
        $directory = sys_get_temp_dir().'/recipe-command-'.bin2hex(random_bytes(6));
        mkdir($directory);
        file_put_contents($directory.'/recipe.json', json_encode($document, JSON_THROW_ON_ERROR));
        file_put_contents($directory.'/pi-server-fixture', 'fixture');
        $probe = machine_recipe_snapshot_probe();
        $this->app->instance(SnapshotAvailability::class, $probe);
        Http::preventStrayRequests();
        Http::fake(['*' => Http::response('fixture')]);
        try {
            expect(Artisan::call('orbit:machine-recipe', [
                '--path' => $directory.'/recipe.json', '--validate' => true, '--json' => true,
                '--role' => 'operator', '--artifact-directory' => $directory,
            ]))->toBe(0);
            $output = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
            expect($output['validation'])->toBe('structure_availability_integrity')
                ->and(array_keys($output['profiles']))->toBe(['operator'])
                ->and($probe->called)->toBeTrue();
            Http::assertSentCount($requests);
            unlink($directory.'/pi-server-fixture');
            expect(Artisan::call('orbit:machine-recipe', [
                '--path' => $directory.'/recipe.json', '--validate' => true, '--json' => true,
                '--role' => 'operator', '--artifact-directory' => $directory,
            ]))->toBe(1);
            $error = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);
            expect($error['error'])->toContain('Missing or unreadable artifact file')
                ->and($error)->not->toHaveKey('validation');
        } finally {
            @unlink($directory.'/pi-server-fixture');
            unlink($directory.'/recipe.json');
            rmdir($directory);
        }
    });

    it('refuses unknown roles and unsupported Pi architecture', function (): void {
        $this->artisan('orbit:machine-recipe', ['--role' => 'typo'])->expectsOutputToContain('Unknown role/profile')->assertFailed();
        $this->artisan('orbit:machine-recipe', ['--role' => 'base', '--architecture' => 'arm64'])->expectsOutputToContain('requires tool [pi]')->assertFailed();
    });

    it('validates the whole recipe even when only operator tools are displayed', function (): void {
        $this->app->instance(SnapshotAvailability::class, machine_recipe_snapshot_probe('unavailable fixture snapshot'));
        $this->artisan('orbit:machine-recipe', ['--role' => 'operator', '--validate' => true])
            ->expectsOutputToContain('unavailable fixture snapshot')->assertFailed();
    });
});
