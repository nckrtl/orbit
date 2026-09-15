<?php

declare(strict_types=1);

use App\Support\Console\ConsoleMode;
use App\Support\Console\ProgressDisplay;
use App\Support\Console\ProgressState;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\StreamOutput;

require dirname(__DIR__, 3).'/vendor/autoload.php';

// Private fixture: php progress-states-fixture.php success|failure auto|plain TRACE.
$scenario = $argv[1] ?? '';
$format = $argv[2] ?? '';
$trace = $argv[3] ?? '';

if (! in_array($scenario, ['success', 'failure'], true)
    || ! in_array($format, ['auto', 'plain'], true)
    || $trace === '') {
    fwrite(STDERR, "Invalid progress state fixture arguments.\n");
    exit(64);
}

$output = new StreamOutput(STDOUT, decorated: $format === 'auto' && stream_isatty(STDOUT));
$mode = ConsoleMode::detect(new ArrayInput([]), $output);
$display = new ProgressDisplay($mode, $output, 'Local checks');
$callbacks = ['check' => 0, 'review' => 0];
$events = [];
$display->admit('check', 'Validate local record', 'Validating local record', 'Validated local record');
$display->admit('review', 'Review local note', 'Reviewing local note', 'Reviewed local note');
$display->admit('optional', 'Read optional input', 'Reading optional input', 'Read optional input');
$display->complete('optional', ProgressState::Skipped, 'No optional input was provided; this step did not read or change a local record.');

// Keep the admitted waiting state visible before starting local validation.
usleep(420000);
$valid = $display->during('check', static function () use ($scenario, &$callbacks, &$events): bool {
    $callbacks['check']++;
    $events[] = ['event' => 'check-start', 'time' => microtime(true)];
    usleep(700000);
    $events[] = ['event' => 'check-return', 'time' => microtime(true)];

    return $scenario === 'success';
});

if ($valid) {
    $display->complete('check', ProgressState::Success, 'Local record is valid.');
    $noteComplete = $display->during('review', static function () use (&$callbacks, &$events): bool {
        $callbacks['review']++;
        $events[] = ['event' => 'review-start', 'time' => microtime(true)];
        usleep(700000);
        $events[] = ['event' => 'review-return', 'time' => microtime(true)];

        return false;
    });

    if ($noteComplete) {
        throw new LogicException('The fixture note must remain incomplete.');
    }

    $display->complete('review', ProgressState::Warning, 'The optional note is incomplete; the local record remains valid and unchanged.');
    $display->finish('Local checks completed.');
    $status = 0;
} else {
    $display->complete('check', ProgressState::Failure, 'The local record is invalid; no follow-up work was started and nothing was changed.');
    $display->finish('Local checks failed.');
    $status = 7;
}

file_put_contents($trace, json_encode([
    'scenario' => $scenario,
    'mode' => (array) $mode,
    'callbacks' => $callbacks,
    'events' => $events,
    'status' => $status,
], JSON_THROW_ON_ERROR)."\n");

exit($status);
