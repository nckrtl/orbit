<?php

declare(strict_types=1);

describe('repository guidance', function (): void {
    it('keeps required SDK guidance in :path', function (string $path): void {
        $guidance = repository_guidance_file($path);
        $violations = repository_guidance_policy_violations($guidance);

        expect($violations)->toBeEmpty(
            "Required repository policies are missing or weakened in [{$path}]: ".implode(', ', $violations),
        );
    })->with([
        'repository instructions' => ['AGENTS.md'],
        'SDK development skill' => ['.agents/skills/orbit-sdk-development/SKILL.md'],
    ]);

    it('detects weakened repository policy for :policy', function (
        string $search,
        string $replacement,
        string $policy,
    ): void {
        $guidance = str_replace($search, $replacement, repository_guidance_file('AGENTS.md'));

        expect(repository_guidance_policy_violations($guidance))->toContain($policy);
    })->with([
        'framework boundary' => [
            'Keep this SDK framework-neutral.',
            'This SDK may use application frameworks.',
            'framework-neutral Laravel Boost boundary',
        ],
        'legacy review' => [
            '/home/nckrtl/orbit-old',
            '/tmp/no-legacy-review',
            'orbit-old and Saloon review',
        ],
        'redaction' => [
            'Redact credentials',
            'Expose credentials',
            'credential redaction surfaces',
        ],
        'test milestones' => [
            'Use Pest 5 TIA',
            'Use a Pest development loop',
            'Pest 5 TIA and no-TIA milestones',
        ],
        'static analysis' => [
            'Run Mago format, lint, and analysis, Rector',
            'Run PHP checks',
            'Mago and Rector gates',
        ],
    ]);

    it('keeps the framework-neutral toolchain executable', function (): void {
        /** @var array{require: array<string, string>, require-dev: array<string, string>, scripts: array<string, string|list<string>>} $composer */
        $composer = json_decode(
            repository_guidance_file('composer.json'),
            associative: true,
            flags: JSON_THROW_ON_ERROR,
        );
        $dependencies = array_merge($composer['require'], $composer['require-dev']);
        $scripts = $composer['scripts'];

        expect(array_key_exists('laravel/framework', $dependencies))->toBeFalse();
        expect(array_key_exists('laravel/boost', $dependencies))->toBeFalse();
        expect($composer['require']['php'])->toBe('^8.5');
        expect($composer['require-dev']['pestphp/pest'])->toStartWith('^5.');
        expect($composer['require-dev'])->toHaveKeys([
            'carthage-software/mago',
            'rector/rector',
        ]);
        expect($scripts['test'] ?? null)->toContain('vendor/bin/pest');
        expect($scripts['test:full'] ?? null)->toContain('--no-tia');
        expect($scripts['format'] ?? null)->toBe('vendor/bin/mago format');
        expect($scripts['format:check'] ?? null)->toBe('vendor/bin/mago format --check');
        expect($scripts['lint'] ?? null)->toBe('vendor/bin/mago lint src tests --reporting-format=medium');
        expect($scripts['analyse'] ?? null)->toBe('vendor/bin/mago analyze src --reporting-format=medium');
        expect($scripts['rector'] ?? null)->toBe('vendor/bin/rector process --dry-run');
        expect($scripts['check'] ?? null)->toBe([
            '@rector',
            '@test',
            '@format:check',
            '@lint',
            '@analyse',
        ]);
        expect(repository_guidance_file('tests/Pest.php'))->toContain('->tia()');
    });
});

function repository_guidance_file(string $path): string
{
    $absolutePath = dirname(path: __DIR__, levels: 2)."/{$path}";

    if (! is_file($absolutePath)) {
        throw new RuntimeException("Required repository file [{$path}] is missing.");
    }

    $contents = file_get_contents($absolutePath);

    if (! is_string($contents)) {
        throw new RuntimeException("Required repository file [{$path}] could not be read.");
    }

    return $contents;
}

/** @return list<string> */
function repository_guidance_policy_violations(string $guidance): array
{
    $policies = [
        'PHP 8.5 and Spatie conventions' => '/PHP 8\.5 with strict types.*Follow (?:the )?Spatie PHP coding guidelines/s',
        'framework-neutral Laravel Boost boundary' => '/framework-neutral\..*Laravel Boost is deliberately required in\s+`orbit-gateway` and `orbit-cli`, not in (?:this package|this SDK)/s',
        'typed transport-only architecture' => '/(?:Implement|Limit).*typed Saloon request(?:s)?.*(?:DTO|response).*only/s',
        'business logic and execution boundary' => '/(?:Keep|Do not add).*business logic,\s+validation policy, (?:and |or )?remote execution/s',
        'structured gateway error contract' => '/Preserve.*structured (?:gateway )?error codes?, safe messages?, details, and\s+request\s+IDs?/s',
        'credential redaction surfaces' => '/Redact credentials.*URL.*nested.*defaults.*exception text.*debug output/s',
        'one-shot root CA bootstrap' => '/GatewayRootCaClient.*(?:deliberately )?one-shot.*(?:fresh|new) client.*retr(?:y|ies)/s',
        'orbit-old and Saloon review' => '/(?:Read|Review).*matching.*\/home\/nckrtl\/orbit-old.*SDK.*Saloon.*(?:before inventing transport\s+behavior|Reuse\s+proven transport invariants)/s',
        'Pest 5 describe and it style' => '/Use Pest 5 with.*describe\(\).*it\(\)/s',
        'Pest 5 TIA and no-TIA milestones' => '/Use Pest 5 TIA.*no-TIA/s',
        'Mago and Rector gates' => '/Run Mago format, lint, and analysis(?:,| and) Rector/s',
    ];
    $violations = [];

    foreach ($policies as $policy => $pattern) {
        if (preg_match($pattern, $guidance) === 1) {
            continue;
        }

        $violations[] = $policy;
    }

    return $violations;
}
