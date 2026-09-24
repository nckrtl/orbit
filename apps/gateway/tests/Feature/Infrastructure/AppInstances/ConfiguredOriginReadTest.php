<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;

it('reads the configured origin, never the insteadOf rewrite, in every source, Doctor, and tool script', function (): void {
    $offenders = collect([
        ...File::allFiles(app_path('Infrastructure/AppInstances')),
        ...File::allFiles(app_path('Infrastructure/Apps')),
        ...File::allFiles(app_path('Infrastructure/Doctor')),
        ...File::allFiles(app_path('Infrastructure/Tools')),
    ])
        ->filter(static fn (SplFileInfo $file): bool => str_contains((string) file_get_contents($file->getPathname()), 'get-url'))
        ->map(static fn (SplFileInfo $file): string => $file->getFilename())
        ->values()
        ->all();

    expect($offenders)->toBe([]);
});
