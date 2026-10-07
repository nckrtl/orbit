<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\GatewayReleases\GatewayReleaseException;
use Closure;
use Illuminate\Console\Command;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * Gateway release commands run on the Gateway host as the Gateway account. Each prints one JSON
 * object on stdout. Success exits 0, a refused input exits 2, and every other failure exits 1
 * with `error_code`, `step`, and `message`, plus `detail` when the step reported what it saw,
 * such as the smoke report.
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
                ...($exception->phase === [] ? [] : ['detail' => $exception->phase]),
            ]);

            return in_array($exception->status, [404, 422], true) ? 2 : self::FAILURE;
        } catch (Throwable $exception) {
            $failure = GatewayReleaseException::fromThrowable($exception, 'command');
            $this->emit([
                'error_code' => $failure->errorCode,
                'step' => $failure->step,
                'message' => $failure->getMessage(),
            ]);

            return self::FAILURE;
        }
    }

    /** @param array<string, mixed> $data */
    protected function emit(array $data): void
    {
        $this->output->writeln(json_encode($data, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), OutputInterface::OUTPUT_RAW);
    }
}
