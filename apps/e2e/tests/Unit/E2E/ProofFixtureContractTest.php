<?php

declare(strict_types=1);

use Illuminate\Process\Factory as ProcessFactory;

it('keeps no tracked legacy proof archive', function (): void {
    $root = dirname(__DIR__, 5);
    $result = new ProcessFactory()->path($root)->run(['git', 'ls-files', 'proofs']);

    expect($result->successful())
        ->toBeTrue()
        ->and(trim($result->output()))
        ->toBe('')
        ->and(is_dir($root.'/proofs'))
        ->toBeFalse();
});

it('keeps fixture contract tests independent of individual issue artifacts', function (): void {
    $tests = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(dirname(__DIR__, 2)));
    $individualIssue = '/(?:'.'N'.'CK|O'.'RB)-[0-9]+/';
    $individualFile = '/(?:'.'Nck|Orb)[0-9]+/';

    foreach ($tests as $test) {
        if (! $test->isFile() || $test->getExtension() !== 'php') {
            continue;
        }
        expect($test->getFilename())
            ->not->toMatch($individualFile)
            ->and((string) file_get_contents($test->getPathname()))
            ->not->toMatch($individualIssue);
    }
});
