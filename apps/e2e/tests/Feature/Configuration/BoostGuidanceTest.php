<?php

declare(strict_types=1);

use Laravel\Boost\Install\SkillComposer;
use Laravel\Boost\Support\RenderFailures;

describe('Boost guidance', function (): void {
    it('has a committed guidance index', function (): void {
        $index = base_path('.ai/rules/index.md');

        expect($index)
            ->toBeFile()
            ->and(file_get_contents($index))
            ->toContain(
                '.ai/rules/app.md',
                '.ai/rules/bootstrap.md',
                '.ai/rules/commands.md',
                '.ai/rules/state.md',
                '.ai/rules/tests.md',
                '.ai/rules/tooling.md',
            );
    });

    it('has valid configuration and non-empty generated skills', function (): void {
        $configuration = json_decode(
            (string) file_get_contents(base_path('boost.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        expect($configuration)->toBeArray();

        foreach ($configuration['skills'] as $skill) {
            $path = base_path(".agents/skills/{$skill}/SKILL.md");

            expect($path)
                ->toBeFile()
                ->and(filesize($path))
                ->toBeGreaterThan(0);
        }
    });

    it('renders all configured guidance without Boost failures', function (): void {
        $configuration = json_decode(
            (string) file_get_contents(base_path('boost.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        expect($configuration['skills'])
            ->toBe([
                'laravel-best-practices',
                'testing-best-practices',
                'spatie-laravel-php',
                'spatie-security',
                'spatie-version-control',
            ]);

        app(SkillComposer::class)->skills();

        expect(app(RenderFailures::class)->paths())
            ->toBeEmpty();
    });
});
