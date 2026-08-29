<?php

declare(strict_types=1);

namespace App\Console\Commands\Legacy;

use App\E2E\LegacyRetirement;
use App\E2E\Value\RetirementInventory;
use Illuminate\Console\Command;
use Throwable;

final class QuarantineCommand extends Command
{
    #[\Override]
    protected $signature = 'legacy:quarantine {--inventory=} {--ack-sha256=} {--freeze-evidence=} {--json}';
    #[\Override]
    protected $description = 'Quarantine an exact reviewed legacy inventory';

    public function handle(LegacyRetirement $retirement): int
    {
        try {
            $path = $this->required('inventory');
            $output = $path.'.quarantine.json';
            $manifest = $retirement->quarantine(
                RetirementInventory::fromArray(LegacyRetirement::readProtectedJson($path)),
                $this->required('ack-sha256'),
                $this->required('freeze-evidence'),
                $output.'.journal.json',
            );
            $retirement->write($output, $manifest->toArray());
            $payload = ['path' => $output, 'sha256' => $manifest->sha256(), ...$manifest->toArray()];
            $this->line(
                $this->option('json')
                    ? json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)
                    : "Quarantine: {$output}\nSHA-256: {$manifest->sha256()}",
            );

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }

    private function required(string $name): string
    {
        $value = $this->option($name);
        if (! is_string($value) || $value === '') {
            throw new \RuntimeException("The --{$name} option is required.");
        }

        return $value;
    }
}
