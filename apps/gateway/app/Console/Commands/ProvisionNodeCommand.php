<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Nodes\ProvisionNodeAction;
use App\Data\Nodes\ProvisionNodeData;
use App\Domain\Nodes\NodeProvisioningException;
use App\Domain\Nodes\RoleName;
use Illuminate\Console\Command;

/** @mago-expect lint:cyclomatic-complexity Provision validates each legacy and canonical console input before convergence. */
final class ProvisionNodeCommand extends Command
{
    #[\Override]
    protected $signature = 'orbit:node-provision
        {name : Node name}
        {host : Public SSH host}
        {--ssh-port=22 : Public SSH port}
        {--user=root : Initial SSH user}
        {--orbit-user= : Managed Orbit user}
        {--architecture= : Node machine architecture}
        {--tld= : Unique development TLD for app-dev}
        {--role=* : Initial role assignment}
        {--cluster= : Cluster ID}
        {--wireguard-ip= : Stable WireGuard IP}
        {--wireguard-address= : Deprecated alias for --wireguard-ip}
        {--lan-ip= : Cluster-local LAN IP}
        {--wireguard-endpoint= : Per-node WireGuard endpoint override}
        {--dns-server= : Per-node DNS server override}
        {--host-key-fingerprint= : Expected first-contact SSH SHA256 fingerprint}';

    #[\Override]
    protected $description = 'Provision the first node directly from the gateway.';

    public function handle(ProvisionNodeAction $action): int
    {
        $name = $this->stringArgument('name');
        $host = $this->stringArgument('host');
        $sshPort = $this->option('ssh-port');
        $user = $this->stringOption('user');
        $roles = $this->roles();
        $wireguardInput = $this->wireguardIp();
        $clusterId = $this->positiveIntegerOption('cluster');
        $lanIp = $this->stringOption('lan-ip');

        if (
            $name === null
            || $host === null
            || ! is_numeric($sshPort)
            || $user === null
            || $roles === null
            || ! $wireguardInput['valid']
            || $this->stringOption('cluster') !== null
            && $clusterId === null
            || $lanIp !== null
            && filter_var($lanIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false
        ) {
            $this->error('Node provisioning arguments are invalid.');

            return self::FAILURE;
        }

        try {
            $node = $action->execute(new ProvisionNodeData(
                name: $name,
                publicSshHost: $host,
                roles: $roles,
                publicSshPort: (int) $sshPort,
                user: $user,
                orbitUser: $this->stringOption('orbit-user'),
                wireguardIp: $wireguardInput['value'],
                wireguardEndpointOverride: $this->stringOption('wireguard-endpoint'),
                dnsServerOverride: $this->stringOption('dns-server'),
                expectedSshHostFingerprint: $this->stringOption('host-key-fingerprint'),
                platform: 'linux',
                architecture: $this->stringOption('architecture'),
                tld: $this->stringOption('tld'),
                clusterProvided: $clusterId !== null,
                clusterId: $clusterId,
                lanIpProvided: $lanIp !== null,
                lanIp: $lanIp,
            ));
        } catch (NodeProvisioningException $exception) {
            $this->error(
                "Node provisioning failed at step [{$exception->step}] with error [{$exception->errorCode}].",
            );

            return self::FAILURE;
        }

        $this->info("Node [{$node->name}] is {$node->status->value}.");

        return self::SUCCESS;
    }

    private function stringArgument(string $name): ?string
    {
        $value = $this->argument($name);

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) && $value !== '' ? $value : null;
    }

    /** @return array{valid: bool, value: ?string} */
    private function wireguardIp(): array
    {
        $canonical = $this->stringOption('wireguard-ip');
        $deprecated = $this->stringOption('wireguard-address');

        if ($canonical !== null && $deprecated !== null && $canonical !== $deprecated) {
            $this->error('The WireGuard IP options conflict.');

            return ['valid' => false, 'value' => null];
        }

        return ['valid' => true, 'value' => $canonical ?? $deprecated];
    }

    private function positiveIntegerOption(string $name): ?int
    {
        $value = $this->stringOption($name);

        if ($value === null || preg_match('/\A[1-9][0-9]*\z/D', $value) !== 1) {
            return null;
        }

        $integer = filter_var($value, FILTER_VALIDATE_INT);

        return is_int($integer) ? $integer : null;
    }

    /**
     * @mago-expect analysis:mixed-assignment Console option values are an untyped boundary.
     *
     * @return list<RoleName>|null
     */
    private function roles(): ?array
    {
        $values = $this->option('role');

        if (! is_array($values)) {
            return null;
        }

        $roles = [];

        foreach ($values as $value) {
            if (! is_string($value) || RoleName::tryFrom($value) === null) {
                return null;
            }

            $roles[] = RoleName::from($value);
        }

        return $roles;
    }
}
