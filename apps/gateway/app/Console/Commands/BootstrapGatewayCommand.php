<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\Gateway\BootstrapGatewayAction;
use App\Data\Gateway\BootstrapGatewayData;
use App\Domain\Nodes\NodeProvisioningException;
use Illuminate\Console\Command;

/** @mago-expect lint:cyclomatic-complexity Bootstrap validates canonical and compatibility WireGuard inputs independently. */
final class BootstrapGatewayCommand extends Command
{
    #[\Override]
    protected $signature = 'orbit:bootstrap
        {public-host : Gateway public IP or hostname}
        {--name=gateway : Gateway node name}
        {--wireguard-ip= : Gateway WireGuard IP}
        {--wireguard-address= : Deprecated alias for --wireguard-ip}
        {--wireguard-subnet=10.44.0.0/24 : Orbit WireGuard subnet}
        {--wireguard-port=51820 : Public WireGuard UDP port}
        {--wireguard-endpoint= : Public WireGuard endpoint}
        {--dns-server= : Default DNS server for peers}
        {--domain=orbit : Private DNS domain}
        {--private-interface= : Optional private underlay interface}';

    #[\Override]
    protected $description = 'Initialize gateway keys, authority, roles, and VPN settings.';

    public function handle(BootstrapGatewayAction $action): int
    {
        $publicHost = $this->stringArgument('public-host');
        $wireguardInput = $this->wireguardIp('10.44.0.1');
        $wireguardIp = $wireguardInput['value'];
        $wireguardSubnet = $this->stringOption('wireguard-subnet');
        $wireguardPort = $this->option('wireguard-port');

        if (
            $publicHost === null
            || ! $wireguardInput['valid']
            || $wireguardIp === null
            || $wireguardSubnet === null
            || ! is_numeric($wireguardPort)
        ) {
            $this->error('Gateway bootstrap arguments are invalid.');

            return self::FAILURE;
        }

        $port = (int) $wireguardPort;
        $endpoint = $this->stringOption('wireguard-endpoint') ?? "{$publicHost}:{$port}";
        try {
            $node = $action->execute(new BootstrapGatewayData(
                publicHost: $publicHost,
                wireguardIp: $wireguardIp,
                wireguardSubnet: $wireguardSubnet,
                wireguardEndpoint: $endpoint,
                dnsServer: $this->stringOption('dns-server') ?? $wireguardIp,
                domain: $this->stringOption('domain') ?? 'orbit',
                privateInterface: $this->stringOption('private-interface'),
                wireguardPort: $port,
                name: $this->stringOption('name') ?? 'gateway',
            ));
        } catch (NodeProvisioningException $exception) {
            $this->error(
                "Gateway bootstrap failed at step [{$exception->step}] with error [{$exception->errorCode}].",
            );

            return self::FAILURE;
        }

        $this->info("Gateway [{$node->name}] initialized.");

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
    private function wireguardIp(?string $default = null): array
    {
        $canonical = $this->stringOption('wireguard-ip');
        $deprecated = $this->stringOption('wireguard-address');

        if ($canonical !== null && $deprecated !== null && $canonical !== $deprecated) {
            $this->error('The WireGuard IP options conflict.');

            return ['valid' => false, 'value' => null];
        }

        return ['valid' => true, 'value' => $canonical ?? $deprecated ?? $default];
    }
}
