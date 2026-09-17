<?php

declare(strict_types=1);

/**
 * Isolated renderer entry point. Never load application code or execute work here.
 * Composition updates share this process and its clock. The parent closes stdin
 * to stop and joins this exact process before writing to the terminal itself.
 */
$writeOutput = static function (string $text): void {
    while ($text !== '') {
        $written = @fwrite(STDOUT, $text);

        if ($written === false || $written === 0) {
            throw new RuntimeException('Terminal output closed.');
        }

        $text = substr($text, $written);
    }

    if (! @fflush(STDOUT)) {
        throw new RuntimeException('Terminal output flush failed.');
    }
};

$clear = '';
$final = '';
$exitCode = 0;
$ready = false;

try {
    $line = fgets(STDIN);
    $configuration = is_string($line) ? json_decode($line, true, flags: JSON_THROW_ON_ERROR) : null;
    $frames = is_array($configuration) ? ($configuration['frames'] ?? null) : null;

    if (! is_array($frames) || count($frames) < 2 || ! array_is_list($frames) || array_any($frames, static fn ($frame): bool => ! is_string($frame))) {
        throw new RuntimeException('Invalid presentation frames.');
    }

    $lines = substr_count($frames[0], "\n");
    $clear = "\r".($lines > 0 ? "\e[{$lines}A" : '')."\e[J";
    $initialClear = is_string($configuration['clear'] ?? null) ? $configuration['clear'] : '';
    $epoch = is_numeric($configuration['epoch'] ?? null) && $configuration['epoch'] > 0 ? (float) $configuration['epoch'] : microtime(true);
    $phase = (int) floor(max(0, microtime(true) - $epoch) / 0.3);
    $index = $phase % count($frames);
    $writeOutput("\e[?25l".$initialClear.$frames[$index]);
    $nextTick = hrtime(true) / 1000000000 + 0.3;
    $ready = fopen('php://fd/3', 'w');

    $receipt = json_encode(['ready' => true, 'epoch' => $epoch], JSON_THROW_ON_ERROR)."\n";

    if ($ready === false || fwrite($ready, $receipt) !== strlen($receipt)) {
        throw new RuntimeException('Parent notification failed.');
    }

    $pending = null;

    while (true) {
        $read = [STDIN];
        $write = $except = [];
        $remaining = max(1000, (int) (($nextTick - hrtime(true) / 1000000000) * 1000000));
        $selected = @stream_select($read, $write, $except, 0, min(300000, $remaining));

        if ($selected === false) {
            throw new RuntimeException('Renderer input failed.');
        }

        if ($selected > 0) {
            $message = fgets(STDIN);

            if (is_string($message)) {
                $command = json_decode($message, true, flags: JSON_THROW_ON_ERROR);

                if (is_array($command) && array_key_exists('frames', $command)) {
                    $replacement = $command['frames'];

                    if (! is_array($replacement) || count($replacement) < 2 || ! array_is_list($replacement) || array_any($replacement, static fn ($frame): bool => ! is_string($frame)) || $pending !== null) {
                        throw new RuntimeException('Invalid presentation update.');
                    }

                    $pending = $replacement;
                } else {
                    $final = is_array($command) && is_array($command['final'] ?? null) && is_string($command['final'][$index] ?? null)
                        ? $command['final'][$index] : '';

                    break;
                }
            } else {
                break;
            }
        }

        if (hrtime(true) / 1000000000 >= $nextTick) {
            $updated = $pending !== null;
            $frames = $pending ?? $frames;
            $pending = null;
            $index = ($index + 1) % count($frames);
            $writeOutput($clear.$frames[$index]);
            $nextTick = hrtime(true) / 1000000000 + 0.3;
            $lines = substr_count($frames[$index], "\n");
            $clear = "\r".($lines > 0 ? "\e[{$lines}A" : '')."\e[J";

            if ($updated && fwrite($ready, $receipt) !== strlen($receipt)) {
                throw new RuntimeException('Parent notification failed.');
            }
        }
    }
} catch (Throwable) {
    $exitCode = 1;
} finally {
    try {
        $writeOutput($clear.$final."\e[0m\e[?25h");
    } catch (Throwable) {
        $exitCode = 1;
    }

    if (is_resource($ready)) {
        fclose($ready);
    }
}

exit($exitCode);
