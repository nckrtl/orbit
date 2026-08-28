<?php

declare(strict_types=1);

namespace App\E2E;

use App\E2E\Value\IncusNetwork;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Process;
use RuntimeException;
use Throwable;

/** @mago-expect lint:cyclomatic-complexity,kan-defect,too-many-methods Create, reconcile, and delete share one exact host-network boundary. */
final readonly class IncusNetworkLifecycle
{
    private const int MAX_DUPLICATE_RULES = 8;
    private const string MANAGED_INTERFACE_PATTERN = 'oe+';

    public function __construct(
        private IncusHost $host,
    ) {}

    /** @param array<string, string> $metadata */
    public function create(string $name, array $metadata = []): IncusNetwork
    {
        $this->assertManagedNetworkName($name);
        $this->assertLocalRemote();
        $this->validateMetadata($metadata);

        $network = $this->host->createNetwork($name, [
            'ipv4.address' => 'auto',
            'ipv4.nat' => 'true',
            'ipv6.address' => 'none',
            ...$metadata,
        ]);

        $this->reinstallRules($name);

        return $network;
    }

    public function reconcile(string $name): IncusNetwork
    {
        $this->assertManagedNetworkName($name);
        $this->assertLocalRemote();
        $network = $this->ownedNetwork($name);
        $this->reinstallRules($name);

        return $network;
    }

    public function delete(string $name): void
    {
        $this->assertManagedNetworkName($name);
        $this->assertLocalRemote();
        $this->ownedNetwork($name);

        $this->removeRules($name);
        $this->host->deleteNetwork($name);
    }

    private function assertLocalRemote(): void
    {
        if ($this->host->scope()['remote'] !== 'local') {
            throw new RuntimeException('Host forwarding requires the local Incus remote.');
        }
    }

    private function assertManagedNetworkName(string $name): void
    {
        if (preg_match('/\Aoe-[a-z0-9](?:[a-z0-9-]{0,10}[a-z0-9])?\z/D', $name) !== 1) {
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
        if (($network->metadata['user.orbit.e2e.owner'] ?? null) !== 'orbit-e2e') {
            throw new RuntimeException("Incus network {$name} ownership does not match.");
        }

        return $network;
    }

    /** @return list<list<string>> */
    private function rules(string $name): array
    {
        return [
            ['-i', $name, '-o', $name, '-j', 'ACCEPT'],
            ['-i', $name, '-o', self::MANAGED_INTERFACE_PATTERN, '-j', 'DROP'],
            ['-i', $name, '-m', 'conntrack', '--ctstate', 'NEW,RELATED,ESTABLISHED', '-j', 'ACCEPT'],
            ['-o', $name, '-m', 'conntrack', '--ctstate', 'RELATED,ESTABLISHED', '-j', 'ACCEPT'],
        ];
    }

    private function removeRules(string $name): void
    {
        foreach ($this->rules($name) as $rule) {
            for ($removed = 0; $removed < self::MAX_DUPLICATE_RULES; $removed++) {
                if (! $this->ruleExists($rule)) {
                    continue 2;
                }
                $this->firewall(['-D', 'FORWARD', ...$rule]);
            }

            if ($this->ruleExists($rule)) {
                throw new RuntimeException('More than eight duplicate forwarding rules exist.');
            }
        }
    }

    private function reinstallRules(string $name): void
    {
        $this->removeRules($name);
        foreach (array_reverse($this->rules($name)) as $rule) {
            $this->firewall(['-I', 'FORWARD', '1', ...$rule]);
        }
    }

    /** @param list<string> $rule */
    private function ruleExists(array $rule): bool
    {
        $result = $this->runFirewall(['-C', 'FORWARD', ...$rule]);

        return match ($result->exitCode()) {
            0 => true,
            1 => false,
            default => throw new RuntimeException('Unable to inspect Incus network forwarding.'),
        };
    }

    /** @param list<string> $arguments */
    private function firewall(array $arguments): ProcessResult
    {
        $result = $this->runFirewall($arguments);
        if ($result->failed()) {
            throw new RuntimeException('Host firewall command failed.');
        }

        return $result;
    }

    /** @param list<string> $arguments */
    private function runFirewall(array $arguments): ProcessResult
    {
        try {
            $result = Process::timeout(30)->run(['sudo', '-n', 'iptables', '-w', '5', ...$arguments]);
        } catch (Throwable $exception) {
            throw new RuntimeException('Host firewall command could not run.', 0, $exception);
        }

        return $result;
    }
}
