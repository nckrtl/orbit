<?php

declare(strict_types=1);

namespace App\E2E;

use App\E2E\State\AtomicJsonStore;
use App\E2E\State\StatePaths;
use App\E2E\Value\LaravelRelease;
use App\E2E\Value\OperationId;
use App\E2E\Value\PreparedFingerprint;
use App\E2E\Value\SourceState;
use App\E2E\Value\TopologyProfile;
use App\E2E\Value\TopologyTarget;
use App\E2E\Value\VerificationMode;
use RuntimeException;
use Throwable;

/** @mago-expect lint:excessive-parameter-list,cyclomatic-complexity,kan-defect Cold construction keeps its exact resource transaction at one boundary. */
final readonly class StandbyBuilder
{
    public function __construct(
        private IncusHost $host,
        private IncusNetworkLifecycle $networks,
        private WorktreeSynchronizer $synchronizer,
        private TopologyConverger $converger,
        private TopologyVerifier $verifier,
        private StandbyManifestStore $manifests,
        private AtomicJsonStore $state,
        private StatePaths $paths,
        private string $mainWorktree,
    ) {}

    public function build(
        string $mainSha,
        PreparedFingerprint $fingerprint,
        string $baseImageFingerprint,
        LaravelRelease $laravel,
        bool $allowCold,
        OperationId $operation,
        string $evidenceId,
    ): SourceState {
        if (! $allowCold) {
            throw new RuntimeException('Cold standby construction requires explicit permission.');
        }

        if ($this->state->read('standby/corrupt.json') !== null) {
            throw new RuntimeException(
                'Cold standby construction is blocked until explicit recovery clears corrupt state.',
            );
        }

        if ($this->manifests->promoted() !== null) {
            throw new RuntimeException('Cold standby construction is refused while a promoted generation exists.');
        }

        if (preg_match('/\A[a-f0-9]{32}\z/D', $evidenceId) !== 1) {
            throw new RuntimeException('The cold-build evidence identity is invalid.');
        }
        $this->assertNoActiveIntent();

        $alias = $fingerprint->manifest['base_image_alias'] ?? null;
        if (! is_string($alias) || $alias === '') {
            throw new RuntimeException('The prepared fingerprint has no base image alias.');
        }

        $target = TopologyTarget::standby();
        if ($this->host->imageFingerprint($alias) !== $baseImageFingerprint) {
            throw new RuntimeException('The base image alias fingerprint changed before cold construction.');
        }
        if ($this->host->network($target->network()) !== null) {
            throw new RuntimeException('The standby network already exists without a promoted generation.');
        }
        $instanceNames = array_map($target->instance(...), TopologyProfile::ROLES);
        if ($this->host->instances($instanceNames) !== []) {
            throw new RuntimeException('A standby VM already exists without a promoted generation.');
        }

        $scope = $this->host->scope();
        $attempt = [
            'schema' => 3,
            'operation_id' => $operation->value,
            'evidence_id' => $evidenceId,
            'remote' => $scope['remote'],
            'project' => $scope['project'],
            'pool' => $scope['pool'],
            'network' => ['name' => $target->network(), 'state' => 'planned', 'absent_preflight' => true],
            'base_image_fingerprint' => $baseImageFingerprint,
            'instances' => array_map(fn (string $role): array => [
                'role' => $role,
                'name' => $target->instance($role),
                'network' => $target->network(),
                'state' => 'planned',
                'absent_preflight' => true,
            ], TopologyProfile::ROLES),
            'status' => 'creating',
        ];
        $this->recordAttempt($evidenceId, $attempt);

        try {
            $resourceMetadata = [
                'user.orbit.e2e.operation' => $operation->value,
                'user.orbit.e2e.evidence' => $evidenceId,
            ];
            $this->networks->create($target->network(), 1, $resourceMetadata);
            $attempt['network']['state'] = 'created';
            $this->recordAttempt($evidenceId, $attempt);
            $vms = [];
            foreach (TopologyProfile::ROLES as $role) {
                $vms[$role] = [
                    'image' => $alias,
                    'name' => $target->instance($role),
                    'network' => $target->network(),
                    'role' => $role,
                    'topology' => $target->network(),
                    'metadata' => $resourceMetadata,
                ];
            }
            $this->host->initVms($vms);
            foreach (TopologyProfile::ROLES as $index => $role) {
                $attempt['instances'][$index]['state'] = 'created';
                $this->recordAttempt($evidenceId, $attempt);
            }
            $instances = array_map($target->instance(...), TopologyProfile::ROLES);
            $this->host->startAll($instances);
            $this->host->prepareClonedHostStates($instances);

            $source = $this->synchronizer->sync($target, $this->mainWorktree);
            if ($source->hostSha !== $mainSha || $source->guestSha !== $mainSha || $source->dirty) {
                throw new RuntimeException('Cold standby source is not clean merged main.');
            }

            $this->converger->converge($target, $source, $laravel);
            $attempt['status'] = 'built';
            $this->recordAttempt($evidenceId, $attempt);

            return $source;
        } catch (Throwable $exception) {
            try {
                $this->state->write("standby/failures/{$evidenceId}.json", [
                    'schema' => 1,
                    'operation_id' => $operation->value,
                    'phase' => 'cold-build',
                    'message' => $exception->getMessage(),
                ]);
            } catch (Throwable) {
                // Cleanup remains mandatory even when evidence storage is unavailable.
            }
            if (! $this->cleanupCold($evidenceId, $operation)) {
                throw new RuntimeException(
                    'Cold standby cleanup failed; explicit recovery is required.',
                    previous: $exception,
                );
            }

            throw $exception;
        }
    }

    public function cleanupCold(string $evidenceId, OperationId $operation): bool
    {
        if ($this->state->read("standby/cold-attempts/{$evidenceId}.json") === null) {
            return true;
        }

        try {
            $attempt = $this->attempt($evidenceId);
            if ($attempt['operation_id'] !== $operation->value) {
                throw new RuntimeException('The cold-build attempt operation identity does not match.');
            }
            $instances = [];
            $instanceNames = array_map(
                static fn (array $intent): string => $intent['name'],
                $attempt['instances'],
            );
            $observedInstances = $instanceNames === [] ? [] : $this->host->instances($instanceNames);
            foreach ($attempt['instances'] as $intent) {
                $instance = $observedInstances[$intent['name']] ?? null;
                if ($instance !== null) {
                    $this->assertAttemptResource($instance->metadata, $attempt);
                    if (
                        $instance->remote !== $attempt['remote']
                        || $instance->project !== $attempt['project']
                        || $instance->pool !== $attempt['pool']
                        || $instance->network !== $intent['network']
                    ) {
                        throw new RuntimeException('A planned cold-build VM identity does not match.');
                    }
                    $instances[$intent['name']] = $instance;
                }
            }
            $network = $this->host->network($attempt['network']['name']);
            if ($network !== null) {
                $this->assertAttemptResource($network->metadata, $attempt);
                if ($network->remote !== $attempt['remote'] || $network->project !== $attempt['project']) {
                    throw new RuntimeException('A planned cold-build network identity does not match.');
                }
            }

            $running = [];
            foreach ($instances as $name => $instance) {
                if ($instance->isRunning()) {
                    $running[] = $name;
                }
            }
            if ($running !== []) {
                $this->host->stopAll($running);
            }
            $deletions = [];
            foreach (array_reverse($attempt['instances']) as $intent) {
                if (isset($instances[$intent['name']])) {
                    $deletions[] = $intent['name'];
                }
            }
            if ($deletions !== []) {
                $this->host->deleteInstances($deletions);
            }
            if ($network !== null) {
                $this->networks->delete($network->name);
            }
            if ($instanceNames !== [] && $this->host->instances($instanceNames) !== []) {
                throw new RuntimeException('A cold-build VM persisted after deletion.');
            }
            if ($this->host->network($attempt['network']['name']) !== null) {
                throw new RuntimeException('A cold-build network persisted after deletion.');
            }
            $attempt['network']['state'] = 'cleaned';
            $attempt['instances'] = [];
            $attempt['status'] = 'cleaned';
            $this->recordAttempt($evidenceId, $attempt);
            $this->state->write("standby/recovery/{$evidenceId}.json", [
                'schema' => 1,
                'operation_id' => $operation->value,
                'recovered' => true,
                'resources_deleted' => true,
            ]);
            $corrupt = $this->state->read('standby/corrupt.json');
            if (is_array($corrupt) && ($corrupt['evidence_id'] ?? null) === $evidenceId) {
                $this->state->delete('standby/corrupt.json');
            }

            return true;
        } catch (Throwable $exception) {
            $this->state->write("standby/recovery/{$evidenceId}.json", [
                'schema' => 1,
                'operation_id' => $operation->value,
                'recovered' => false,
                'message' => $exception->getMessage(),
            ]);
            $this->state->write('standby/corrupt.json', [
                'schema' => 1,
                'evidence_id' => $evidenceId,
                'message' => $exception->getMessage(),
            ]);

            return false;
        }
    }

    /** @param array<string, mixed> $attempt */
    private function recordAttempt(string $evidenceId, array $attempt): void
    {
        $this->state->write("standby/cold-attempts/{$evidenceId}.json", $attempt);
    }

    /** @return array{schema:int,operation_id:string,evidence_id:string,remote:string,project:string,pool:string,network:array{name:string,state:string,absent_preflight:bool},base_image_fingerprint:string,instances:list<array{role:string,name:string,network:string,state:string,absent_preflight:bool}>,status:string} */
    private function attempt(string $evidenceId): array
    {
        $value = $this->state->read("standby/cold-attempts/{$evidenceId}.json");

        if (is_array($value) && ($value['schema'] ?? null) === 2) {
            $legacyNetwork = is_array($value['network'] ?? null) ? $value['network'] : null;
            $legacyNetworkName = is_string($legacyNetwork['name'] ?? null) ? $legacyNetwork['name'] : null;
            if (
                array_keys($value) !== [
                    'schema',
                    'operation_id',
                    'remote',
                    'project',
                    'pool',
                    'network',
                    'base_image_fingerprint',
                    'instances',
                    'status',
                ]
                || $value['operation_id'] !== $evidenceId
                || ! is_string($value['remote'])
                || ! is_string($value['project'])
                || ! is_string($value['pool'])
                || $legacyNetwork === null
                || $legacyNetwork !== [
                    'name' => $legacyNetworkName,
                    'state' => 'cleaned',
                    'absent_preflight' => true,
                ]
                || ! in_array($legacyNetworkName, ['oe-standby', 'orbit-e2e-standby'], true)
                || ! is_string($value['base_image_fingerprint'])
                || preg_match('/\A[a-f0-9]{64}\z/D', $value['base_image_fingerprint']) !== 1
                || $value['instances'] !== []
                || $value['status'] !== 'cleaned'
            ) {
                throw new RuntimeException('The cold-build attempt evidence is invalid.');
            }

            $value = [
                'schema' => 3,
                'operation_id' => $value['operation_id'],
                'evidence_id' => $evidenceId,
                'remote' => $value['remote'],
                'project' => $value['project'],
                'pool' => $value['pool'],
                'network' => $legacyNetwork,
                'base_image_fingerprint' => $value['base_image_fingerprint'],
                'instances' => [],
                'status' => 'cleaned',
            ];
        }

        $network = is_array($value['network'] ?? null) ? $value['network'] : null;
        $networkName = is_string($network['name'] ?? null) ? $network['name'] : null;
        if (
            $value === null
            || array_keys($value) !== [
                'schema',
                'operation_id',
                'evidence_id',
                'remote',
                'project',
                'pool',
                'network',
                'base_image_fingerprint',
                'instances',
                'status',
            ]
            || $value['schema'] !== 3
            || ! is_string($value['operation_id'])
            || preg_match('/\A[a-f0-9]{32}\z/D', $value['operation_id']) !== 1
            || $value['evidence_id'] !== $evidenceId
            || ! is_string($value['remote'])
            || ! is_string($value['project'])
            || ! is_string($value['pool'])
            || $network === null
            || $network !== [
                'name' => $networkName,
                'state' => $network['state'] ?? null,
                'absent_preflight' => true,
            ]
            || ! in_array($networkName, ['oe-standby', 'orbit-e2e-standby'], true)
            || ! in_array($network['state'] ?? null, ['planned', 'created', 'cleaned'], true)
            || ! is_string($value['base_image_fingerprint'])
            || preg_match('/\A[a-f0-9]{64}\z/D', $value['base_image_fingerprint']) !== 1
            || ! is_array($value['instances'])
            || ! array_is_list($value['instances'])
            || ! is_string($value['status'])
            || ! in_array($value['status'], ['creating', 'built', 'cleaned'], true)
        ) {
            throw new RuntimeException('The cold-build attempt evidence is invalid.');
        }
        if (
            $networkName === 'orbit-e2e-standby'
            && ($network['state'] !== 'cleaned'
            || $value['instances'] !== []
            || $value['status'] !== 'cleaned')
        ) {
            throw new RuntimeException('The cold-build attempt evidence is invalid.');
        }
        $instances = [];
        foreach ($value['instances'] as $intent) {
            if (
                ! is_array($intent)
                || array_keys($intent) !== ['role', 'name', 'network', 'state', 'absent_preflight']
                || ! is_string($intent['role'])
                || ! is_string($intent['name'])
                || $intent['network'] !== $networkName
                || ! in_array($intent['state'], ['planned', 'created'], true)
                || $intent['absent_preflight'] !== true
                || ! in_array(
                    $intent['name'],
                    [
                        'orbit-e2e-standby-gateway',
                        'orbit-e2e-standby-app-dev',
                        'orbit-e2e-standby-app-prod',
                    ],
                    true,
                )
            ) {
                throw new RuntimeException('The cold-build attempt resource identity is invalid.');
            }
            if ($intent['name'] !== TopologyTarget::standby()->instance($intent['role'])) {
                throw new RuntimeException('The cold-build attempt role identity is invalid.');
            }
            $instances[] = [
                'role' => $intent['role'],
                'name' => $intent['name'],
                'network' => $networkName,
                'state' => $intent['state'],
                'absent_preflight' => true,
            ];
        }

        return [
            'schema' => 3,
            'operation_id' => $value['operation_id'],
            'evidence_id' => $value['evidence_id'],
            'remote' => $value['remote'],
            'project' => $value['project'],
            'pool' => $value['pool'],
            'network' => [
                'name' => $networkName,
                'state' => $network['state'],
                'absent_preflight' => true,
            ],
            'base_image_fingerprint' => $value['base_image_fingerprint'],
            'instances' => $instances,
            'status' => $value['status'],
        ];
    }

    /** @param array<string, string> $metadata */
    private function assertOwned(array $metadata): void
    {
        if (($metadata['user.orbit.e2e.owner'] ?? null) !== 'orbit-e2e') {
            throw new RuntimeException('A cold-build resource ownership identity does not match.');
        }
    }

    /** @param array<string, string> $metadata @param array<string, mixed> $attempt */
    private function assertAttemptResource(array $metadata, array $attempt): void
    {
        $this->assertOwned($metadata);
        if (
            ($metadata['user.orbit.e2e.operation'] ?? null) !== $attempt['operation_id']
            || ($metadata['user.orbit.e2e.evidence'] ?? null) !== $attempt['evidence_id']
        ) {
            throw new RuntimeException('A cold-build resource operation or evidence identity does not match.');
        }
    }

    private function assertNoActiveIntent(): void
    {
        $attempts = glob($this->paths->path('standby/cold-attempts').'/*.json');
        if ($attempts === false) {
            throw new RuntimeException('Cold-build resource intents cannot be inspected.');
        }

        foreach ($attempts as $file) {
            $id = pathinfo($file, PATHINFO_FILENAME);
            if (preg_match('/\A[a-f0-9]{32}\z/D', $id) !== 1) {
                throw new RuntimeException('An invalid cold-build resource intent blocks construction.');
            }
            if ($this->attempt($id)['status'] !== 'cleaned') {
                throw new RuntimeException('An active cold-build resource intent blocks construction.');
            }
        }
    }
}
