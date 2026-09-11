<?php

declare(strict_types=1);

describe('Composer configuration', function (): void {
    it('enables TIA for every repository-owned Pest command', function (): void {
        foreach ([
            '.github/workflows/ci.yml',
            'bin/test',
            'apps/cli/composer.json',
            'apps/docs/composer.json',
            'apps/gateway/composer.json',
            'apps/e2e/composer.json',
            'apps/e2e/app/E2E/ScenarioPestProcess.php',
            'packages/php-sdk/composer.json',
        ] as $path) {
            $contents = file_get_contents(base_path('../../'.$path));

            expect($contents)
                ->toBeString()
                ->toContain('--tia')
                ->not->toContain(
                    '--no-tia',
                    '--filter',
                    '--exclude-filter',
                    '--group',
                    '--exclude-group',
                    '--testsuite',
                    '--exclude-testsuite',
                );

            if (str_ends_with($path, 'composer.json')) {
                /** @var array{scripts: array<string, mixed>} $composer */
                $composer = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);

                array_walk_recursive($composer['scripts'], function (mixed $command): void {
                    if (! is_string($command) || ! str_contains($command, 'vendor/bin/pest')) {
                        return;
                    }

                    expect(preg_match('/(?:^|\s)tests\//', $command))->toBe(0);
                });
            } else {
                expect($contents)->not->toContain('tests/');
            }
        }
    });

    it('requires analysis level 6 or higher in every Composer project', function (string $project): void {
        $configuration = file_get_contents(base_path('../../'.$project.'/phpstan.neon'));

        expect(preg_match_all('/^\s*level:\s*(\d+)\s*$/m', $configuration, $matches))->toBe(1);
        expect((int) $matches[1][0])->toBeGreaterThanOrEqual(6);
    })->with(['apps/cli', 'apps/gateway', 'apps/docs', 'apps/e2e', 'packages/php-sdk']);

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
            ->toBe('vendor/bin/phpstan analyse --no-progress --memory-limit=1G');
        expect($composer['scripts']['lint'])
            ->toBe('@format:check');
        expect($composer['scripts'])
            ->not
            ->toHaveKey('test:live-incus')
            ->and($composer['scripts']['test'])
            ->toBe('vendor/bin/pest --parallel --tia --compact')
            ->and($composer['scripts']['test:fresh'])
            ->toBe('vendor/bin/pest --parallel --tia --fresh --compact')
            ->and($composer['scripts']['guidance:check'])
            ->toBe('vendor/bin/pest --tia --compact');
        expect($composer['scripts'])->not->toHaveKey('test:scenario-cold');
        expect($composer['scripts']['scenario:cold'])
            ->toBe([
                'Composer\\Config::disableProcessTimeout',
                '@php artisan scenario:cold',
            ]);
        expect($composer['scripts']['scenario:snapshot'])
            ->toBe([
                'Composer\\Config::disableProcessTimeout',
                '@php artisan scenario:snapshot',
            ]);
        expect($composer['scripts']['scenario:run'])
            ->toBe([
                'Composer\\Config::disableProcessTimeout',
                '@php artisan scenario:run',
            ]);
        expect($composer['scripts']['scenario:cleanup'])->toBe('@php artisan scenario:cleanup');
        expect(file_get_contents(base_path('phpunit.xml')))->not->toContain('<directory>tests/Scenario</directory>');
        expect(file_get_contents(base_path('phpunit.scenario-cold.xml')))
            ->toContain('<file>tests/Scenario/ColdTopologyAcceptanceTest.php</file>');
        expect(file_get_contents(base_path('phpunit.scenario-snapshot.xml')))
            ->toContain('<file>tests/Scenario/SnapshotTopologyAcceptanceTest.php</file>');
        expect(file_get_contents(base_path('../../bin/test')))
            ->toContain('--tia')
            ->not->toContain('incus-live');
        expect(file_get_contents(base_path('../../.github/workflows/ci.yml')))
            ->toContain('coverage: pcov', '--tia')
            ->not->toContain('incus-live');
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

        foreach (['pint.json', 'phpstan.neon'] as $file) {
            expect(file_get_contents(base_path($file)))->not->toMatch('/database|routes/i');
        }
    });
});
