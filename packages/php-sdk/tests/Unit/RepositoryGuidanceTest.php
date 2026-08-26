<?php

declare(strict_types=1);

describe('repository guidance bootstrap', function (): void {
    it('indexes every required rule and material repository path', function (): void {
        $violations = repository_guidance_index_violations(
            repository_guidance_file('.ai/rules/index.md'),
            fn (string $path): bool => is_file(repository_guidance_path($path)),
        );

        expect($violations)->toBeEmpty(
            'Repository guidance bootstrap is invalid: '.implode(', ', $violations),
        );
    });

    it('keeps required policy in every indexed rule', function (): void {
        $guidance = repository_indexed_guidance();
        $violations = repository_indexed_guidance_policy_violations($guidance);

        expect($violations)->toBeEmpty(
            'Indexed repository guidance is incomplete: '.implode(', ', $violations),
        );
    });

    it('reports an actionable bootstrap failure for :failure', function (
        string $search,
        string $replacement,
        string $failure,
    ): void {
        $index = str_replace(
            $search,
            $replacement,
            repository_guidance_file('.ai/rules/index.md'),
        );

        expect(repository_guidance_index_violations(
            $index,
            fn (string $path): bool => is_file(repository_guidance_path($path)),
        ))->toContain($failure);
    })->with([
        'missing required rule' => [
            '[PHP and Spatie](./php-spatie.md)',
            'PHP guidance is unavailable.',
            'required rule [php-spatie.md] is not indexed',
        ],
        'missing source coverage' => [
            '`src/Requests/**/*.php`',
            '`request classes`',
            'material path [src/Requests/**/*.php] is not covered',
        ],
        'missing test coverage' => [
            '`tests/**/*.php`',
            '`test files`',
            'material path [tests/**/*.php] is not covered',
        ],
    ]);

    it('reports a missing file referenced by the rule index', function (): void {
        $violations = repository_guidance_index_violations(
            repository_guidance_file('.ai/rules/index.md'),
            fn (string $path): bool => $path !== '.ai/rules/redaction-security.md'
            && is_file(repository_guidance_path($path)),
        );

        expect($violations)->toContain(
            'indexed rule [.ai/rules/redaction-security.md] is missing',
        );
    });
});

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
        expect($scripts['guidance:check'] ?? null)
            ->toBe('vendor/bin/pest --no-tia --compact tests/Unit/RepositoryGuidanceTest.php');
        expect($scripts['format'] ?? null)->toBe('vendor/bin/mago format');
        expect($scripts['format:check'] ?? null)->toBe('vendor/bin/mago format --check');
        expect($scripts['lint'] ?? null)->toBe('vendor/bin/mago lint src tests --reporting-format=medium');
        expect($scripts['analyse'] ?? null)->toBe('vendor/bin/mago analyze src --reporting-format=medium');
        expect($scripts['rector'] ?? null)->toBe('vendor/bin/rector process --dry-run');
        expect($scripts['check'] ?? null)->toBe([
            '@guidance:check',
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
    $absolutePath = repository_guidance_path($path);

    if (! is_file($absolutePath)) {
        throw new RuntimeException(
            "Repository guidance bootstrap failed: required file [{$path}] is missing.",
        );
    }

    $contents = file_get_contents($absolutePath);

    if (! is_string($contents)) {
        throw new RuntimeException(
            "Repository guidance bootstrap failed: required file [{$path}] could not be read.",
        );
    }

    return $contents;
}

function repository_guidance_path(string $path): string
{
    return dirname(path: __DIR__, levels: 2)."/{$path}";
}

/**
 * @param  callable(string): bool  $fileExists
 * @return list<string>
 */
function repository_guidance_index_violations(string $index, callable $fileExists): array
{
    $requiredRules = [
        'php-spatie.md',
        'saloon-transport.md',
        'redaction-security.md',
        'public-contract.md',
        'testing-quality.md',
    ];
    $materialPaths = [
        'src/Gateway*.php',
        'src/Requests/**/*.php',
        'src/Responses/**/*.php',
        'src/Support/**/*.php',
        'src/Testing/**/*.php',
        'tests/**/*.php',
        'README.md',
        'AGENTS.md',
        '.agents/**/*.md',
        '.ai/rules/**/*.md',
        'composer.json',
        'composer.lock',
        'phpunit.xml.dist',
        'mago.toml',
        'rector.php',
        '.gitignore',
    ];
    $violations = [];

    foreach ($requiredRules as $rule) {
        if (str_contains($index, "](./{$rule})")) {
            continue;
        }

        $violations[] = "required rule [{$rule}] is not indexed";
    }

    foreach ($materialPaths as $path) {
        if (str_contains($index, "`{$path}`")) {
            continue;
        }

        $violations[] = "material path [{$path}] is not covered";
    }

    preg_match_all('/\]\(\.\/([a-z0-9]+(?:-[a-z0-9]+)*\.md)\)/', $index, $matches);
    $indexedRules = array_values(array_unique($matches[1] ?? []));

    foreach ($indexedRules as $rule) {
        $path = ".ai/rules/{$rule}";

        if (! $fileExists($path)) {
            $violations[] = "indexed rule [{$path}] is missing";
        }
    }

    return $violations;
}

/** @return array<string, string> */
function repository_indexed_guidance(): array
{
    $guidance = [];

    foreach ([
        'php-spatie.md',
        'saloon-transport.md',
        'redaction-security.md',
        'public-contract.md',
        'testing-quality.md',
    ] as $rule) {
        $guidance[$rule] = repository_guidance_file(".ai/rules/{$rule}");
    }

    return $guidance;
}

/**
 * @param  array<string, string>  $guidance
 * @return list<string>
 */
function repository_indexed_guidance_policy_violations(array $guidance): array
{
    $policies = [
        'php-spatie.md' => [
            'PHP 8.5, strict types, and Spatie conventions' => '/PHP 8\.5.*declare\(strict_types=1\).*Spatie.*PSR-12/s',
            'framework-neutral no-Boost boundary' => '/framework-neutral.*Laravel and Boost are not dependencies/s',
        ],
        'saloon-transport.md' => [
            'typed Saloon request and DTO transport' => '/typed Saloon 4 requests.*response DTOs.*exact HTTP method.*endpoint/s',
            'transport-only input preservation' => '/Do not add business logic.*omit.*null.*explicit empty/is',
            'orbit-old review before invention' => '/Review.*\/home\/nckrtl\/orbit-old.*before.*invent/s',
        ],
        'redaction-security.md' => [
            'structured safe error envelope' => '/error code.*safe message.*details.*request ID/s',
            'credential redaction surfaces' => '/Redact credentials.*URL userinfo.*query.*nested.*defaults.*exception.*debug.*trace.*serialization/s',
            'root CA trust boundary' => '/one-shot.*verify=false.*bootstrap.*normal connector.*CA path.*redirects/s',
        ],
        'public-contract.md' => [
            'small approved public surface' => '/exactly 33.*public Gateway API operations/s',
            'forbidden legacy architecture' => '/Do not restore.*Agent.*generic executor.*Docker Swarm.*role add\/remove/s',
            'fingerprint direction' => '/host_key_fingerprint.*request.*ssh_host_fingerprint.*response/s',
        ],
        'testing-quality.md' => [
            'Pest 5 TIA and full milestones' => '/Pest 5.*describe\(\).*it\(\).*TIA.*no-TIA/s',
            'Mago and Rector gates' => '/Mago format.*lint.*analy.*Rector.*git diff --check/s',
        ],
    ];
    $violations = [];

    foreach ($policies as $file => $filePolicies) {
        foreach ($filePolicies as $policy => $pattern) {
            if (preg_match($pattern, $guidance[$file] ?? '') === 1) {
                continue;
            }

            $violations[] = "{$file}: {$policy}";
        }
    }

    return $violations;
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
