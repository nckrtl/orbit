<?php

declare(strict_types=1);

namespace App\Infrastructure\Tasks\Pi;

/**
 * Converts Pi transcript entries into Orbit's normalized entries. It remembers tool calls so each
 * tool result can name the call it answers. Use one instance per transcript, in order.
 *
 * A bash result ends with the command and `exit code N`, so the scheduler reads a check from the
 * retained end of the text. Other tools show only their name and target: file contents stay out,
 * so words inside a read file cannot look like an edit.
 */
final class PiTranscript
{
    private const int OUTPUT_LIMIT = 8000;

    /** @var array<string, array{name: string, arguments: array<string, mixed>}> */
    private array $calls = [];

    /**
     * @param  array<string, mixed>  $entry  A Pi server transcript entry: `{id, timestamp, message}`.
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
     * @param  array<mixed>  $message
     * @return list<array{id: string, kind: string, label: string, text: string, at: string}>
     */
    private function assistant(string $id, array $message, string $at): array
    {
        foreach (is_array($message['content'] ?? null) ? $message['content'] : [] as $block) {
            if (is_array($block) && ($block['type'] ?? null) === 'toolCall' && is_string($block['id'] ?? null)) {
                $this->calls[$block['id']] = [
                    'name' => $this->string($block['name'] ?? null),
                    'arguments' => is_array($block['arguments'] ?? null) ? $block['arguments'] : [],
                ];
            }
        }
        $text = $this->text($message['content'] ?? null);
        if ($text === '' && ($message['stopReason'] ?? null) === 'error') {
            $text = 'Error: '.$this->string($message['errorMessage'] ?? null);
        }

        return $this->message($id, 'assistant', $text, $at);
    }

    /**
     * @param  array<mixed>  $message
     * @return array{id: string, kind: string, label: string, text: string, at: string}
     */
    private function toolResult(string $id, array $message, string $at): array
    {
        $call = $this->calls[$this->string($message['toolCallId'] ?? null)] ?? null;
        $name = $this->string($message['toolName'] ?? null) ?: ($call['name'] ?? 'tool');
        $arguments = $call['arguments'] ?? [];
        $output = $this->text($message['content'] ?? null);
        $failed = ($message['isError'] ?? false) === true;

        if ($name === 'bash') {
            $command = $this->string($arguments['command'] ?? null);
            $exit = $failed ? $this->exitCode($output) : 0;
            $text = mb_substr($output, -self::OUTPUT_LIMIT)."\n$ ".$command.($exit === null ? '' : "\nexit code ".$exit);
        } else {
            $target = $this->string($arguments['path'] ?? $arguments['file_path'] ?? $arguments['pattern'] ?? null);
            $text = trim($name.' '.$target).($failed ? ' failed: '.mb_substr($output, 0, 500) : '');
        }

        return ['id' => $id, 'kind' => 'activity', 'label' => $name, 'text' => ltrim($text, "\n"), 'at' => $at];
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
