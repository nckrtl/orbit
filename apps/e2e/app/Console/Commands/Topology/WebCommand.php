<?php

declare(strict_types=1);

namespace App\Console\Commands\Topology;

use App\Console\Commands\E2ECommand;
use App\E2E\State\SecretRedactor;
use App\E2E\TopologyAcquirer;
use App\E2E\TopologyWebSession;
use Throwable;

final class WebCommand extends E2ECommand
{
    #[\Override]
    protected $signature = 'topology:web {issue} '.self::WORKTREE_OPTION.' {--json}';

    #[\Override]
    protected $description = 'Run the topology-pinned web dev server in the operator until interrupted';

    public function handle(TopologyAcquirer $acquirer, TopologyWebSession $session, SecretRedactor $redactor): int
    {
        $running = true;
        $previous = [];
        pcntl_async_signals(true);
        foreach ([SIGINT, SIGTERM, SIGHUP] as $signal) {
            $previous[$signal] = pcntl_signal_get_handler($signal);
            pcntl_signal($signal, static function () use (&$running): void {
                $running = false;
            });
        }
        try {
            $instance = $acquirer->instance($this->request(), 'operator');
            $session->run(
                $instance,
                static function () use (&$running): bool {
                    return $running;
                },
                function (string $line) use ($redactor): void {
                    $line = $redactor->redact($line);
                    $this->outputJson(['state' => 'web', 'message' => $line], $line);
                },
            );

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->outputFailure($exception);

            return self::FAILURE;
        } finally {
            foreach ($previous as $signal => $handler) {
                pcntl_signal($signal, $handler);
            }
        }
    }
}
