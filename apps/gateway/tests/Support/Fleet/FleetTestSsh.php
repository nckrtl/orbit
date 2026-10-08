<?php

declare(strict_types=1);

namespace Tests\Support\Fleet;

use App\Domain\Certificates\LeafCertificateSigner;
use App\Infrastructure\Fleet\NodeShell;
use App\Infrastructure\Ssh\HostKey;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\SshKeyProvider;

/** The SSH key, known hosts, and root certificate stand-ins of the fleet tests. */
final class FleetTestSsh implements KnownHostsStore, LeafCertificateSigner, SshKeyProvider
{
    public const string RootCertificate = "-----BEGIN CERTIFICATE-----\nroot\n-----END CERTIFICATE-----\n";

    public static function shell(ScriptedSshExecutor $ssh): NodeShell
    {
        $stand = new self;

        return new NodeShell($ssh, $stand, $stand);
    }

    public function privateKeyPath(): string
    {
        return '/tmp/key';
    }

    public function publicKey(): string
    {
        return 'ssh-ed25519 test';
    }

    public function path(): string
    {
        return '/tmp/known-hosts';
    }

    public function put(string $host, int $port, HostKey $key): void {}

    public function sign(string $hostname, string $certificateRequest): string
    {
        return '';
    }

    public function rootCertificate(): string
    {
        return self::RootCertificate;
    }
}
