<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

/** @return list<string> */
function orb277_projects(): array
{
    return ['apps/cli', 'apps/docs', 'apps/gateway', 'apps/e2e', 'packages/php-sdk'];
}

/**
 * Print the TIA directory a project's guidance configuration resolves, without the parent run's Pest state.
 */
function orb277_tia_directory(string $root, string|false $override): string
{
    $process = new Process(['vendor/bin/pest', '--configuration=phpunit.guidance.xml', '--baseline'], $root, [
        'COLLISION_PRINTER' => false,
        'ORBIT_TIA_DIRECTORY' => $override,
        'PARATEST' => false,
        'PEST_TIA' => false,
        'PEST_TIA_BASELINED' => false,
        'PEST_TIA_FILTERED' => false,
        'PEST_TIA_LOCALLY' => false,
        'TEST_TOKEN' => false,
        'UNIQUE_TEST_TOKEN' => false,
    ]);
    $process->setTimeout(120);
    $process->mustRun();
    $output = trim($process->getOutput());
    $compact = json_decode($output, true);

    return is_array($compact) && is_string($compact['raw'][0] ?? null) ? $compact['raw'][0] : $output;
}

/** @return array{root: string, main: string, candidate: string, path: string} */
function orb277_gate_fixture(): array
{
    $root = temporaryPath('orbit-builder-gate-', 6);

    mkdir($root.'/bin', 0o700, true);
    mkdir($root.'/tooling', 0o700, true);

    foreach (orb277_projects() as $project) {
        mkdir($root.'/'.$project, 0o700, true);
        file_put_contents($root.'/'.$project.'/.gitkeep', '');
    }

    copy(base_path('../../bin/review-check'), $root.'/bin/review-check');
    copy(base_path('tests/Fixtures/BuilderGate/composer'), $root.'/tooling/composer');
    file_put_contents($root.'/bin/tia-cache', "#!/usr/bin/env sh\n\nexit 0\n");
    chmod($root.'/bin/review-check', 0o700);
    chmod($root.'/bin/tia-cache', 0o700);
    chmod($root.'/tooling/composer', 0o700);

    foreach ([
        ['git', 'init', '--quiet', '--initial-branch=main'],
        ['git', 'config', 'user.name', 'Orbit test'],
        ['git', 'config', 'user.email', 'test@orbit.invalid'],
        ['git', 'add', '.'],
        ['git', 'commit', '--quiet', '-m', 'main'],
    ] as $arguments) {
        (new Process($arguments, $root))->mustRun();
    }

    $main = trim((new Process(['git', 'rev-parse', 'HEAD'], $root))->mustRun()->getOutput());

    mkdir($root.'/apps/gateway/app', 0o700, true);
    mkdir($root.'/apps/gateway/tests', 0o700, true);
    file_put_contents($root.'/apps/gateway/app/Example.php', "<?php\n\nfinal class Example {}\n");
    file_put_contents($root.'/apps/gateway/tests/ExampleTest.php', "<?php\n\nit('exists', fn () => expect(true)->toBeTrue());\n");

    foreach ([
        ['git', 'checkout', '--quiet', '-b', 'orb-277-candidate'],
        ['git', 'add', '.'],
        ['git', 'commit', '--quiet', '-m', 'candidate'],
    ] as $arguments) {
        (new Process($arguments, $root))->mustRun();
    }

    return [
        'root' => $root,
        'main' => $main,
        'candidate' => trim((new Process(['git', 'rev-parse', 'HEAD'], $root))->mustRun()->getOutput()),
        'path' => $root.'/tooling:'.getenv('PATH'),
    ];
}

/** @return array{process: Process, receipt: array<string, mixed>} */
function orb277_run_gate(array $fixture, string $unselected): array
{
    $process = new Process([$fixture['root'].'/bin/review-check'], $fixture['root'], [
        'ORBIT_GATE_UNSELECTED' => $unselected,
        'PATH' => $fixture['path'],
    ]);
    $process->setTimeout(60);
    $process->run();

    $head = trim((new Process(['git', 'rev-parse', 'HEAD'], $fixture['root']))->mustRun()->getOutput());
    $paths = glob("{$fixture['root']}/.git/orbit-checks/{$head}/review-*/result.json");

    expect($process->getExitCode())->toBe(0, $process->getOutput().$process->getErrorOutput());
    expect($paths)->toBeArray()->toHaveCount(1);

    return [
        'process' => $process,
        'receipt' => json_decode((string) file_get_contents($paths[0]), true, flags: JSON_THROW_ON_ERROR),
    ];
}

/** @param array<string, mixed> $receipt */
function orb277_check(array $receipt, string $project, string $script): array
{
    $check = collect($receipt['checks'])->first(
        static fn (array $check): bool => $check['project'] === $project && $check['command'] === ['composer', $script],
    );

    expect($check)->toBeArray();

    return $check;
}

describe('Builder gate', function (): void {
    it('records guidance checks into a separate TIA cache in every project', function (string $project): void {
        $repository = realpath(base_path('../..'));
        $root = $repository.'/'.$project;
        $composer = json_decode((string) file_get_contents($root.'/composer.json'), true, flags: JSON_THROW_ON_ERROR);
        $guidance = $root.'/.pest/guidance';

        expect($composer['scripts']['guidance:check'])->toBe(
            'ORBIT_TIA_DIRECTORY=.pest/guidance vendor/bin/pest --configuration=phpunit.guidance.xml --tia --fresh --compact',
        );
        expect(orb277_tia_directory($root, '.pest/guidance'))->toBe($guidance);
        expect(orb277_tia_directory($root, false))->not->toStartWith($guidance);

        $ignored = new Process(['git', 'check-ignore', '-q', $project.'/.pest/guidance/graph.json'], $repository);
        $ignored->run();

        expect($ignored->getExitCode())->toBe(0, 'The guidance cache must stay out of the candidate tree.');
    })->with(orb277_projects());

    it('records a selection warning when affected tests select nothing for a changed project', function (): void {
        $fixture = orb277_gate_fixture();
        $run = orb277_run_gate($fixture, 'apps/gateway,apps/cli');
        $receipt = $run['receipt'];
        $changed = ['apps/gateway/app/Example.php', 'apps/gateway/tests/ExampleTest.php'];

        expect($receipt['passed'])->toBeTrue();
        expect($receipt['base'])->toBe($fixture['main']);
        expect($receipt['changed_paths'])->toBe($changed);
        expect($receipt['warnings'])->toHaveCount(1);
        expect($receipt['warnings'][0])
            ->toMatchArray(['project' => 'apps/gateway', 'command' => ['composer', 'test:affected'], 'paths' => $changed])
            ->and($receipt['warnings'][0]['message'])->toContain('selected no tests', '2 path(s) under apps/gateway/')
            ->and(file_exists($receipt['warnings'][0]['log']))->toBeTrue();
        expect(orb277_check($receipt, 'apps/gateway', 'test:affected'))
            ->toHaveKey('warning', $receipt['warnings'][0]['message']);
        expect(orb277_check($receipt, 'apps/cli', 'test:affected'))->not->toHaveKey('warning');
        expect(orb277_check($receipt, 'apps/gateway', 'check'))->not->toHaveKey('warning');
        expect($run['process']->getOutput())
            ->toContain('[apps/gateway] WARNING: composer test:affected selected no tests', 'Selection warnings: 1')
            ->not->toContain('[apps/cli] WARNING');
    });

    it('records no selection warning when the changed project executes tests or the candidate is main', function (): void {
        $fixture = orb277_gate_fixture();
        $executed = orb277_run_gate($fixture, 'apps/cli')['receipt'];

        expect($executed['passed'])->toBeTrue();
        expect($executed['warnings'])->toBe([]);
        expect(collect($executed['checks'])->pluck('warning')->filter()->all())->toBe([]);

        (new Process(['git', 'checkout', '--quiet', 'main'], $fixture['root']))->mustRun();
        $main = orb277_run_gate($fixture, 'apps/gateway');

        expect($main['receipt']['candidate'])->toBe($fixture['main']);
        expect($main['receipt']['changed_paths'])->toBe([]);
        expect($main['receipt']['warnings'])->toBe([]);
        expect($main['process']->getOutput())->not->toContain('WARNING');
    });
});
