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

it('documents the public Darwin role lifecycle and local setup boundary', function (): void {
    $readme = file_get_contents(base_path('README.md'));
    $publicFlow = <<<'MARKDOWN'
        ./orbit node:provision mini \
          --platform=darwin \
          --architecture=arm64 \
          --ssh-user='<personal-user>' \
          --tld=test \
          --wireguard-public-key='<existing-public-key>'
        ./orbit node:role:add 2 app-dev
        ./orbit node:setup app-dev
        MARKDOWN;

    expect($readme)
        ->toBeString()
        ->toContain('## Local macOS app-dev setup')
        ->toContain($publicFlow)
        ->toContain('requires an interactive controlling terminal')
        ->toContain('does not keep the setup script or transcript')
        ->toContain('The gateway owns role and runtime policy.')
        ->not->toContain('node:role:remove')
        ->not->toContain('node:setup:script')
        ->not->toContain('agent:start');
});
