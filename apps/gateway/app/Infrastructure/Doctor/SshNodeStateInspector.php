<?php

declare(strict_types=1);

namespace App\Infrastructure\Doctor;

use App\Domain\Doctor\DoctorInspectionException;
use App\Domain\Doctor\NodeInspectionData;
use App\Domain\Doctor\NodeStateInspector;
use App\Infrastructure\Processes\CommandDeadline;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\Node;
use Throwable;

final readonly class SshNodeStateInspector implements NodeStateInspector
{
    private const string SCRIPT = <<<'BASH'
        address=$1
        platform=$(uname -s)
        architecture=$(uname -m)
        if ip -o -4 addr show | grep -Fq -- " $address/"; then wireguard=1; else wireguard=0; fi
        printf '%s\n%s\n%s\n' "$platform" "$architecture" "$wireguard"
        BASH;

    public function __construct(
        private SshExecutor $ssh,
        private SshKeyProvider $keys,
        private KnownHostsStore $knownHosts,
        private CommandDeadline $deadline,
    ) {}

    public function inspect(Node $node): NodeInspectionData
    {
        $address = $node->wireguard_ip;
        if (! is_string($address) || $address === '') {
            return new NodeInspectionData(false, null, null, null);
        }
        try {
            $result = $this->ssh->execute(
                new SshConnection(
                    $address,
                    $node->user,
                    22,
                    $this->keys->privateKeyPath(),
                    $this->knownHosts->path(),
                    commandTimeout: $this->deadline->cap(30.0),
                ),
                new RemoteCommand(['bash', '-seu', '--', $address], self::SCRIPT),
            );
        } catch (Throwable) {
            return new NodeInspectionData(false, null, null, null);
        }
        if (! $result->succeeded()) {
            return new NodeInspectionData(false, null, null, null);
        }
        if ($result->truncated) {
            throw new DoctorInspectionException;
        }
        $lines = explode("\n", $result->stdout);
        if (count($lines) !== 4 || $lines[3] !== '') {
            throw new DoctorInspectionException;
        }
        $platform = strtolower($lines[0]);
        $wireguard = $lines[2];
        if (
            ! in_array($platform, ['linux', 'darwin', 'freebsd'], strict: true)
            || ! in_array($wireguard, ['0', '1'], strict: true)
        ) {
            throw new DoctorInspectionException;
        }
        $architecture = match (strtolower($lines[1])) {
            'x86_64', 'amd64' => 'x86_64',
            'aarch64', 'arm64' => 'aarch64',
            default => throw new DoctorInspectionException,
        };

        return new NodeInspectionData(true, $platform, $architecture, $wireguard === '1');
    }
}
