<?php

declare(strict_types=1);

use App\Support\Console\Animation;
use App\Support\Console\ConsoleInterrupted;
use App\Support\Console\ConsoleMode;
use App\Support\Console\ConsoleWriter;
use App\Support\Console\ProgressDisplay;
use App\Support\Console\ProgressState;
use App\Support\Console\SpinnerDisplay;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Output\StreamOutput;

require __DIR__.'/renderer-faults.php';
require dirname(__DIR__, 3).'/vendor/autoload.php';

$input = new ArgvInput;
$case = $argv[1] ?? 'progress';
$outputStream = $case === 'spinner-output-failure' ? fopen('/dev/full', 'w') : (in_array('--stderr', $argv, true) ? STDERR : STDOUT);
$output = new StreamOutput($outputStream, decorated: ! in_array('--plain', $argv, true));
$mode = ConsoleMode::detect($input, $output, machine: in_array('--json', $argv, true),
    outputTty: in_array('--renderer-fixture', $argv, true) ? true : null);
$trace = getenv('ORBIT_UX_TRACE');
$record = static function (string $event, array $facts = []) use ($trace): void {
    if (is_string($trace) && $trace !== '') {
        file_put_contents($trace, json_encode(['event' => $event, 'pid' => getmypid(), 'time' => microtime(true), ...$facts], JSON_THROW_ON_ERROR)."\n", FILE_APPEND);
    }
};
$paintNonce = getenv('ORBIT_UX_PAINT_NONCE');
$firstPaint = static function (string $id) use ($paintNonce, $outputStream): void {
    if (! is_string($paintNonce) || $paintNonce === '') {
        return;
    }

    if (preg_match('/\A[a-f0-9]{16,64}\z/', $paintNonce) !== 1) {
        throw new RuntimeException('Invalid first-paint nonce.');
    }

    // Share the renderer's PTY byte order without causing a helper repaint.
    $marker = "\e]777;orbit-first-paint;{$paintNonce};{$id};".getmypid()."\x07";

    while ($marker !== '') {
        $written = @fwrite($outputStream, $marker);

        if ($written === false || $written === 0) {
            throw new RuntimeException('Could not emit callback-entry marker.');
        }

        $marker = substr($marker, $written);
    }

    if (! @fflush($outputStream)) {
        throw new RuntimeException('Could not flush callback-entry marker.');
    }
};
$record('start', ['mode' => (array) $mode]);
$signals = function_exists('pcntl_async_signals') ? pcntl_async_signals() : null;
$handler = function_exists('pcntl_signal_get_handler') ? pcntl_signal_get_handler(SIGTERM) : null;
$calls = 0;
$operation = static function () use ($case, &$calls, $record, $mode, $output, $firstPaint): int {
    $firstPaint('operation');
    $calls++;
    $record('callback', ['count' => $calls]);

    if (in_array($case, ['exit', 'spinner-exit'], true)) {
        usleep(350000);
        exit(23);
    }

    if (getenv('ORBIT_UX_FAULT') === 'renderer-exit') {
        posix_kill($GLOBALS['orbit_ux_renderer_pid'], SIGKILL);
    }

    usleep($case === 'short-spinner' ? 650000 : (in_array($case, ['signal', 'spinner-signal'], true) ? 10000000 : 1300000));

    if (in_array($case, ['failure', 'spinner-failure', 'invalid-settled-exception', 'failure-teardown-signal'], true)) {
        throw new RuntimeException('Private fixture detail must not be printed.', 57);
    }

    if ($case === 'nested-spinners') {
        new SpinnerDisplay($mode, $output)->during('Waiting for nested response', static function () use ($record, $firstPaint): void {
            $firstPaint('nested-spinner');
            $record('inner-callback');
            usleep(1200000);
        });
        usleep(650000);
    }

    if ($case === 'nested-invalid-start') {
        new Animation($mode, $output, ["\xff", "\xff"])->during(static function () use ($firstPaint): int {
            $firstPaint('invalid-inner');

            return 0;
        });
    }

    $record('callback-return');

    return 42;
};
$exitCode = 0;

try {
    if ($case === 'modes') {
        ConsoleWriter::write($output, json_encode((array) $mode, JSON_THROW_ON_ERROR)."\n");
    } elseif ($case === 'invalid-start') {
        new Animation($mode, $output, ["\xff", "\xff"])->during($operation);
    } elseif ($case === 'failure-teardown-signal') {
        new Animation($mode, $output, ["○ Rendering\n", "◉ Rendering\n"], settled: static function (): string {
            posix_kill(getmypid(), SIGTERM);

            return "Wait failed.\n";
        })->during($operation);
    } elseif (in_array($case, ['invalid-settled', 'invalid-settled-exception'], true)) {
        new Animation($mode, $output, ["○ Rendering\n", "◉ Rendering\n"], "Rendering...\n",
            settled: static fn (): string => "\xff")->during($operation);
    } elseif (in_array($case, ['spinner', 'short-spinner', 'spinner-failure', 'spinner-signal', 'spinner-exit', 'spinner-output-failure', 'nested-spinners'], true)) {
        $value = new SpinnerDisplay($mode, $output)->during('Waiting for response', $operation);
        $record('result', ['value' => $value]);
    } else {
        $display = new ProgressDisplay($mode, $output, 'Update resource');
        $display->admit('resolve', 'Resolve resource', 'Resolving resource', 'Resolved resource');
        $display->admit('update', 'Update resource', 'Updating resource', 'Updated resource');
        $value = $display->during('resolve', $operation);
        $display->complete('resolve', ProgressState::Success, 'Resource found.');
        $display->during('update', static function () use ($case, $mode, $output, $record, $firstPaint): void {
            $firstPaint('update');
            if ($case === 'nested') {
                new SpinnerDisplay($mode, $output)->during('Waiting for inner response', static function () use ($record, $firstPaint): void {
                    $firstPaint('inner-spinner');
                    $record('inner-callback');
                    usleep(1200000);
                });
            }

            if ($case === 'nested-progress') {
                $inner = new ProgressDisplay($mode, $output, 'Inner operation');
                $inner->admit('inner', 'Read inner resource', 'Reading inner resource', 'Read inner resource');
                $inner->during('inner', static function () use ($record, $firstPaint): void {
                    $firstPaint('inner-progress');
                    $record('inner-callback');
                    usleep(1200000);
                });
                $inner->complete('inner', ProgressState::Success);
                $inner->finish('Inner operation completed.');
            }

            $events = (static function (): Generator {
                usleep(650000);
                yield 'accepted';
                usleep(650000);
                yield 'completed';
            })();

            foreach ($events as $event) {
                $record('stream-event', ['value' => $event]);
            }
        });
        $display->complete('update', ProgressState::Success);
        $display->finish('Resource updated.');
        $record('result', ['value' => $value]);
    }
} catch (ConsoleInterrupted $exception) {
    $exitCode = $exception->getCode();
    $record('interrupted', ['signal' => $exception->signal]);
} catch (Throwable $exception) {
    $exitCode = 7;
    $record('failure', ['class' => $exception::class, 'code' => $exception->getCode()]);
}

$record('finish', [
    'callbacks' => $calls,
    'status' => $exitCode,
    'async_restored' => $signals === null || $signals === pcntl_async_signals(),
    'handler_restored' => $handler === null || $handler === pcntl_signal_get_handler(SIGTERM),
]);

if ($mode->machine && $case !== 'modes') {
    ConsoleWriter::write($output, json_encode(['fixture_status' => $exitCode, 'callbacks' => $calls], JSON_THROW_ON_ERROR)."\n");
}

exit($exitCode);
