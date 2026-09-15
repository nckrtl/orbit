<?php

declare(strict_types=1);

use App\Support\Console\ConsoleMode;
use App\Support\Console\HumanRenderer;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\StreamOutput;

require dirname(__DIR__, 3).'/vendor/autoload.php';

// Private proof fixture: php layout-fixture.php SCENARIO [auto|plain|machine] [COLUMNS].
// Without COLUMNS, detect the actual terminal width. No command is registered.
// The five-column table needs 25 columns; at 24 it becomes labeled records.
$scenario = $argv[1] ?? 'all';
$format = $argv[2] ?? 'auto';
$requestedColumns = $argv[3] ?? null;

if (! in_array($scenario, ['all', 'detail', 'table', 'properties', 'failure', 'empty'], true)
    || ! in_array($format, ['auto', 'plain', 'machine'], true)
    || ($requestedColumns !== null && (! ctype_digit($requestedColumns) || (int) $requestedColumns < 2))) {
    fwrite(STDERR, "Invalid layout fixture arguments.\n");
    exit(64);
}

$output = new StreamOutput(STDOUT, decorated: $format !== 'plain' && stream_isatty(STDOUT));
$mode = ConsoleMode::detect(
    new ArrayInput([]),
    $output,
    machine: $format === 'machine',
    columns: $requestedColumns === null ? null : (int) $requestedColumns,
);
$renderer = new HumanRenderer($mode);
$fields = [
    'Name' => 'fixture-record-with-a-long-unbroken-identity-0123456789',
    'Unicode' => '東京 café 👩‍💻 👍🏽 1️⃣',
    'Optional' => null,
    'Enabled' => false,
    'Count' => 0,
    'Tags' => ['first', 'second'],
    'Literal markup' => '<info>literal</info>',
];

$scenarios = $scenario === 'all' ? ['detail', 'table', 'properties', 'failure', 'empty'] : [$scenario];

foreach ($scenarios as $item) {
    $rendered = match ($item) {
        'detail' => $renderer->detail('Resource: layout-fixture', $fields),
        'table' => $renderer->table(
            ['Resource identity', 'Unicode location', 'Optional value', 'Enabled state', 'Request count'],
            [
                ['fixture-record-with-a-long-unbroken-identity-0123456789', '東京 café 👩‍💻', null, false, 0],
                ['<info>literal</info>', '👍🏽 1️⃣', 'present', true, 12],
            ],
        ),
        'properties' => $renderer->properties([
            ['title' => 'Fixture properties', 'items' => [
                ['label' => 'Primary record', 'fields' => $fields],
            ]],
        ]),
        'failure' => $renderer->failure(
            'The fixture request could not be completed.',
            ['name' => ['Use a unique fixture name.', 'Keep the entire long identity visible: fixture-record-with-a-long-unbroken-identity-0123456789.']],
            requestId: 'fixture-request-0123456789',
            code: 'fixture.validation_failed',
        ),
        'empty' => $renderer->table(['Name'], []),
    };

    $output->write($rendered, options: StreamOutput::OUTPUT_RAW);
}
