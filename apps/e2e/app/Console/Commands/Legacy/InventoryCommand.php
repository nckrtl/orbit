<?php

declare(strict_types=1);

namespace App\Console\Commands\Legacy;

use App\E2E\LegacyRetirement;
use Illuminate\Console\Command;
use Throwable;

final class InventoryCommand extends Command
{
    #[\Override]
    protected $signature = 'legacy:inventory {--output=} {--json}';
    #[\Override]
    protected $description = 'Inventory exact legacy retirement candidates without mutation';

    public function handle(LegacyRetirement $retirement): int
    {
        try {
            $output = $this->option('output');
            if (! is_string($output) || ! str_starts_with($output, '/')) {
                throw new \RuntimeException('An absolute output path is required.');
            }
            $inventory = $retirement->inventory();
            $retirement->write($output, $inventory->toArray());
            $payload = ['path' => $output, 'sha256' => $inventory->sha256(), ...$inventory->toArray()];
            $this->line(
                $this->option('json')
                    ? json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)
                    : "Inventory: {$output}\nSHA-256: {$inventory->sha256()}",
            );

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
