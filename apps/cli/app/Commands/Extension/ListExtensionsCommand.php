<?php

declare(strict_types=1);

namespace App\Commands\Extension;

use App\Commands\GatewayCommand;
use App\Exceptions\GatewayConfigException;
use App\Services\Extensions\LocalExtensionState;

final class ListExtensionsCommand extends GatewayCommand
{
    #[\Override]
    protected $signature = 'extension:list {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'List optional Orbit CLI extensions.';

    public function handle(LocalExtensionState $extensions): int
    {
        try {
            $rows = array_map(
                static fn (string $extension): array => [
                    'extension' => $extension,
                    'enabled' => $extensions->enabled($extension),
                ],
                $extensions->extensions(),
            );
        } catch (GatewayConfigException) {
            return $this->renderGatewayFailure(
                'extension.config_invalid',
                'Orbit extension configuration is invalid or not private.',
            );
        }

        if ($this->option('json') === true) {
            $this->writeJson(['extensions' => $rows]);
        } else {
            $this->table(['Extension', 'State'], array_map(
                static fn (array $row): array => [$row['extension'], $row['enabled'] ? 'enabled' : 'disabled'],
                $rows,
            ));
        }

        return self::SUCCESS;
    }
}
