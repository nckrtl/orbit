<?php

declare(strict_types=1);

namespace App\Infrastructure\Doctor;

use App\Domain\Doctor\DoctorInspectionException;
use App\Domain\Doctor\NodeInspectionData;
use App\Domain\Doctor\NodeStateInspector;
use App\Infrastructure\Nodes\NodeAgentFootprint;
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
        binary_exists=0
        unit_exists=0
        agent_active=0
        checksum=''
        if test -x /usr/local/bin/orbit-agent; then
            binary_exists=1
            checksum=$(sha256sum -- /usr/local/bin/orbit-agent | cut -d ' ' -f 1)
        fi
        if test -f /etc/systemd/system/orbit-agent.service; then unit_exists=1; fi
        if systemctl is-active --quiet orbit-agent.service; then agent_active=1; fi
        secret_checksum=''
        if sudo -n test -f /etc/orbit/agent/secret 2>/dev/null; then
            secret_checksum=$(sudo -n sha256sum -- /etc/orbit/agent/secret | cut -d ' ' -f 1)
        fi
        printf '%s\n%s\n%s\n%s\n%s\n%s\n%s\n%s\n' \
            "$platform" "$architecture" "$wireguard" "$binary_exists" "$unit_exists" "$agent_active" "$checksum" "$secret_checksum"
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
                    shareConnection: false,
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
        if (count($lines) !== 9 || $lines[8] !== '') {
            throw new DoctorInspectionException;
        }
        $platform = strtolower($lines[0]);
        $wireguard = $lines[2];
        $binaryExists = $lines[3];
        $unitExists = $lines[4];
        $agentActive = $lines[5];
        $checksum = $lines[6];
        $secretChecksum = $lines[7];
        if (
            ! in_array($platform, ['linux', 'darwin', 'freebsd'], strict: true)
            || ! in_array($wireguard, ['0', '1'], strict: true)
            || ! in_array($binaryExists, ['0', '1'], strict: true)
            || ! in_array($unitExists, ['0', '1'], strict: true)
            || ! in_array($agentActive, ['0', '1'], strict: true)
            || ($binaryExists === '1' && preg_match('/\A[a-f0-9]{64}\z/', $checksum) !== 1)
            || ($binaryExists === '0' && $checksum !== '')
            || ($secretChecksum !== '' && preg_match('/\A[a-f0-9]{64}\z/', $secretChecksum) !== 1)
        ) {
            throw new DoctorInspectionException;
        }
        $architecture = match (strtolower($lines[1])) {
            'x86_64', 'amd64' => 'x86_64',
            'aarch64', 'arm64' => 'aarch64',
            default => throw new DoctorInspectionException,
        };

        $agentArchitecture = match (strtolower($node->architecture ?? '')) {
            'amd64', 'x86_64' => 'x86_64',
            'arm64', 'aarch64' => 'aarch64',
            default => null,
        };

        return new NodeInspectionData(
            true,
            $platform,
            $architecture,
            $wireguard === '1',
            $binaryExists === '1',
            $unitExists === '1',
            $agentActive === '1',
            $agentArchitecture === null
                ? null
                : hash_equals(NodeAgentFootprint::checksum($agentArchitecture), $checksum),
            $secretChecksum === '' ? null : $secretChecksum,
        );
    }
}
