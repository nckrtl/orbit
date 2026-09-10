<?php

declare(strict_types=1);

use App\E2E\ColdTopologyConstructor;
use App\E2E\Git\GitRepository;
use App\E2E\HostCapacity;
use App\E2E\IncusHost;
use App\E2E\IncusNetworkLifecycle;
use App\E2E\PreparedStateFingerprint;
use App\E2E\State\AtomicJsonStore;
use App\E2E\State\OperationLock;
use App\E2E\State\SecretRedactor;
use App\E2E\State\StatePaths;
use App\E2E\TopologyConverger;
use App\E2E\TopologySnapshotManifestStore;
use App\E2E\TopologySnapshotPromotionStore;
use App\E2E\TopologySnapshotReplacementInstaller;
use App\E2E\TopologySnapshotReplacementStore;
use App\E2E\TopologyVerifier;
use App\E2E\Value\AttemptId;
use App\E2E\Value\AttemptPurpose;
use App\E2E\Value\CapturedProof;
use App\E2E\Value\FeatureTopology;
use App\E2E\Value\LaravelRelease;
use App\E2E\Value\OperationId;
use App\E2E\Value\ProofInputManifest;
use App\E2E\Value\SourceState;
use App\E2E\Value\TopologyConstructionInputs;
use App\E2E\Value\TopologyProfile;
use App\E2E\Value\TopologyRecipe;
use App\E2E\Value\TopologyRequest;
use App\E2E\Value\TopologySnapshotGeneration;
use App\E2E\Value\TopologySnapshotIdentity;
use App\E2E\Value\TopologySnapshotReplacementInstallation;
use App\E2E\Value\TopologySnapshotReplacementResult;
use App\E2E\Value\TopologyTarget;
use App\E2E\Value\VerificationReport;
use App\E2E\WorktreeSynchronizer;
use Illuminate\Container\Container;
use Illuminate\Process\Factory as ProcessFactory;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\Facade;
use Illuminate\Support\Facades\Process;

require_once __DIR__.'/Support/TopologyFixtures.php';

beforeEach(function (): void {
    $container = new Container;
    $container->instance(ProcessFactory::class, new ProcessFactory);
    Facade::clearResolvedInstances();
    Facade::setFacadeApplication($container);
});

/** @return array{capture:CapturedProof,installation:TopologySnapshotReplacementInstallation,candidate:string,artifact:string,merge:string,main:string} */
function interruptedReplacementFixture(): array
{
    $issue = 'TST-231';
    $proofAttempt = new AttemptId(str_repeat('a', 32));
    $replacementAttempt = new AttemptId(str_repeat('b', 32));
    $operation = new OperationId(str_repeat('c', 32));
    $candidate = str_repeat('1', 40);
    $artifact = str_repeat('2', 40);
    $merge = str_repeat('3', 40);
    $main = str_repeat('4', 40);
    $alias = TopologyRecipe::BASE_IMAGE;
    $imageFingerprint = str_repeat('5', 64);
    $laravel = new LaravelRelease('v13.0.0', str_repeat('6', 40));
    $proofGeneration = replacementGeneration(
        'proof-generation',
        $candidate,
        str_repeat('7', 64),
        $imageFingerprint,
        $alias,
        $laravel,
    );
    $proofTarget = TopologyTarget::feature($issue, $proofAttempt, TopologyRecipe::registered($alias));
    $construction = TopologyConstructionInputs::forSnapshotReplacement(
        $proofTarget,
        2,
        $alias,
        $imageFingerprint,
    );
    $verification = new VerificationReport(true, [
        'replacement' => verificationProbeFixture(),
    ]);
    $topology = new FeatureTopology(
        $construction,
        AttemptPurpose::Proof,
        $proofGeneration,
        new SourceState($candidate, $candidate, operationId: $operation->value),
        $verification,
    );
    $manifest = new ProofInputManifest(
        1,
        $candidate,
        $candidate,
        [],
        [],
        '.loop/proof/TST-231.json',
        [],
        $construction,
        null,
        [
            'static_classification' => true,
            'proof_contract' => true,
            'checkout_literals' => true,
            'observed_processes' => true,
            'observed_paths' => true,
            'pcov_cleanup' => true,
        ],
    );
    $proof = [
        'status' => 'proved',
        'issue' => $issue,
        'attempt_id' => $proofAttempt->value,
        'candidate_sha' => $candidate,
        'plan_sha256' => str_repeat('8', 64),
        'manifest_sha256' => $manifest->fingerprint(),
    ];
    $capture = new CapturedProof(
        $issue,
        $proofAttempt,
        $candidate,
        str_repeat('8', 64),
        $manifest->fingerprint(),
        $proof,
        $topology,
        $manifest->toArray(),
        '2026-09-10T12:00:00Z',
    );
    $old = replacementGeneration(
        'old-generation',
        str_repeat('9', 40),
        str_repeat('a', 64),
        str_repeat('b', 64),
        $alias,
        $laravel,
    );
    $new = replacementGeneration(
        'new-generation',
        $main,
        str_repeat('c', 64),
        $imageFingerprint,
        $alias,
        $laravel,
        $old->id,
    );
    $temporary = TopologyTarget::disposableCold(
        $issue,
        $replacementAttempt,
        TopologyRecipe::registered($alias),
    );
    $canonical = TopologyTarget::topologySnapshot();
    $temporaryInstances = [];
    $canonicalInstances = [];
    $nextInstances = [];
    $oldInstances = [];
    foreach (TopologyProfile::ROLES as $role) {
        $temporaryInstances[$role] = $temporary->instance($role);
        $canonicalInstances[$role] = $canonical->instance($role);
        $nextInstances[$role] = $canonicalInstances[$role].'-next';
        $oldInstances[$role] = $canonicalInstances[$role].'-old';
    }
    $installation = new TopologySnapshotReplacementInstallation(
        $issue,
        $proofAttempt,
        $replacementAttempt,
        $operation,
        $candidate,
        $artifact,
        $merge,
        $main,
        $capture->fingerprint(),
        $manifest->fingerprint(),
        $old,
        $new,
        $alias,
        $imageFingerprint,
        $temporary->network(),
        $temporaryInstances,
        $canonicalInstances,
        $nextInstances,
        $oldInstances,
    );

    return compact('capture', 'installation', 'candidate', 'artifact', 'merge', 'main');
}

function replacementGeneration(
    string $id,
    string $sha,
    string $prepared,
    string $imageFingerprint,
    string $alias,
    LaravelRelease $laravel,
    ?string $previous = null,
): TopologySnapshotGeneration {
    return new TopologySnapshotGeneration(
        $id,
        $sha,
        array_fill_keys(TopologyProfile::ROLES, 'main-'.$id),
        $prepared,
        $imageFingerprint,
        $laravel,
        str_repeat('d', 64),
        2,
        'cold-v1',
        $alias,
        TopologyProfile::NAME,
        TopologyProfile::ROLES,
        TopologyProfile::CHECKOUT_ROLES,
        $previous,
        TopologyProfile::ASSIGNMENTS,
    );
}

final class ReplacementInstallerIncusFake
{
    /** @var array<string, array{role:string,generation:string,metadata:array<string,string>,network:string,mac:string,running:bool}> */
    public array $instances = [];

    /** @var array<string, list<string>> */
    public array $snapshots = [];

    /** @var array<string, array<string,string>> */
    public array $networks = [];

    /** @var list<mixed> */
    public array $events = [];

    public int $renameCount = 0;

    public bool $renameFailed = false;

    public bool $oldDeleteFailed = false;

    public function __construct(
        public readonly TopologySnapshotReplacementInstallation $installation,
        public ?int $failRenameAt = null,
        public bool $failOldDeleteOnce = false,
        bool $seedTemporary = true,
        public ?string $mainSha = null,
        public ?string $mainTree = null,
    ) {
        $snapshot = TopologyTarget::topologySnapshot();
        foreach (TopologyProfile::ROLES as $role) {
            $this->put(
                $installation->canonicalInstances[$role],
                $role,
                'old',
                ['user.orbit.e2e.owner' => 'orbit-e2e'],
                $snapshot->network(),
                $snapshot->mac($role),
                [$installation->oldGeneration->snapshots[$role]],
            );
            if ($seedTemporary) {
                $this->put(
                    $installation->temporaryInstances[$role],
                    $role,
                    'temporary',
                    [
                        'user.orbit.e2e.owner' => 'orbit-e2e',
                        'user.orbit.e2e.operation' => $installation->resourceOperation->value,
                        'user.orbit.e2e.issue' => $installation->issue,
                        'user.orbit.e2e.attempt' => $installation->replacementAttempt->value,
                    ],
                    $installation->temporaryNetwork,
                    TopologyTarget::macFor($installation->temporaryNetwork, $role),
                );
            }
        }
        if ($seedTemporary) {
            $this->networks[$installation->temporaryNetwork] = [
                'ipv4.address' => '10.232.2.1/24',
                'user.orbit.e2e.owner' => 'orbit-e2e',
                'user.orbit.e2e.operation' => $installation->resourceOperation->value,
            ];
        }
    }

    public function __invoke(PendingProcess $process): mixed
    {
        $command = $process->command;
        if (! is_array($command)) {
            throw new RuntimeException('Expected an array process command.');
        }
        if (($command[0] ?? null) === 'git') {
            $real = new ProcessFactory;
            $pending = $real->path((string) ($process->path ?: getcwd()));
            if ($process->input !== null) {
                $pending = $pending->input($process->input);
            }

            return $pending->run($command);
        }
        $events = &$this->events;
        $batch = pinnedWorktreeBatchResult(
            $process,
            $events,
            fn (array $guest): mixed => $this->guestOverride($guest),
        );
        if ($batch !== null) {
            return $batch;
        }
        if ($command[0] === 'python3') {
            $this->events[] = 'firewall';

            return Process::result('{"changed":true}');
        }
        if (array_slice($command, 0, 3) !== ['incus', '--project', 'default']) {
            throw new RuntimeException('Unexpected process command: '.implode(' ', $command));
        }
        $arguments = array_slice($command, 3);
        if (($arguments[0] ?? null) === 'image' && ($arguments[1] ?? null) === 'list') {
            return Process::result(preparedBaseImageJson($this->installation->genericImageFingerprint));
        }
        if (array_slice($arguments, 0, 2) === ['network', 'create']) {
            $name = $this->localName($arguments[2]);
            $configuration = [];
            foreach (array_slice($arguments, 3) as $item) {
                if (! is_string($item) || ! str_contains($item, '=')) {
                    continue;
                }
                [$key, $value] = explode('=', $item, 2);
                $configuration[$key] = $value;
            }
            $this->networks[$name] = $configuration;
            $this->events[] = 'create-network:'.$name;

            return Process::result();
        }
        if (($arguments[0] ?? null) === 'init') {
            $name = $this->localName($arguments[2]);
            $role = array_search($name, $this->installation->temporaryInstances, true);
            if (! is_string($role)) {
                throw new RuntimeException('Unexpected replacement construction target.');
            }
            $metadata = ['user.orbit.e2e.owner' => 'orbit-e2e'];
            foreach ($arguments as $item) {
                if (! is_string($item) || ! str_starts_with($item, 'user.orbit.e2e.') || ! str_contains($item, '=')) {
                    continue;
                }
                [$key, $value] = explode('=', $item, 2);
                $metadata[$key] = $value;
            }
            $this->put(
                $name,
                $role,
                'temporary',
                $metadata,
                $this->installation->temporaryNetwork,
                TopologyTarget::macFor($this->installation->temporaryNetwork, $role),
            );
            $this->events[] = 'init:'.$name;

            return Process::result();
        }
        if (($arguments[0] ?? null) === 'start') {
            $name = $this->localName($arguments[1]);
            $this->instances[$name]['running'] = true;
            $this->events[] = 'start:'.$name;

            return Process::result();
        }
        if (($arguments[0] ?? null) === 'stop') {
            $name = $this->localName($arguments[1]);
            $this->instances[$name]['running'] = false;
            $this->events[] = 'stop:'.$name;

            return Process::result();
        }
        if ($arguments === ['list', 'local:', '--format=json']) {
            return Process::result(json_encode(array_map(
                fn (string $name): array => $this->resource($name),
                array_keys($this->instances),
            ), JSON_THROW_ON_ERROR));
        }
        if (
            ($arguments[0] ?? null) === 'list'
            && is_string($arguments[1] ?? null)
            && str_starts_with($arguments[1], 'local:')
            && ($arguments[2] ?? null) === '--format=json'
        ) {
            $name = $this->localName($arguments[1]);

            return Process::result(json_encode(
                isset($this->instances[$name]) ? [$this->resource($name)] : [],
                JSON_THROW_ON_ERROR,
            ));
        }
        if ($arguments === ['network', 'list', 'local:', '--format=json']) {
            return Process::result(json_encode(array_map(
                fn (string $name): array => [
                    'name' => $name,
                    'type' => 'bridge',
                    'config' => $this->networks[$name],
                    'used_by' => [],
                ],
                array_keys($this->networks),
            ), JSON_THROW_ON_ERROR));
        }
        if (($arguments[0] ?? null) === 'copy') {
            $target = $this->localName($arguments[2]);
            $role = array_search($target, $this->installation->nextInstances, true);
            if (! is_string($role)) {
                throw new RuntimeException('Unexpected replacement copy target.');
            }
            $snapshot = TopologyTarget::topologySnapshot();
            $this->put(
                $target,
                $role,
                'new',
                [
                    'user.orbit.e2e.owner' => 'orbit-e2e',
                    'user.orbit.e2e.operation' => $this->installation->resourceOperation->value,
                ],
                $snapshot->network(),
                $snapshot->mac($role),
            );
            $this->events[] = 'copy:'.$target;

            return Process::result();
        }
        if (array_slice($arguments, 0, 2) === ['file', 'push']) {
            return Process::result();
        }
        if (array_slice($arguments, 0, 2) === ['config', 'unset']) {
            $name = $this->localName($arguments[2]);
            unset($this->instances[$name]['metadata'][$arguments[3]]);

            return Process::result();
        }
        if (array_slice($arguments, 0, 2) === ['snapshot', 'create']) {
            $name = $this->localName($arguments[2]);
            $this->snapshots[$name][] = $arguments[3];
            $this->events[] = 'snapshot:'.$name;

            return Process::result();
        }
        if (array_slice($arguments, 0, 2) === ['snapshot', 'list']) {
            $name = $this->localName($arguments[2]);

            return Process::result(json_encode(array_map(
                static fn (string $snapshot): array => [
                    'name' => $snapshot,
                    'created_at' => '2026-09-10T12:00:00Z',
                ],
                $this->snapshots[$name] ?? [],
            ), JSON_THROW_ON_ERROR));
        }
        if (($arguments[0] ?? null) === 'rename') {
            $from = $this->localName($arguments[1]);
            $to = $arguments[2];
            $this->renameCount++;
            $this->instances[$to] = $this->instances[$from];
            $this->snapshots[$to] = $this->snapshots[$from] ?? [];
            unset($this->instances[$from], $this->snapshots[$from]);
            $this->events[] = 'rename:'.$from.':'.$to;
            if ($this->failRenameAt === $this->renameCount && ! $this->renameFailed) {
                $this->renameFailed = true;

                return Process::result('', 'simulated rename interruption', 1);
            }

            return Process::result();
        }
        if (($arguments[0] ?? null) === 'delete') {
            $name = $this->localName($arguments[1]);
            if (
                $this->failOldDeleteOnce
                && ! $this->oldDeleteFailed
                && in_array($name, $this->installation->oldInstances, true)
            ) {
                $this->oldDeleteFailed = true;

                return Process::result('', 'simulated cleanup interruption', 1);
            }
            unset($this->instances[$name], $this->snapshots[$name]);
            $this->events[] = 'delete:'.$name;

            return Process::result();
        }
        if (array_slice($arguments, 0, 2) === ['network', 'delete']) {
            $name = $this->localName($arguments[2]);
            unset($this->networks[$name]);
            $this->events[] = 'delete-network:'.$name;

            return Process::result();
        }
        if (($arguments[0] ?? null) === 'exec') {
            $guest = array_slice($arguments, 3);

            return $this->guestOverride($guest) ?? pinnedWorktreeGuestResult($command);
        }

        throw new RuntimeException('Unexpected Incus command: '.implode(' ', $arguments));
    }

    /** @param array<string,string> $metadata @param list<string> $snapshots */
    private function put(
        string $name,
        string $role,
        string $generation,
        array $metadata,
        string $network,
        string $mac,
        array $snapshots = [],
        bool $running = false,
    ): void {
        $this->instances[$name] = compact('role', 'generation', 'metadata', 'network', 'mac', 'running');
        $this->snapshots[$name] = $snapshots;
    }

    /** @return array<string,mixed> */
    private function resource(string $name): array
    {
        $instance = $this->instances[$name];
        $slot = $instance['network'] === TopologyTarget::topologySnapshot()->network() ? 1 : 2;

        return [
            'name' => $name,
            'type' => 'virtual-machine',
            'status' => $instance['running'] ? 'Running' : 'Stopped',
            'status_code' => $instance['running'] ? 103 : 102,
            'config' => $instance['metadata'],
            'devices' => [
                'root' => ['type' => 'disk', 'pool' => 'orbit-e2e'],
                'eth0' => [
                    'network' => $instance['network'],
                    'hwaddr' => $instance['mac'],
                    'ipv4.address' => TopologyTarget::ipv4For($slot, $instance['role']),
                ],
            ],
        ];
    }

    private function localName(string $target): string
    {
        return str_starts_with($target, 'local:') ? substr($target, 6) : $target;
    }

    /** @param list<string> $guest */
    private function guestOverride(array $guest): mixed
    {
        if (array_slice($guest, 0, 6) === ['runuser', '-u', 'orbit', '--', 'env', 'HOME=/home/orbit']) {
            $guest = array_slice($guest, 6);
        }
        if (
            $this->mainSha !== null
            && $guest === ['git', '-C', '/home/orbit/orbit', 'rev-parse', '--verify', 'HEAD^{commit}']
        ) {
            return Process::result($this->mainSha."\n");
        }
        if (
            $this->mainTree !== null
            && $guest === ['git', '-C', '/home/orbit/orbit', 'rev-parse', '--verify', 'HEAD^{tree}']
        ) {
            return Process::result($this->mainTree."\n");
        }
        if ($guest === ['git', '-C', '/home/orbit/orbit', 'status', '--porcelain=v1', '--untracked-files=all']) {
            return Process::result();
        }
        if (
            $guest === ['/usr/local/bin/converge-sample-app.sh', 'inspect-state', 'native']
            || ($guest[0] ?? null) === '/usr/local/bin/converge-sample-app.sh'
            && ($guest[1] ?? null) === 'create-resources'
            && ($guest[5] ?? null) === 'native'
        ) {
            return Process::result(json_encode([
                'shape' => 'app_instances',
                'app_id' => 1,
                'node_id' => 2,
                'name' => 'e2e-dev',
                'checkout_path' => '/srv/orbit/apps/e2e-dev',
                'effective_root' => 'public',
            ], JSON_THROW_ON_ERROR));
        }

        return null;
    }
}

function seedReplacementTransaction(
    TopologySnapshotReplacementStore $store,
    TopologySnapshotReplacementInstallation $installation,
): void {
    $recovery = $store->start($installation, '2026-09-10T12:00:00Z')
        ->withPhase('construction_pending', '2026-09-10T12:00:01Z')
        ->withPhase('construction_verified', '2026-09-10T12:00:02Z');
    $store->advance($recovery);
}

function retriedReplacementInstallation(
    TopologySnapshotReplacementInstallation $previous,
): TopologySnapshotReplacementInstallation {
    $attempt = new AttemptId(str_repeat('f', 32));
    $operation = new OperationId(str_repeat('0', 32));
    $temporary = TopologyTarget::disposableCold(
        $previous->issue,
        $attempt,
        TopologyRecipe::registered($previous->genericImageAlias),
    );
    $temporaryInstances = [];
    foreach (TopologyProfile::ROLES as $role) {
        $temporaryInstances[$role] = $temporary->instance($role);
    }

    return new TopologySnapshotReplacementInstallation(
        $previous->issue,
        $previous->proofAttempt,
        $attempt,
        $operation,
        $previous->candidateSha,
        $previous->artifactSha,
        $previous->mergeSha,
        $previous->mainSha,
        $previous->capturedProofFingerprint,
        $previous->manifestFingerprint,
        $previous->oldGeneration,
        $previous->newGeneration,
        $previous->genericImageAlias,
        $previous->genericImageFingerprint,
        $temporary->network(),
        $temporaryInstances,
        $previous->canonicalInstances,
        $previous->nextInstances,
        $previous->oldInstances,
    );
}

function replacementInstallationAtMain(
    TopologySnapshotReplacementInstallation $installation,
    string $mainSha,
): TopologySnapshotReplacementInstallation {
    $generation = $installation->newGeneration;
    $new = new TopologySnapshotGeneration(
        $generation->id,
        $mainSha,
        $generation->snapshots,
        $generation->preparedFingerprint,
        $generation->baseImageFingerprint,
        $generation->laravel,
        $generation->structuralFingerprint,
        $generation->preparedSchema,
        $generation->coldEpoch,
        $generation->baseImageAlias,
        $generation->topologyProfile,
        $generation->topologyRoles,
        $generation->checkoutRoles,
        $generation->previousGenerationId,
        $generation->topologyAssignments,
        $generation->manifestSchema,
    );

    return new TopologySnapshotReplacementInstallation(
        $installation->issue,
        $installation->proofAttempt,
        $installation->replacementAttempt,
        $installation->resourceOperation,
        $installation->candidateSha,
        $installation->artifactSha,
        $installation->mergeSha,
        $mainSha,
        $installation->capturedProofFingerprint,
        $installation->manifestFingerprint,
        $installation->oldGeneration,
        $new,
        $installation->genericImageAlias,
        $installation->genericImageFingerprint,
        $installation->temporaryNetwork,
        $installation->temporaryInstances,
        $installation->canonicalInstances,
        $installation->nextInstances,
        $installation->oldInstances,
    );
}

function interruptedReplacementInstaller(
    StatePaths $paths,
    TopologySnapshotReplacementStore $store,
    ?string $repositoryRoot = null,
): TopologySnapshotReplacementInstaller {
    $repositoryRoot ??= dirname(__DIR__, 5);
    $host = new IncusHost(pool: 'orbit-e2e');
    $operation = new OperationId(str_repeat('e', 32));
    $constructor = new ColdTopologyConstructor(
        $host,
        new IncusNetworkLifecycle($host),
        new WorktreeSynchronizer($host, $repositoryRoot, $operation),
        new TopologyConverger($host),
        new HostCapacity($host, 9),
        $paths,
    );
    $git = new GitRepository($repositoryRoot);

    return new TopologySnapshotReplacementInstaller(
        $host,
        $constructor,
        new PreparedStateFingerprint($git),
        new TopologyVerifier($host, 1, 0),
        new TopologySnapshotManifestStore(new AtomicJsonStore($paths), $paths, $host),
        new TopologySnapshotPromotionStore(new AtomicJsonStore($paths)),
        $store,
        new OperationLock($paths),
        new OperationLock($paths),
        $git,
        $operation,
        TopologySnapshotIdentity::primary(),
        $repositoryRoot,
        new SecretRedactor,
    );
}

it('cleans and archives interrupted preparation before allowing a fresh retry', function (string $phase): void {
    $fixture = interruptedReplacementFixture();
    $paths = new StatePaths(temporaryPath('replacement-installer-', 4));
    $store = new TopologySnapshotReplacementStore(new AtomicJsonStore($paths));
    $manifests = new TopologySnapshotManifestStore(new AtomicJsonStore($paths), $paths, new IncusHost);
    $manifests->record($fixture['installation']->oldGeneration);
    $manifests->promote($fixture['installation']->oldGeneration);
    $manifests->record($fixture['installation']->newGeneration);
    $recovery = $store->start($fixture['installation'], '2026-09-10T12:00:00Z')
        ->withPhase('construction_pending', '2026-09-10T12:00:01Z');
    if ($phase === 'staging_pending') {
        $recovery = $recovery
            ->withPhase('construction_verified', '2026-09-10T12:00:02Z')
            ->withPhase('staging_pending', '2026-09-10T12:00:03Z');
    }
    $store->advance($recovery);
    Process::fake(function (PendingProcess $process) {
        $command = $process->command;
        assert(is_array($command));
        if (
            $command === ['incus', '--project', 'default', 'list', 'local:', '--format=json']
            || $command === ['incus', '--project', 'default', 'network', 'list', 'local:', '--format=json']
        ) {
            return Process::result('[]');
        }

        throw new RuntimeException('Unexpected mutation: '.implode(' ', $command));
    });

    $result = interruptedReplacementInstaller($paths, $store)->install(
        new TopologyRequest('TST-231', dirname(__DIR__, 5)),
        $fixture['capture'],
        $fixture['candidate'],
        $fixture['artifact'],
        $fixture['merge'],
        $fixture['main'],
    );
    expect($result->state)
        ->toBe('failed')
        ->and($result->phase)
        ->toBe('abandoned')
        ->and($store->active())
        ->toBeNull()
        ->and($store->archived($fixture['capture']->attempt)?->phase)
        ->toBe('abandoned')
        ->and($manifests->promoted()?->id)
        ->toBe($fixture['installation']->oldGeneration->id)
        ->and(array_map(static fn (TopologySnapshotGeneration $generation): string => $generation->id, $manifests->recorded()))
        ->not->toContain($fixture['installation']->newGeneration->id);
})->with(['construction_pending', 'staging_pending']);

it('records clean reconstruction lineage when resuming after manifest promotion', function (): void {
    $fixture = interruptedReplacementFixture();
    $paths = new StatePaths(temporaryPath('replacement-lineage-', 4));
    $atomic = new AtomicJsonStore($paths);
    $store = new TopologySnapshotReplacementStore($atomic);
    $manifests = new TopologySnapshotManifestStore($atomic, $paths, new IncusHost);
    $manifests->record($fixture['installation']->oldGeneration);
    $manifests->promote($fixture['installation']->oldGeneration);
    $manifests->record($fixture['installation']->newGeneration);
    $manifests->promote($fixture['installation']->newGeneration);

    $recovery = $store->start($fixture['installation'], '2026-09-10T12:00:00Z');
    foreach ([
        'construction_pending',
        'construction_verified',
        'staging_pending',
        'staging_verified',
        'swap_pending',
        'swap_in_progress',
    ] as $phase) {
        $recovery = $recovery->withPhase($phase, '2026-09-10T12:00:01Z');
    }
    foreach (TopologyProfile::ROLES as $role) {
        $recovery = $recovery->withOldRename($role)->withNewRename($role);
    }
    $recovery = $recovery
        ->withPhase('manifest_pending', '2026-09-10T12:00:02Z')
        ->withGenerationRecorded();
    $store->advance($recovery);

    $installer = interruptedReplacementInstaller($paths, $store);
    $recordCommitted = new ReflectionMethod($installer, 'recordCommittedManifest');
    $resumed = $recordCommitted->invoke($installer, $recovery);
    $promotions = new TopologySnapshotPromotionStore($atomic);
    $lineage = $promotions->find($fixture['installation']->newGeneration->id);

    expect($resumed->phase)
        ->toBe('manifest_promoted')
        ->and($lineage)
        ->not->toBeNull()
        ->and($lineage['promotion_path'] ?? null)
        ->toBe('clean-reconstruction')
        ->and($lineage['proved_sha'] ?? null)
        ->toBe($fixture['candidate'])
        ->and($lineage['merged_sha'] ?? null)
        ->toBe($fixture['main']);

    $recordedAt = $lineage['recorded_at'] ?? null;
    $recordCommitted->invoke($installer, $resumed);

    expect($promotions->find($fixture['installation']->newGeneration->id)['recorded_at'] ?? null)
        ->toBe($recordedAt);
});

it('constructs and installs a clean replacement from authorized through complete', function (): void {
    $fixture = interruptedReplacementFixture();
    $repositoryRoot = preparedTopologyRepository();
    $processes = new ProcessFactory;
    $main = trim($processes->run(['git', '-C', $repositoryRoot, 'rev-parse', 'HEAD'])->output());
    $tree = trim($processes->run(['git', '-C', $repositoryRoot, 'rev-parse', 'HEAD^{tree}'])->output());
    $installation = replacementInstallationAtMain($fixture['installation'], $main);
    $paths = new StatePaths(temporaryPath('replacement-success-', 4));
    $atomic = new AtomicJsonStore($paths);
    $store = new TopologySnapshotReplacementStore($atomic);
    $manifests = new TopologySnapshotManifestStore($atomic, $paths, new IncusHost(pool: 'orbit-e2e'));
    $manifests->record($installation->oldGeneration);
    $manifests->promote($installation->oldGeneration);
    $store->start($installation, '2026-09-10T12:00:00Z');
    $incus = new ReplacementInstallerIncusFake(
        $installation,
        seedTemporary: false,
        mainSha: $main,
        mainTree: $tree,
    );
    Process::fake(fn (PendingProcess $process): mixed => $incus($process));

    $result = interruptedReplacementInstaller($paths, $store, $repositoryRoot)->install(
        new TopologyRequest('TST-231', dirname(__DIR__, 5)),
        $fixture['capture'],
        $fixture['candidate'],
        $fixture['artifact'],
        $fixture['merge'],
        $main,
    );
    $instanceNames = array_keys($incus->instances);
    $expectedInstances = array_values($installation->canonicalInstances);
    $history = array_column($store->archived($fixture['capture']->attempt)?->history ?? [], 'phase');
    sort($instanceNames);
    sort($expectedInstances);

    expect($result->state)
        ->toBe('installed')
        ->and($result->phase)
        ->toBe('complete')
        ->and($store->active())
        ->toBeNull()
        ->and($store->archived($fixture['capture']->attempt)?->phase)
        ->toBe('complete')
        ->and($history)
        ->toContain('construction_pending', 'construction_verified')
        ->and($manifests->promoted()?->toArray())
        ->toBe($installation->newGeneration->toArray())
        ->and((new TopologySnapshotPromotionStore($atomic))->find(
            $installation->newGeneration->id,
        )['promotion_path'] ?? null)
        ->toBe('clean-reconstruction')
        ->and($incus->events)
        ->toContain(
            'create-network:'.$installation->temporaryNetwork,
            'init:'.$installation->temporaryInstances['gateway'],
        )
        ->and($instanceNames)
        ->toBe($expectedInstances)
        ->and(array_unique(array_column($incus->instances, 'generation')))
        ->toBe(['new'])
        ->and($incus->networks)
        ->toBe([]);
});

it('rolls back every interrupted rename boundary to the exact old generation', function (int $boundary): void {
    $fixture = interruptedReplacementFixture();
    $paths = new StatePaths(temporaryPath('replacement-rollback-', 4));
    $atomic = new AtomicJsonStore($paths);
    $store = new TopologySnapshotReplacementStore($atomic);
    $manifests = new TopologySnapshotManifestStore($atomic, $paths, new IncusHost(pool: 'orbit-e2e'));
    $manifests->record($fixture['installation']->oldGeneration);
    $manifests->promote($fixture['installation']->oldGeneration);
    seedReplacementTransaction($store, $fixture['installation']);
    $incus = new ReplacementInstallerIncusFake($fixture['installation'], failRenameAt: $boundary);
    Process::fake(fn (PendingProcess $process): mixed => $incus($process));

    $result = interruptedReplacementInstaller($paths, $store)->install(
        new TopologyRequest('TST-231', dirname(__DIR__, 5)),
        $fixture['capture'],
        $fixture['candidate'],
        $fixture['artifact'],
        $fixture['merge'],
        $fixture['main'],
    );
    $instanceNames = array_keys($incus->instances);
    $expectedInstances = array_values($fixture['installation']->canonicalInstances);
    sort($instanceNames);
    sort($expectedInstances);

    expect($result->state)
        ->toBe('failed')
        ->and($result->phase)
        ->toBe('abandoned')
        ->and($store->active())
        ->toBeNull()
        ->and(array_column($store->archived($fixture['capture']->attempt)?->history ?? [], 'phase'))
        ->toContain('rolled_back')
        ->and($manifests->promoted()?->toArray())
        ->toBe($fixture['installation']->oldGeneration->toArray())
        ->and($instanceNames)
        ->toBe($expectedInstances)
        ->and(array_unique(array_column($incus->instances, 'generation')))
        ->toBe(['old'])
        ->and($incus->networks)
        ->toBe([]);
})->with([1, 2, 3, 4, 5, 6]);

it('retains cleanup pending after a committed cleanup failure and finishes it on retry', function (): void {
    $fixture = interruptedReplacementFixture();
    $paths = new StatePaths(temporaryPath('replacement-forward-recovery-', 4));
    $atomic = new AtomicJsonStore($paths);
    $store = new TopologySnapshotReplacementStore($atomic);
    $manifests = new TopologySnapshotManifestStore($atomic, $paths, new IncusHost(pool: 'orbit-e2e'));
    $manifests->record($fixture['installation']->oldGeneration);
    $manifests->promote($fixture['installation']->oldGeneration);
    seedReplacementTransaction($store, $fixture['installation']);
    $incus = new ReplacementInstallerIncusFake($fixture['installation'], failOldDeleteOnce: true);
    Process::fake(fn (PendingProcess $process): mixed => $incus($process));
    $installer = interruptedReplacementInstaller($paths, $store);
    $arguments = [
        new TopologyRequest('TST-231', dirname(__DIR__, 5)),
        $fixture['capture'],
        $fixture['candidate'],
        $fixture['artifact'],
        $fixture['merge'],
        $fixture['main'],
    ];

    $failed = $installer->install(...$arguments);

    expect($failed->state)
        ->toBe('failed')
        ->and($failed->phase)
        ->toBe('cleanup_pending')
        ->and($failed->error)
        ->toContain('simulated cleanup interruption')
        ->not->toContain('phase transition is invalid')
        ->and($store->active()?->phase)
        ->toBe('cleanup_pending')
        ->and($manifests->promoted()?->toArray())
        ->toBe($fixture['installation']->newGeneration->toArray());

    $installed = $installer->install(...$arguments);

    expect($installed->state)
        ->toBe('installed')
        ->and($installed->phase)
        ->toBe('complete')
        ->and($store->active())
        ->toBeNull()
        ->and(array_keys($incus->instances))
        ->toBe(array_values($fixture['installation']->canonicalInstances))
        ->and($incus->networks)
        ->toBe([]);
});

it('starts a fresh clean install after an archived rollback for the same retained proof', function (): void {
    $fixture = interruptedReplacementFixture();
    $paths = new StatePaths(temporaryPath('replacement-fresh-retry-', 4));
    $atomic = new AtomicJsonStore($paths);
    $store = new TopologySnapshotReplacementStore($atomic);
    $manifests = new TopologySnapshotManifestStore($atomic, $paths, new IncusHost(pool: 'orbit-e2e'));
    $manifests->record($fixture['installation']->oldGeneration);
    $manifests->promote($fixture['installation']->oldGeneration);
    seedReplacementTransaction($store, $fixture['installation']);
    $failedIncus = new ReplacementInstallerIncusFake($fixture['installation'], failRenameAt: 2);
    Process::fake(fn (PendingProcess $process): mixed => $failedIncus($process));
    $request = new TopologyRequest('TST-231', dirname(__DIR__, 5));

    $failed = interruptedReplacementInstaller($paths, $store)->install(
        $request,
        $fixture['capture'],
        $fixture['candidate'],
        $fixture['artifact'],
        $fixture['merge'],
        $fixture['main'],
    );
    $retry = retriedReplacementInstallation($fixture['installation']);
    seedReplacementTransaction($store, $retry);
    $retryIncus = new ReplacementInstallerIncusFake($retry);
    Process::fake(fn (PendingProcess $process): mixed => $retryIncus($process));
    $installed = interruptedReplacementInstaller($paths, $store)->install(
        $request,
        $fixture['capture'],
        $fixture['candidate'],
        $fixture['artifact'],
        $fixture['merge'],
        $fixture['main'],
    );

    expect($failed->phase)
        ->toBe('abandoned')
        ->and($installed->state)
        ->toBe('installed')
        ->and($store->archived($fixture['capture']->attempt)?->phase)
        ->toBe('complete')
        ->and($atomic->read(
            'topology-snapshot/replacements/'.$fixture['capture']->attempt->value
            .'/'.$fixture['installation']->replacementAttempt->value.'.json',
        )['phase'] ?? null)
        ->toBe('abandoned')
        ->and($manifests->promoted()?->toArray())
        ->toBe($retry->newGeneration->toArray());
});

it('returns an idempotent abandonment result when no installation exists', function (): void {
    $fixture = interruptedReplacementFixture();
    $paths = new StatePaths(temporaryPath('replacement-abandon-', 4));
    $store = new TopologySnapshotReplacementStore(new AtomicJsonStore($paths));

    $result = interruptedReplacementInstaller($paths, $store)->abandon(
        new TopologyRequest('TST-231', dirname(__DIR__, 5)),
        $fixture['capture'],
    );

    expect($result->state)->toBe('abandoned')
        ->and($result->successful())->toBeFalse()
        ->and($result->error)->toBeNull();
});

it('accepts only complete installed and actionable failed results', function (): void {
    $installed = new TopologySnapshotReplacementResult(
        'installed',
        str_repeat('a', 32),
        'new-generation',
        'complete',
    );

    expect($installed->successful())->toBeTrue()
        ->and($installed->toArray()['generation_id'])->toBe('new-generation')
        ->and(fn () => new TopologySnapshotReplacementResult(
            'failed',
            str_repeat('a', 32),
            null,
            'validation',
        ))->toThrow(InvalidArgumentException::class);
});
