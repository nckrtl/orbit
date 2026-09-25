<?php

declare(strict_types=1);

namespace App\Support\Logs;

use App\Support\Console\ConsoleWriter;
use App\Support\Console\TerminalText;
use Illuminate\Console\OutputStyle;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Renders a log follow. Human mode prints each line with control characters escaped, the dropped
 * and skipped markers inline, and diagnostics on stderr. JSON mode prints one object per line on
 * stdout and no diagnostics.
 */
final readonly class ConsoleLogFollowOutput implements LogFollowOutput
{
    public function __construct(
        private OutputInterface $output,
        private bool $json,
    ) {}

    #[\Override]
    public function lines(array $lines, int $dropped, int $skipped): void
    {
        if ($this->json) {
            $this->writeJson(['type' => 'lines', 'lines' => $lines, 'dropped' => $dropped, 'skipped' => $skipped]);

            return;
        }

        $text = '';

        if ($dropped > 0) {
            $text .= sprintf("[orbit] %d %s dropped\n", $dropped, $dropped === 1 ? 'line' : 'lines');
        }

        if ($skipped > 0) {
            $text .= sprintf("[orbit] %.1f MiB skipped\n", $skipped / 1_048_576);
        }

        foreach ($lines as $line) {
            $text .= TerminalText::safe($line)."\n";
        }

        ConsoleWriter::write($this->output, $text);
    }

    #[\Override]
    public function polling(string $code, ?string $reason): void
    {
        if ($this->json) {
            $this->writeJson(['type' => 'notice', 'code' => $code, 'reason' => $reason]);

            return;
        }

        $this->diagnostic(sprintf(
            'Live tail unavailable%s; polling every %d seconds.',
            match (true) {
                $reason === 'realtime_unreachable' => ' (realtime unreachable)',
                $reason !== null => " ({$reason})",
                $code === 'logs.stream_limit' => ' (stream limit reached)',
                default => '',
            },
            (int) LogFollower::POLL_SECONDS,
        ));
    }

    #[\Override]
    public function reconnecting(): void
    {
        if (! $this->json) {
            $this->diagnostic('Live tail disconnected. Reconnecting…');
        }
    }

    /** @param  array<string, mixed>  $payload */
    private function writeJson(array $payload): void
    {
        ConsoleWriter::write($this->output, json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n");
    }

    private function diagnostic(string $message): void
    {
        $output = $this->output;

        while ($output instanceof OutputStyle) {
            $output = $output->getOutput();
        }

        ConsoleWriter::write($output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $this->output, $message."\n");
    }
}
