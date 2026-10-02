<?php

declare(strict_types=1);

namespace App\Infrastructure\Doctor;

use App\Domain\Doctor\DoctorInspectionException;
use App\Domain\Doctor\NodeDiskFilesystemData;
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
        disk=$(LC_ALL=C df --output=source,avail,size,iavail,itotal -k -- / "$HOME")
        printf '%s\n%s\n%s\n%s\n%s\n%s\n%s\n%s\n%s\n' \
            "$platform" "$architecture" "$wireguard" "$binary_exists" "$unit_exists" "$agent_active" "$checksum" "$secret_checksum" "$disk"
        BASH;

    /**
     * Bounded macOS observation. It reads platform, architecture, the enrolled
     * address, and free space on the home volume. It does not call systemd,
     * getent, or GNU df, and it does not look for a Node agent.
     */
    private const string MAC_SCRIPT = <<<'BASH'
        address=$1
        platform=$(uname -s)
        architecture=$(uname -m)
        if interfaces=$(/sbin/ifconfig); then
          tunnel=ok
          wireguard=0
          if printf '%s\n' "$interfaces" | awk -v address="$address" '$1 == "inet" && $2 == address { found=1 } END { exit found ? 0 : 1 }'; then
            wireguard=1
          fi
        else
          tunnel=unreadable
          wireguard=0
        fi
        home=${HOME-}
        if [ -z "$home" ]; then
          disk_status=unreadable
          disk='0 0'
        elif disk=$(LC_ALL=C df -kP "$home" | awk 'NR==2 { print $4, $2 }') && [ -n "$disk" ]; then
          disk_status=ok
        else
          disk_status=unreadable
          disk='0 0'
        fi
        printf '%s\n%s\n%s\n%s\n%s\n%s\n' "$platform" "$architecture" "$tunnel" "$wireguard" "$disk_status" "$disk"
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
                new RemoteCommand(
                    ['bash', '-seu', '--', $address],
                    $node->platform === 'macos' ? self::MAC_SCRIPT : self::SCRIPT,
                ),
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
        if ($node->platform === 'macos') {
            return $this->macInspection($result->stdout);
        }
        $lines = explode("\n", $result->stdout);
        if (count($lines) !== 12 || $lines[11] !== '' || preg_match('/\A\s*Filesystem\s+Avail\s+1K-blocks\s+IFree\s+Inodes\s*\z/', $lines[8]) !== 1) {
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

        // df returns one row per requested path, even when root and home share a mount.
        // Keep the source only for deduplication; never include it in a Doctor report.
        $filesystems = [];
        $seenSources = [];
        foreach (['root' => $lines[9], 'home' => $lines[10]] as $location => $line) {
            if (preg_match('/\A(.+)\s+(-?[0-9]+)\s+([0-9]+)\s+([0-9]+|-)\s+([0-9]+|-)\z/', $line, $matches) !== 1) {
                throw new DoctorInspectionException;
            }
            [$source, $available, $size, $freeInodes, $totalInodes] = array_slice($matches, 1);
            if (filter_var($available, FILTER_VALIDATE_INT) === false
                || filter_var($size, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false
                || (int) $available > (int) $size) {
                throw new DoctorInspectionException;
            }
            // Some filesystems (including Btrfs) report 0/0, while df uses -/- for
            // unavailable counters. Neither pair supplies a meaningful inode percentage.
            if ($freeInodes === '-' || $totalInodes === '-') {
                if ($freeInodes !== '-' || $totalInodes !== '-') {
                    throw new DoctorInspectionException;
                }
                $freeInodeCount = $totalInodeCount = null;
            } else {
                if (filter_var($freeInodes, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]) === false
                    || filter_var($totalInodes, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]) === false
                    || (int) $freeInodes > (int) $totalInodes
                    || ((int) $totalInodes === 0 && (int) $freeInodes !== 0)) {
                    throw new DoctorInspectionException;
                }
                $freeInodeCount = (int) $totalInodes === 0 ? null : (int) $freeInodes;
                $totalInodeCount = (int) $totalInodes === 0 ? null : (int) $totalInodes;
            }
            if (! isset($seenSources[$source])) {
                $filesystems[] = new NodeDiskFilesystemData($location, max(0, (int) $available), (int) $size, $freeInodeCount, $totalInodeCount);
                $seenSources[$source] = true;
            }
        }

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
            $filesystems,
        );
    }

    private function macInspection(string $stdout): NodeInspectionData
    {
        $lines = explode("\n", $stdout);
        $platform = strtolower($lines[0]);
        if (! in_array($platform, ['linux', 'darwin', 'freebsd'], strict: true) || ! isset($lines[1])) {
            throw new DoctorInspectionException;
        }
        $architecture = match (strtolower($lines[1])) {
            'x86_64', 'amd64' => 'x86_64',
            'aarch64', 'arm64' => 'aarch64',
            default => throw new DoctorInspectionException,
        };
        // Ubuntu has no /sbin/ifconfig, so a Linux host prints an unreadable tunnel
        // before any disk line. That is a platform mismatch, not a failed read.
        if ($platform !== 'darwin') {
            return new NodeInspectionData(true, $platform, $architecture, true);
        }
        if (
            count($lines) !== 7
            || $lines[6] !== ''
            || ! in_array($lines[2], ['ok', 'unreadable'], strict: true)
            || ! in_array($lines[3], ['0', '1'], strict: true)
            || ! in_array($lines[4], ['ok', 'unreadable'], strict: true)
            || $lines[2] === 'unreadable'
            || $lines[4] === 'unreadable'
        ) {
            throw new DoctorInspectionException;
        }
        if (preg_match('/\A([0-9]+) ([0-9]+)\z/', $lines[5], $matches) !== 1) {
            throw new DoctorInspectionException;
        }
        $available = $matches[1];
        $size = $matches[2];
        if (filter_var($available, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]) === false
            || filter_var($size, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) === false
            || (int) $available > (int) $size) {
            throw new DoctorInspectionException;
        }

        return new NodeInspectionData(
            true,
            $platform,
            $architecture,
            $lines[3] === '1',
            diskFilesystems: [
                new NodeDiskFilesystemData('home', (int) $available, (int) $size, null, null),
            ],
        );
    }
}
