<?php

declare(strict_types=1);

namespace App\Commands\Extension;

use App\Commands\GatewayCommand;
use App\Exceptions\GatewayConfigException;
use App\Services\Extensions\LocalExtensionState;
use App\Support\Console\ProgressState;

final class EnableExtensionCommand extends GatewayCommand
{
    #[\Override]
    protected $signature = 'extension:enable {extension : Extension slug} {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Enable an optional Orbit CLI extension.';

    public function handle(LocalExtensionState $extensions): int
    {
        $extension = $this->argument('extension');

        if (! $extensions->known($extension)) {
            return $this->renderGatewayFailure('extension.unknown', 'Unknown Orbit extension.');
        }

        $progress = $this->progressDisplay("Extension: {$extension}");
        $progress->admit('enable', 'Enable extension', 'Enabling extension', 'Enabled extension');

        try {
            $progress->during('enable', fn () => $extensions->enable($extension));
        } catch (GatewayConfigException) {
            return $this->renderGatewayFailure(
                'extension.config_invalid',
                'Orbit extension configuration is invalid or not private.',
            );
        }

        $progress->complete('enable', ProgressState::Success);
        $progress->finish("Orbit extension [{$extension}] is enabled.");

        if ($this->option('json') === true) {
            $this->writeJson(['extension' => $extension, 'enabled' => true]);
        }

        return self::SUCCESS;
    }
}
