<?php

declare(strict_types=1);

describe('Composer configuration', function (): void {
    it('defines the database-free E2E project', function (): void {
        $composer = json_decode(
            (string) file_get_contents(base_path('composer.json')),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        expect($composer['name'])
            ->toBe('nckrtl/orbit-e2e')
            ->and($composer['require'])
            ->toHaveKeys(['php', 'composer/semver', 'laravel/framework'])
            ->and($composer['autoload']['psr-4'])
            ->not->toHaveKey('Database\\')->and(json_encode($composer['scripts'], JSON_THROW_ON_ERROR))
            ->not->toMatch('/migrate|artisan dev|sqlite|routes|database/i');

        expect($composer['scripts']['analyse'])
            ->toBe('vendor/bin/mago analyze app --reporting-format=medium --minimum-report-level=error');
        expect($composer['scripts']['lint'])
            ->toBe('vendor/bin/mago lint app tests --reporting-format=medium --minimum-report-level=error');
        expect($composer['scripts'])
            ->not
            ->toHaveKey('test:live-incus')
            ->and($composer['scripts']['test'])
            ->toBe('vendor/bin/pest --parallel --no-tia --compact');
        expect($composer['scripts']['test:scenario-cold'])
            ->toBe([
                'Composer\\Config::disableProcessTimeout',
                'vendor/bin/pest --no-tia --compact tests/Scenario/ColdTopologyAcceptanceTest.php',
            ]);
        expect(file_get_contents(base_path('phpunit.xml')))->not->toContain('<directory>tests/Scenario</directory>');
        expect(file_get_contents(base_path('../../bin/test')))->not->toContain('incus-live');
        expect(file_get_contents(base_path('../../.github/workflows/ci.yml')))->not->toContain('incus-live');
        expect(file_get_contents(base_path('../../.github/pull_request_template.md')))->not->toContain('bin/e2e-live');

        foreach (['.env.example', 'config/app.php', 'phpunit.xml', 'tests/Pest.php'] as $file) {
            expect(file_get_contents(base_path($file)))
                ->not
                ->toMatch('/DB_|QUEUE_|RefreshDatabase|APP_URL|Gateway|gateway|sqlite|database/i');
        }

        expect(file_get_contents(base_path('config/e2e.php')))->not->toContain('ORBIT_E2E_PROFILE', "'profile'");

        foreach (['app', 'bootstrap', 'commands', 'state', 'tests', 'tooling'] as $rule) {
            expect(trim((string) file_get_contents(base_path(".ai/rules/{$rule}.md"))))->not->toBeEmpty();
        }

        expect(file_get_contents(base_path('mago.toml')))->not->toMatch('/database|routes/i');
    });
});
