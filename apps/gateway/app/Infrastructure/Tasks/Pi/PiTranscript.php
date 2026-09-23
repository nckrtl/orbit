<?php

declare(strict_types=1);

namespace App\Infrastructure\Tasks\Pi;

/**
 * Converts Pi transcript entries into Orbit's normalized entries. It remembers tool calls so each
 * tool result can name the call it answers. Use one instance per transcript, in order.
 *
 * Pi writes a tool call when it starts and the result when it ends. The call becomes a running
 * activity at once. The result replaces it: both use the call's ID, `{assistant entry}:{index}`.
 *
 * A bash result ends with the command and `exit code N`, so the scheduler reads a check from the
 * retained end of the text. Other tools show only their name and target: file contents stay out,
 * so words inside a read file cannot look like an edit.
 */
final class PiTranscript
{
    /** The label of a tool call that has no result yet. */
    public const string RUNNING = 'Running';

    private const int OUTPUT_LIMIT = 8000;

    /** File tools show a verb and the file name, such as "Reading Task.php". */
    private const array FILE_VERBS = ['read' => 'Reading', 'edit' => 'Editing', 'write' => 'Writing'];

    /** @var array<string, array{id: string, name: string, arguments: array<string, mixed>}> */
    private array $calls = [];

    /** @var array<string, array{id: string, kind: string, label: string, text: string, at: string}> */
    private array $running = [];

    /**
     * @param  array<mixed>  $entry  A Pi server transcript entry: `{id, timestamp, message}`.
     * @return list<array{id: string, kind: string, label: string, text: string, at: string}>
     */
    public function entries(array $entry): array
    {
        $id = $this->string($entry['id'] ?? null);
        $at = $this->string($entry['timestamp'] ?? null);
        $message = is_array($entry['message'] ?? null) ? $entry['message'] : [];
        if ($id === '') {
            return [];
        }

        return match ($message['role'] ?? null) {
            'user' => $this->message($id, 'user', $this->text($message['content'] ?? null), $at),
            'assistant' => $this->assistant($id, $message, $at),
            'toolResult' => [$this->toolResult($id, $message, $at)],
            default => [],
        };
    }

    /**
     * Learns the tool calls of an entry the viewer already has, so later results can name them.
     *
     * @param  array<mixed>  $entry
     */
    public function remember(array $entry): void
    {
        $message = is_array($entry['message'] ?? null) ? $entry['message'] : [];
        $id = $this->string($entry['id'] ?? null);
        if ($id !== '' && ($message['role'] ?? null) === 'assistant') {
            $this->calls($id, $message);
        }
    }

    /**
     * Ends the running calls once the turn has settled. A call without a result, such as one cut
     * off by a server restart, stops showing as running.
     *
     * @return list<array{id: string, kind: string, label: string, text: string, at: string}>
     */
    public function settle(): array
    {
        $stopped = [];
        foreach ($this->running as $callId => $entry) {
            $call = $this->calls[$callId] ?? ['name' => 'tool', 'arguments' => []];
            $stopped[] = [...$entry, 'label' => $call['name'], 'text' => $this->describe($call['name'], $call['arguments']).' (stopped without a result)'];
        }
        $this->running = [];

        return $stopped;
    }

    /**
     * @param  array<mixed>  $message
     * @return list<array{id: string, kind: string, label: string, text: string, at: string}>
     */
    private function assistant(string $id, array $message, string $at): array
    {
        $running = [];
        foreach ($this->calls($id, $message) as $callId => $call) {
            $running[] = $this->running[$callId] = [
                'id' => $call['id'], 'kind' => 'activity', 'label' => self::RUNNING,
                'text' => 'Running: '.$this->describe($call['name'], $call['arguments']), 'at' => $at,
            ];
        }
        $text = $this->text($message['content'] ?? null);
        if ($text === '' && ($message['stopReason'] ?? null) === 'error') {
            $text = 'Error: '.$this->string($message['errorMessage'] ?? null);
        }

        return [...$this->message($id, 'assistant', $text, $at), ...$running];
    }

    /**
     * Records the tool calls of an assistant message.
     *
     * @param  array<mixed>  $message
     * @return array<string, array{id: string, name: string, arguments: array<string, mixed>}>
     */
    private function calls(string $id, array $message): array
    {
        $calls = [];
        $index = 0;
        foreach (is_array($message['content'] ?? null) ? $message['content'] : [] as $block) {
            if (is_array($block) && ($block['type'] ?? null) === 'toolCall' && is_string($block['id'] ?? null)) {
                $calls[$block['id']] = $this->calls[$block['id']] = [
                    'id' => $id.':'.$index++,
                    'name' => $this->string($block['name'] ?? null),
                    'arguments' => is_array($block['arguments'] ?? null) ? $block['arguments'] : [],
                ];
            }
        }

        return $calls;
    }

    /**
     * @param  array<mixed>  $message
     * @return array{id: string, kind: string, label: string, text: string, at: string}
     */
    private function toolResult(string $id, array $message, string $at): array
    {
        $callId = $this->string($message['toolCallId'] ?? null);
        $call = $this->calls[$callId] ?? null;
        unset($this->running[$callId]);
        $name = $this->string($message['toolName'] ?? null) ?: ($call['name'] ?? 'tool');
        $arguments = $call['arguments'] ?? [];
        $output = $this->text($message['content'] ?? null);
        $failed = ($message['isError'] ?? false) === true;

        if ($name === 'bash') {
            $exit = $failed ? $this->exitCode($output) : 0;
            $text = mb_substr($output, -self::OUTPUT_LIMIT)."\n".$this->describe($name, $arguments).($exit === null ? '' : "\nexit code ".$exit);
        } else {
            $text = $this->describe($name, $arguments).($failed ? ' failed: '.mb_substr($output, 0, 500) : '');
        }

        return ['id' => $call['id'] ?? $id, 'kind' => 'activity', 'label' => $name, 'text' => ltrim($text, "\n"), 'at' => $at];
    }

    /** @param array<mixed> $arguments */
    private function describe(string $name, array $arguments): string
    {
        if ($name === 'bash') {
            return '$ '.$this->string($arguments['command'] ?? null);
        }
        $target = $this->string($arguments['path'] ?? $arguments['file_path'] ?? $arguments['pattern'] ?? null);
        $verb = self::FILE_VERBS[$name] ?? null;
        $queries = array_values(array_filter((array) ($arguments['queries'] ?? []), is_string(...)));

        return match (true) {
            $name === 'search_docs' && $queries !== [] => 'Searching docs for '.implode(', ', array_map(static fn (string $query): string => '"'.$query.'"', $queries)),
            $verb !== null && $target !== '' => $verb.' '.basename($target),
            default => trim($name.' '.$target),
        };
    }

    /** Pi reports a failed command as an error result ending in "Command exited with code N". */
    private function exitCode(string $output): ?int
    {
        return preg_match('/Command exited with code (-?\d+)\s*$/', $output, $match) === 1 ? (int) $match[1] : null;
    }

    /** @return list<array{id: string, kind: string, label: string, text: string, at: string}> */
    private function message(string $id, string $label, string $text, string $at): array
    {
        return $text === '' ? [] : [['id' => $id, 'kind' => 'message', 'label' => $label, 'text' => $text, 'at' => $at]];
    }

    private function text(mixed $content): string
    {
        if (is_string($content)) {
            return $content;
        }
        $parts = [];
        foreach (is_array($content) ? $content : [] as $block) {
            if (is_array($block) && ($block['type'] ?? null) === 'text' && is_string($block['text'] ?? null)) {
                $parts[] = $block['text'];
            }
        }

        return implode("\n", $parts);
    }

    private function string(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }
}
