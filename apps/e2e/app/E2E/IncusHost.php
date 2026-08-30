<?php

declare(strict_types=1);

namespace App\E2E;

use App\E2E\State\OperationJournal;
use App\E2E\State\SecretRedactor;
use App\E2E\Value\GuestCommand;
use App\E2E\Value\GuestCommandResult;
use App\E2E\Value\IncusInstance;
use App\E2E\Value\IncusNetwork;
use App\E2E\Value\MountPath;
use App\E2E\Value\OperationId;
use App\E2E\Value\TopologyTarget;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Process\Pool;
use Illuminate\Support\Facades\Process;
use InvalidArgumentException;
use JsonException;
use RuntimeException;
use Throwable;

/**
 * @mago-expect lint:cyclomatic-complexity Exact Incus operations keep validation at the process boundary.
 * @mago-expect lint:kan-defect The boundary fails closed for each identity and ownership check.
 * @mago-expect lint:too-many-methods The approved adapter contract requires this exact method surface.
 */
final class IncusHost implements GuestTransport
{
    private const int GUEST_READINESS_POLL_INTERVAL_MICROSECONDS = 1_000_000;
    private const string DEFAULT_ROUTE_INTERFACE_RESOLUTION = 'interface=$(ip -4 route show default | awk \'$1 == "default" { for (i = 2; i < NF; i++) if ($i == "dev") { print $(i + 1); exit } }\') && [ -n "$interface" ]';
    private const string GLOBAL_IPV4_PROBE =
        self::DEFAULT_ROUTE_INTERFACE_RESOLUTION.' && ip -4 -o addr show dev "$interface" scope global';
    private const string CLONED_HOST_STATE_RESET_SUFFIX = ' && systemctl restart systemd-journald && for directory in /run/systemd/netif/leases /var/lib/systemd/network; do if [ -e "$directory" ]; then [ -d "$directory" ] && [ ! -L "$directory" ] || exit 1; find "$directory" -mindepth 1 -maxdepth 1 -type f -delete || exit 1; fi; done && ip -4 addr flush dev "$interface" scope global && ip link set dev "$interface" down && ip link set dev "$interface" up && (systemctl restart systemd-networkd || systemctl restart NetworkManager)';

    /** @var array<string, IncusInstance> */
    private array $ownedInstanceCache = [];

    /**
     * @param array<string, string> $ownershipMetadata
     * @mago-expect lint:excessive-parameter-list Explicit dependencies keep this infrastructure boundary configurable and testable.
     */
    public function __construct(
        private readonly string $remote = 'local',
        private readonly string $project = 'default',
        private readonly string $pool = 'default',
        private readonly array $ownershipMetadata = ['user.orbit.e2e.owner' => 'orbit-e2e'],
        private readonly SecretRedactor $redactor = new SecretRedactor,
        private readonly ?OperationJournal $journal = null,
        private readonly ?OperationId $operationId = null,
        private readonly int $guestReadinessTimeoutSeconds = 600,
    ) {
        $this->validateName($remote, 'remote');
        $this->validateName($project, 'project');
        $this->validateName($pool, 'storage pool');
        if ($guestReadinessTimeoutSeconds < 1) {
            throw new InvalidArgumentException('Guest readiness timeout must be positive.');
        }

        if ($ownershipMetadata === []) {
            throw new RuntimeException('Incus ownership metadata cannot be empty.');
        }

        foreach ($ownershipMetadata as $key => $value) {
            $this->validateMetadata($key, $value);
        }

        if (($journal === null) !== ($operationId === null)) {
            throw new RuntimeException('Incus journal and operation identity must be provided together.');
        }
    }

    public function instance(string $name): ?IncusInstance
    {
        $this->validateName($name, 'instance');
        $resources = $this->readJson(['list', $this->target($name), '--format=json']);

        foreach ($resources as $resource) {
            if (is_array($resource) && ($resource['name'] ?? null) === $name) {
                if (($resource['type'] ?? null) !== 'virtual-machine') {
                    throw new RuntimeException("Incus instance {$name} is not a virtual machine.");
                }

                $pool = $resource['devices']['root']['pool'] ?? $resource['expanded_devices']['root']['pool'] ?? null;
                if (! is_string($pool) || $pool === '') {
                    throw new RuntimeException("Incus instance {$name} has no storage pool identity.");
                }

                if ($pool !== $this->pool) {
                    throw new RuntimeException("Incus instance {$name} storage pool identity does not match.");
                }

                $status = $resource['status'] ?? null;
                $statusCode = $resource['status_code'] ?? null;
                if (! is_string($status) || ! is_int($statusCode)) {
                    throw new RuntimeException("Incus instance {$name} has no valid power status.");
                }
                $network =
                    $resource['devices']['eth0']['network'] ?? $resource['expanded_devices']['eth0']['network'] ?? null;
                $mac =
                    $resource['devices']['eth0']['hwaddr'] ?? $resource['expanded_devices']['eth0']['hwaddr'] ?? null;
                if ($network !== null && ! is_string($network)) {
                    throw new RuntimeException("Incus instance {$name} has an invalid network identity.");
                }
                if ($mac !== null && ! is_string($mac)) {
                    throw new RuntimeException("Incus instance {$name} has an invalid MAC identity.");
                }

                return new IncusInstance(
                    $this->remote,
                    $this->project,
                    $name,
                    $pool,
                    $this->metadata($resource),
                    strtoupper($status),
                    $statusCode,
                    $network,
                    $mac,
                    $this->disks($resource, $name),
                );
            }
        }

        return null;
    }

    /**
     * @param list<string> $names
     * @return array<string, IncusInstance>
     */
    public function instances(array $names): array
    {
        $resources = $this->instanceInventory($names, 'inventory');
        $instances = [];
        foreach ($resources as $name => $resource) {
            $instances[$name] = $this->instanceFromResource($resource);
        }

        return $instances;
    }

    public function network(string $name): ?IncusNetwork
    {
        $this->validateName($name, 'network');
        $resources = $this->readJson(['network', 'list', "{$this->remote}:", '--format=json']);

        foreach ($resources as $resource) {
            if (is_array($resource) && ($resource['name'] ?? null) === $name) {
                return new IncusNetwork(
                    $this->remote,
                    $this->project,
                    $name,
                    $this->metadata($resource),
                    $this->configuration($resource),
                );
            }
        }

        return null;
    }

    /** @return array<string, IncusNetwork> */
    public function networks(): array
    {
        $resources = $this->readJson(['network', 'list', "{$this->remote}:", '--format=json']);
        $networks = [];
        foreach ($resources as $resource) {
            if (! is_array($resource)) {
                throw new RuntimeException('Incus network inventory identity is invalid.');
            }
            $name = $resource['name'] ?? null;
            if (! is_string($name) || isset($networks[$name])) {
                throw new RuntimeException('Incus network inventory identity is invalid.');
            }
            $networks[$name] = new IncusNetwork(
                $this->remote,
                $this->project,
                $name,
                $this->metadata($resource),
                $this->configuration($resource),
            );
        }

        return $networks;
    }

    public function imageFingerprint(string $alias): string
    {
        $this->validateImage($alias);
        [$remote, $selector] = $this->imageSelector($alias);
        $images = $this->readJson(['image', 'list', $remote, $selector, '--format=json']);
        $matches = array_values(array_filter($images, function (mixed $image) use ($selector): bool {
            if (! is_array($image) || ($image['type'] ?? null) !== 'virtual-machine') {
                return false;
            }

            if (($image['fingerprint'] ?? null) === $selector) {
                return true;
            }

            $aliases = $image['aliases'] ?? null;
            if (! is_array($aliases)) {
                return false;
            }

            return array_any(
                $aliases,
                fn ($imageAlias) => is_array($imageAlias) && ($imageAlias['name'] ?? null) === $selector,
            );
        }));
        if (count($matches) !== 1) {
            throw new RuntimeException('Incus image selector did not identify exactly one virtual-machine image.');
        }
        $fingerprint = $matches[0]['fingerprint'] ?? null;

        if (! is_string($fingerprint) || preg_match('/\A[a-f0-9]{64}\z/', $fingerprint) !== 1) {
            throw new RuntimeException('Incus returned an invalid image fingerprint.');
        }

        return $fingerprint;
    }

    /** @return array{remote:string,project:string,pool:string} */
    public function scope(): array
    {
        return ['remote' => $this->remote, 'project' => $this->project, 'pool' => $this->pool];
    }

    /** @param array<string, string> $configuration */
    public function createNetwork(string $name, array $configuration): IncusNetwork
    {
        $this->validateName($name, 'network');
        if (strlen($name) > 15) {
            throw new RuntimeException('Incus network names must be 15 ASCII characters or fewer.');
        }
        $this->validateStringMap($configuration, 'network configuration');
        $configuration = [...$configuration, ...$this->ownershipMetadata];
        $arguments = ['network', 'create', "{$this->remote}:{$name}"];
        foreach ($configuration as $key => $value) {
            $this->validateConfiguration($key, $value);
            $arguments[] = "{$key}={$value}";
        }

        $this->run($arguments);

        return new IncusNetwork($this->remote, $this->project, $name, $this->e2eMetadata($configuration));
    }

    /** @param array<string, string> $creationMetadata */
    public function initVm(string $image, string $name, string $network, array $creationMetadata = []): IncusInstance
    {
        $this->validateImage($image);
        $this->validateName($name, 'instance');
        $this->validateName($network, 'network');
        $this->validateStringMap($creationMetadata, 'creation metadata');
        foreach ($creationMetadata as $key => $value) {
            $this->validateMetadata($key, $value);
            if (array_key_exists($key, $this->ownershipMetadata)) {
                throw new RuntimeException('Incus creation metadata cannot override ownership metadata.');
            }
        }
        $metadata = [...$this->ownershipMetadata, ...$creationMetadata];
        [$imageRemote, $imageSelector] = $this->imageSelector($image);
        $image = $imageRemote.$imageSelector;
        $arguments = [
            'init',
            $image,
            $this->target($name),
            '--vm',
            '--storage',
            $this->pool,
            '--config',
            'limits.cpu='.$this->incusLimit('cpu', '1'),
            '--config',
            'limits.memory='.$this->incusLimit('memory', '2GiB'),
            '--device',
            'root,pool='.$this->pool,
            '--device',
            'root,size='.$this->incusLimit('root_size', '16GiB'),
            '--network',
            $network,
        ];
        foreach ($metadata as $key => $value) {
            $arguments[] = '--config';
            $arguments[] = "{$key}={$value}";
        }

        $this->run($arguments, 300);

        return new IncusInstance(
            $this->remote,
            $this->project,
            $name,
            $this->pool,
            $metadata,
            network: $network,
        );
    }

    /** @param array<string, array{image:string,name:string,network:string,role:string,topology:string,slot:int,metadata:array<string,string>}> $vms */
    public function initVms(array $vms): array
    {
        if ($vms === []) {
            throw new RuntimeException('Incus VM initialization batch must be non-empty.');
        }
        $commands = [];
        $instances = [];
        $targets = [];
        foreach ($vms as $label => $vm) {
            $this->validateName($label, 'VM initialization label');
            $this->validateImage($vm['image']);
            $this->validateName($vm['name'], 'instance');
            $this->validateName($vm['network'], 'network');
            $this->validateName($vm['role'], 'role');
            $this->validateName($vm['topology'], 'topology');
            if (isset($targets[$vm['name']])) {
                throw new RuntimeException('Incus VM initialization targets must be unique.');
            }
            $targets[$vm['name']] = true;
            $this->validateStringMap($vm['metadata'], 'creation metadata');
            foreach ($vm['metadata'] as $key => $value) {
                $this->validateMetadata($key, $value);
                if (array_key_exists($key, $this->ownershipMetadata)) {
                    throw new RuntimeException('Incus creation metadata cannot override ownership metadata.');
                }
            }
            [$remote, $selector] = $this->imageSelector($vm['image']);
            $arguments = [
                'init',
                $remote.$selector,
                $this->target($vm['name']),
                '--vm',
                '--storage',
                $this->pool,
                '--config',
                'limits.cpu='.$this->incusLimit('cpu', '1'),
                '--config',
                'limits.memory='.$this->incusLimit('memory', '2GiB'),
                '--device',
                'root,pool='.$this->pool,
                '--device',
                'root,size='.$this->incusLimit('root_size', '16GiB'),
                '--device',
                'eth0,network='.$vm['network'],
                '--device',
                'eth0,ipv4.address='.TopologyTarget::ipv4For($vm['slot'], $vm['role']),
                '--device',
                'eth0,hwaddr='.$this->deterministicMac($vm['topology'], $vm['role']),
            ];
            foreach ([...$this->ownershipMetadata, ...$vm['metadata']] as $key => $value) {
                $arguments[] = '--config';
                $arguments[] = "{$key}={$value}";
            }
            $commands[$label] = $arguments;
            $instances[$label] = new IncusInstance(
                $this->remote,
                $this->project,
                $vm['name'],
                $this->pool,
                [...$this->ownershipMetadata, ...$vm['metadata']],
                network: $vm['network'],
            );
        }
        $this->runParallel($commands, 300, failureMessage: 'Incus VM initialization batch failed');

        return $instances;
    }

    /** @param array<string, string> $acquisitionMetadata */
    public function copySnapshot(
        string $source,
        string $snapshot,
        string $target,
        array $acquisitionMetadata = [],
    ): IncusInstance {
        /** @var array{0:list<string>,1:IncusInstance} $copy */
        $copy = $this->snapshotCopy($source, $snapshot, $target, $acquisitionMetadata);
        [$command, $instance] = $copy;
        /** @var list<string> $command */
        $this->run($command, 300);

        return $instance;
    }

    /**
     * @param array<string, array{source:string,snapshot:string,target:string,metadata:array<string, string>,network?:string,role?:string,topology?:string,slot?:int,mount?:array{device:string,source:string,path:string}}> $copies
     * @return array<string, IncusInstance>
     */
    public function copySnapshots(array $copies): array
    {
        if ($copies === []) {
            throw new RuntimeException('Incus snapshot copy batch must be non-empty.');
        }

        /** @var array<string, list<string>> $commands */
        $commands = [];
        /** @var array<string, IncusInstance> $instances */
        $instances = [];
        $targets = [];
        foreach ($copies as $label => $copy) {
            $this->validateName($label, 'snapshot copy label');
            $source = $copy['source'];
            $snapshot = $copy['snapshot'];
            $target = $copy['target'];
            $metadata = $copy['metadata'];
            if (isset($targets[$target])) {
                throw new RuntimeException('Incus snapshot copy targets must be unique.');
            }
            $targets[$target] = true;
        }
        $this->validateSnapshotCopies($copies);
        foreach ($copies as $label => $copy) {
            /** @var array{0:list<string>,1:IncusInstance} $copyResult */
            $copyResult = $this->snapshotCopy(
                $copy['source'],
                $copy['snapshot'],
                $copy['target'],
                $copy['metadata'],
                $copy['network'] ?? null,
                $copy['role'] ?? null,
                $copy['topology'] ?? null,
                $copy['slot'] ?? null,
                false,
                $copy['mount'] ?? null,
            );
            [$commands[$label], $instances[$label]] = $copyResult;
        }

        $this->runParallel($commands, 300, failureMessage: 'Incus snapshot copy batch failed');

        return $instances;
    }

    public function setNetwork(string $instance, string $network, string $role): void
    {
        $this->validatedOwnedVm($instance);
        $resource = $this->network($network);
        if ($resource === null) {
            throw new RuntimeException("Incus network {$network} does not exist.");
        }

        $this->assertOwned($resource->metadata, "network {$network}");
        $slot = $this->networkSlot($resource);
        $this->run([
            'config',
            'device',
            'override',
            $this->target($instance),
            'eth0',
            "network={$network}",
            'ipv4.address='.TopologyTarget::ipv4For($slot, $role),
            'hwaddr='.$this->deterministicMac($network, $role),
        ]);
    }

    /** @param array<string, string> $instancesByRole */
    public function configureCloneNetworks(array $instancesByRole, string $network): void
    {
        $this->validateName($network, 'network');
        $this->validateUniqueInstances(array_values($instancesByRole), 'clone configuration');
        $resource = $this->network($network);
        if ($resource === null) {
            throw new RuntimeException("Incus network {$network} does not exist.");
        }
        $this->assertOwned($resource->metadata, "network {$network}");
        $slot = $this->networkSlot($resource);

        $commands = [];
        $ownedInstances = $this->ownedInstances(array_values($instancesByRole), 'clone network configuration');
        foreach ($instancesByRole as $role => $instance) {
            $this->validateName($role, 'role');
            if (! isset($ownedInstances[$instance])) {
                throw new RuntimeException("Incus instance {$instance} does not exist.");
            }
            $commands[$role] = [
                'config',
                'device',
                'override',
                $this->target($instance),
                'eth0',
                "network={$network}",
                'ipv4.address='.TopologyTarget::ipv4For($slot, $role),
                'hwaddr='.$this->deterministicMac($network, $role),
            ];
        }

        $this->runParallel($commands, 60, failureMessage: 'Incus clone network configuration batch failed');
    }

    /** @param array<string, string> $instancesByRole */
    public function assertTopologyNetworkIdentity(array $instancesByRole, string $network): void
    {
        $this->validateName($network, 'network');
        $this->validateUniqueInstances(array_values($instancesByRole), 'topology network validation');
        foreach (array_keys($instancesByRole) as $role) {
            $this->validateName($role, 'role');
        }

        $resource = $this->network($network);
        if ($resource === null) {
            throw new RuntimeException("Incus network {$network} does not exist.");
        }
        $this->assertOwned($resource->metadata, "network {$network}");
        $slot = $this->networkSlot($resource);

        $resources = $this->instanceInventory(array_values($instancesByRole), 'topology network validation');
        foreach ($instancesByRole as $role => $instance) {
            $resource = $resources[$instance] ?? null;
            if ($resource === null) {
                throw new RuntimeException("Incus instance {$instance} does not exist.");
            }
            $vm = $this->instanceFromResource($resource);
            $this->assertOwned($vm->metadata, "instance {$instance}");
            $eth0 = $resource['devices']['eth0'] ?? $resource['expanded_devices']['eth0'] ?? null;
            if (! is_array($eth0) || ($eth0['network'] ?? null) !== $network) {
                throw new RuntimeException("Incus instance {$instance} network identity does not match topology.");
            }
            if (($eth0['hwaddr'] ?? null) !== $this->deterministicMac($network, $role)) {
                throw new RuntimeException("Incus instance {$instance} MAC identity does not match topology.");
            }
            if (($eth0['ipv4.address'] ?? null) !== TopologyTarget::ipv4For($slot, $role)) {
                throw new RuntimeException("Incus instance {$instance} IPv4 identity does not match topology.");
            }
        }
    }

    /** @param array<string, string> $metadata */
    public function setMetadata(string $resource, array $metadata): void
    {
        $this->validateName($resource, 'resource');
        if ($metadata === []) {
            throw new RuntimeException('Incus metadata cannot be empty.');
        }

        $this->validateStringMap($metadata, 'metadata');
        foreach ($metadata as $key => $value) {
            $this->validateMetadata($key, $value);
        }

        $instance = $this->instance($resource);
        if ($instance !== null) {
            $this->assertOwned($instance->metadata, "instance {$resource}");
            $arguments = ['config', 'set', $this->target($resource)];
        } else {
            $network = $this->network($resource);
            if ($network === null) {
                throw new RuntimeException("Incus resource {$resource} does not exist.");
            }

            $this->assertOwned($network->metadata, "network {$resource}");
            $arguments = ['network', 'set', "{$this->remote}:{$resource}"];
        }

        foreach ($metadata as $key => $value) {
            $arguments[] = "{$key}={$value}";
        }

        $this->run($arguments);
    }

    /** @param array<string, string> $configuration */
    public function setNetworkConfiguration(string $name, array $configuration): void
    {
        $this->validateName($name, 'network');
        $network = $this->network($name);
        if ($network === null) {
            throw new RuntimeException("Incus network {$name} does not exist.");
        }
        $this->assertOwned($network->metadata, "network {$name}");
        $this->validateStringMap($configuration, 'network configuration');
        $arguments = ['network', 'set', $this->target($name)];
        foreach ($configuration as $key => $value) {
            $this->validateConfiguration($key, $value);
            $arguments[] = "{$key}={$value}";
        }
        $this->run($arguments);
    }

    public function start(string $instance): void
    {
        if ($this->validatedOwnedVm($instance)->isRunning()) {
            return;
        }

        $this->run(['start', $this->target($instance)], 120);
    }

    /** @param list<string> $instances */
    public function startAll(array $instances): void
    {
        $this->validateUniqueInstances($instances, 'start');
        $commands = [];
        foreach ($this->ownedInstances($instances, 'start') as $instance => $vm) {
            if (! $vm->isRunning()) {
                $commands[$instance] = ['start', $this->target($instance)];
            }
        }
        if ($commands === []) {
            return;
        }

        $this->runParallel($commands, 120, failureMessage: 'Incus instance start batch failed');
    }

    /** @param list<string> $instances */
    public function waitForAgents(array $instances): void
    {
        $this->validateUniqueInstances($instances, 'guest agent');

        $this->operationOwnedInstances($instances, 'guest agent');

        $deadline = microtime(true) + $this->guestReadinessTimeoutSeconds;
        $pending = array_fill_keys($instances, true);

        while ($pending !== []) {
            if (microtime(true) >= $deadline) {
                break;
            }

            $commands = [];
            foreach (array_keys($pending) as $instance) {
                $commands[$instance] = ['exec', $this->target($instance), '--', '/bin/true'];
            }
            try {
                $probes = $this->runParallel($commands, 10, false);
            } catch (RuntimeException) {
                $probes = [];
            }
            foreach ($probes as $instance => $probe) {
                if ($probe->successful()) {
                    unset($pending[$instance]);
                }
            }

            if (microtime(true) >= $deadline) {
                break;
            }

            if ($pending === []) {
                break;
            }

            usleep(self::GUEST_READINESS_POLL_INTERVAL_MICROSECONDS);
        }

        if ($pending !== []) {
            throw new RuntimeException('Timed out waiting for Incus guest agents to become ready.');
        }
    }

    /** @param list<string> $instances */
    public function waitForGlobalIpv4(array $instances): void
    {
        $this->validateUniqueInstances($instances, 'IPv4');

        $this->operationOwnedInstances($instances, 'IPv4');

        $deadline = microtime(true) + $this->guestReadinessTimeoutSeconds;
        $pending = array_fill_keys($instances, true);
        while ($pending !== []) {
            if (microtime(true) >= $deadline) {
                break;
            }

            $commands = [];
            foreach (array_keys($pending) as $instance) {
                $commands[$instance] = [
                    'exec',
                    $this->target($instance),
                    '--',
                    'sh',
                    '-c',
                    self::GLOBAL_IPV4_PROBE,
                ];
            }
            try {
                $probes = $this->runParallel($commands, 10, false);
            } catch (RuntimeException) {
                $probes = [];
            }
            foreach ($probes as $instance => $probe) {
                if ($probe->successful() && $this->hasUsableGlobalIpv4($probe->output())) {
                    unset($pending[$instance]);
                }
            }
            if ($pending === [] || microtime(true) >= $deadline) {
                break;
            }
            usleep(self::GUEST_READINESS_POLL_INTERVAL_MICROSECONDS);
        }

        if ($pending !== []) {
            throw new RuntimeException('Timed out waiting for Incus guests to receive global IPv4 addresses.');
        }
    }

    public function globalIpv4(string $instance): string
    {
        return $this->globalIpv4All([$instance => $instance])[$instance];
    }

    /**
     * @param array<string, string> $instances
     * @return array<string, string>
     */
    public function globalIpv4All(array $instances): array
    {
        $commands = [];
        foreach ($instances as $label => $instance) {
            $commands[$label] = [
                'instance' => $instance,
                'command' => new GuestCommand(
                    [
                        'sh',
                        '-c',
                        self::GLOBAL_IPV4_PROBE,
                    ],
                    10,
                ),
            ];
        }

        $addressesByLabel = [];
        foreach ($this->execAll($commands) as $label => $probe) {
            $instance = $instances[$label];
            if (! $probe->successful()) {
                throw new RuntimeException("Failed to read global IPv4 address for Incus guest {$instance}.");
            }

            $addresses = $this->globalIpv4Candidates($probe->stdout);
            if (count($addresses) === 1) {
                $addressesByLabel[$label] = $addresses[0];

                continue;
            }
            if ($addresses === []) {
                throw new RuntimeException("Incus guest {$instance} has no usable global IPv4 address.");
            }

            throw new RuntimeException("Incus guest {$instance} does not have exactly one usable global IPv4 address.");
        }

        return $addressesByLabel;
    }

    /** @return list<string> */
    private function globalIpv4Candidates(string $output): array
    {
        $addresses = [];
        foreach (preg_split('/\R/', trim($output)) ?: [] as $line) {
            $fields = preg_split('/\s+/', trim($line)) ?: [];
            $inet = array_search('inet', $fields, true);
            $address = is_int($inet) ? explode('/', $fields[$inet + 1] ?? '', 2)[0] : '';
            if (
                filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false
                || str_starts_with($address, '127.')
            ) {
                continue;
            }

            $addresses[] = $address;
        }

        return array_values(array_unique($addresses));
    }

    public function resetClonedHostState(string $instance): void
    {
        $this->resetClonedHostStates([$instance]);
    }

    /** @param list<string> $instances */
    public function resetClonedHostStates(array $instances): void
    {
        $this->validateUniqueInstances($instances, 'cloned host-state reset');
        $commands = [];
        foreach ($this->ownedInstances($instances, 'cloned host-state reset') as $instance => $_vm) {
            $commands[$instance] = [
                'exec',
                $this->target($instance),
                '--',
                'sh',
                '-c',
                $this->clonedHostStateResetScript($instance),
            ];
        }

        try {
            $this->runParallel($commands, 60, failureMessage: 'Failed to reset cloned host state');
        } catch (Throwable $exception) {
            throw new RuntimeException('Failed to reset cloned host state: '.$exception->getMessage(), 0, $exception);
        }
    }

    /**
     * Advance each clone independently instead of waiting for the slowest role
     * at three separate global barriers.
     *
     * @param list<string> $instances
     */
    public function prepareClonedHostStates(array $instances): void
    {
        $this->validateUniqueInstances($instances, 'cloned host-state preparation');
        $this->operationOwnedInstances($instances, 'cloned host-state preparation');

        $states = array_fill_keys($instances, 'agent');
        $deadline = microtime(true) + $this->guestReadinessTimeoutSeconds;
        while ($states !== [] && microtime(true) < $deadline) {
            $commands = [];
            foreach ($states as $instance => $state) {
                $commands[$instance] = match ($state) {
                    'agent' => ['exec', $this->target($instance), '--', '/bin/true'],
                    'pre-reset-ipv4', 'ipv4' => [
                        'exec',
                        $this->target($instance),
                        '--',
                        'sh',
                        '-c',
                        self::GLOBAL_IPV4_PROBE,
                    ],
                    'reset' => [
                        'exec',
                        $this->target($instance),
                        '--',
                        'sh',
                        '-c',
                        $this->clonedHostStateResetScript($instance),
                    ],
                    default => throw new RuntimeException('Cloned host-state preparation entered an invalid state.'),
                };
            }

            try {
                $results = $this->runParallel($commands, 60, false);
            } catch (Throwable) {
                $results = [];
            }

            $advanced = false;
            foreach ($states as $instance => $state) {
                $result = $results[$instance] ?? null;
                if ($state === 'reset') {
                    if (! $result instanceof ProcessResult) {
                        continue;
                    }
                    if (! $result->successful()) {
                        throw new RuntimeException('Failed to reset cloned host state.');
                    }
                    $states[$instance] = 'ipv4';
                    $advanced = true;

                    continue;
                }
                if (! $result instanceof ProcessResult || ! $result->successful()) {
                    continue;
                }
                if ($state === 'agent') {
                    $states[$instance] = 'pre-reset-ipv4';
                    $advanced = true;

                    continue;
                }
                if (! $this->hasUsableGlobalIpv4($result->output())) {
                    continue;
                }
                if ($state === 'pre-reset-ipv4') {
                    $states[$instance] = 'reset';
                    $advanced = true;

                    continue;
                }
                if ($state === 'ipv4') {
                    unset($states[$instance]);
                    $advanced = true;
                }
            }

            if ($states !== [] && ! $advanced) {
                usleep(self::GUEST_READINESS_POLL_INTERVAL_MICROSECONDS);
            }
        }

        if ($states !== []) {
            throw new RuntimeException('Timed out preparing cloned Incus guest identities.');
        }
    }

    /** Wait for restored guests without changing their machine identity. */
    public function waitForRestoredHostStates(array $instances): void
    {
        assert(array_is_list($instances));
        /** @var list<string> $instances */
        $this->validateUniqueInstances($instances, 'restored host-state readiness');
        $this->operationOwnedInstances($instances, 'restored host-state readiness');

        $states = array_fill_keys($instances, 'agent');
        $deadline = microtime(true) + $this->guestReadinessTimeoutSeconds;
        while ($states !== [] && microtime(true) < $deadline) {
            /** @var array<string, list<string>> $commands */
            $commands = [];
            foreach ($states as $instance => $state) {
                $commands[$instance] = match ($state) {
                    'agent' => ['exec', $this->target($instance), '--', '/bin/true'],
                    'ipv4' => [
                        'exec',
                        $this->target($instance),
                        '--',
                        'sh',
                        '-c',
                        self::GLOBAL_IPV4_PROBE,
                    ],
                    default => throw new RuntimeException('Restored host-state readiness entered an invalid state.'),
                };
            }

            try {
                $results = $this->runParallel($commands, 10, false);
            } catch (Throwable) {
                $results = [];
            }

            $advanced = false;
            foreach ($states as $instance => $state) {
                $result = $results[$instance] ?? null;
                if (! $result instanceof ProcessResult || ! $result->successful()) {
                    continue;
                }
                if ($state === 'agent') {
                    $states[$instance] = 'ipv4';
                    $advanced = true;
                } elseif ($this->hasUsableGlobalIpv4($result->output())) {
                    unset($states[$instance]);
                    $advanced = true;
                }
            }

            if ($states !== [] && ! $advanced) {
                usleep(self::GUEST_READINESS_POLL_INTERVAL_MICROSECONDS);
            }
        }

        if ($states !== []) {
            throw new RuntimeException('Timed out waiting for restored Incus guests to become ready.');
        }
    }

    public function setDeterministicMac(string $instance, string $topologyId, string $role): void
    {
        $this->validatedOwnedVm($instance);
        $this->run([
            'config',
            'device',
            'override',
            $this->target($instance),
            'eth0',
            'hwaddr='.$this->deterministicMac($topologyId, $role),
        ]);
    }

    private function deterministicMac(string $topologyId, string $role): string
    {
        $this->validateName($topologyId, 'topology');
        $this->validateName($role, 'role');

        return TopologyTarget::macFor($topologyId, $role);
    }

    private function clonedHostStateResetScript(string $instance): string
    {
        $machineId = substr(
            hash('sha256', implode(':', [$this->remote, $this->project, $this->pool, $instance])),
            0,
            32,
        );
        $machineIdReset = sprintf(
            "rm -f /etc/machine-id /var/lib/dbus/machine-id && printf '%%s\\n' '%s' > /etc/machine-id && ln -s /etc/machine-id /var/lib/dbus/machine-id",
            $machineId,
        );

        return self::DEFAULT_ROUTE_INTERFACE_RESOLUTION.' && '.$machineIdReset.self::CLONED_HOST_STATE_RESET_SUFFIX;
    }

    private function incusLimit(string $key, string $default): string
    {
        if (function_exists('config') && app()->bound('config')) {
            $value = (string) config("e2e.incus.{$key}", $default);
        } else {
            $value = $default;
        }
        if ($key === 'cpu' && ! preg_match('/^[1-9][0-9]*$/', $value)) {
            throw new InvalidArgumentException('Incus CPU limit must be a positive integer.');
        }
        if ($key === 'memory' && ! preg_match('/^[1-9][0-9]*(MiB|GiB)$/', $value)) {
            throw new InvalidArgumentException('Incus memory limit must use MiB or GiB.');
        }
        if ($key === 'root_size' && ! preg_match('/^[1-9][0-9]*(MiB|GiB)$/', $value)) {
            throw new InvalidArgumentException('Incus root volume size must use MiB or GiB.');
        }

        return $value;
    }

    private function hasUsableGlobalIpv4(string $output): bool
    {
        return count($this->globalIpv4Candidates($output)) === 1;
    }

    public function stop(string $instance): void
    {
        $this->validatedOwnedVm($instance);
        try {
            $this->run(['stop', $this->target($instance)], 120);
        } catch (RuntimeException) {
            $this->run(['stop', $this->target($instance), '--force'], 120);
        }
    }

    /** @param list<string> $instances */
    public function stopAll(array $instances): void
    {
        $this->validateUniqueInstances($instances, 'stop');
        $commands = [];
        foreach ($this->ownedInstances($instances, 'stop') as $instance => $vm) {
            if ($vm->isRunning()) {
                $commands[$instance] = ['stop', $this->target($instance)];
            }
        }
        if ($commands !== []) {
            try {
                $this->runParallel($commands, 120, false);
            } catch (Throwable) {
                $results = [];
            }
            $forced = [];
            $observed = $this->instances(array_keys($commands));
            foreach (array_keys($commands) as $instance) {
                $vm = $observed[$instance] ?? null;
                if ($vm === null || $vm->isRunning()) {
                    $forced[$instance] = ['stop', $this->target($instance), '--force'];
                }
            }
            if ($forced !== []) {
                $this->runParallel($forced, 120, failureMessage: 'Incus forced instance stop batch failed');
            }
        }
    }

    /** @param list<string> $instances */
    public function forceStopAll(array $instances): void
    {
        $this->validateUniqueInstances($instances, 'forced stop');
        $commands = [];
        foreach ($this->ownedInstances($instances, 'forced stop') as $instance => $vm) {
            if ($vm->isRunning()) {
                $commands[$instance] = ['stop', $this->target($instance), '--force'];
            }
        }
        if ($commands !== []) {
            $this->runParallel($commands, 120, failureMessage: 'Incus forced stop batch failed');
        }
    }

    /** @param array<string,string> $snapshots */
    public function snapshotAll(array $snapshots): void
    {
        if ($snapshots === [])
            throw new RuntimeException('Incus snapshot batch must be non-empty.');
        $this->ownedInstances(array_keys($snapshots), 'snapshot creation');
        $commands = [];
        foreach ($snapshots as $instance => $snapshot) {
            $this->validateName($snapshot, 'snapshot');
            $commands[$instance] = ['snapshot', 'create', $this->target($instance), $snapshot];
        }
        $this->runParallel($commands, 300, failureMessage: 'Incus snapshot batch failed');
    }

    /** @param array<string,string> $snapshots */
    public function restoreAll(array $snapshots): void
    {
        if ($snapshots === [])
            throw new RuntimeException('Incus restore batch must be non-empty.');
        $this->assertOwnedSnapshots($snapshots);
        $commands = [];
        foreach ($snapshots as $instance => $snapshot) {
            $commands[$instance] = ['snapshot', 'restore', $this->target($instance), $snapshot];
        }
        $this->runParallel($commands, 300, failureMessage: 'Incus restore batch failed');
    }

    public function snapshot(string $instance, string $snapshot): void
    {
        $this->validatedOwnedVm($instance);
        $this->validateName($snapshot, 'snapshot');
        $this->run(['snapshot', 'create', $this->target($instance), $snapshot], 300);
    }

    public function restore(string $instance, string $snapshot): void
    {
        $this->validatedOwnedSnapshot($instance, $snapshot);
        $this->run(['snapshot', 'restore', $this->target($instance), $snapshot], 300);
    }

    /** Assert that an exact, Orbit-owned snapshot exists on an exact VM. */
    public function assertOwnedSnapshot(string $instance, string $snapshot): void
    {
        $this->validatedOwnedSnapshot($instance, $snapshot);
    }

    /** @param array<string, string> $snapshots */
    public function assertOwnedSnapshots(array $snapshots): void
    {
        if ($snapshots === []) {
            throw new RuntimeException('Incus snapshot validation batch must be non-empty.');
        }

        foreach ($snapshots as $instance => $snapshot) {
            $this->validateName($instance, 'instance');
            $this->validateName($snapshot, 'snapshot');
        }

        $instances = $this->ownedInstances(array_keys($snapshots), 'snapshot validation');
        $existing = $this->existingOwnedSnapshots($snapshots, $instances);
        if (count($existing) === count($snapshots)) {
            return;
        }

        $missing = array_keys(array_diff_key($snapshots, $existing));

        throw new RuntimeException('Incus snapshots do not exist: '.implode(', ', $missing).'.');
    }

    /**
     * @param list<string> $instanceNames
     * @return array<string, list<array{name:string,created_at:string}>>
     */
    public function ownedSnapshotNames(array $instanceNames): array
    {
        $instances = $this->ownedInstances($instanceNames, 'snapshot inventory');
        $commands = [];
        foreach ($instanceNames as $instance) {
            $commands[$instance] = ['snapshot', 'list', $this->target($instance), '--format=json'];
        }
        $results = $this->runParallel($commands, 60, failureMessage: 'Incus snapshot inventory batch failed');
        $snapshots = [];
        foreach ($instanceNames as $instance) {
            try {
                $resources = json_decode($results[$instance]->output(), true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException $exception) {
                throw new RuntimeException('Incus returned malformed snapshot JSON.', 0, $exception);
            }
            if (! is_array($resources)) {
                throw new RuntimeException('Incus returned malformed snapshot JSON.');
            }
            $names = [];
            foreach ($resources as $resource) {
                if (! is_array($resource) || ! is_string($resource['name'] ?? null)) {
                    throw new RuntimeException('Incus snapshot identity is invalid.');
                }
                $name = $resource['name'];
                if (str_contains($name, '/')) {
                    [$parent, $name] = explode('/', $name, 2);
                    if ($parent !== $instance) {
                        throw new RuntimeException('Incus snapshot identity is invalid.');
                    }
                }
                $this->validateName($name, 'snapshot');
                $metadata = $this->metadata($resource);
                $this->assertOwned(
                    $metadata === [] ? $instances[$instance]->metadata : $metadata,
                    "snapshot {$instance}/{$name}",
                );
                if (isset($names[$name])) {
                    throw new RuntimeException('Incus snapshot identity appears more than once.');
                }
                $createdAt = $resource['created_at'] ?? null;
                if (! is_string($createdAt) || $createdAt === '' || strtotime($createdAt) === false) {
                    throw new RuntimeException('Incus snapshot creation metadata is invalid.');
                }
                $names[$name] = ['name' => $name, 'created_at' => $createdAt];
            }
            $snapshots[$instance] = array_values($names);
            usort($snapshots[$instance], static fn (array $a, array $b): int => $a['name'] <=> $b['name']);
        }

        return $snapshots;
    }

    public function deleteSnapshot(string $instance, string $snapshot): void
    {
        $this->validatedOwnedSnapshot($instance, $snapshot);
        $this->run(['snapshot', 'delete', $this->target($instance), $snapshot], 300);
    }

    public function deleteSnapshotIfExists(string $instance, string $snapshot): void
    {
        if (! $this->ownedSnapshotExists($instance, $snapshot)) {
            return;
        }

        $this->run(['snapshot', 'delete', $this->target($instance), $snapshot], 300);
    }

    /** @param array<string, string> $snapshots */
    public function deleteSnapshotsIfExist(array $snapshots): void
    {
        if ($snapshots === []) {
            throw new RuntimeException('Incus snapshot deletion batch must be non-empty.');
        }
        foreach ($snapshots as $instance => $snapshot) {
            $this->validateName($instance, 'instance');
            $this->validateName($snapshot, 'snapshot');
        }

        $instances = $this->ownedInstances(array_keys($snapshots), 'snapshot deletion');
        $existing = $this->existingOwnedSnapshots($snapshots, $instances);
        if ($existing === []) {
            return;
        }

        $commands = [];
        foreach ($existing as $instance => $snapshot) {
            $commands[$instance] = ['snapshot', 'delete', $this->target($instance), $snapshot];
        }
        $this->runParallel($commands, 300, failureMessage: 'Incus snapshot deletion batch failed');

        $remaining = $this->existingOwnedSnapshots($existing, $instances);
        if ($remaining !== []) {
            throw new RuntimeException(
                'Incus snapshots still exist after deletion: '.implode(', ', array_keys($remaining)).'.',
            );
        }
    }

    public function deleteInstance(string $instance): void
    {
        $this->validatedOwnedVm($instance);
        $this->run(['delete', $this->target($instance)], 300);
    }

    /** @param list<string> $instances */
    public function deleteInstances(array $instances): void
    {
        $this->ownedInstances($instances, 'deletion');
        $commands = [];
        foreach ($instances as $instance) {
            $commands[$instance] = ['delete', $this->target($instance)];
        }
        $this->runParallel($commands, 300, failureMessage: 'Incus instance deletion batch failed');

        $remaining = $this->instances($instances);
        if ($remaining !== []) {
            throw new RuntimeException(
                'Incus instances still exist after deletion: '.implode(', ', array_keys($remaining)).'.',
            );
        }
    }

    public function deleteNetwork(string $network): void
    {
        $resource = $this->network($network);
        if ($resource === null) {
            throw new RuntimeException("Incus network {$network} does not exist.");
        }

        $this->assertOwned($resource->metadata, "network {$network}");
        $this->run(['network', 'delete', "{$this->remote}:{$network}"]);
    }

    public function pushFile(string $instance, string $source, string $destination): void
    {
        $this->validatedOwnedVm($instance);
        $this->validateFilePushPaths($source, $destination);

        $this->run(['file', 'push', $source, "{$this->target($instance)}{$destination}"], 300);
    }

    /** @param array<string, array{instance:string, source:string, destination:string}> $files */
    public function pushFiles(array $files): void
    {
        if ($files === []) {
            throw new RuntimeException('Incus file push batch must be non-empty.');
        }

        $requests = [];
        $instances = [];
        foreach ($files as $label => $request) {
            $this->validateName($label, 'file push label');
            $instance = $request['instance'] ?? null;
            $source = $request['source'] ?? null;
            $destination = $request['destination'] ?? null;
            if (! is_string($instance) || ! is_string($source) || ! is_string($destination)) {
                throw new RuntimeException('Incus file push batch request is invalid.');
            }
            $this->validateFilePushPaths($source, $destination);
            $requests[$label] = compact('instance', 'source', 'destination');
            $instances[$instance] = true;
        }

        $this->operationOwnedInstances(array_keys($instances), 'file push');

        $commands = [];
        foreach ($requests as $label => $request) {
            $commands[$label] = [
                'file',
                'push',
                $request['source'],
                $this->target($request['instance']).$request['destination'],
            ];
        }
        $this->runParallel($commands, 300, failureMessage: 'Incus file push batch failed');
    }

    public function exec(string $instance, GuestCommand $command): GuestCommandResult
    {
        if (! isset($this->ownedInstanceCache[$instance])) {
            $this->ownedInstanceCache[$instance] = $this->validatedOwnedVm($instance);
        } else {
            $this->assertOwned($this->ownedInstanceCache[$instance]->metadata, "instance {$instance}");
        }
        $result = $this->run(
            ['exec', $this->target($instance), '--', ...$command->command],
            $command->timeout,
            false,
            $command->stdin,
        );

        return new GuestCommandResult($result->output(), $result->errorOutput(), (int) $result->exitCode());
    }

    /** @param array<string, array{instance:string, command:GuestCommand}> $commands
     *  @return array<string, GuestCommandResult> */
    public function execAll(array $commands): array
    {
        if ($commands === []) {
            throw new RuntimeException('Incus guest command batch must be non-empty.');
        }
        $full = [];
        $instances = [];
        foreach ($commands as $label => $request) {
            $this->validateName($label, 'guest command label');
            $instance = $request['instance'] ?? null;
            $command = $request['command'] ?? null;
            if (! is_string($instance) || ! $command instanceof GuestCommand) {
                throw new RuntimeException('Incus guest command batch request is invalid.');
            }
            $instances[$instance] = true;
            $full[$label] = ['exec', $this->target($instance), '--', ...$command->command];
        }
        $this->operationOwnedInstances(array_keys($instances), 'guest command');
        try {
            $requests = [];
            foreach ($full as $label => $argv) {
                $requests[] = [
                    'label' => $label,
                    'project' => $this->project,
                    'instance' => $this->target($commands[$label]['instance']),
                    'argv' => $commands[$label]['command']->command,
                    'timeout' => $commands[$label]['command']->timeout,
                    'stdin' => $commands[$label]['command']->stdin,
                ];
            }
            $input = json_encode(['requests' => $requests], JSON_THROW_ON_ERROR);
            $timeout = max(array_map(static fn (array $request): int => $request['timeout'], $requests)) + 10;
            $result = Process::timeout($timeout)->input($input)->run([
                'python3',
                __DIR__.'/../../resources/host/exec-all.py',
            ]);
            if (! $result->successful()) {
                $error = $this->redactor->redact(trim($result->errorOutput()."\n".$result->output()));
                $detail = $error !== '' ? $error : 'exit code '.$result->exitCode();

                throw new RuntimeException('Batch helper failed: '.$detail.'.');
            }
            $decoded = json_decode($result->output(), true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($decoded) || ! array_is_list($decoded)) {
                throw new RuntimeException('Batch helper returned invalid output.');
            }
            $results = [];
            foreach ($decoded as $item) {
                if (! is_array($item)) {
                    throw new RuntimeException('Batch helper returned invalid result.');
                }
                $invalidTimeout =
                    array_key_exists('timed_out', $item)
                    && (! is_bool($item['timed_out']) || $item['timed_out'] && ($item['exit_code'] ?? null) !== 124);
                if (
                    ! is_string($item['label'] ?? null)
                    || ! array_key_exists($item['label'], $full)
                    || array_key_exists($item['label'], $results)
                    || ! is_string($item['stdout'] ?? null)
                    || ! is_string($item['stderr'] ?? null)
                    || ! is_int($item['exit_code'] ?? null)
                    || $invalidTimeout
                    || array_diff(array_keys($item), ['label', 'stdout', 'stderr', 'exit_code', 'timed_out']) !== []
                ) {
                    throw new RuntimeException('Batch helper returned invalid result.');
                }
                $results[$item['label']] = Process::result(
                    $item['stdout'],
                    $item['stderr'],
                    $item['exit_code'],
                );
            }
            if (array_keys($results) !== array_keys($full)) {
                throw new RuntimeException('Batch helper returned incomplete results.');
            }
        } catch (Throwable $exception) {
            $message = $this->redactor->redact($exception->getMessage());
            foreach ($full as $argv) {
                $this->recordFailure(['incus', '--project', $this->project, ...$argv], null, $message);
            }

            throw new RuntimeException('Incus guest command batch failed: '.$message, 0, $exception);
        }
        $resolved = [];
        foreach ($full as $label => $argv) {
            $result = $results[$label];
            $error = $this->redactor->redact(trim($result->errorOutput()."\n".$result->output()));
            if (! $result->successful()) {
                $this->recordFailure(
                    ['incus', '--project', $this->project, ...$argv],
                    $result->exitCode(),
                    $error,
                );
            }
            $resolved[$label] = new GuestCommandResult(
                $result->output(),
                $result->errorOutput(),
                (int) $result->exitCode(),
            );
        }

        return $resolved;
    }

    private function validateFilePushPaths(string $source, string $destination): void
    {
        if (
            $source === ''
            || str_contains($source, "\0")
            || ! str_starts_with($destination, '/')
            || str_contains($destination, "\0")
        ) {
            throw new RuntimeException('Invalid Incus file path.');
        }
    }

    private function validatedVm(string $name): IncusInstance
    {
        $instance = $this->instance($name);
        if ($instance === null) {
            throw new RuntimeException("Incus instance {$name} does not exist.");
        }

        return $instance;
    }

    /**
     * @param list<string> $names
     * @return array<string, array<array-key, mixed>>
     */
    private function instanceInventory(array $names, string $label): array
    {
        $this->validateUniqueInstances($names, $label);
        $wanted = array_fill_keys($names, true);
        $found = [];
        foreach ($this->readJson(['list', "{$this->remote}:", '--format=json']) as $resource) {
            if (! is_array($resource)) {
                continue;
            }
            $name = $resource['name'] ?? null;
            if (! is_string($name) || ! isset($wanted[$name])) {
                continue;
            }
            if (isset($found[$name])) {
                throw new RuntimeException("Incus instance {$name} appears more than once in inventory.");
            }
            $found[$name] = $resource;
        }

        return $found;
    }

    /** @param array<array-key, mixed> $resource */
    private function instanceFromResource(array $resource): IncusInstance
    {
        $name = $resource['name'] ?? null;
        if (! is_string($name) || ($resource['type'] ?? null) !== 'virtual-machine') {
            throw new RuntimeException('Incus instance identity is not a virtual machine.');
        }
        $pool = $resource['devices']['root']['pool'] ?? $resource['expanded_devices']['root']['pool'] ?? null;
        $status = $resource['status'] ?? null;
        $statusCode = $resource['status_code'] ?? null;
        if (! is_string($pool) || $pool !== $this->pool || ! is_string($status) || ! is_int($statusCode)) {
            throw new RuntimeException("Incus instance {$name} identity is invalid.");
        }
        $network = $resource['devices']['eth0']['network'] ?? $resource['expanded_devices']['eth0']['network'] ?? null;
        $mac = $resource['devices']['eth0']['hwaddr'] ?? $resource['expanded_devices']['eth0']['hwaddr'] ?? null;
        if ($network !== null && ! is_string($network)) {
            throw new RuntimeException("Incus instance {$name} network identity is invalid.");
        }
        if ($mac !== null && ! is_string($mac)) {
            throw new RuntimeException("Incus instance {$name} MAC identity is invalid.");
        }

        return new IncusInstance(
            $this->remote,
            $this->project,
            $name,
            $pool,
            $this->metadata($resource),
            strtoupper($status),
            $statusCode,
            $network,
            $mac,
            $this->disks($resource, $name),
        );
    }

    /**
     * Every non-root disk device with a host source: the exact mount identity release must re-check.
     *
     * @param array<array-key, mixed> $resource
     * @return array<string, array{source:string,path:string}>
     */
    private function disks(array $resource, string $name): array
    {
        $devices = $resource['devices'] ?? $resource['expanded_devices'] ?? [];
        if (! is_array($devices)) {
            throw new RuntimeException("Incus instance {$name} has invalid devices.");
        }
        $disks = [];
        foreach ($devices as $device => $configuration) {
            if (
                ! is_string($device)
                || $device === 'root'
                || ! is_array($configuration)
                || ($configuration['type'] ?? null) !== 'disk'
            ) {
                continue;
            }
            $source = $configuration['source'] ?? null;
            $path = $configuration['path'] ?? null;
            if (! is_string($source) || ! is_string($path) || $source === '' || $path === '') {
                throw new RuntimeException("Incus instance {$name} disk device {$device} identity is invalid.");
            }
            $disks[$device] = ['source' => $source, 'path' => $path];
        }

        return $disks;
    }

    private function validatedOwnedVm(string $name): IncusInstance
    {
        $instance = $this->validatedVm($name);
        $this->assertOwned($instance->metadata, "instance {$name}");

        return $instance;
    }

    private function validatedOwnedSnapshot(string $instance, string $snapshot): void
    {
        if (! $this->ownedSnapshotExists($instance, $snapshot)) {
            throw new RuntimeException('Incus snapshot identity changed before mutation.');
        }
    }

    private function ownedSnapshotExists(string $instance, string $snapshot): bool
    {
        $vm = $this->validatedOwnedVm($instance);
        $this->validateName($snapshot, 'snapshot');
        $resources = $this->readJson(['snapshot', 'list', $this->target($instance), '--format=json']);
        $expectedNames = [$snapshot, "{$instance}/{$snapshot}"];
        $resource = array_find(
            $resources,
            fn ($candidate) => is_array($candidate) && in_array($candidate['name'] ?? null, $expectedNames, true),
        );
        if (! is_array($resource)) {
            return false;
        }
        $observedName = $resource['name'] ?? null;

        if ($observedName !== $snapshot && $observedName !== "{$instance}/{$snapshot}") {
            throw new RuntimeException('Incus snapshot identity changed before mutation.');
        }

        $metadata = $this->metadata($resource);
        $this->assertOwned($metadata === [] ? $vm->metadata : $metadata, "snapshot {$instance}/{$snapshot}");

        return true;
    }

    /**
     * @param array<string, string> $snapshots
     * @param array<string, IncusInstance> $instances
     * @return array<string, string>
     */
    private function existingOwnedSnapshots(array $snapshots, array $instances): array
    {
        $commands = [];
        foreach ($snapshots as $instance => $_snapshot) {
            $commands[$instance] = ['snapshot', 'list', $this->target($instance), '--format=json'];
        }
        $results = $this->runParallel($commands, 60, failureMessage: 'Incus snapshot inventory batch failed');

        $existing = [];
        foreach ($snapshots as $instance => $snapshot) {
            try {
                $resources = json_decode($results[$instance]->output(), true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException $exception) {
                throw new RuntimeException('Incus returned malformed snapshot JSON.', 0, $exception);
            }
            if (! is_array($resources)) {
                throw new RuntimeException('Incus returned malformed snapshot JSON.');
            }
            $expectedNames = [$snapshot, "{$instance}/{$snapshot}"];
            $resource = array_find(
                $resources,
                static fn (mixed $candidate): bool => (
                    is_array($candidate) && in_array($candidate['name'] ?? null, $expectedNames, true)
                ),
            );
            if (! is_array($resource)) {
                continue;
            }
            $metadata = $this->metadata($resource);
            $this->assertOwned(
                $metadata === [] ? $instances[$instance]->metadata : $metadata,
                "snapshot {$instance}/{$snapshot}",
            );
            $existing[$instance] = $snapshot;
        }

        return $existing;
    }

    /** @param array<string, string> $metadata */
    private function assertOwned(array $metadata, string $resource): void
    {
        foreach ($this->ownershipMetadata as $key => $value) {
            if (($metadata[$key] ?? null) !== $value) {
                throw new RuntimeException("Incus {$resource} ownership metadata does not match.");
            }
        }
    }

    /**
     * @param list<string> $arguments
     * @return array<mixed>
     */
    private function readJson(array $arguments): array
    {
        $result = $this->run($arguments);

        try {
            $decoded = json_decode($result->output(), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Incus returned malformed JSON.', 0, $exception);
        }

        if (! is_array($decoded)) {
            throw new RuntimeException('Incus returned malformed JSON.');
        }

        return $decoded;
    }

    /**
     * @param array<string, list<string>> $commands
     * @return array<string, ProcessResult>
     */
    private function runParallel(
        array $commands,
        int $timeout,
        bool $failOnError = true,
        string $failureMessage = 'Incus parallel command batch failed',
    ): array {
        if ($commands === []) {
            throw new RuntimeException('Incus parallel command batch must be non-empty.');
        }

        $fullCommands = [];
        foreach ($commands as $label => $arguments) {
            $this->validateName($label, 'parallel command label');
            if ($arguments === []) {
                throw new RuntimeException('Incus parallel command arguments must be non-empty.');
            }
            $fullCommands[$label] = ['incus', '--project', $this->project, ...$arguments];
        }

        try {
            $results = Process::pool(function (Pool $pool) use ($fullCommands, $timeout): void {
                foreach ($fullCommands as $label => $command) {
                    $pool->as($label)->timeout($timeout)->command($command);
                }
            })->run();
        } catch (Throwable $exception) {
            $message = $this->redactor->redact($exception->getMessage());
            if ($failOnError) {
                foreach ($fullCommands as $command) {
                    $this->recordFailure($command, null, $message);
                }
            }

            throw new RuntimeException("{$failureMessage}: {$message}", 0, $exception);
        }

        $resolved = [];
        $failed = [];
        foreach ($fullCommands as $label => $command) {
            $result = $results[$label] ?? null;
            if (! $result instanceof ProcessResult) {
                throw new RuntimeException('Incus parallel command result is invalid.');
            }
            $resolved[$label] = $result;
            if ($result->successful()) {
                continue;
            }

            $error = $this->redactor->redact(trim($result->errorOutput()."\n".$result->output()));
            $failed[$label] = $error !== '' ? $error : 'exit code '.$result->exitCode();
            if ($failOnError) {
                $this->recordFailure($command, $result->exitCode(), $error);
            }
        }
        if ($failOnError && $failed !== []) {
            $details = [];
            foreach ($failed as $label => $error) {
                $details[] = "{$label}: {$error}";
            }

            throw new RuntimeException($failureMessage.' for '.implode('; ', $details).'.');
        }

        if (
            $this->ownedInstanceCache !== []
            && array_any(
                $commands,
                fn (array $arguments): bool => $this->invalidatesOwnershipCache($arguments),
            )
        ) {
            $this->ownedInstanceCache = [];
        }

        return $resolved;
    }

    /**
     * @param array<string, string> $acquisitionMetadata
     * @param array{device:string,source:string,path:string}|null $mount A host directory attached as a virtiofs disk at copy time.
     * @return array{list<string>, IncusInstance}
     * @mago-expect lint:excessive-parameter-list Snapshot transfer inputs remain explicit at the Incus trust boundary.
     */
    private function snapshotCopy(
        string $source,
        string $snapshot,
        string $target,
        array $acquisitionMetadata,
        ?string $network = null,
        ?string $role = null,
        ?string $topology = null,
        ?int $slot = null,
        bool $validateSource = true,
        ?array $mount = null,
    ): array {
        $this->validateName($source, 'instance');
        $this->validateName($snapshot, 'snapshot');
        $this->validateName($target, 'instance');
        $this->validateStringMap($acquisitionMetadata, 'acquisition metadata');
        foreach ($acquisitionMetadata as $key => $value) {
            $this->validateMetadata($key, $value);
            if (array_key_exists($key, $this->ownershipMetadata)) {
                throw new RuntimeException('Incus acquisition metadata cannot override ownership metadata.');
            }
        }
        if ($validateSource) {
            $this->assertOwnedSnapshot($source, $snapshot);
        }
        $metadata = [...$this->ownershipMetadata, ...$acquisitionMetadata];
        $configuration = [];
        foreach ($metadata as $key => $value) {
            $configuration[] = '--config';
            $configuration[] = "{$key}={$value}";
        }
        if ($network !== null || $role !== null || $topology !== null || $slot !== null) {
            if (! is_string($network) || ! is_string($role) || ! is_string($topology)) {
                throw new RuntimeException('Incus snapshot copy network identity is incomplete.');
            }
            if ($slot === null) {
                throw new RuntimeException('Incus snapshot copy network identity is incomplete.');
            }
            $this->validateName($network, 'network');
            $this->validateName($role, 'role');
            $this->validateName($topology, 'topology');
            $configuration[] = '--device';
            $configuration[] = 'eth0,network='.$network;
            $configuration[] = '--device';
            $configuration[] = 'eth0,ipv4.address='.TopologyTarget::ipv4For($slot, $role);
            $configuration[] = '--device';
            $configuration[] = 'eth0,hwaddr='.$this->deterministicMac($topology, $role);
        }
        $disks = [];
        if ($mount !== null) {
            $this->validateMount($mount);
            // Incus takes one --device flag per key.
            $configuration[] = '--device';
            $configuration[] = "{$mount['device']},type=disk";
            $configuration[] = '--device';
            $configuration[] = "{$mount['device']},source={$mount['source']}";
            $configuration[] = '--device';
            $configuration[] = "{$mount['device']},path={$mount['path']}";
            $disks[$mount['device']] = ['source' => $mount['source'], 'path' => $mount['path']];
        }

        return [
            [
                'copy',
                "{$this->target($source)}/{$snapshot}",
                $this->target($target),
                '--storage',
                $this->pool,
                '--config',
                'limits.cpu='.$this->incusLimit('cpu', '1'),
                '--config',
                'limits.memory='.$this->incusLimit('memory', '2GiB'),
                '--device',
                'root,pool='.$this->pool,
                '--device',
                'root,size='.$this->incusLimit('root_size', '16GiB'),
                ...$configuration,
            ],
            new IncusInstance($this->remote, $this->project, $target, $this->pool, $metadata, disks: $disks),
        ];
    }

    /**
     * A source mount must name an existing host directory; the guest path and the
     * device name are passed to Incus verbatim, so they must be free of separators.
     *
     * @param array{device:string,source:string,path:string} $mount
     */
    private function validateMount(array $mount): void
    {
        $this->validateName($mount['device'], 'device');
        if ($mount['device'] === 'root' || $mount['device'] === 'eth0') {
            throw new RuntimeException('Invalid Incus device identity.');
        }
        foreach (['source', 'path'] as $key) {
            if (! MountPath::isSafe($mount[$key])) {
                throw new RuntimeException("Invalid Incus mount {$key}.");
            }
        }
        if (! MountPath::isMountableDirectory($mount['source'])) {
            throw new RuntimeException('The Incus mount source must be an existing directory.');
        }
    }

    private function networkSlot(IncusNetwork $network): int
    {
        $address = $network->config['ipv4.address'] ?? null;
        if (! is_string($address) || preg_match('/\A10\.232\.(\d{1,3})\.1\/24\z/D', $address, $matches) !== 1) {
            throw new RuntimeException('Incus network IPv4 configuration does not contain a valid topology slot.');
        }

        $slot = (int) $matches[1];
        if ($slot < 1 || $slot > 200) {
            throw new RuntimeException('Incus network IPv4 configuration contains an invalid topology slot.');
        }

        return $slot;
    }

    /**
     * @param array<string, array{
     *     source:string,
     *     snapshot:string,
     *     target:string,
     *     metadata:array<string,string>,
     *     network?:string,
     *     role?:string,
     *     topology?:string,
     *     slot?:int,
     *     mount?:array{device:string,source:string,path:string}
     * }> $copies
     */
    private function validateSnapshotCopies(array $copies): void
    {
        $sources = array_values(array_unique(array_column($copies, 'source')));
        $this->ownedInstances($sources, 'snapshot copy');
        $commands = [];
        foreach ($copies as $label => $copy) {
            $commands[$label] = ['snapshot', 'list', $this->target($copy['source']), '--format=json'];
        }
        $results = $this->runParallel($commands, 60, failureMessage: 'Incus snapshot validation batch failed');
        foreach ($copies as $label => $copy) {
            $resources = json_decode($results[$label]->output(), true);
            if (! is_array($resources)) {
                throw new RuntimeException('Incus returned malformed snapshot JSON.');
            }
            $expected = [$copy['snapshot'], $copy['source'].'/'.$copy['snapshot']];
            $resource = array_find(
                $resources,
                fn ($candidate): bool => is_array($candidate) && in_array($candidate['name'] ?? null, $expected, true),
            );
            if (! is_array($resource)) {
                throw new RuntimeException('Incus snapshot identity changed before mutation.');
            }
            $this->assertOwned($this->metadata($resource), "snapshot {$copy['source']}/{$copy['snapshot']}");
        }
    }

    /** @param list<string> $instances */
    private function validateUniqueInstances(array $instances, string $label): void
    {
        if ($instances === [] || count($instances) !== count(array_unique($instances))) {
            throw new RuntimeException("Incus {$label} instance list must be non-empty and unique.");
        }

        foreach ($instances as $instance) {
            $this->validateName($instance, 'instance');
        }
    }

    /**
     * @param list<string> $instances
     * @return array<string, IncusInstance>
     */
    private function ownedInstances(array $instances, string $label): array
    {
        $this->validateUniqueInstances($instances, $label);
        $found = $this->instances($instances);
        foreach ($instances as $instance) {
            if (! isset($found[$instance])) {
                throw new RuntimeException("Incus instance {$instance} does not exist.");
            }
            $this->assertOwned($found[$instance]->metadata, "instance {$instance}");
        }

        return $found;
    }

    /**
     * Reuse ownership proof only within one journalled CLI operation. A new
     * command builds a new IncusHost, so it must validate external state again.
     *
     * @param list<string> $instances
     * @return array<string, IncusInstance>
     */
    private function operationOwnedInstances(array $instances, string $label): array
    {
        $missing = array_values(array_filter(
            $instances,
            fn (string $instance): bool => ! isset($this->ownedInstanceCache[$instance]),
        ));
        if ($missing !== []) {
            $this->ownedInstanceCache = [
                ...$this->ownedInstanceCache,
                ...$this->ownedInstances($missing, $label),
            ];
        }
        foreach ($instances as $instance) {
            if (! isset($this->ownedInstanceCache[$instance])) {
                throw new RuntimeException("Incus instance {$instance} does not exist.");
            }
            $this->assertOwned($this->ownedInstanceCache[$instance]->metadata, "instance {$instance}");
        }

        return array_intersect_key($this->ownedInstanceCache, array_fill_keys($instances, true));
    }

    /** @param list<string> $arguments */
    private function run(
        array $arguments,
        int $timeout = 60,
        bool $failOnError = true,
        ?string $stdin = null,
    ): ProcessResult {
        $command = ['incus', '--project', $this->project, ...$arguments];

        try {
            $process = Process::timeout($timeout);
            if ($stdin !== null) {
                $process = $process->input($stdin);
            }
            $result = $process->run($command);
        } catch (Throwable $exception) {
            $message = $this->redactor->redact($exception->getMessage());
            if ($this->isGuestExecCommand($command)) {
                $message = 'Incus guest command could not run.';
            }
            $this->recordFailure($command, null, $message);

            throw new RuntimeException("Incus command timed out or could not run: {$message}", 0, $exception);
        }

        if (! $result->successful()) {
            // Record every non-zero exit, redacted, so guest failures leave evidence
            // even when the caller inspects the exit code itself.
            $error = $this->redactor->redact(trim($result->errorOutput()."\n".$result->output()));
            $this->recordFailure($command, $result->exitCode(), $error);
            if ($failOnError) {
                throw new RuntimeException("Incus command failed with exit code {$result->exitCode()}: {$error}");
            }
        }

        if ($this->invalidatesOwnershipCache($arguments)) {
            $this->ownedInstanceCache = [];
        }

        return $result;
    }

    /** @param list<string> $arguments */
    private function invalidatesOwnershipCache(array $arguments): bool
    {
        return in_array(
            $arguments[0] ?? '',
            [
                'config',
                'copy',
                'delete',
                'init',
                'launch',
                'move',
                'rebuild',
                'rename',
            ],
            true,
        );
    }

    /** @param list<string> $command */
    private function recordFailure(array $command, ?int $exitCode, string $error): void
    {
        $journal = $this->journal;
        $operationId = $this->operationId;
        if (! $journal instanceof OperationJournal || ! $operationId instanceof OperationId) {
            return;
        }

        $journalCommand = $command;
        if ($this->isGuestExecCommand($command)) {
            $journalCommand = array_slice($command, 0, 6);
        }

        $journal->append($operationId, [
            'command' => $this->redactor->redactArray($journalCommand),
            'exit_code' => $exitCode,
            'error' => $error,
        ]);
    }

    /** @param list<string> $command */
    private function isGuestExecCommand(array $command): bool
    {
        return (
            count($command) >= 6
            && ($command[0] ?? null) === 'incus'
            && ($command[1] ?? null) === '--project'
            && is_string($command[2] ?? null)
            && ($command[3] ?? null) === 'exec'
            && is_string($command[4] ?? null)
            && ($command[5] ?? null) === '--'
        );
    }

    /** @return array<string, string> */
    private function metadata(array $resource): array
    {
        $configuration = $resource['config'] ?? [];
        if (! is_array($configuration)) {
            throw new RuntimeException('Incus returned invalid resource configuration.');
        }

        return $this->e2eMetadata($configuration);
    }

    /** @return array<string, string> */
    private function configuration(array $resource): array
    {
        $configuration = $resource['config'] ?? [];
        if (! is_array($configuration)) {
            throw new RuntimeException('Incus returned invalid resource configuration.');
        }

        $validated = [];
        foreach ($configuration as $key => $value) {
            if (! is_string($key) || ! is_string($value)) {
                throw new RuntimeException('Incus returned invalid resource configuration.');
            }
            $validated[$key] = $value;
        }

        return $validated;
    }

    /** @return array<string, string> */
    private function e2eMetadata(array $configuration): array
    {
        $metadata = [];

        foreach ($configuration as $key => $value) {
            if (is_string($key) && str_starts_with($key, 'user.orbit.e2e.') && is_string($value)) {
                $metadata[$key] = $value;
            }
        }

        return $metadata;
    }

    private function validateName(string $value, string $label): void
    {
        if (preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9_.-]{0,62}\z/', $value) !== 1) {
            throw new RuntimeException("Invalid Incus {$label} identity.");
        }
    }

    private function target(string $name): string
    {
        return "{$this->remote}:{$name}";
    }

    /** @return array{string, string} */
    private function imageSelector(string $image): array
    {
        $separator = strpos($image, ':');
        if ($separator === false) {
            return ["{$this->remote}:", $image];
        }

        $remote = substr($image, 0, $separator);
        $selector = substr($image, $separator + 1);
        $this->validateName($remote, 'image remote');
        if ($selector === '' || str_contains($selector, ':')) {
            throw new RuntimeException('Invalid Incus image identity.');
        }

        return ["{$remote}:", $selector];
    }

    private function validateImage(string $image): void
    {
        if (preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9_.:\/-]{0,254}\z/', $image) !== 1) {
            throw new RuntimeException('Invalid Incus image identity.');
        }
    }

    private function validateMetadata(string $key, string $value): void
    {
        if (preg_match('/\Auser\.orbit\.e2e\.[a-z0-9.-]+\z/', $key) !== 1 || str_contains($value, "\0")) {
            throw new RuntimeException('Invalid Incus ownership metadata.');
        }
    }

    private function validateConfiguration(string $key, string $value): void
    {
        if (preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9_.-]*\z/', $key) !== 1 || str_contains($value, "\0")) {
            throw new RuntimeException('Invalid Incus network configuration.');
        }
    }

    private function validateStringMap(array $values, string $label): void
    {
        foreach ($values as $key => $value) {
            if (! is_string($key) || ! is_string($value)) {
                throw new RuntimeException("Incus {$label} must contain string keys and values.");
            }
        }
    }
}
