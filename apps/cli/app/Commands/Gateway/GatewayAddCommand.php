<?php

declare(strict_types=1);

namespace App\Commands\Gateway;

use App\Data\GatewayProfile;
use App\Repositories\GatewayConfigRepository;
use LaravelZero\Framework\Commands\Command;

final class GatewayAddCommand extends Command
{
    protected $signature = 'gateway:add
        {name : Local profile name}
        {url : Gateway HTTPS URL}
        {--ca= : Path to the Orbit root CA certificate}
        {--use : Make this profile active}
        {--json : Return machine-readable JSON}';

    protected $description = 'Add a gateway profile.';

    public function handle(GatewayConfigRepository $repository): int
    {
        $name = $this->argument('name');
        $url = $this->argument('url');
        $caPath = $this->option('ca');

        if (! is_string($name) || ! is_string($url)) {
            $this->error('Gateway name and URL must be strings.');

            return self::FAILURE;
        }

        $url = rtrim(string: $url, characters: '/');

        if (! str_starts_with($url, 'https://') || filter_var($url, FILTER_VALIDATE_URL) === false) {
            $this->error('Gateway URL must use HTTPS.');

            return self::FAILURE;
        }

        $profile = new GatewayProfile(
            name: $name,
            url: $url,
            caPath: is_string($caPath) && $caPath !== '' ? $caPath : null,
        );
        $repository->add($profile);

        if ($this->option('use') === true) {
            $repository->use($name);
        }

        if ($this->option('json') === true) {
            $this->line(json_encode([
                'name' => $profile->name,
                ...$profile->toArray(),
                'active' => $repository->active()?->name === $name,
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        }

        $this->info("Gateway [{$name}] added.");

        return self::SUCCESS;
    }
}
