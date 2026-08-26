<?php

declare(strict_types=1);

use App\Support\LaravelZeroGuidelineComposer;
use Illuminate\Filesystem\Filesystem;
use Laravel\Boost\Install\GuidelineComposer;
use Laravel\Boost\Install\GuidelineConfig;
use Laravel\Boost\Support\Config as BoostConfig;
use Symfony\Component\Finder\SplFileInfo;
use Symfony\Component\Yaml\Yaml;

it('keeps scoped Boost guidance enabled and discoverable', function (): void {
    expect(config('boost.rules.enabled'))
        ->toBeTrue()
        ->and(config('boost.rules.scoped_guidelines'))
        ->toBeTrue()
        ->and(base_path('.ai/rules/index.md'))
        ->toBeFile();

    $index = file_get_contents(base_path('.ai/rules/index.md'));

    expect($index)
        ->toBeString()
        ->not->toBeEmpty();

    $matches = [];
    preg_match_all(
        pattern: '/^\|\s*([^|\r\n]+?)\s*\|\s*(\.ai\/rules\/[a-zA-Z0-9._\/-]+\.md)\s*\|\s*$/m',
        subject: $index,
        matches: $matches,
    );
    $indexedGlobs = $matches[1] ?? [];
    $indexedRuleFiles = $matches[2] ?? [];

    expect($indexedRuleFiles)
        ->not
        ->toBeEmpty()
        ->toHaveCount(count(array_unique($indexedRuleFiles)));

    foreach ($indexedRuleFiles as $index => $ruleFile) {
        $path = base_path($ruleFile);
        $contents = is_readable($path) ? file_get_contents($path) : false;

        expect($path)
            ->toBeFile()
            ->toBeReadableFile();
        expect($contents)
            ->toBeString()
            ->not->toBeEmpty();

        $frontmatterMatches = [];
        expect(preg_match('/\A---\R(.*?)\R---\R/s', $contents, $frontmatterMatches))->toBe(1);

        $frontmatter = Yaml::parse($frontmatterMatches[1]);
        $expectedGlobs = array_map(
            static fn (string $glob): string => trim($glob),
            explode(',', $indexedGlobs[$index]),
        );

        expect($frontmatter)
            ->toBeArray()
            ->toHaveKey('paths')
            ->and($frontmatter['paths'])
            ->toBe($expectedGlobs);
    }

    $discoveredRuleFiles = collect(app(Filesystem::class)->allFiles(base_path('.ai/rules')))
        ->filter(static fn (SplFileInfo $file): bool => $file->getExtension() === 'md')
        ->map(
            static fn (SplFileInfo $file): string => '.ai/rules/'
            .str_replace(
                search: '\\',
                replace: '/',
                subject: $file->getRelativePathname(),
            ),
        )
        ->reject(static fn (string $path): bool => $path === '.ai/rules/index.md')
        ->sort()
        ->values()
        ->all();
    $sortedIndexedRuleFiles = $indexedRuleFiles;
    sort($sortedIndexedRuleFiles);

    expect($discoveredRuleFiles)->toBe($sortedIndexedRuleFiles);

    $appRules = file_get_contents(base_path('.ai/rules/app.md'));

    expect($appRules)
        ->toBeString()
        ->toContain(
            'Every JSON error envelope includes `error.request_id`; use `null` when no request ID is available.',
        );
});

it('requires guidance bootstrap before repository edits', function (): void {
    $agents = file_get_contents(base_path('AGENTS.md'));
    $bootstrapGuidance = is_string($agents)
        ? strstr($agents, needle: '<laravel-boost-guidelines>', before_needle: true)
        : false;
    $normalizedBootstrapGuidance = is_string($bootstrapGuidance)
        ? preg_replace(pattern: '/\s+/', replacement: ' ', subject: $bootstrapGuidance)
        : null;

    expect($normalizedBootstrapGuidance)
        ->toBeString()
        ->toContain('## Required Guidance Bootstrap')
        ->toContain('`.ai/rules/index.md` and every indexed project rule file are required repository state.')
        ->toContain('If either is absent, the checkout or Boost bootstrap is incomplete.')
        ->toContain('Restore or regenerate the guidance before planning or editing any file.')
        ->toContain('Do not silently continue without the project rule set.');
});

it('never generates guidance that permits a silent rules skip', function (): void {
    $agents = file_get_contents(base_path('AGENTS.md'));
    $boost = new BoostConfig;
    $config = new GuidelineConfig;
    $config->usesSail = $boost->getSail();
    $config->hasSkills = $boost->hasSkills();
    $config->hasMcp = $boost->getMcp();
    $config->aiGuidelines = $boost->getPackages();
    $composer = app(GuidelineComposer::class)->config($config);
    $generatedGuidance = $composer->compose();
    $requiredFallback = 'If `.ai/rules` does not exist, the checkout or Boost bootstrap is incomplete.';
    $forbiddenFallbackPattern = '/If `?\.ai\/rules`? (?:does not exist|is absent),\s*(?:you may\s+)?(?:continue|proceed|skip)\b/i';
    $committedGuidance = null;

    if (
        is_string($agents)
        && preg_match(
            pattern: '/<laravel-boost-guidelines>\s*(.*?)\s*<\/laravel-boost-guidelines>/s',
            subject: $agents,
            matches: $matches,
        ) === 1
    ) {
        $committedGuidance = $matches[1];
    }

    expect($composer)->toBeInstanceOf(LaravelZeroGuidelineComposer::class);
    expect($agents)
        ->toBeString()
        ->not->toMatch($forbiddenFallbackPattern)
        ->not->toContain('in `.ai/rules` when that directory exists')->toContain($requiredFallback);
    expect($generatedGuidance)
        ->not->toMatch($forbiddenFallbackPattern)
        ->not->toContain('in `.ai/rules` when that directory exists')->toContain($requiredFallback)->toContain(
            'Restore or regenerate the guidance before planning or editing any file.',
        );
    expect($committedGuidance)
        ->toBeString()
        ->toBe(trim($generatedGuidance));
});

it('fails closed when Boost project-rule source markers drift', function (string $sourceGuidance): void {
    $composer = app(GuidelineComposer::class);
    $guidelines = new ReflectionProperty(GuidelineComposer::class, 'guidelines');
    $guidelines->setValue($composer, collect([
        'boost' => [
            'content' => $sourceGuidance,
            'name' => 'boost',
            'path' => null,
            'custom' => false,
        ],
    ]));

    expect($composer->compose(...))
        ->toThrow(
            RuntimeException::class,
            'Boost project-rule guidance changed. Update the Orbit CLI hard-stop transformation before regenerating.',
        );
})->with([
    'missing optional location' => 'If `.ai/rules` does not exist, continue without it.',
    'duplicate optional location' => implode("\n", [
        'in `.ai/rules` when that directory exists',
        'in `.ai/rules` when that directory exists',
        'If `.ai/rules` does not exist, continue without it.',
    ]),
    'missing permissive fallback' => 'in `.ai/rules` when that directory exists',
    'duplicate permissive fallback' => implode("\n", [
        'in `.ai/rules` when that directory exists',
        'If `.ai/rules` does not exist, continue without it.',
        'If `.ai/rules` does not exist, continue without it.',
    ]),
]);
