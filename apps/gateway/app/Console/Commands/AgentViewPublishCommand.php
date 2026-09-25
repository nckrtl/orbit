<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\AgentView\AgentStateView;
use App\Domain\Processes\ProcessUsageBroadcaster;
use App\Domain\Tasks\TaskBroadcasts;
use App\Domain\Tasks\TaskWorkspaceSummary;
use App\Infrastructure\AgentView\LogRelay;
use App\Infrastructure\AgentView\LogRelayQueue;
use Illuminate\Console\Command;
use Symfony\Component\Console\Input\StreamableInputInterface;
use Throwable;

/**
 * One publish run for the agent view subscriber, which starts it as a child process so its socket
 * loop never waits (ADR 0151). It stores task line counts from the view, sends the task notices, and
 * broadcasts one Process usage sample.
 *
 * With `--logs` it is one live log relay run instead (ADR 0153): it reads a batch from
 * {@see LogRelayQueue} as JSON on standard input, relays it with {@see LogRelay}, and prints
 * `{"open_streams": n}`.
 *
 * @phpstan-import-type Batch from LogRelayQueue
 * @phpstan-import-type Item from LogRelayQueue
 */
final class AgentViewPublishCommand extends Command
{
    #[\Override]
    protected $signature = 'orbit:agent-view-publish {--workspace=* : A changed task workspace as NODE:INSTANCE} {--usage= : Broadcast a Process usage sample with this Unix time} {--logs : Relay the live log batch on standard input}';

    #[\Override]
    protected $description = 'Store task line counts from the agent view, broadcast task notices and Process usage, or relay live log lines.';

    public function handle(AgentStateView $view, TaskWorkspaceSummary $summary, TaskBroadcasts $broadcasts, ProcessUsageBroadcaster $usage, LogRelay $logs): int
    {
        if ($this->option('logs') === true) {
            return $this->relayLogs($logs);
        }

        $failed = false;

        foreach ((array) $this->option('workspace') as $pair) {
            if (! is_string($pair) || preg_match('/\A([1-9][0-9]*):([1-9][0-9]*)\z/D', $pair, $matches) !== 1) {
                continue;
            }

            $workspace = $view->node((int) $matches[1])->workspace((int) $matches[2]);

            if ($workspace === null) {
                continue;
            }

            try {
                $summary->apply((int) $matches[1], $workspace);
            } catch (Throwable $exception) {
                $failed = true;
                $this->error('Task line counts could not be stored: '.$exception->getMessage());
            }
        }

        $broadcasts->flush();
        $sampledAt = $this->option('usage');

        if (is_string($sampledAt) && ctype_digit($sampledAt)) {
            $usage->publish((int) $sampledAt);
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    private function relayLogs(LogRelay $logs): int
    {
        $stream = $this->input instanceof StreamableInputInterface ? $this->input->getStream() : null;
        $batch = self::batch(stream_get_contents(is_resource($stream) ? $stream : STDIN));

        if ($batch === null) {
            $this->error('The live log batch on standard input is not valid.');

            return self::FAILURE;
        }

        try {
            $open = $logs->relay($batch);
        } catch (Throwable $exception) {
            $this->error('Live log lines could not be relayed: '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->line((string) json_encode(['open_streams' => $open]));

        return self::SUCCESS;
    }

    /** @return Batch|null */
    private static function batch(string|false $json): ?array
    {
        $batch = is_string($json) ? json_decode($json, associative: true) : null;

        if (
            ! is_array($batch) || ! is_string($batch['relay'] ?? null) || ! is_bool($batch['sweep'] ?? null)
            || ! is_array($batch['items'] ?? null) || ! array_is_list($batch['items'])
        ) {
            return null;
        }

        $items = [];

        foreach ($batch['items'] as $item) {
            $item = self::item($item);

            if ($item === null) {
                return null;
            }

            $items[] = $item;
        }

        return ['relay' => $batch['relay'], 'items' => $items, 'sweep' => $batch['sweep']];
    }

    /** @return Item|null */
    private static function item(mixed $item): ?array
    {
        if (! is_array($item) || ! is_int($item['item'] ?? null) || ! is_int($item['node'] ?? null)) {
            return null;
        }

        $stream = $item['stream'] ?? null;

        return match ($item['type'] ?? null) {
            'lines' => is_string($stream) && is_array($item['lines'] ?? null) && array_is_list($item['lines'])
                && array_all($item['lines'], static fn (mixed $line): bool => is_string($line))
                && is_int($item['dropped'] ?? null) && is_int($item['skipped'] ?? null)
                    ? ['type' => 'lines', 'item' => $item['item'], 'node' => $item['node'], 'stream' => $stream, 'lines' => $item['lines'], 'dropped' => $item['dropped'], 'skipped' => $item['skipped']]
                    : null,
            'end' => is_string($stream) && is_string($item['reason'] ?? null)
                ? ['type' => 'end', 'item' => $item['item'], 'node' => $item['node'], 'stream' => $stream, 'reason' => $item['reason']]
                : null,
            'agent_left' => ['type' => 'agent_left', 'item' => $item['item'], 'node' => $item['node']],
            default => null,
        };
    }
}
