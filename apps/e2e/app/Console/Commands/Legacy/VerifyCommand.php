<?php

declare(strict_types=1);

namespace App\Console\Commands\Legacy;

use App\E2E\LegacyRetirement;
use App\E2E\Value\RetirementResult;
use Illuminate\Console\Command;
use Throwable;

final class VerifyCommand extends Command
{
    #[\Override]
    protected $signature = 'legacy:verify {--retirement=} {--json}';
    #[\Override]
    protected $description = 'Verify exact retirement absence and preservation';

    public function handle(LegacyRetirement $retirement): int
    {
        try {
            $path = $this->option('retirement');
            if (! is_string($path) || $path === '') {
                throw new \RuntimeException('The --retirement option is required.');
            }
            $result = $retirement->verify(RetirementResult::fromArray($retirement->read($path)));
            $output = match (true) {
                (bool) $this->option('json') => json_encode(
                    $result->toArray(),
                    JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES,
                ),
                $result->successful => 'Legacy retirement verified.',
                default => 'Legacy retirement verification failed.',
            };
            $this->line($output);

            return $result->successful ? self::SUCCESS : self::FAILURE;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
