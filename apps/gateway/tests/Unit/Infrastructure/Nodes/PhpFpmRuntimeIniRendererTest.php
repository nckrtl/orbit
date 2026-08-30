<?php

declare(strict_types=1);

use App\Infrastructure\Nodes\PhpFpmRuntimeIniRenderer;

it('renders a Debian module with the phpenmod priority header first', function (string $profile): void {
    $ini = new PhpFpmRuntimeIniRenderer()->render($profile);

    expect($ini)
        ->toStartWith("; priority=99\n")
        ->toEndWith("\n")
        ->toContain(
            'opcache.enable = On',
            'opcache.max_accelerated_files = 65407',
            'opcache.jit = disable',
            'opcache.jit_buffer_size = 0',
        )
        ->not->toContain(
            'zend_extension',
            'validate_timestamps',
            'revalidate_freq',
            'preload',
            'file_cache',
            'huge_code_pages',
        );
})->with(['app-dev', 'app-prod']);

it('sizes shared memory for many development sites and a lean production service', function (): void {
    $renderer = new PhpFpmRuntimeIniRenderer;

    expect($renderer->render('app-dev'))
        ->toContain(
            'opcache.memory_consumption = 512',
            'opcache.interned_strings_buffer = 64',
        )
        ->and($renderer->render('app-prod'))
        ->toContain(
            'opcache.memory_consumption = 256',
            'opcache.interned_strings_buffer = 32',
        );
});

it('rejects unknown runtime profiles', function (): void {
    new PhpFpmRuntimeIniRenderer()->render('app-staging');
})->throws(InvalidArgumentException::class);
