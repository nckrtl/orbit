<?php

declare(strict_types=1);

namespace App\Commands\Gateway;

use App\Commands\GatewayCommand;
use App\Data\GatewayProfile;
use App\Exceptions\GatewayConfigException;
use App\Repositories\GatewayConfigRepository;

final class GatewayAddCommand extends GatewayCommand
{
    #[\Override]
    protected $signature = 'gateway:add
        {name : Local profile name}
        {url : Gateway HTTPS URL}
        {--ca= : Path to the Orbit root CA certificate}
        {--use : Make this profile active}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Add a gateway profile.';

    public function handle(GatewayConfigRepository $repository): int
    {
        $name = $this->argument('name');
        $url = $this->argument('url');
        $caPath = $this->option('ca');

        if (! is_string($name) || ! is_string($url)) {
            return $this->renderGatewayFailure(
                'gateway.profile_invalid',
                'Gateway name and URL must be strings.',
            );
        }

        if (! GatewayProfile::hasValidName($name)) {
            return $this->renderGatewayFailure(
                'gateway.profile_invalid',
                'Gateway profile name is invalid.',
            );
        }

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
            $repository->add($profile);

            if ($this->option('use') === true) {
                $repository->use($name);
            }

            $active = $repository->active()?->name === $name;
        } catch (GatewayConfigException) {
            return $this->renderGatewayFailure(
                'gateway.config_invalid',
                'Orbit gateway configuration is invalid.',
            );
        }

        if ($this->option('json') === true) {
            $this->line(json_encode([
                'name' => $profile->name,
                ...$profile->toArray(),
                'active' => $active,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->info("Gateway [{$name}] added.");

        return self::SUCCESS;
    }
}
