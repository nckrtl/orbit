<?php

declare(strict_types=1);

namespace App\Commands\Gateway;

use App\Commands\GatewayCommand;
use App\Data\GatewayProfile;
use App\Exceptions\GatewayConfigException;
use App\Repositories\GatewayConfigRepository;
use App\Services\Trust\GatewayRootCaTrustException;
use App\Services\Trust\GatewayRootCaTrustService;

final class GatewayAddCommand extends GatewayCommand
{
    #[\Override]
    protected $signature = 'gateway:add
        {gateway : Gateway IP or HTTPS origin}
        {--name=default : Local profile name}
        {--ca= : Path to the Orbit root CA certificate}
        {--use : Make this profile active}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Add a gateway profile.';

    public function handle(
        GatewayConfigRepository $repository,
        GatewayRootCaTrustService $trust,
    ): int {
        $name = $this->option('name');
        $gateway = $this->argument('gateway');
        $caPath = $this->option('ca');

        if (! is_string($name) || ! is_string($gateway)) {
            return $this->renderGatewayFailure(
                'gateway.profile_invalid',
                'Gateway name and address must be strings.',
            );
        }

        if (! GatewayProfile::hasValidName($name)) {
            return $this->renderGatewayFailure(
                'gateway.profile_invalid',
                'Gateway profile name is invalid.',
            );
        }

        $url = $this->normalizeGatewayUrl($gateway);

        if (! str_starts_with($url, 'https://')) {
            return $this->renderGatewayFailure(
                'gateway.profile_invalid',
                'Gateway URL must use HTTPS.',
            );
        }

        if (! GatewayProfile::hasSafeUrl($url)) {
            return $this->renderGatewayFailure(
                'gateway.profile_invalid',
                'Gateway URL must be a safe HTTPS origin.',
            );
        }

        $url = rtrim(string: $url, characters: '/');

        $caPath = is_string($caPath) && $caPath !== '' ? $caPath : null;

        if (! GatewayProfile::hasValidCaPath($caPath)) {
            return $this->renderGatewayFailure(
                'gateway.profile_invalid',
                'Gateway CA path must be an absolute path.',
            );
        }

        $profile = new GatewayProfile(
            name: $name,
            url: $url,
            caPath: $caPath,
        );

        try {
            $result = $trust->trust($profile);

            if ($this->option('use') === true) {
                $repository->use($name);
            }

            $active = $repository->active()?->name === $name;
        } catch (GatewayRootCaTrustException $exception) {
            return $this->renderGatewayFailure(
                $exception->errorCode,
                $exception->getMessage(),
                $exception->requestId,
                "{$exception->errorCode}: {$exception->getMessage()}",
            );
        } catch (GatewayConfigException) {
            return $this->renderGatewayFailure(
                'gateway.config_invalid',
                'Orbit gateway configuration is invalid.',
            );
        }

        if ($this->option('json') === true) {
            $this->line(json_encode([
                'name' => $profile->name,
                ...$result->profile->toArray(),
                'active' => $active,
                'trust_status' => $result->status,
                'sha256' => $result->certificate->fingerprint,
                'request_id' => $result->requestId,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->info("Gateway [{$name}] added.");
        $this->line("Request ID: {$result->requestId}");

        return self::SUCCESS;
    }

    private function normalizeGatewayUrl(string $gateway): string
    {
        if (filter_var($gateway, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return "https://{$gateway}";
        }

        if (filter_var($gateway, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            return "https://[{$gateway}]";
        }

        return $gateway;
    }
}
