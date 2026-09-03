<?php

declare(strict_types=1);

use App\E2E\Git\GitRepository;
use App\E2E\StaticProofInputPolicy;
use App\E2E\Value\ProofInputClassification;
use Illuminate\Container\Container;
use Illuminate\Process\Factory as ProcessFactory;
use Illuminate\Support\Facades\Facade;

beforeEach(function (): void {
    $container = new Container;
    $container->instance(ProcessFactory::class, new ProcessFactory);
    Facade::clearResolvedInstances();
    Facade::setFacadeApplication($container);
});

describe('StaticProofInputPolicy', function (): void {
    it('classifies the phase-one repository boundaries explicitly', function (string $path, string $expected): void {
        expect(new StaticProofInputPolicy()->classify($path)->value)->toBe($expected);
    })->with([
        'CLI runtime' => ['apps/cli/app/Commands/DoctorCommand.php', 'runtime'],
        'Gateway migration' => ['apps/gateway/database/migrations/example.php', 'runtime'],
        'E2E entrypoint' => ['bin/e2e-topology', 'runtime'],
        'SDK source' => ['packages/php-sdk/src/Client.php', 'runtime'],
        'maintained documentation' => ['docs/reference/incus-topologies.md', 'non-runtime'],
        'documentation tooling' => ['apps/docs/app/Rules/Rule.php', 'non-runtime'],
        'agent instructions' => ['apps/e2e/.agents/skills/example/SKILL.md', 'non-runtime'],
        'tests' => ['apps/e2e/tests/Unit/ExampleTest.php', 'non-runtime'],
        'unknown governed path' => ['apps/cli/extensions/Extension.php', 'indeterminate'],
        'unknown root path' => ['unexpected.txt', 'indeterminate'],
    ]);

    it('classifies every currently tracked repository path', function (): void {
        $repository = new GitRepository(dirname(__DIR__, 5));
        $unknown = [];
        $policy = new StaticProofInputPolicy;

        foreach (array_keys($repository->entries($repository->commit())) as $path) {
            if ($policy->classify($path) === ProofInputClassification::Indeterminate) {
                $unknown[] = $path;
            }
        }

        expect($unknown)->toBe([]);
    });

    it('limits observation replacement to ordinary tracked PHP source', function (string $path, bool $expected): void {
        expect(new StaticProofInputPolicy()->isObservablePhpSource($path))->toBe($expected);
    })->with([
        ['apps/cli/app/Commands/DoctorCommand.php',                true],
        ['apps/gateway/app/Http/Controllers/StatusController.php', true],
        ['packages/php-sdk/src/Client.php',                        true],
        ['apps/cli/bootstrap/app.php',                             false],
        ['apps/gateway/routes/web.php',                            false],
        ['apps/e2e/app/E2E/TopologyProofRunner.php',               false],
        ['apps/cli/app/README.md',                                 false],
    ]);
});
