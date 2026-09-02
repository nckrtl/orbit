<?php

declare(strict_types=1);

use App\E2E\Git\GitRepository;
use App\E2E\ProofInputManifestBuilder;
use App\E2E\StaticProofInputPolicy;
use App\E2E\Value\ProofPlan;
use Illuminate\Container\Container;
use Illuminate\Process\Factory as ProcessFactory;
use Illuminate\Support\Facades\Facade;

beforeEach(function (): void {
    $container = new Container;
    $container->instance(ProcessFactory::class, new ProcessFactory);
    Facade::clearResolvedInstances();
    Facade::setFacadeApplication($container);
});

/** @return array{root:string,main:string,proved:string,plan:ProofPlan} */
function proofManifestRepository(): array
{
    $root = temporaryPath('orbit-proof-inputs-', 6);
    foreach (['apps/cli/app', 'docs/extra', 'docs/reference', 'proofs/ORB-99'] as $directory) {
        mkdir($root.'/'.$directory, 0700, true);
    }
    file_put_contents($root.'/.gitignore', "/.e2e/\n");
    file_put_contents($root.'/apps/cli/app/base.php', "<?php\n");
    file_put_contents($root.'/docs/extra/input.txt', "extra\n");
    file_put_contents($root.'/docs/reference/note.md', "note\n");
    file_put_contents($root.'/proofs/ORB-99/check.sh', "#!/bin/sh\n");
    chmod($root.'/proofs/ORB-99/check.sh', 0755);
    file_put_contents($root.'/proofs/ORB-99.json', json_encode([
        'setup' => [],
        'acceptance' => [[
            'id' => 'read-extra',
            'node' => 'app-dev',
            'argv' => ['cat', '/home/orbit/orbit/docs/extra/input.txt'],
            'timeout_seconds' => 30,
        ]],
        'inputs' => ['docs/extra/'],
    ], JSON_THROW_ON_ERROR));
    proofInputGit($root, ['init', '--quiet', '-b', 'codex/orb-99-inputs']);
    proofInputGit($root, ['config', 'user.email', 'orbit@example.test']);
    proofInputGit($root, ['config', 'user.name', 'Orbit']);
    proofInputGit($root, ['add', '.']);
    proofInputGit($root, ['commit', '--quiet', '-m', 'main']);
    $main = proofInputGit($root, ['rev-parse', 'HEAD']);
    proofInputGit($root, ['update-ref', 'refs/remotes/origin/main', $main]);
    file_put_contents($root.'/apps/cli/app/feature.php', "<?php\n");
    proofInputGit($root, ['add', '.']);
    proofInputGit($root, ['commit', '--quiet', '-m', 'feature']);
    $proved = proofInputGit($root, ['rev-parse', 'HEAD']);

    return [
        'root' => $root,
        'main' => $main,
        'proved' => $proved,
        'plan' => ProofPlan::fromFile($root.'/proofs/ORB-99.json'),
    ];
}

/** @param list<string> $arguments */
function proofInputGit(string $root, array $arguments): string
{
    $command = array_map(escapeshellarg(...), ['git', '-C', $root, ...$arguments]);
    $output = [];
    $exitCode = 0;
    exec(implode(' ', $command), $output, $exitCode);
    if ($exitCode !== 0) {
        throw new RuntimeException('Proof-input Git fixture command failed.');
    }

    return trim(implode("\n", $output));
}

function proofManifestBuilder(): ProofInputManifestBuilder
{
    return new ProofInputManifestBuilder(new StaticProofInputPolicy);
}

describe('ProofInputManifestBuilder', function (): void {
    it('records runtime, proof-contract, fixture, mode, and feature-path inputs canonically', function (): void {
        $fixture = proofManifestRepository();
        $manifest = proofManifestBuilder()->build(
            new GitRepository($fixture['root']),
            $fixture['proved'],
            $fixture['main'],
            'ORB-99',
            'proofs/ORB-99.json',
            $fixture['plan'],
        );
        $byPath = array_column($manifest->staticInputs, null, 'path');

        expect($manifest->featureRuntimePaths)
            ->toBe(['apps/cli/app/feature.php'])
            ->and($manifest->extraInputs)
            ->toBe(['docs/extra'])
            ->and($byPath['apps/cli/app/base.php']['classification'])
            ->toBe('runtime')
            ->and($byPath['proofs/ORB-99.json']['classification'])
            ->toBe('proof-contract')
            ->and($byPath['proofs/ORB-99/check.sh']['mode'])
            ->toBe('100755')
            ->and($byPath['docs/extra/input.txt']['classification'])
            ->toBe('proof-contract')
            ->and($manifest->toArray()['fingerprint'])
            ->toBe($manifest->fingerprint());
    });

    it('rejects literal checkout reads outside runtime and declared inputs', function (): void {
        $fixture = proofManifestRepository();
        $plan = ProofPlan::fromArray([
            'setup' => [],
            'acceptance' => [[
                'id' => 'undeclared',
                'node' => 'app-dev',
                'argv' => ['cat', '/home/orbit/orbit/docs/reference/note.md'],
                'timeout_seconds' => 30,
            ]],
        ]);

        expect(fn () => proofManifestBuilder()->build(
            new GitRepository($fixture['root']),
            $fixture['proved'],
            $fixture['main'],
            'ORB-99',
            'proofs/ORB-99.json',
            $plan,
        ))
            ->toThrow(InvalidArgumentException::class, 'reads undeclared checkout input');
    });

    it('accepts a declared proof input outside the static path policy', function (): void {
        $fixture = proofManifestRepository();
        mkdir($fixture['root'].'/custom');
        file_put_contents($fixture['root'].'/custom/input.txt', "custom\n");
        proofInputGit($fixture['root'], ['add', '.']);
        proofInputGit($fixture['root'], ['commit', '--quiet', '-m', 'custom proof input']);
        $candidate = proofInputGit($fixture['root'], ['rev-parse', 'HEAD']);
        $plan = ProofPlan::fromArray([
            'setup' => [],
            'acceptance' => [[
                'id' => 'declared',
                'node' => 'app-dev',
                'argv' => ['cat', '/home/orbit/orbit/custom/input.txt'],
                'timeout_seconds' => 30,
            ]],
            'inputs' => ['custom/input.txt'],
        ]);

        $manifest = proofManifestBuilder()->build(
            new GitRepository($fixture['root']),
            $candidate,
            $fixture['main'],
            'ORB-99',
            'proofs/ORB-99.json',
            $plan,
        );

        expect(array_column($manifest->staticInputs, 'classification', 'path')['custom/input.txt'])
            ->toBe('proof-contract');
    });

    it('rejects a literal parent directory unless every tracked descendant is declared', function (): void {
        $fixture = proofManifestRepository();
        $plan = ProofPlan::fromArray([
            'setup' => [],
            'acceptance' => [[
                'id' => 'overbroad',
                'node' => 'app-dev',
                'argv' => ['find', '/home/orbit/orbit/docs'],
                'timeout_seconds' => 30,
            ]],
            'inputs' => ['docs/extra/input.txt'],
        ]);

        expect(fn () => proofManifestBuilder()->build(
            new GitRepository($fixture['root']),
            $fixture['proved'],
            $fixture['main'],
            'ORB-99',
            'proofs/ORB-99.json',
            $plan,
        ))
            ->toThrow(InvalidArgumentException::class, 'reads undeclared checkout input');
    });

    it('fails closed for an unclassified tracked path', function (): void {
        $fixture = proofManifestRepository();
        file_put_contents($fixture['root'].'/unexpected.txt', "unknown\n");
        proofInputGit($fixture['root'], ['add', '.']);
        proofInputGit($fixture['root'], ['commit', '--quiet', '-m', 'unknown']);
        $candidate = proofInputGit($fixture['root'], ['rev-parse', 'HEAD']);

        expect(fn () => proofManifestBuilder()->build(
            new GitRepository($fixture['root']),
            $candidate,
            $fixture['main'],
            'ORB-99',
            'proofs/ORB-99.json',
            $fixture['plan'],
        ))
            ->toThrow(InvalidArgumentException::class, 'classification is incomplete: unexpected.txt');
    });

    it('requires the candidate to include current main', function (): void {
        $fixture = proofManifestRepository();
        file_put_contents($fixture['root'].'/docs/reference/main.md', "advanced\n");
        proofInputGit($fixture['root'], ['add', '.']);
        proofInputGit($fixture['root'], ['commit', '--quiet', '-m', 'advanced main']);
        $advancedMain = proofInputGit($fixture['root'], ['rev-parse', 'HEAD']);

        expect(fn () => proofManifestBuilder()->build(
            new GitRepository($fixture['root']),
            $fixture['proved'],
            $advancedMain,
            'ORB-99',
            'proofs/ORB-99.json',
            $fixture['plan'],
        ))
            ->toThrow(InvalidArgumentException::class, 'does not include current origin/main');
    });
});
