<?php

declare(strict_types=1);

it('documents the first-use gateway trust flow', function (): void {
    $readme = file_get_contents(base_path('README.md'));
    $firstUseFlow = <<<'MARKDOWN'
        ./orbit gateway:add local https://gateway.orbit --use
        ./orbit gateway:trust
        ./orbit gateway:status
        MARKDOWN;

    expect($readme)
        ->toBeString()
        ->toContain('## First Use')
        ->toContain($firstUseFlow)
        ->toContain('visible local operating-system trust step')
        ->not->toContain('gateway:add local https://gateway.orbit --ca=');
});
