<?php

declare(strict_types=1);

namespace App\Commands\Extension;

use App\Commands\GatewayCommand;
use App\Exceptions\GatewayConfigException;
use App\Services\Extensions\LocalExtensionState;
use App\Support\Console\ProgressState;

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

        $progress = $this->progressDisplay("Extension: {$extension}");
        $progress->admit('disable', 'Disable extension', 'Disabling extension', 'Disabled extension');

        try {
            $progress->during('disable', fn () => $extensions->disable($extension));
        } catch (GatewayConfigException) {
            return $this->renderGatewayFailure(
                'extension.config_invalid',
                'Orbit extension configuration is invalid or not private.',
            );
        }

        $progress->complete('disable', ProgressState::Success);
        $progress->finish("Orbit extension [{$extension}] is disabled.");

        if ($this->option('json') === true) {
            $this->writeJson(['extension' => $extension, 'enabled' => false]);
        }

        return self::SUCCESS;
    }
}
