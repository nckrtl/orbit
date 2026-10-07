<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\GatewayReleases\GatewayReleaseException;
use Closure;
use Illuminate\Console\Command;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Gateway release commands run on the Gateway host as the Gateway account. Each prints one JSON
 * object on stdout. Success exits 0, a refused input exits 2, and every other failure exits 1
 * with `error_code`, `step`, and `message`.
 */
abstract class GatewayReleaseCommand extends Command
{
    /** @param Closure(): array<string, mixed> $operation */
    protected function report(Closure $operation): int
    {
        try {
            $this->emit($operation());

            return self::SUCCESS;
        } catch (GatewayReleaseException $exception) {
            $this->emit([
                'error_code' => $exception->errorCode,
                'step' => $exception->step,
                'message' => $exception->getMessage(),
            ]);

            return $exception->status === 422 ? 2 : self::FAILURE;
        }
    }

    /** @param array<string, mixed> $data */
    protected function emit(array $data): void
    {
        $this->output->writeln(json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), OutputInterface::OUTPUT_RAW);
    }
}
