<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;

it('keeps the committed documentation context index current', function (): void {
    $exitCode = Artisan::call('orbit:docs-index', ['--check' => true]);

    expect($exitCode)->toBe(0)->and(Artisan::output())->toContain('Documentation context index is current.');
});

it('returns ordered context for a repository component', function (): void {
    $exitCode = Artisan::call('orbit:docs-context', [
        '--component' => ['apps/docs'],
        '--format' => 'json',
    ]);

    $output = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($exitCode)
        ->toBe(0)
        ->and($output['schema_version'])
        ->toBe(1)
        ->and(collect($output['documents'])->pluck('path')->all())
        ->toContain('docs/decisions/0014-maintain-verified-documentation-context.md');
});

it('keeps the application console-only', function (): void {
    expect(base_path('routes/web.php'))
        ->not->toBeFile()->and(base_path('database'))
        ->not->toBeDirectory()->and(base_path('package.json'))
        ->not->toBeFile()->and(base_path('resources'))
        ->not->toBeDirectory();
});
