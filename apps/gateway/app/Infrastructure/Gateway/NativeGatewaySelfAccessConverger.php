<?php

declare(strict_types=1);

namespace App\Infrastructure\Gateway;

use App\Domain\Gateway\GatewaySelfAccessConverger;
use App\Domain\Nodes\NodeProvisioningException;
use App\Infrastructure\Processes\ProcessInvocation;
use App\Infrastructure\Processes\ProcessRunner;
use App\Infrastructure\Ssh\HostKey;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\Node;
use Closure;

/**
 * Converge the gateway's own SSH self-access so Doctor's fixed SSH boundary
 * can reach the gateway as a registered node: the managed public key must be
 * authorized for the node's account, and the gateway's own SSH host key must
 * be pinned in the managed known_hosts file.
 *
 * @mago-expect lint:cyclomatic-complexity Fail-closed validation of every self-access precondition stays in one converger.
 */
final readonly class NativeGatewaySelfAccessConverger implements GatewaySelfAccessConverger
{
    private const string STEP = 'gateway-self-access';

    private const string ERROR_CODE = 'gateway.self_access_failed';

    /** @param Closure(string): (string|false) $homeDirectory */
    public function __construct(
        private ProcessRunner $processes,
        private KnownHostsStore $knownHosts,
        private SshKeyProvider $sshKeys,
        private Closure $homeDirectory,
        private string $hostPublicKeyPath = '/etc/ssh/ssh_host_ed25519_key.pub',
    ) {}

    public function converge(Node $node): void
    {
        $this->ensureAuthorizedKey($node);
        $this->pinHostKey($node);
    }

    private function ensureAuthorizedKey(Node $node): void
    {
        $home = ($this->homeDirectory)($node->user);

        if (! is_string($home) || $home === '') {
            $this->fail("Could not resolve the home directory for [{$node->user}].");
        }

        $sshDirectory = rtrim(string: $home, characters: '/').'/.ssh';
        $authorizedKeys = $sshDirectory.'/authorized_keys';

        if (
            ! is_dir($sshDirectory)
            && ! mkdir(directory: $sshDirectory, permissions: 0o700, recursive: true)
            && ! is_dir($sshDirectory)
        ) {
            $this->fail("Could not create SSH directory [{$sshDirectory}].");
        }

        chmod(filename: $sshDirectory, permissions: 0o700);

        if (is_link($authorizedKeys) || file_exists($authorizedKeys) && ! is_file($authorizedKeys)) {
            $this->fail("Authorized keys path [{$authorizedKeys}] is not a regular file.");
        }

        if (! is_file($authorizedKeys) && file_put_contents($authorizedKeys, data: '', flags: LOCK_EX) === false) {
            $this->fail("Could not create authorized keys file [{$authorizedKeys}].");
        }

        chmod(filename: $authorizedKeys, permissions: 0o600);

        $publicKey = trim($this->sshKeys->publicKey());
        $contents = file_get_contents($authorizedKeys);

        if (! is_string($contents)) {
            $this->fail("Could not read authorized keys file [{$authorizedKeys}].");
        }

        $splitLines = preg_split('/\R/', trim($contents));
        $lines = is_array($splitLines)
            ? array_values(array_filter($splitLines, static fn (string $line): bool => $line !== ''))
            : [];

        if (in_array(needle: $publicKey, haystack: $lines, strict: true)) {
            return;
        }

        if (file_put_contents($authorizedKeys, $publicKey.PHP_EOL, FILE_APPEND | LOCK_EX) === false) {
            $this->fail("Could not update authorized keys file [{$authorizedKeys}].");
        }

        chmod(filename: $authorizedKeys, permissions: 0o600);
    }

    private function pinHostKey(Node $node): void
    {
        $address = $node->wireguard_address;

        if (! is_string($address) || $address === '') {
            $this->fail("Node [{$node->name}] has no WireGuard address.");
        }

        $contents = is_file($this->hostPublicKeyPath) ? file_get_contents($this->hostPublicKeyPath) : false;

        if (! is_string($contents) || trim($contents) === '') {
            $this->fail("Could not read the gateway SSH host public key [{$this->hostPublicKeyPath}].");
        }

        $line = trim($contents);
        $parts = preg_split(pattern: '/\s+/', subject: $line, limit: 3);
        $type = is_array($parts) ? $parts[0] ?? '' : '';
        $value = is_array($parts) ? $parts[1] ?? '' : '';

        if ($type === '' || $value === '') {
            $this->fail("The gateway SSH host public key [{$this->hostPublicKeyPath}] is invalid.");
        }

        $fingerprint = $this->processes->run(new ProcessInvocation(
            arguments: ['ssh-keygen', '-lf', '-', '-E', 'sha256'],
            timeout: 10.0,
            input: $line.PHP_EOL,
        ));

        $matches = [];

        if (! $fingerprint->succeeded() || preg_match('/\b(SHA256:[^\s]+)/', $fingerprint->stdout, $matches) !== 1) {
            throw new NodeProvisioningException(
                step: self::STEP,
                errorCode: self::ERROR_CODE,
                message: "Could not fingerprint the gateway SSH host key [{$this->hostPublicKeyPath}].",
                result: $fingerprint,
            );
        }

        $this->knownHosts->put($address, 22, new HostKey(
            type: $type,
            value: $value,
            fingerprint: $matches[1],
        ));
    }

    /** @return never */
    private function fail(string $message): never
    {
        throw new NodeProvisioningException(
            step: self::STEP,
            errorCode: self::ERROR_CODE,
            message: $message,
        );
    }
}
