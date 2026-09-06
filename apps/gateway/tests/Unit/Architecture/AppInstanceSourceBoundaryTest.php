<?php

declare(strict_types=1);

it('keeps the source lifecycle inside the source-only dependency boundary', function (): void {
    $appDirectory = dirname(path: __DIR__, levels: 3).'/app';
    $files = glob($appDirectory.'/Infrastructure/AppInstances/*SourceLifecycle.php') ?: [];
    $forbidden = [
        'AppDevRuntimeConverger',
        'AppDevSourceManager',
        'AppDevCaddy',
        'Certificate',
        'PrivateDns',
        'Route',
        'Router',
        'Hostname',
        'PhpFpm',
        'RuntimeManager',
        'PublicationManager',
    ];
    $violations = [];

    foreach ($files as $file) {
        $contents = file_get_contents($file);

        if (! is_string($contents)) {
            continue;
        }

        foreach ($forbidden as $token) {
            if (str_contains($contents, $token)) {
                $violations[] = basename($file).": {$token}";
            }
        }
    }

    expect($files)
        ->not
        ->toBeEmpty()
        ->and($violations)
        ->toBeEmpty();
});
