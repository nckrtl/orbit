<?php

declare(strict_types=1);

namespace App\Console\Commands\Legacy;

use App\E2E\LegacyRetirement;
use App\E2E\Value\QuarantineManifest;
use Illuminate\Console\Command;
use Throwable;

final class DeleteCommand extends Command
{
    #[\Override]
    protected $signature = 'legacy:delete {--quarantine=} {--ack-sha256=} {--json}';
    #[\Override]
    protected $description = 'Delete exact legacy targets after quarantine retention';

    public function handle(LegacyRetirement $retirement): int
    {
        try {
            $path = $this->required('quarantine');
            $output = $path.'.retirement.json';
            $result = $retirement->delete(
                QuarantineManifest::fromArray($retirement->read($path)),
                $this->required('ack-sha256'),
                $output.'.journal.json',
            );
            $retirement->write($output, $result->toArray());
            $this->line(
                $this->option('json')
                    ? json_encode([
                        'path' => $output,
                        ...$result->toArray(),
                    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) : "Retirement: {$output}",
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
