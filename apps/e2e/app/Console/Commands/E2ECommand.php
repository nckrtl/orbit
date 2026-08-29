<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\E2E\State\SecretRedactor;
use App\E2E\Value\OperationId;
use Illuminate\Console\Command;
use Throwable;

abstract class E2ECommand extends Command
{
    protected function outputFailure(Throwable $exception, OperationId $operation): void
    {
        if ($this->option('json')) {
            $this->line(json_encode([
                'state' => 'failed',
                'operation_id' => $operation->value,
                'error' => app(SecretRedactor::class)->redact($exception->getMessage()),
            ], JSON_THROW_ON_ERROR));
        } else {
            $this->error(app(SecretRedactor::class)->redact($exception->getMessage()));
        }
    }

    /** @param array<string, mixed> $payload */
    protected function outputJson(array $payload, string $text): void
    {
        $this->line($this->option('json') ? json_encode($payload, JSON_THROW_ON_ERROR) : $text);
    }
}
