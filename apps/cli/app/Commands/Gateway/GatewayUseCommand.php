<?php

declare(strict_types=1);

namespace App\Commands\Gateway;

use App\Commands\GatewayCommand;
use App\Data\GatewayProfile;
use App\Exceptions\GatewayConfigException;
use App\Repositories\GatewayConfigRepository;
use App\Support\Console\ProgressState;

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
            if ($repository->find($name) === null) {
                return $this->renderGatewayFailure(
                    'gateway.profile_not_found',
                    'Gateway profile does not exist.',
                );
            }

            $progress = $this->progressDisplay("Gateway profile: {$name}");
            $progress->admit('select', 'Select profile', 'Selecting profile', 'Selected profile');
            $progress->during('select', fn () => $repository->use($name));
        } catch (GatewayConfigException) {
            return $this->renderGatewayFailure(
                'gateway.config_invalid',
                'Orbit gateway configuration is invalid.',
            );
        }

        $progress->complete('select', ProgressState::Success);
        $progress->finish("Gateway [{$name}] is active.");

        if ($this->option('json') === true) {
            $this->writeJson(['active_gateway' => $name]);
        }

        return self::SUCCESS;
    }
}
