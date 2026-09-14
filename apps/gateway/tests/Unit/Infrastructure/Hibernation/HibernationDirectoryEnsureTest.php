<?php

declare(strict_types=1);

use App\Infrastructure\Hibernation\HibernationDirectoryEnsure;

it('repairs marker and log ancestors so caddy can traverse them', function (): void {
    $script = HibernationDirectoryEnsure::script();

    expect($script)
        ->toContain('ensure_hibernation_ancestor "$1"')
        ->toContain('install -d -o root -g caddy -m 0755 -- "$1"')
        ->toContain('ensure_hibernation_ancestor "$2"')
        ->toContain('install -d -o root -g caddy -m 2775 -- "$2"')
        ->toContain('/dev/shm')
        ->toContain('install -d -m 0755 -- "$current"');
});

it('binds publisher variables so injected test paths still repair ancestors', function (): void {
    expect(HibernationDirectoryEnsure::script('"$hibernation_markers"', '"$hibernation_logs"'))
        ->toContain('ensure_hibernation_ancestor "$hibernation_markers"')
        ->toContain('install -d -o root -g caddy -m 0755 -- "$hibernation_markers"')
        ->toContain('ensure_hibernation_ancestor "$hibernation_logs"')
        ->toContain('install -d -o root -g caddy -m 2775 -- "$hibernation_logs"');
});
