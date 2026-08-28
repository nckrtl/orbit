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

it('documents JavaScript processes through the managed Vite+ entry point', function (): void {
    $readme = file_get_contents(base_path('README.md'));
    $viteProcess = <<<'MARKDOWN'
        ./orbit process:add vite \
            --instance=12 \
            --runtime=systemd \
            --command=/usr/local/bin/vp \
            --command=run \
            --command=dev \
            --command=--host=0.0.0.0 \
            --working-directory=/home/orbit/apps/acme \
            --restart=always \
            --start
        MARKDOWN;

    expect($readme)
        ->toBeString()
        ->toContain($viteProcess)
        ->toContain('defaults projects without a manager signal to pnpm')
        ->toContain('Orbit installs pnpm by default')
        ->toContain('Orbit installs Bun separately')
        ->not->toMatch('/\b(?:npm|npx|pnpm|pnpx|yarn|yarnpkg|bun|bunx)\s+(?:ci|install|run|exec|add|remove|update)\b/');
});

it('documents tool management through the gateway boundary', function (): void {
    $readme = file_get_contents(base_path('README.md'));
    $toolFlow = <<<'MARKDOWN'
        ./orbit tool:manager:list --node=12
        ./orbit tool:install @openai/codex --node=12 --manager=vp --constraint='^0.150'
        ./orbit tool:list --node=12
        ./orbit tool:update 41
        ./orbit tool:show 41
        ./orbit tool:remove 41
        MARKDOWN;

    expect($readme)
        ->toBeString()
        ->toContain('## Tool Management')
        ->toContain($toolFlow)
        ->toContain('npm-compatible global tools')
        ->toContain('`vendor/package`')
        ->toContain('Ubuntu packages')
        ->toContain('unsafe normal candidate');
});
