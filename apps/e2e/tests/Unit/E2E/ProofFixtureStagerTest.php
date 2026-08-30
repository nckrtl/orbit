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
use Illuminate\Container\Container;
use Illuminate\Process\Factory as ProcessFactory;
use Illuminate\Support\Facades\Facade;

/** A guest that installs whatever is pushed and reports the inventory it holds per role. */
final class ProofFixtureGuestFake implements GuestTransport
{
    /** @var list<list<string>> */
    public array $batches = [];

    /** @var list<array{instance:string, source:string, destination:string, content:string}> */
    public array $pushes = [];

    /** @var array<string, array<string, array{mode:string, content:string}>> */
    public array $installed = [];

    /** @param array<string, string> $inventoryOverride Per instance, the inventory text to report instead of the real one. */
    public function __construct(
        public array $inventoryOverride = [],
        public ?string $failingLabel = null,
    ) {}

    public function exec(string $instance, GuestCommand $command): GuestCommandResult
    {
        throw new RuntimeException('The stager batches every guest command.');
    }

    public function execAll(array $commands): array
    {
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
        foreach ($files as $file) {
            $this->pushes[] = [...$file, 'content' => (string) file_get_contents($file['source'])];
        }
    }

    /** @param list<string> $argv */
    private function run(string $label, string $instance, array $argv): GuestCommandResult
    {
        if ($label === $this->failingLabel) {
            return new GuestCommandResult('', "refused\n", 1);
        }
        if ($argv[0] === 'install' && ($argv[1] ?? null) === '-o') {
            $name = basename($argv[8]);
            $staged = array_values(array_filter(
                $this->pushes,
                static fn (array $push): bool => $push['instance'] === $instance && $push['destination'] === $argv[7],
            ));
            $this->installed[$instance][$name] = ['mode' => ltrim($argv[6], '0'), 'content' => $staged[0]['content']];
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

/** @return array{repository:GitRepository, commit:string} */
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
    if ($files !== []) {
        $directory = $path.'/proofs/'.$issue;
        mkdir($directory, 0700, true);
        foreach ($files as $name => [$content, $mode]) {
            file_put_contents($directory.'/'.$name, $content);
            chmod($directory.'/'.$name, $mode);
        }
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
        ['repository' => $repository, 'commit' => $commit] = proofFixtureRepository('NCK-82', [
            'plan.json' => ["{}\n", 0644],
            'check.sh' => ["#!/bin/sh\nexit 0\n", 0755],
        ]);
        $guest = new ProofFixtureGuestFake;
        $target = featureTarget('NCK-82', 'b');
        $operation = new OperationId(str_repeat('a', 32));

        $fixtures = new ProofFixtureStager($guest, $operation)->stage($target, $repository, $commit);

        $files = [
            'check.sh' => ['mode' => '755', 'sha256' => hash('sha256', "#!/bin/sh\nexit 0\n")],
            'plan.json' => ['mode' => '644', 'sha256' => hash('sha256', "{}\n")],
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

    it('stages an empty inventory without pushing files when the issue has no fixture directory', function (): void {
        ['repository' => $repository, 'commit' => $commit] = proofFixtureRepository('NCK-82', []);
        $guest = new ProofFixtureGuestFake;

        $fixtures = new ProofFixtureStager($guest, new OperationId(str_repeat('a', 32)))
            ->stage(featureTarget('NCK-82', 'b'), $repository, $commit);

        expect($fixtures->files)
            ->toBe([])
            ->and($fixtures->digest)
            ->toBe(hash('sha256', ''))
            ->and($guest->pushes)
            ->toBe([])
            ->and(array_map(static fn (array $labels): string => explode('.', $labels[0])[0], $guest->batches))
            ->toBe(['fixture-prepare', 'fixture-directory', 'fixture-verify', 'fixture-cleanup']);
    });

    it('reads only the fixtures of the named issue at the exact commit', function (): void {
        ['repository' => $repository, 'commit' => $commit] = proofFixtureRepository('NCK-82', [
            'check.sh' => ["#!/bin/sh\n", 0755],
        ]);
        $stager = new ProofFixtureStager(new ProofFixtureGuestFake, new OperationId(str_repeat('a', 32)));

        expect(array_keys($stager->inventory($repository, $commit, 'NCK-82')))
            ->toBe(['check.sh'])
            ->and($stager->inventory($repository, $commit, 'NCK-58'))
            ->toBe([]);
    });

    it('fails closed and cleans the staging directory when a role reports another inventory', function (): void {
        ['repository' => $repository, 'commit' => $commit] = proofFixtureRepository('NCK-82', [
            'check.sh' => ["#!/bin/sh\n", 0755],
        ]);
        $target = featureTarget('NCK-82', 'b');
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
        ['repository' => $repository, 'commit' => $commit] = proofFixtureRepository('NCK-82', [
            'check.sh' => ["#!/bin/sh\n", 0755],
        ]);
        $target = featureTarget('NCK-82', 'b');
        $guest = new ProofFixtureGuestFake(failingLabel: 'fixture-install.app-dev.check.sh');

        expect(
            fn () => new ProofFixtureStager($guest, new OperationId(str_repeat('a', 32)))->stage(
                $target,
                $repository,
                $commit,
            ),
        )
            ->toThrow(
                RuntimeException::class,
                'Proof fixture installation failed. Failed operations: fixture-install.app-dev.check.sh.',
            );
    });

    it('refuses a fixture whose name is not shell-safe', function (): void {
        ['repository' => $repository, 'commit' => $commit] = proofFixtureRepository('NCK-82', [
            'Check File.sh' => ["#!/bin/sh\n", 0755],
        ]);
        $stager = new ProofFixtureStager(new ProofFixtureGuestFake, new OperationId(str_repeat('a', 32)));

        expect(fn () => $stager->inventory($repository, $commit, 'NCK-82'))
            ->toThrow(RuntimeException::class, 'Proof fixture [Check File.sh] has an invalid file name.');
    });
});
