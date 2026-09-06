<?php

declare(strict_types=1);

use App\E2E\Git\GitRepository;
use App\E2E\GuestTransport;
use App\E2E\ProofFixtureStager;
use App\E2E\Value\GuestCommand;
use App\E2E\Value\GuestCommandResult;
use App\E2E\Value\OperationId;
use App\E2E\Value\ProofFixtures;
use App\E2E\Value\TopologyProfile;
use App\E2E\Value\TopologyRecipe;
use Illuminate\Container\Container;
use Illuminate\Process\Factory as ProcessFactory;
use Illuminate\Support\Facades\Facade;

/** A guest that installs whatever is pushed and reports the inventory it holds per role. */
final class ProofFixtureGuestFake implements GuestTransport
{
    /** @var list<list<string>> */
    public array $batches = [];

    /** @var list<array{label:string, instance:string, source:string, destination:string, content:string}> */
    public array $pushes = [];

    /** @var array<string, array<string, array{mode:string, content:string}>> */
    public array $installed = [];

    /** @var list<string> */
    public array $reset = [];

    /** @param array<string, string> $inventoryOverride Per instance, the inventory text to report instead of the real one. */
    public function __construct(
        public array $inventoryOverride = [],
        public ?string $failingLabel = null,
        public int $maxBatchSize = PHP_INT_MAX,
    ) {}

    public function exec(string $instance, GuestCommand $command): GuestCommandResult
    {
        throw new RuntimeException('The stager batches every guest command.');
    }

    public function execAll(array $commands): array
    {
        if (count($commands) > $this->maxBatchSize) {
            throw new RuntimeException('Guest command batch exceeds its request limit.');
        }
        $this->batches[] = array_keys($commands);
        $results = [];
        foreach ($commands as $label => $request) {
            $results[$label] = $this->run($label, $request['instance'], $request['command']->command);
        }

        return $results;
    }

    public function pushFile(string $instance, string $source, string $destination): void
    {
        throw new RuntimeException('The stager batches every file push.');
    }

    public function pushFiles(array $files): void
    {
        foreach ($files as $label => $file) {
            $this->pushes[] = [
                'label' => $label,
                ...$file,
                'content' => (string) file_get_contents($file['source']),
            ];
        }
    }

    /** @param list<string> $argv */
    private function run(string $label, string $instance, array $argv): GuestCommandResult
    {
        if ($label === $this->failingLabel) {
            return new GuestCommandResult('', "refused\n", 1);
        }
        if ($argv[0] === 'install' && ($argv[1] ?? null) === '-o') {
            $name = str_replace(ProofFixtures::GUEST_DIRECTORY.'/', '', $argv[8]);
            $staged = array_values(array_filter(
                $this->pushes,
                static fn (array $push): bool => $push['instance'] === $instance && $push['destination'] === $argv[7],
            ));
            $this->installed[$instance][$name] = ['mode' => ltrim($argv[6], '0'), 'content' => $staged[0]['content']];
        }
        if ($argv[0] === 'sh' && str_contains($argv[2] ?? '', 'rm -rf')) {
            $this->reset[] = $instance;
            unset($this->installed[$instance]);

            return new GuestCommandResult('', '', 0);
        }
        if ($argv[0] === 'sh') {
            if (isset($this->inventoryOverride[$instance])) {
                return new GuestCommandResult($this->inventoryOverride[$instance], '', 0);
            }
            $files = $this->installed[$instance] ?? [];
            ksort($files, SORT_STRING);
            $text = '';
            foreach ($files as $name => $file) {
                $text .= $name."\t".$file['mode']."\t".hash('sha256', $file['content'])."\n";
            }

            return new GuestCommandResult($text, '', 0);
        }

        return new GuestCommandResult('', '', 0);
    }
}

/**
 * @param array<string, array{string, int}> $files
 * @return array{repository:GitRepository, commit:string}
 */
function proofFixtureRepository(string $issue, array $files): array
{
    $path = temporaryPath('orbit-proof-fixture-git-', 6);
    mkdir($path, 0700, true);
    foreach ([
        ['init',   '--quiet'],
        ['config', 'user.email', 'orbit@example.test'],
        ['config', 'user.name',  'Orbit'],
    ] as $arguments) {
        proofFixtureGit($path, $arguments);
    }
    file_put_contents($path.'/README.md', "fixture\n");
    $directory = $path.'/.loop/proof';
    mkdir($directory, 0700, true);
    file_put_contents($directory.'/'.$issue.'.json', "{\"setup\":[],\"acceptance\":[]}\n");
    foreach ($files as $name => [$content, $mode]) {
        file_put_contents($directory.'/'.$name, $content);
        chmod($directory.'/'.$name, $mode);
    }
    proofFixtureGit($path, ['add', '.']);
    proofFixtureGit($path, ['commit', '--quiet', '-m', 'fixtures']);
    $repository = new GitRepository($path);

    return ['repository' => $repository, 'commit' => $repository->commit()];
}

/** @param list<string> $arguments */
function proofFixtureGit(string $path, array $arguments): void
{
    $result = new ProcessFactory()->path($path)->run(['git', ...$arguments]);
    if ($result->failed()) {
        throw new RuntimeException('Git fixture command failed: '.$result->errorOutput());
    }
}

describe('ProofFixtureStager', function (): void {
    beforeEach(function (): void {
        $container = new Container;
        $container->instance(ProcessFactory::class, new ProcessFactory);
        Facade::clearResolvedInstances();
        /** @mago-expect analysis:possibly-invalid-argument The process facade only needs the container contract. */
        Facade::setFacadeApplication($container);
    });

    it('stages the candidate fixtures root-owned on every role and records the digest each role observed', function (): void {
        ['repository' => $repository, 'commit' => $commit] = proofFixtureRepository('TST-82', [
            'check.sh' => ["#!/bin/sh\nexit 0\n", 0755],
            'input.txt' => ["fixture\n", 0644],
        ]);
        $guest = new ProofFixtureGuestFake;
        $target = featureTarget('TST-82', 'b');
        $operation = new OperationId(str_repeat('a', 32));

        $fixtures = new ProofFixtureStager($guest, $operation)->stage($target, $repository, $commit);

        $files = [
            'check.sh' => ['mode' => '755', 'sha256' => hash('sha256', "#!/bin/sh\nexit 0\n")],
            'input.txt' => ['mode' => '644', 'sha256' => hash('sha256', "fixture\n")],
        ];
        $digest = hash('sha256', ProofFixtures::inventoryText($files));
        expect($fixtures->toArray())
            ->toBe([
                'guest_directory' => '/var/lib/orbit-e2e/proof',
                'files' => $files,
                'digest' => $digest,
                'roles' => ['gateway' => $digest, 'app-dev' => $digest, 'app-prod' => $digest],
            ])
            ->and(array_keys($guest->installed))
            ->toBe(array_map($target->instance(...), TopologyProfile::ROLES))
            ->and(array_column($guest->pushes, 'destination'))
            ->each
            ->toStartWith('/var/lib/orbit-e2e/proof-staging/'.str_repeat('a', 32).'/')
            ->and(count($guest->pushes))
            ->toBe(6)
            ->and(array_map(static fn (array $labels): string => explode('.', $labels[0])[0], $guest->batches))
            ->toBe([
                'fixture-prepare',
                'fixture-directory',
                'fixture-install',
                'fixture-verify',
                'fixture-cleanup',
            ])
            ->and(glob(sys_get_temp_dir().'/orbit-proof-fixtures-*'))
            ->toBe([]);
    });

    it('keeps each fixture installation batch within the guest transport request limit', function (): void {
        $files = [];
        foreach (range(1, 43) as $index) {
            $files[sprintf('fixture-%02d.sh', $index)] = ["#!/bin/sh\nexit 0\n", 0644];
        }
        ['repository' => $repository, 'commit' => $commit] = proofFixtureRepository('AUX-7', $files);
        $guest = new ProofFixtureGuestFake(maxBatchSize: 128);

        new ProofFixtureStager($guest, new OperationId(str_repeat('d', 32)))->stage(
            featureTarget('AUX-7', 'b'),
            $repository,
            $commit,
        );

        expect(max(array_map(count(...), $guest->batches)))
            ->toBeLessThanOrEqual(128);
    });

    it('stages and verifies fixtures on all four extended physical Nodes', function (): void {
        ['repository' => $repository, 'commit' => $commit] = proofFixtureRepository('AUX-132', [
            'extended-runtime-connectivity.sh' => ["#!/bin/sh\nexit 0\n", 0755],
        ]);
        $guest = new ProofFixtureGuestFake;
        $target = featureTarget('AUX-132', 'b', TopologyRecipe::extendedAppProd());

        $fixtures = new ProofFixtureStager($guest, new OperationId(str_repeat('a', 32)))
            ->stage($target, $repository, $commit);

        expect(array_keys($fixtures->roles))
            ->toBe(['gateway', 'app-dev', 'app-prod', 'app-prod-2'])
            ->and(array_keys($guest->installed))
            ->toBe(array_map($target->instance(...), $target->recipe->nodeKeys()))
            ->and($guest->reset)
            ->toBe(array_map($target->instance(...), $target->recipe->nodeKeys()));
    });

    it('empties the guest fixture directory on every role before it installs', function (): void {
        ['repository' => $repository, 'commit' => $commit] = proofFixtureRepository('TST-100', [
            'check.sh' => ["#!/bin/sh\nexit 0\n", 0755],
        ]);
        $guest = new ProofFixtureGuestFake;
        $guest->installed['orbit-e2e-tst-100-bbbbbbbb-gateway'] = ['marker' => ['mode' => '644', 'content' => "x\n"]];
        $target = featureTarget('TST-100', 'b');

        new ProofFixtureStager($guest, new OperationId(str_repeat('c', 32)))->stage($target, $repository, $commit);

        expect($guest->reset)
            ->toHaveCount(3)
            ->and($guest->installed['orbit-e2e-tst-100-bbbbbbbb-gateway'])
            ->toHaveKeys(['check.sh'])
            ->and($guest->installed['orbit-e2e-tst-100-bbbbbbbb-gateway'])
            ->not->toHaveKey('marker');
    });

    it('stages an empty inventory without pushing files when the issue has no fixture directory', function (): void {
        ['repository' => $repository, 'commit' => $commit] = proofFixtureRepository('TST-82', []);
        $guest = new ProofFixtureGuestFake;

        $fixtures = new ProofFixtureStager($guest, new OperationId(str_repeat('a', 32)))
            ->stage(featureTarget('TST-82', 'b'), $repository, $commit);

        expect($fixtures->files)
            ->toBe([])
            ->and($fixtures->digest)
            ->toBe(hash('sha256', ''))
            ->and($guest->pushes)
            ->toBe([])
            ->and(array_map(static fn (array $labels): string => explode('.', $labels[0])[0], $guest->batches))
            ->toBe(['fixture-prepare', 'fixture-directory', 'fixture-verify', 'fixture-cleanup']);
    });

    it('reads only current-workspace fixtures at the exact commit and excludes the plan', function (): void {
        ['repository' => $repository, 'commit' => $commit] = proofFixtureRepository('TST-82', [
            'check.sh' => ["#!/bin/sh\n", 0755],
        ]);
        file_put_contents($repository->root().'/.loop/proof/later.txt', "later\n");
        proofFixtureGit($repository->root(), ['add', '.']);
        proofFixtureGit($repository->root(), ['commit', '--quiet', '-m', 'later fixture']);
        $stager = new ProofFixtureStager(new ProofFixtureGuestFake, new OperationId(str_repeat('a', 32)));

        expect(array_keys($stager->inventory($repository, $commit, 'TST-82')))
            ->toBe(['check.sh']);
    });

    it('stages only flat fixtures carried by the active workspace', function (): void {
        ['repository' => $repository, 'commit' => $commit] = proofFixtureRepository('AUX-7', [
            'driver.sh' => ["#!/bin/sh\n", 0755],
            'recover.sh' => ["#!/bin/sh\nexit 0\n", 0755],
            'support.txt' => ["support\n", 0644],
        ]);
        $guest = new ProofFixtureGuestFake;
        $target = featureTarget('AUX-7', 'b');

        $fixtures = new ProofFixtureStager($guest, new OperationId(str_repeat('a', 32)))
            ->stage($target, $repository, $commit);

        expect(array_keys($fixtures->files))
            ->toBe(['driver.sh', 'recover.sh', 'support.txt'])
            ->and(array_keys($guest->installed[$target->instance('app-prod')]))
            ->toBe(['driver.sh', 'recover.sh', 'support.txt'])
            ->and(array_column($guest->pushes, 'label'))
            ->each->toMatch('/\A[a-zA-Z0-9][a-zA-Z0-9_.-]{0,62}\z/D');
    });

    it('fails closed and cleans the staging directory when a role reports another inventory', function (): void {
        ['repository' => $repository, 'commit' => $commit] = proofFixtureRepository('TST-82', [
            'check.sh' => ["#!/bin/sh\n", 0755],
        ]);
        $target = featureTarget('TST-82', 'b');
        $guest = new ProofFixtureGuestFake([
            $target->instance('app-prod') => "check.sh\t644\t".str_repeat('0', 64)."\n",
        ]);

        expect(
            fn () => new ProofFixtureStager($guest, new OperationId(str_repeat('a', 32)))->stage(
                $target,
                $repository,
                $commit,
            ),
        )
            ->toThrow(RuntimeException::class, 'Role [app-prod] does not hold the staged proof fixture inventory.')
            ->and(explode('.', end($guest->batches)[0])[0])
            ->toBe('fixture-cleanup')
            ->and(glob(sys_get_temp_dir().'/orbit-proof-fixtures-*'))
            ->toBe([]);
    });

    it('fails closed when a guest install refuses', function (): void {
        ['repository' => $repository, 'commit' => $commit] = proofFixtureRepository('TST-82', [
            'check.sh' => ["#!/bin/sh\n", 0755],
        ]);
        $target = featureTarget('TST-82', 'b');
        $guest = new ProofFixtureGuestFake(failingLabel: 'fixture-install.app-dev.0');

        expect(
            fn () => new ProofFixtureStager($guest, new OperationId(str_repeat('a', 32)))->stage(
                $target,
                $repository,
                $commit,
            ),
        )
            ->toThrow(
                RuntimeException::class,
                'Proof fixture installation failed. Failed operations: fixture-install.app-dev.0.',
            );
    });

    it('refuses a fixture whose name is not shell-safe', function (): void {
        ['repository' => $repository, 'commit' => $commit] = proofFixtureRepository('TST-82', [
            'Check File.sh' => ["#!/bin/sh\n", 0755],
        ]);
        $stager = new ProofFixtureStager(new ProofFixtureGuestFake, new OperationId(str_repeat('a', 32)));

        expect(fn () => $stager->inventory($repository, $commit, 'TST-82'))
            ->toThrow(RuntimeException::class, 'Proof fixture [Check File.sh] has an invalid file name.');
    });
});
