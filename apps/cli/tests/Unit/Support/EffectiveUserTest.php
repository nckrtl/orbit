<?php

declare(strict_types=1);

use App\Support\EffectiveUser;

describe(EffectiveUser::class, function (): void {
    it('reports the user that owns files this process creates', function (): void {
        $probe = tempnam(sys_get_temp_dir(), 'orbit-euid-test-');
        $owner = fileowner($probe);
        unlink($probe);

        expect(EffectiveUser::id())->toBe($owner);
    });

    it('agrees with posix when the extension is loaded', function (): void {
        expect(EffectiveUser::id())->toBe(posix_geteuid());
    })->skip(! function_exists('posix_geteuid'), 'The posix extension is not loaded.');
});
