<?php

declare(strict_types=1);

use App\Support\LaravelZeroGuidelineComposer;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Laravel\Boost\Install\GuidelineComposer;
use Laravel\Boost\Install\GuidelineConfig;
use Laravel\Boost\Mcp\Boost;
use Laravel\Boost\Support\Config as BoostConfig;
use Symfony\Component\Finder\Finder;
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
    $allIndexedGlobs = collect($indexedGlobs)
        ->flatMap(static fn (string $globs): array => array_map('trim', explode(',', $globs)))
        ->all();

    expect($indexedRuleFiles)
        ->not
        ->toBeEmpty()
        ->toHaveCount(count(array_unique($indexedRuleFiles)));
    expect($allIndexedGlobs)
        ->not
        ->toBeEmpty()
        ->toHaveCount(count(array_unique($allIndexedGlobs)));

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

    $materialPaths = collect(
        new Finder()
            ->files()
            ->in(base_path())
            ->exclude(['.git', 'storage', 'vendor'])
            ->ignoreDotFiles(false)
            ->ignoreVCS(true)
            ->ignoreVCSIgnored(true),
    )
        ->map(static fn (SplFileInfo $file): string => str_replace(
            search: '\\',
            replace: '/',
            subject: $file->getRelativePathname(),
        ));
    $uncoveredPaths = $materialPaths
        ->reject(static fn (string $path): bool => collect($allIndexedGlobs)
            ->contains(static fn (string $glob): bool => Str::is($glob, $path)))
        ->sort()
        ->values()
        ->all();

    expect($uncoveredPaths)->toBeEmpty();

    $appRules = file_get_contents(base_path('.ai/rules/app.md'));

    expect($appRules)
        ->toBeString()
        ->toContain(
            'Every JSON error envelope includes `error.request_id`; use `null` when no request ID is available.',
        );
});

it('routes project JavaScript process guidance through Vite+', function (): void {
    $commandRules = file_get_contents(base_path('.ai/rules/commands.md'));

    expect($commandRules)
        ->toBeString()
        ->toContain('/usr/local/bin/vp')
        ->toContain('one argv item')
        ->not->toMatch('/\b(?:npm|npx|pnpm|pnpx|yarn|yarnpkg|bun|bunx)\s+(?:ci|install|run|exec|add|remove|update)\b/');
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
        ->toContain('Run `composer guidance:check` before planning or editing any file.')
        ->toContain(
            'git restore --source=HEAD -- AGENTS.md .ai .agents/skills .codex/config.toml boost.json config/boost.php composer.json composer.lock',
        )
        ->toContain('Run `composer guidance:generate` to refresh Boost-managed output.')
        ->toContain('Run `composer guidance:check` again and stop if it still fails.')
        ->toContain('Do not silently continue without the project rule set.');
});

it('keeps Boost setup and repository-owned skills reproducible', function (): void {
    $composer = json_decode(
        (string) file_get_contents(base_path('composer.json')),
        associative: true,
        flags: JSON_THROW_ON_ERROR,
    );
    $boost = json_decode(
        (string) file_get_contents(base_path('boost.json')),
        associative: true,
        flags: JSON_THROW_ON_ERROR,
    );
    $mcp = file_get_contents(base_path('.codex/config.toml'));

    expect($composer['require-dev']['laravel/boost'] ?? null)
        ->toBeString()
        ->and($composer['scripts']['guidance:generate'] ?? null)
        ->toBe('@php orbit boost:update --no-discover --no-interaction')
        ->and($composer['scripts']['guidance:check'] ?? null)
        ->toBe('vendor/bin/pest tests/Feature/BoostGuidanceTest.php --no-tia --compact')
        ->and($composer['scripts']['check'][0] ?? null)
        ->toBe('@guidance:check');
    expect(config('boost.guidelines.exclude'))
        ->toContain('deployments', 'foundation', 'laravel/core', 'pest/core')
        ->and(app(GuidelineComposer::class))
        ->toBeInstanceOf(LaravelZeroGuidelineComposer::class)
        ->and(class_exists(Boost::class))
        ->toBeTrue();
    expect($mcp)
        ->toBeString()
        ->toContain('[mcp_servers.laravel-boost]')
        ->toContain('args = ["orbit", "boost:mcp"]');

    $skillNames = $boost['skills'] ?? [];

    expect($skillNames)->toBeArray()->not->toBeEmpty();

    foreach ($skillNames as $skillName) {
        expect(base_path('.agents/skills/'.$skillName.'/SKILL.md'))
            ->toBeFile()
            ->toBeReadableFile();
    }

    $repositorySkillFiles = collect(app(Filesystem::class)->allFiles(base_path('.ai/skills')))
        ->filter(static fn (SplFileInfo $file): bool => $file->getFilename() === 'SKILL.md');

    expect($repositorySkillFiles)->not->toBeEmpty();

    foreach ($repositorySkillFiles as $skillFile) {
        $contents = $skillFile->getContents();
        $frontmatterMatches = [];

        expect(preg_match('/\A---\R(.*?)\R---\R/s', $contents, $frontmatterMatches))->toBe(1);

        $frontmatter = Yaml::parse($frontmatterMatches[1]);
        $skillName = $frontmatter['name'] ?? null;

        expect($skillName)->toBeString()->not->toBeEmpty();
        expect($skillNames)->toContain($skillName);
        expect(base_path('.agents/skills/'.$skillName.'/SKILL.md'))
            ->toBeFile()
            ->toBeReadableFile();
        expect(file_get_contents(base_path('.agents/skills/'.$skillName.'/SKILL.md')))->toBe($contents);
    }

    $guidanceContents = collect([
        base_path('AGENTS.md'),
        ...collect(app(Filesystem::class)->allFiles(base_path('.ai')))
            ->map(static fn (SplFileInfo $file): string => $file->getRealPath())
            ->all(),
        ...collect(app(Filesystem::class)->allFiles(base_path('.agents')))
            ->map(static fn (SplFileInfo $file): string => $file->getRealPath())
            ->all(),
    ])
        ->map(static fn (string $path): string => (string) file_get_contents($path))
        ->join("\n");

    expect($guidanceContents)
        ->not->toContain('database-query')
        ->not->toContain('database-schema')
        ->not->toContain('get-absolute-url')
        ->not->toContain('browser-logs')
        ->not->toContain('record-rule')
        ->not->toContain('search-docs');

    preg_match_all(
        pattern: '/(?:Activate|Read) `([a-z0-9-]+)`/',
        subject: $guidanceContents,
        matches: $namedSkillMatches,
    );

    foreach (array_unique($namedSkillMatches[1] ?? []) as $skillName) {
        expect(base_path('.agents/skills/'.$skillName.'/SKILL.md'))
            ->toBeFile()
            ->toBeReadableFile();
    }
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
    expect($generatedGuidance)
        ->toContain('Use a Boost MCP tool only when the active client exposes that tool.')
        ->not->toContain('database-query')
        ->not->toContain('database-schema')
        ->not->toContain('get-absolute-url')
        ->not->toContain('browser-logs')
        ->not->toContain('record-rule')
        ->not->toContain('search-docs')
        ->not->toContain('Laravel Cloud')
        ->not->toContain('npm run')
        ->not->toContain('php orbit route:list')
        ->not->toContain('php orbit tinker')
        ->not->toContain('php orbit make:model');
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
