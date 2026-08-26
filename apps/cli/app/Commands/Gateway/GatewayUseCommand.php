<?php

declare(strict_types=1);

namespace App\Commands\Gateway;

use App\Commands\GatewayCommand;
use App\Data\GatewayProfile;
use App\Exceptions\GatewayConfigException;
use App\Repositories\GatewayConfigRepository;

final class GatewayUseCommand extends GatewayCommand
{
    #[\Override]
    protected $signature = 'gateway:use
        {name : Local profile name}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Select the active gateway profile.';

    public function handle(GatewayConfigRepository $repository): int
    {
        $name = $this->argument('name');

        if (! is_string($name)) {
            return $this->renderGatewayFailure(
                'gateway.profile_invalid',
                'Gateway name must be a string.',
            );
        }

        if (! GatewayProfile::hasValidName($name)) {
            return $this->renderGatewayFailure(
                'gateway.profile_invalid',
                'Gateway profile name is invalid.',
            );
        }

        try {
            if ($repository->find($name) === null) {
                return $this->renderGatewayFailure(
                    'gateway.profile_not_found',
                    'Gateway profile does not exist.',
                );
            }

            $repository->use($name);
        } catch (GatewayConfigException) {
            return $this->renderGatewayFailure(
                'gateway.config_invalid',
                'Orbit gateway configuration is invalid.',
            );
        }

        if ($this->option('json') === true) {
            $this->line(json_encode(
                ['active_gateway' => $name],
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
            ));

            return self::SUCCESS;
        }

        $this->info("Gateway [{$name}] is active.");

        return self::SUCCESS;
    }
}
