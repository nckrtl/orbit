<?php

declare(strict_types=1);

use Symfony\Component\Process\Process;

/** @return array{root: string, head: string, path: string} */
function orb247_gate_fixture(): array
{
    $root = temporaryPath('orbit-test-database-gate-', 6);
    $fixture = base_path('tests/Fixtures/TestDatabaseGate');

    mkdir($root.'/bin', 0o700, true);
    mkdir($root.'/tooling', 0o700, true);

    foreach (['apps/cli', 'apps/docs', 'apps/gateway', 'apps/e2e', 'packages/php-sdk'] as $project) {
        mkdir($root.'/'.$project, 0o700, true);
        file_put_contents($root.'/'.$project.'/.gitkeep', '');
    }

    copy(base_path('../../bin/review-check'), $root.'/bin/review-check');
    copy($fixture.'/tia-cache', $root.'/bin/tia-cache');
    copy($fixture.'/composer', $root.'/tooling/composer');
    chmod($root.'/bin/review-check', 0o700);
    chmod($root.'/bin/tia-cache', 0o700);
    chmod($root.'/tooling/composer', 0o700);

    foreach ([
        ['git', 'init', '--quiet', '--initial-branch=main'],
        ['git', 'config', 'user.name', 'Orbit test'],
        ['git', 'config', 'user.email', 'test@orbit.invalid'],
        ['git', 'add', '.'],
        ['git', 'commit', '--quiet', '-m', 'fixture'],
    ] as $arguments) {
        (new Process($arguments, $root))->mustRun();
    }

    $head = trim((new Process(['git', 'rev-parse', 'HEAD'], $root))->mustRun()->getOutput());

    return [
        'root' => $root,
        'head' => $head,
        'path' => $root.'/tooling:'.getenv('PATH'),
    ];
}

/** @return list<array<string, mixed>> */
function orb247_gate_receipts(string $root, string $head): array
{
    $paths = glob("{$root}/.git/orbit-checks/{$head}/review-*/result.json");

    if (! is_array($paths)) {
        return [];
    }

    return array_map(
        static fn (string $path): array => json_decode(
            (string) file_get_contents($path),
            true,
            flags: JSON_THROW_ON_ERROR,
        ),
        $paths,
    );
}

/** @return array{hash: string, tables: list<string>, rows: list<array{id: int, value: string}>} */
function orb247_gate_sentinel(string $operation, string $database): array
{
    $process = new Process([
        PHP_BINARY,
        base_path('tests/Fixtures/TestDatabaseGate/sentinel.php'),
        $operation,
        $database,
    ]);
    $process->mustRun();

    return json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
}

it('keeps the root candidate check away from an inherited caller database', function (): void {
    $fixture = orb247_gate_fixture();
    $sentinel = temporaryFile('orbit-gateway-caller-');
    $before = orb247_gate_sentinel('create', $sentinel);
    $composer = trim((new Process(['which', 'composer']))->mustRun()->getOutput());
    $process = new Process([$fixture['root'].'/bin/review-check'], $fixture['root'], [
        'DB_DATABASE' => $sentinel,
        'DB_URL' => 'sqlite:////'.ltrim($sentinel, '/'),
        'ORBIT_GATEWAY_ACTUAL_CHECK' => '1',
        'ORBIT_GATEWAY_SOURCE' => base_path('../../apps/gateway'),
        'ORBIT_REAL_COMPOSER' => $composer,
        'ORBIT_TEST_DATABASE' => false,
        'PATH' => $fixture['path'],
    ]);
    $process->setTimeout(120);
    $process->run();
    $receipts = orb247_gate_receipts($fixture['root'], $fixture['head']);

    expect($process->getExitCode())->toBe(0, $process->getOutput().$process->getErrorOutput());
    expect($receipts)->toHaveCount(1);
    expect($receipts[0]['passed'] ?? null)->toBeTrue();
    expect(orb247_gate_sentinel('inspect', $sentinel))->toBe($before);
});

it('records a database safety refusal as a failed gate and retains normal success', function (): void {
    $fixture = orb247_gate_fixture();
    $refusal = new Process([$fixture['root'].'/bin/review-check'], $fixture['root'], [
        'ORBIT_GATEWAY_GATE_REFUSAL' => '1',
        'PATH' => $fixture['path'],
    ]);
    $refusal->setTimeout(30);
    $refusal->run();
    $failedReceipts = orb247_gate_receipts($fixture['root'], $fixture['head']);

    expect($refusal->getExitCode())->not->toBe(0);
    expect($refusal->getOutput().$refusal->getErrorOutput())
        ->toContain(
            '[apps/gateway] composer test:affected: FAILED',
            'Gateway tests refused an unsafe database connection.',
            'Candidate gate FAILED',
        );
    expect($failedReceipts)->toHaveCount(1);
    expect($failedReceipts[0]['role'] ?? null)->toBe('builder');
    expect($failedReceipts[0]['passed'] ?? null)->toBeFalse();
    $gatewayTest = collect($failedReceipts[0]['checks'] ?? [])->first(
        static fn (array $check): bool => ($check['project'] ?? null) === 'apps/gateway'
            && ($check['command'] ?? null) === ['composer', 'test:affected'],
    );
    expect($gatewayTest['exit_code'] ?? null)->toBe(23);

    $success = new Process([$fixture['root'].'/bin/review-check'], $fixture['root'], [
        'ORBIT_GATEWAY_GATE_REFUSAL' => '0',
        'PATH' => $fixture['path'],
    ]);
    $success->setTimeout(30);
    $success->run();
    $allReceipts = orb247_gate_receipts($fixture['root'], $fixture['head']);

    expect($success->getExitCode())->toBe(0, $success->getOutput().$success->getErrorOutput());
    expect($allReceipts)->toHaveCount(2);
    expect(collect($allReceipts)->contains(
        static fn (array $receipt): bool => ($receipt['passed'] ?? false) === true,
    ))->toBeTrue();
});

it('checks a candidate with uncommitted changes as it is and records its working tree', function (): void {
    $fixture = orb247_gate_fixture();
    file_put_contents($fixture['root'].'/apps/cli/.gitkeep', "changed\n");
    file_put_contents($fixture['root'].'/apps/cli/new-file.php', "<?php\n");
    $status = (new Process(['git', 'status', '--porcelain'], $fixture['root']))->mustRun()->getOutput();

    $process = new Process([$fixture['root'].'/bin/review-check'], $fixture['root'], [
        'ORBIT_GATEWAY_GATE_REFUSAL' => '0',
        'PATH' => $fixture['path'],
    ]);
    $process->setTimeout(30);
    $process->run();
    $receipts = orb247_gate_receipts($fixture['root'], $fixture['head']);
    $index = temporaryPath('orbit-gate-index-', 6);
    $expected = trim((new Process(['sh', '-c', 'cp .git/index "$1" && GIT_INDEX_FILE="$1" git add --all && GIT_INDEX_FILE="$1" git write-tree', 'tree', $index], $fixture['root']))->mustRun()->getOutput());

    expect($process->getExitCode())->toBe(0, $process->getOutput().$process->getErrorOutput())
        ->and($process->getOutput())->toContain('with uncommitted changes')
        ->and($receipts)->toHaveCount(1)
        ->and($receipts[0]['committed'] ?? null)->toBeFalse()
        ->and($receipts[0]['tree'] ?? null)->toBe($expected)
        ->and($receipts[0]['changed_paths'] ?? null)->toBe(['apps/cli/.gitkeep', 'apps/cli/new-file.php'])
        ->and($receipts[0]['passed'] ?? null)->toBeTrue()
        ->and((new Process(['git', 'status', '--porcelain'], $fixture['root']))->mustRun()->getOutput())->toBe($status);
});
