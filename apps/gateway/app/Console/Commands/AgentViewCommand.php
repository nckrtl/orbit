<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Infrastructure\AgentView\AgentViewSubscriber;
use Illuminate\Console\Command;

final class AgentViewCommand extends Command
{
    #[\Override]
    protected $signature = 'orbit:agent-view';

    #[\Override]
    protected $description = 'Keep the Gateway view of Node agent state from the agents\' presence channels.';

    private bool $stopping = false;

    public function handle(AgentViewSubscriber $subscriber): int
    {
        if (function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
            pcntl_signal(SIGTERM, fn (): bool => $this->stopping = true);
            pcntl_signal(SIGINT, fn (): bool => $this->stopping = true);
        }

        $reason = $subscriber->run(fn (): bool => $this->stopping);
        $this->info("The agent view subscriber stopped: {$reason}.");

        return self::SUCCESS;
    }
}
