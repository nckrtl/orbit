<?php

declare(strict_types=1);

namespace App\Console\Commands\Topology;

use App\E2E\TopologyAcquirer;
use Illuminate\Console\Command;
use Throwable;

final class VerifyCommand extends Command
{
    #[\Override]
    protected $signature = 'topology:verify {issue} {--json}';
    #[\Override]
    protected $description = 'Verify one disposable feature topology';

    public function handle(TopologyAcquirer $acquirer): int
    {
        try {
            $topology = $acquirer->verify((string) $this->argument('issue'));
            $identity = bin2hex(random_bytes(16));
            $payload = [
                'state' => 'verified',
                'operation_id' => $identity,
                'evidence_id' => $identity,
                'verification' => $topology->verification->toArray(),
            ];
            $this->line($this->option('json') ? json_encode($payload, JSON_THROW_ON_ERROR) : 'verified');

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $identity = bin2hex(random_bytes(16));
            $this->option('json')
                ? $this->line(json_encode([
                    'state' => 'failed',
                    'operation_id' => $identity,
                    'evidence_id' => $identity,
                    'error' => $exception->getMessage(),
                ], JSON_THROW_ON_ERROR))
                : $this->error($exception->getMessage());

            return self::FAILURE;
        }
    }
}
