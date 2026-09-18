<?php

declare(strict_types=1);

namespace App\Commands\Realtime;

use App\Commands\GatewayCommand;
use App\Repositories\GatewayConfigRepository;
use App\Support\Console\ConsoleInterrupted;
use App\Support\Console\ConsoleWriter;
use App\Support\Console\InterruptIntent;
use App\Support\Console\TerminalText;
use App\Support\Realtime\RealtimeEvent;
use App\Support\Realtime\RealtimeState;
use App\Support\Realtime\RealtimeSubscriber;
use App\Support\Realtime\WebSocketTransport;

final class RealtimeTailCommand extends GatewayCommand
{
    #[\Override]
    protected $signature = 'realtime:tail
        {--types= : Comma-separated event type filters, for example node.*,process.status}
        {--json : Print each event envelope as JSON}';

    #[\Override]
    protected $description = 'Stream realtime gateway events as they arrive.';

    public function handle(
        GatewayConfigRepository $repository,
        WebSocketTransport $transport,
    ): int {
        $json = $this->option('json') === true;

        if (! $json && ! $this->consoleMode()->mayPrompt) {
            return $this->renderGatewayFailure(
                'input.noninteractive_required',
                'orbit realtime:tail requires an interactive terminal or --json in a non-interactive context.',
            );
        }

        $profile = $this->activeGatewayProfile($repository);

        if ($profile === null) {
            return self::FAILURE;
        }

        $types = $this->typeFilters();

        if ($types === null) {
            return self::FAILURE;
        }

        $subscriber = RealtimeSubscriber::forProfile($profile, app()->version(), $transport);

        if ($subscriber->state() === RealtimeState::NotConfigured) {
            return $this->renderGatewayFailure(
                'realtime.not_configured',
                'Realtime is not configured for this gateway. Poll the affected commands instead.',
            );
        }

        $this->writeHumanMessage('Watching realtime events. Press Ctrl-C to stop.');
        $subscriber->connect();

        try {
            return $this->watch($subscriber, $types, $json);
        } finally {
            $subscriber->close();
        }
    }

    /** @param  list<string>  $types */
    private function watch(RealtimeSubscriber $subscriber, array $types, bool $json): int
    {
        $lastState = $subscriber->state();
        $handlers = [];
        $async = null;

        try {
            if (function_exists('pcntl_async_signals') && function_exists('pcntl_signal_get_handler')) {
                $async = pcntl_async_signals();

                foreach ([SIGINT, SIGTERM] as $signal) {
                    $handlers[$signal] = pcntl_signal_get_handler($signal);
                    pcntl_signal($signal, static function (int $received): never {
                        throw new ConsoleInterrupted($received);
                    }, restart_syscalls: false);
                }

                pcntl_async_signals(true);
            }

            for (; ;) {
                InterruptIntent::throwIfPending();

                foreach ($subscriber->poll() as $event) {
                    if ($this->matchesTypeFilter($event->type, $types)) {
                        $this->renderEvent($event, $json);
                    }
                }

                $state = $subscriber->state();

                if ($state !== $lastState) {
                    $this->renderStateChange($state);
                }

                $lastState = $state;

                InterruptIntent::throwIfPending();
                usleep(100_000);
            }
        } finally {
            foreach ($handlers as $signal => $handler) {
                pcntl_signal($signal, $handler);
            }

            if ($async !== null) {
                pcntl_async_signals($async);
            }
        }
    }

    private function renderEvent(RealtimeEvent $event, bool $json): void
    {
        if ($json) {
            $this->writeJson($event->toArray());

            return;
        }

        $line = sprintf('%s  %s  %s', $event->at->format(DATE_ATOM), $event->type, $this->summarize($event->data));
        ConsoleWriter::write($this->output, TerminalText::safe($line)."\n");
    }

    private function renderStateChange(RealtimeState $state): void
    {
        $this->writeHumanMessage(match ($state) {
            RealtimeState::Connected => 'Realtime connected.',
            RealtimeState::Reconnecting => 'Realtime disconnected. Reconnecting…',
            RealtimeState::NotConfigured => 'Realtime is not configured.',
        });
    }

    /** @param  array<string, mixed>  $data */
    private function summarize(array $data): string
    {
        if ($data === []) {
            return '—';
        }

        $parts = [];

        foreach ($data as $key => $value) {
            $parts[] = "{$key}=".$this->scalarize($value);
        }

        return implode(' ', $parts);
    }

    private function scalarize(mixed $value): string
    {
        return match (true) {
            is_string($value) => $value,
            is_bool($value) => $value ? 'true' : 'false',
            is_int($value), is_float($value) => (string) $value,
            $value === null => '—',
            default => json_encode($value, JSON_UNESCAPED_SLASHES) ?: '—',
        };
    }

    /** @param  list<string>  $patterns */
    private function matchesTypeFilter(string $type, array $patterns): bool
    {
        if ($patterns === []) {
            return true;
        }

        foreach ($patterns as $pattern) {
            if (str_ends_with($pattern, '*')) {
                if (str_starts_with($type, substr($pattern, 0, -1))) {
                    return true;
                }

                continue;
            }

            if ($type === $pattern) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string>|null Null once a failure was already rendered. */
    private function typeFilters(): ?array
    {
        $option = $this->option('types');

        if (! is_string($option) || trim($option) === '') {
            return [];
        }

        $patterns = [];

        foreach (explode(',', $option) as $candidate) {
            $candidate = trim($candidate);

            if ($candidate === '') {
                continue;
            }

            if (preg_match('/\A[a-z][a-z0-9_]*(\.([a-z][a-z0-9_]*|\*))?\z/D', $candidate) !== 1) {
                $this->renderGatewayFailure(
                    'input.invalid',
                    "Realtime event type filter [{$candidate}] is invalid.",
                );

                return null;
            }

            $patterns[] = $candidate;
        }

        return $patterns;
    }
}
