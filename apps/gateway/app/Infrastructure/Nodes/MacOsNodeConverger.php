<?php

declare(strict_types=1);

namespace App\Infrastructure\Nodes;

use App\Domain\Nodes\MachineArchitecture;
use App\Domain\Nodes\MacOsEnrollmentObservation;
use App\Domain\Nodes\NodeProvisioningException;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Ssh\HostKey;
use App\Infrastructure\Ssh\HostKeyScanner;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshHostKeyScanException;
use App\Infrastructure\Ssh\SshKeyProvider;

/**
 * Enrolls a Mac over the existing account without changing the machine.
 *
 * The checks are host key, account, platform and architecture, then the
 * WireGuard address. Nothing in this path creates a user, installs packages,
 * or rewrites DNS, firewall, or tunnel state.
 */
final readonly class MacOsNodeConverger
{
    /**
     * Reads platform, architecture, and whether the enrolled address is up.
     * The address is an argument, never interpolated into the script.
     */
    public const string SCRIPT = <<<'BASH'
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
        printf '%s\n%s\n%s\n%s\n' "$platform" "$architecture" "$tunnel" "$wireguard"
        BASH;

    public function __construct(
        private HostKeyScanner $hostKeys,
        private SshExecutor $ssh,
        private SshKeyProvider $sshKeys,
    ) {}

    public function enroll(
        string $name,
        string $host,
        int $port,
        string $account,
        string $address,
        ?string $storedFingerprint,
        ?string $expectedFingerprint,
    ): MacOsEnrollmentObservation {
        try {
            $hostKey = $this->hostKeys->scan($host, $port);
        } catch (SshHostKeyScanException $exception) {
            throw new NodeProvisioningException(
                step: 'ssh-host-key',
                errorCode: 'node.ssh_host_key_scan_failed',
                message: "Could not scan the SSH host key for node [{$name}].",
                previous: $exception,
                result: $exception->result,
            );
        }

        if (! $this->fingerprintMatches($storedFingerprint, $hostKey->fingerprint)) {
            throw new NodeProvisioningException(
                step: 'ssh-host-key',
                errorCode: 'node.ssh_host_key_changed',
                message: "The SSH host key changed for node [{$name}].",
            );
        }

        if (
            $storedFingerprint === null
            && $expectedFingerprint === null
        ) {
            throw new NodeProvisioningException(
                step: 'ssh-host-key',
                errorCode: 'node.ssh_host_fingerprint_required',
                message: "An expected SSH host fingerprint is required for node [{$name}].",
            );
        }

        if (! $this->fingerprintMatches($expectedFingerprint, $hostKey->fingerprint)) {
            throw new NodeProvisioningException(
                step: 'ssh-host-key',
                errorCode: 'node.ssh_host_key_mismatch',
                message: "The SSH host fingerprint did not match for node [{$name}].",
            );
        }

        $knownHosts = $this->temporaryKnownHosts($host, $port, $hostKey);

        try {
            $connection = new SshConnection(
                host: $host,
                user: $account,
                port: $port,
                identityFile: $this->sshKeys->privateKeyPath(),
                knownHostsFile: $knownHosts,
                commandTimeout: 30.0,
                shareConnection: false,
            );
            $accountCheck = $this->ssh->execute($connection, new RemoteCommand(['true']));

            if (! $accountCheck->succeeded()) {
                throw new NodeProvisioningException(
                    step: 'account',
                    errorCode: 'node.account_unavailable',
                    message: "Could not connect to node [{$name}] as {$account}.",
                    result: $accountCheck,
                );
            }

            $observation = $this->ssh->execute($connection, new RemoteCommand(
                ['bash', '-seu', '--', $address],
                self::SCRIPT,
            ));

            return $this->readObservation($name, $address, $hostKey, $observation);
        } finally {
            if (is_file($knownHosts)) {
                unlink($knownHosts);
            }
        }
    }

    private function readObservation(
        string $name,
        string $address,
        HostKey $hostKey,
        CommandResult $observation,
    ): MacOsEnrollmentObservation {
        $lines = explode("\n", rtrim($observation->stdout, "\n"));

        if (! $observation->succeeded() || count($lines) !== 4) {
            throw new NodeProvisioningException(
                step: 'machine-architecture',
                errorCode: 'node.architecture_unavailable',
                message: "Could not observe the machine architecture of node [{$name}].",
                result: $observation,
            );
        }

        [$platform, $architecture, $tunnel, $wireguard] = $lines;
        $platform = strtolower($platform);

        if ($platform !== 'darwin') {
            throw new NodeProvisioningException(
                step: 'machine-architecture',
                errorCode: 'node.platform_mismatch',
                message: "Node [{$name}] reports platform [{$platform}], not macOS.",
                result: $observation,
            );
        }

        if (! MachineArchitecture::isValid($architecture)) {
            throw new NodeProvisioningException(
                step: 'machine-architecture',
                errorCode: 'node.architecture_unavailable',
                message: "Could not observe the machine architecture of node [{$name}].",
                result: $observation,
            );
        }

        if ($tunnel !== 'ok' || $wireguard !== '1') {
            throw new NodeProvisioningException(
                step: 'identity',
                errorCode: 'node.wireguard_required',
                message: "WireGuard address [{$address}] is not present on node [{$name}].",
                result: $observation,
            );
        }

        return new MacOsEnrollmentObservation($architecture, $hostKey);
    }

    private function fingerprintMatches(?string $expected, string $actual): bool
    {
        if ($expected === null) {
            return true;
        }

        return strlen($expected) === strlen($actual) && hash_equals($expected, $actual);
    }

    private function temporaryKnownHosts(string $host, int $port, HostKey $key): string
    {
        $path = tempnam(sys_get_temp_dir(), 'orbit-macos-known-hosts-');

        if ($path === false) {
            throw new NodeProvisioningException(
                step: 'ssh-host-key',
                errorCode: 'node.ssh_host_key_scan_failed',
                message: "Could not scan the SSH host key for node [{$host}].",
            );
        }

        $label = $port === 22 ? $host : "[{$host}]:{$port}";
        $contents = "{$label} {$key->type} {$key->value}\n";
        $written = file_put_contents($path, $contents, LOCK_EX);

        if ($written !== strlen($contents) || ! chmod($path, 0600)) {
            unlink($path);

            throw new NodeProvisioningException(
                step: 'ssh-host-key',
                errorCode: 'node.ssh_host_key_scan_failed',
                message: "Could not scan the SSH host key for node [{$host}].",
            );
        }

        return $path;
    }
}
