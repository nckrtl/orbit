<?php

declare(strict_types=1);

use App\E2E\IssueState;
use App\E2E\ProofPlanFile;
use App\E2E\Value\TopologyRequest;
use Illuminate\Container\Container;
use Illuminate\Process\Factory as ProcessFactory;
use Illuminate\Support\Facades\Facade;

beforeEach(function (): void {
    $container = new Container;
    $container->instance(ProcessFactory::class, new ProcessFactory);
    Facade::clearResolvedInstances();
    Facade::setFacadeApplication($container);
});

/** @return array{root:string,proved:string} */
function proofPlanFileRepository(): array
{
    $root = temporaryPath('orbit-proof-plan-file-', 6);
    mkdir($root.'/.loop/proof', 0700, true);
    file_put_contents($root.'/.gitignore', "/.e2e/\n");
    file_put_contents($root.'/.loop/plan.md', "# Feature plan\n");
    file_put_contents($root.'/.loop/proof/TST-42.json', json_encode([
        'setup' => [],
        'acceptance' => [[
            'id' => 'check-workspace',
            'node' => 'app-dev',
            'argv' => ['test', '-f', '/home/orbit/orbit/.loop/plan.md'],
            'timeout_seconds' => 30,
        ]],
    ], JSON_THROW_ON_ERROR));
    proofPlanFileGit($root, ['init', '--quiet', '-b', 'tst-42-feature']);
    proofPlanFileGit($root, ['config', 'user.email', 'orbit@example.test']);
    proofPlanFileGit($root, ['config', 'user.name', 'Orbit']);
    proofPlanFileGit($root, ['add', '.']);
    proofPlanFileGit($root, ['commit', '--quiet', '-m', 'proved workspace']);

    return ['root' => $root, 'proved' => proofPlanFileGit($root, ['rev-parse', 'HEAD'])];
}

/** @param list<string> $arguments */
function proofPlanFileGit(string $root, array $arguments): string
{
    $result = new ProcessFactory()->path($root)->run(['git', ...$arguments]);
    if ($result->failed()) {
        throw new RuntimeException('Proof-plan-file Git fixture failed: '.$result->errorOutput());
    }

    return trim($result->output());
}

describe('ProofPlanFile', function (): void {
    it('loads only the active issue plan from the current tracked workspace', function (): void {
        $fixture = proofPlanFileRepository();
        $request = new TopologyRequest('TST-42', $fixture['root']);

        $resolved = ProofPlanFile::current($request, null);

        expect($resolved->path)
            ->toBe('.loop/proof/TST-42.json')
            ->and($resolved->plan->acceptance[0]['id'])
            ->toBe('check-workspace')
            ->and(fn () => ProofPlanFile::current($request, '.loop/proof/AUX-7.json'))
            ->toThrow(
                InvalidArgumentException::class,
                'Proof plan ".loop/proof/AUX-7.json" must be ".loop/proof/TST-42.json" for the active issue.',
            );
    });

    it('loads the same plan from the retained proved commit after a removal-only head', function (): void {
        $fixture = proofPlanFileRepository();
        IssueState::forWorktree('TST-42', $fixture['root'])->writeProof([
            'status' => 'proved',
            'candidate_sha' => $fixture['proved'],
        ]);
        proofPlanFileGit($fixture['root'], ['rm', '--quiet', '-r', '.loop']);
        proofPlanFileGit($fixture['root'], ['commit', '--quiet', '-m', 'remove issue workspace']);

        $resolved = ProofPlanFile::currentOrRetained(new TopologyRequest('TST-42', $fixture['root']), null);

        expect($resolved->path)
            ->toBe('.loop/proof/TST-42.json')
            ->and($resolved->plan->acceptance[0]['id'])
            ->toBe('check-workspace');
    });

    it('refuses retained recovery while any tracked issue-workspace entry remains', function (): void {
        $fixture = proofPlanFileRepository();
        IssueState::forWorktree('TST-42', $fixture['root'])->writeProof([
            'status' => 'proved',
            'candidate_sha' => $fixture['proved'],
        ]);
        proofPlanFileGit($fixture['root'], ['rm', '--quiet', '.loop/proof/TST-42.json']);
        proofPlanFileGit($fixture['root'], ['commit', '--quiet', '-m', 'partial workspace removal']);

        expect(fn () => ProofPlanFile::currentOrRetained(
            new TopologyRequest('TST-42', $fixture['root']),
            null,
        ))->toThrow(
            InvalidArgumentException::class,
            'Proof plan ".loop/proof/TST-42.json" cannot be read from the current issue workspace.',
        );
    });

    it('names the active plan when its current contents are invalid', function (): void {
        $fixture = proofPlanFileRepository();
        file_put_contents($fixture['root'].'/.loop/proof/TST-42.json', "[]\n");

        expect(fn () => ProofPlanFile::current(new TopologyRequest('TST-42', $fixture['root']), null))
            ->toThrow(
                InvalidArgumentException::class,
                'Proof plan ".loop/proof/TST-42.json" is invalid: The proof plan must be a JSON object.',
            );
    });
});
