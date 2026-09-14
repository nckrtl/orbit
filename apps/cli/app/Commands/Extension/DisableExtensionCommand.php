<?php

declare(strict_types=1);

namespace App\Commands\Extension;

use App\Commands\GatewayCommand;
use App\Exceptions\GatewayConfigException;
use App\Services\Extensions\LocalExtensionState;

final class DisableExtensionCommand extends GatewayCommand
{
    #[\Override]
    protected $signature = 'extension:disable {extension : Extension slug} {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Disable an optional Orbit CLI extension.';

    public function handle(LocalExtensionState $extensions): int
    {
        $extension = $this->argument('extension');

        if (! $extensions->known($extension)) {
            return $this->renderGatewayFailure('extension.unknown', 'Unknown Orbit extension.');
        }

        try {
            $extensions->disable($extension);
        } catch (GatewayConfigException) {
            return $this->renderGatewayFailure(
                'extension.config_invalid',
                'Orbit extension configuration is invalid or not private.',
            );
        }

        if ($this->option('json') === true) {
            $this->writeJson(['extension' => $extension, 'enabled' => false]);
        } else {
            $this->info("Orbit extension [{$extension}] is disabled.");
        }

        return self::SUCCESS;
    }
}
