<?php

declare(strict_types=1);

namespace App\E2E;

use App\E2E\Value\IncusNetwork;
use Illuminate\Support\Facades\Process;
use JsonException;
use RuntimeException;
use Throwable;

/** @mago-expect lint:cyclomatic-complexity,kan-defect,too-many-methods Create, reconcile, and delete share one exact host-network boundary. */
final readonly class IncusNetworkLifecycle
{
    private const string MANAGED_INTERFACE_PATTERN = 'oe+';
    private const string MANAGED_NETWORK_PATTERN = '/\Aoe-[a-z0-9](?:[a-z0-9-]{0,10}[a-z0-9])?\z/D';
    private const string OWNER = 'orbit-e2e';

    public function __construct(
        private IncusHost $host,
    ) {}

    /** @param array<string, string> $metadata */
    public function create(string $name, int $slot, array $metadata = []): IncusNetwork
    {
        $this->assertManagedNetworkName($name);
        $this->assertLocalRemote();
        $this->validateMetadata($metadata);

        if ($slot < 1 || $slot > 200) {
            throw new RuntimeException('Incus network slot is outside the supported range 1-200.');
        }
        $network = $this->host->createNetwork($name, [
            'ipv4.address' => "10.232.{$slot}.1/24",
            ...$this->networkConfiguration($slot),
            ...$metadata,
        ]);

        try {
            $this->reconcileRules('ensure', $name);
        } catch (Throwable $setupException) {
            try {
                $this->reconcileRules('remove', $name);
                $this->host->deleteNetwork($name);
            } catch (Throwable $cleanupException) {
                throw new RuntimeException(
                    "Incus network setup failed and rollback failed; manual recovery is required: {$cleanupException->getMessage()}",
                    0,
                    $setupException,
                );
            }

            throw $setupException;
        }

        return $network;
    }

    public function reconcile(string $name): IncusNetwork
    {
        $this->assertManagedNetworkName($name);
        $this->assertLocalRemote();
        $networks = $this->host->networks();
        $network = $networks[$name] ?? null;
        if ($network === null) {
            throw new RuntimeException("Incus network {$name} does not exist.");
        }
        if (($network->metadata['user.orbit.e2e.owner'] ?? null) !== self::OWNER) {
            throw new RuntimeException("Incus network {$name} ownership does not match.");
        }
        $slot = $this->slotFromIpv4Subnet($network->config['ipv4.address'] ?? null);
        foreach ($networks as $otherName => $otherNetwork) {
            if (
                $otherName !== $name
                && ($otherNetwork->config['ipv4.address'] ?? null) === $network->config['ipv4.address']
            ) {
                throw new RuntimeException("Incus network {$name} IPv4 subnet is already used by {$otherName}.");
            }
        }
        /** @var array<string, string> $configurationDrift */
        $configurationDrift = [];
        foreach ($this->networkConfiguration($slot) as $key => $value) {
            if (($network->config[$key] ?? null) !== $value) {
                $configurationDrift[$key] = $value;
            }
        }

        if ($configurationDrift !== []) {
            $this->host->setNetworkConfiguration($name, $configurationDrift);
            $network = $this->ownedNetwork($name);
        }

        $this->reconcileRules('ensure', $name);

        return $network;
    }

    private function slotFromIpv4Subnet(?string $cidr): int
    {
        if ($cidr === null || preg_match('/\A10\.232\.(\d{1,3})\.1\/24\z/D', $cidr, $matches) !== 1) {
            throw new RuntimeException('Incus network IPv4 address must use a managed slot subnet.');
        }

        $slot = (int) $matches[1];
        if ($slot < 1 || $slot > 200) {
            throw new RuntimeException('Incus network IPv4 address must use a managed slot subnet.');
        }

        return $slot;
    }

    /** @return array<string, string> */
    private function networkConfiguration(int $slot): array
    {
        $prefix = "10.232.{$slot}";

        return [
            'ipv4.nat' => 'true',
            'ipv4.dhcp.ranges' => "{$prefix}.10-{$prefix}.12",
            'ipv6.address' => 'none',
            'raw.dnsmasq' => 'port=0',
        ];
    }

    public function delete(string $name): void
    {
        $this->assertManagedNetworkName($name);
        $this->assertLocalRemote();
        $this->ownedNetwork($name);

        $this->reconcileRules('remove', $name);
        $this->host->deleteNetwork($name);
    }

    /**
     * Delete one orphaned harness network. A managed `oe-*` network also loses
     * its host firewall rules; a legacy `orbit-e2e-*` network never had harness
     * rules and the firewall helper refuses its name, so it is deleted directly.
     * Ownership metadata is not required: legacy networks never carried it.
     */
    public function deleteOrphan(string $name): void
    {
        if (! OrphanNetworkSweep::isHarnessNetworkName($name)) {
            throw new RuntimeException('Incus network name is outside the harness prefixes.');
        }
        $this->assertLocalRemote();

        if (preg_match(self::MANAGED_NETWORK_PATTERN, $name) === 1) {
            $this->reconcileRules('remove', $name);
        }
        $this->host->deleteOrphanNetwork($name);
    }

    private function assertLocalRemote(): void
    {
        if ($this->host->scope()['remote'] !== 'local') {
            throw new RuntimeException('Host forwarding requires the local Incus remote.');
        }
    }

    private function assertManagedNetworkName(string $name): void
    {
        if (preg_match(self::MANAGED_NETWORK_PATTERN, $name) !== 1) {
            throw new RuntimeException('Incus network name is outside the managed interface prefix.');
        }
    }

    /** @param array<string, string> $metadata */
    private function validateMetadata(array $metadata): void
    {
        foreach ($metadata as $key => $value) {
            if (
                preg_match('/\Auser\.orbit\.e2e\.[a-z0-9.-]+\z/D', $key) !== 1
                || $key === 'user.orbit.e2e.owner'
                || str_contains($value, "\0")
            ) {
                throw new RuntimeException('Invalid Incus network metadata.');
            }
        }
    }

    private function ownedNetwork(string $name): IncusNetwork
    {
        $network = $this->host->network($name);
        if ($network === null) {
            throw new RuntimeException("Incus network {$name} does not exist.");
        }
        if (($network->metadata['user.orbit.e2e.owner'] ?? null) !== self::OWNER) {
            throw new RuntimeException("Incus network {$name} ownership does not match.");
        }

        return $network;
    }

    private function reconcileRules(string $operation, string $name): void
    {
        if (! in_array($operation, ['ensure', 'remove'], true)) {
            throw new RuntimeException('The host firewall operation is invalid.');
        }
        $helper = dirname(__DIR__, 2).'/resources/host/reconcile-firewall.py';
        if (! is_file($helper) || ! is_executable($helper)) {
            throw new RuntimeException('The host firewall helper is unavailable.');
        }
        try {
            $input = json_encode([
                'operation' => $operation,
                'network' => $name,
                'managed_interface_pattern' => self::MANAGED_INTERFACE_PATTERN,
                'owner' => self::OWNER,
            ], JSON_THROW_ON_ERROR);
            $result = Process::timeout(30)->input($input)->run(['python3', $helper]);
        } catch (Throwable $exception) {
            throw new RuntimeException('Host firewall command could not run.', 0, $exception);
        }
        if ($result->failed()) {
            throw new RuntimeException('Host firewall command failed: '.trim($result->errorOutput()));
        }
        try {
            $output = json_decode($result->output(), true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Host firewall helper returned invalid output.', 0, $exception);
        }
        if (! is_array($output) || ! is_bool($output['changed'] ?? null)) {
            throw new RuntimeException('Host firewall helper returned invalid output.');
        }
    }
}
