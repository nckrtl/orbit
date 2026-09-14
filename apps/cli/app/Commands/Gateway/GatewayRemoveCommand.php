<?php

declare(strict_types=1);

namespace App\Commands\Gateway;

use App\Commands\GatewayCommand;
use App\Data\GatewayProfile;
use App\Exceptions\GatewayConfigException;
use App\Repositories\GatewayConfigRepository;

final class GatewayRemoveCommand extends GatewayCommand
{
    #[\Override]
    protected $signature = 'gateway:remove
        {name : Local profile name}
        {--force : Remove the active profile and clear the active selection}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Remove a gateway profile.';

    public function handle(GatewayConfigRepository $repository): int
    {
        $name = $this->input->getArgument('name');

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
            $repository->remove($name, $this->option('force') === true);
        } catch (GatewayConfigException $exception) {
            return $this->renderRemoveFailure($exception);
        }

        if ($this->option('json') === true) {
            $this->writeJson(['profile' => $name]);

            return self::SUCCESS;
        }

        $this->info("Gateway [{$name}] removed.");

        return self::SUCCESS;
    }

    private function renderRemoveFailure(GatewayConfigException $exception): int
    {
        $code = $exception->errorCode;

        if ($code === 'gateway.profile_not_found') {
            return $this->renderGatewayFailure($code, 'Gateway profile does not exist.');
        }

        if ($code === 'gateway.profile_active') {
            return $this->renderGatewayFailure($code, 'Cannot remove the active gateway profile.');
        }

        return $this->renderGatewayFailure(
            'gateway.config_invalid',
            'Orbit gateway configuration is invalid.',
        );
    }
}
