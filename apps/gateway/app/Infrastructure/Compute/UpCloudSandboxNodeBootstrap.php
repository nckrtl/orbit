<?php

declare(strict_types=1);

namespace App\Infrastructure\Compute;

use App\Actions\Nodes\ProvisionNodeAction;
use App\Data\Nodes\ProvisionNodeData;
use App\Domain\Compute\ComputeException;
use App\Domain\Compute\SandboxNodeBootstrap;
use App\Domain\Nodes\RoleName;
use App\Infrastructure\Ssh\HostKey;
use App\Infrastructure\Ssh\HostKeyScanner;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\Node;
use App\Models\TaskSandbox;
use Illuminate\Support\Facades\DB;
use Throwable;

final readonly class UpCloudSandboxNodeBootstrap implements SandboxNodeBootstrap
{
    public function __construct(private HostKeyScanner $scanner, private KnownHostsStore $hosts, private SshExecutor $ssh,
        private SshKeyProvider $keys, private ProvisionNodeAction $nodes) {}

    public function prepare(TaskSandbox $sandbox, Node $node): void
    {
        try {
            $e = $sandbox->enrollment;
            if ($e === null) {
                throw new ComputeException('compute.ownership_mismatch', 'The sandbox enrollment identity is missing.');
            }
            if ($node->ssh_host_fingerprint === null && ! isset($e['ssh_fingerprint'])) {
                $key = $this->scanner->scan($node->public_ssh_host, 22);
                DB::transaction(function () use ($node, $sandbox, $e, $key): void {
                    $node->update(['ssh_host_key_type' => $key->type, 'ssh_host_key' => $key->value, 'ssh_host_fingerprint' => $key->fingerprint]);
                    $sandbox->enrollment = [...$e, 'ssh_fingerprint' => $key->fingerprint];
                    $sandbox->save();
                });
            } elseif ($node->ssh_host_fingerprint !== ($e['ssh_fingerprint'] ?? null)) {
                throw new ComputeException('compute.ownership_mismatch', 'The recorded sandbox SSH identity changed.');
            }
            $type = $node->getAttribute('ssh_host_key_type');
            $value = $node->getAttribute('ssh_host_key');
            if (! is_string($type) || ! is_string($value) || ! is_string($node->ssh_host_fingerprint)) {
                throw new ComputeException('compute.ownership_mismatch', 'The recorded sandbox SSH identity is missing.');
            }
            $host = $node->roles()->exists() ? $node->wireguard_ip : $node->public_ssh_host;
            if (! is_string($host)) {
                throw new ComputeException('compute.ownership_mismatch', 'The sandbox SSH address is missing.');
            }
            $this->hosts->put($host, 22, new HostKey($type, $value, $node->ssh_host_fingerprint));
            $result = $this->ssh->execute(new SshConnection($host, 'orbit', 22, $this->keys->privateKeyPath(), $this->hosts->path(), commandTimeout: 30, shareConnection: false),
                new RemoteCommand(['sudo', '-n', 'python3', '-I', '-c', <<<'PY'
                    import json, pwd, subprocess, sys
                    r = subprocess.run(['cloud-init', 'status', '--format=json'], capture_output=True, text=True, timeout=15)
                    data = json.loads(r.stdout)
                    if r.returncode != 0 or data.get('status') != 'done' or data.get('errors'):
                        sys.exit(1)
                    try:
                        pwd.getpwnam('orbit-worker')
                        sys.exit(1)
                    except KeyError:
                        pass
                    print(json.dumps({'ready': True}))
                    PY], timeout: 25, maxOutputBytes: 1024));
            if (! $result->succeeded() || $result->truncated || json_decode($result->stdout, true) !== ['ready' => true]) {
                throw new ComputeException('compute.bootstrap_not_ready', 'The sandbox bootstrap is not ready.');
            }
        } catch (ComputeException $e) {
            throw $e;
        } catch (Throwable) {
            throw new ComputeException('compute.bootstrap_not_ready', 'The sandbox bootstrap is not ready.');
        }
    }

    public function enroll(TaskSandbox $sandbox, Node $node): Node
    {
        return $this->nodes->executeSandbox(new ProvisionNodeData(name: $node->name, publicSshHost: $node->public_ssh_host,
            roles: [RoleName::AppDev], user: 'orbit', orbitUser: 'orbit', wireguardIp: $node->wireguard_ip,
            expectedSshHostFingerprint: $node->ssh_host_fingerprint, architecture: 'x86_64', clusterId: $node->cluster_id, clusterProvided: true), $sandbox);
    }
}
