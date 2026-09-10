<?php

declare(strict_types=1);

it('keeps concurrent Rector runs isolated and each run single-process', function (): void {
    $repository = dirname(__DIR__, 5);
    $projects = [
        'apps/cli',
        'apps/docs',
        'apps/gateway',
        'apps/e2e',
        'packages/php-sdk',
    ];

    foreach ($projects as $project) {
        $configuration = file_get_contents($repository.'/'.$project.'/rector.php');

        expect($configuration)
            ->not->toBeFalse()
            ->toContain(<<<'PHP'
                ->withCache(
                        __DIR__.'/vendor/rector/cache',
                        null,
                        __DIR__.'/vendor/rector',
                    )
                    ->withoutParallel()
                PHP);
    }
});
