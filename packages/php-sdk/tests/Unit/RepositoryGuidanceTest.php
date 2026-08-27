<?php

declare(strict_types=1);

describe('repository guidance bootstrap', function (): void {
    it('indexes every required readable rule file', function (): void {
        $index = repository_guidance_contents('.ai/rules/index.md');

        preg_match_all(
            pattern: '/\]\(\.\/([a-z0-9]+(?:-[a-z0-9]+)*\.md)\)/',
            subject: $index,
            matches: $matches,
        );

        $ruleFiles = array_values(array_unique($matches[1] ?? []));

        expect($ruleFiles)->toEqualCanonicalizing([
            'php-spatie.md',
            'saloon-transport.md',
            'redaction-security.md',
            'public-contract.md',
            'testing-quality.md',
        ]);

        foreach ($ruleFiles as $ruleFile) {
            expect(repository_guidance_contents(".ai/rules/{$ruleFile}"))->not->toBeEmpty();
        }
    });

    it('maps every material source, test, and tooling path', function (): void {
        expect(repository_guidance_contents('.ai/rules/index.md'))
            ->toContain(
                '`src/Gateway*.php`',
                '`src/Requests/**/*.php`',
                '`src/Responses/**/*.php`',
                '`src/Support/**/*.php`',
                '`src/Testing/**/*.php`',
                '`tests/**/*.php`',
                '`README.md`',
                '`AGENTS.md`',
                '`.agents/**/*.md`',
                '`.ai/rules/**/*.md`',
                '`composer.json`',
                '`composer.lock`',
                '`phpunit.xml.dist`',
                '`mago.toml`',
                '`rector.php`',
                '`.gitignore`',
            );
    });

    it('provides an actionable restoration path', function (): void {
        $restoreCommand = 'git restore --source=HEAD -- AGENTS.md .ai/rules composer.json';

        expect(fn (): string => repository_guidance_contents('.ai/rules/missing.md'))
            ->toThrow(RuntimeException::class, $restoreCommand);
        expect(repository_guidance_contents('AGENTS.md'))
            ->toContain($restoreCommand)
            ->toContain('composer guidance:check')
            ->toContain('do not silently skip it');
    });

    it('runs the no-TIA guidance gate first without Laravel or Boost', function (): void {
        /** @var array{require: array<string, string>, require-dev: array<string, string>, scripts: array<string, string|list<string>>} $composer */
        $composer = json_decode(
            repository_guidance_contents('composer.json'),
            associative: true,
            flags: JSON_THROW_ON_ERROR,
        );
        $dependencies = array_merge($composer['require'], $composer['require-dev']);

        expect($dependencies)
            ->not->toHaveKeys(['laravel/framework', 'laravel/boost']);
        expect($composer['scripts']['guidance:check'] ?? null)
            ->toBe('vendor/bin/pest --no-tia --compact tests/Unit/RepositoryGuidanceTest.php');
        expect($composer['scripts']['check'][0] ?? null)->toBe('@guidance:check');
    });

    it('documents the 38-operation SDK surface and the binary node access boundary', function (): void {
        $publicContract = repository_guidance_contents('.ai/rules/public-contract.md');
        $normalizedPublicContract = repository_guidance_normalized_contents('.ai/rules/public-contract.md');

        expect($publicContract)
            ->toContain('The SDK models exactly 38 concrete public Gateway API operations:')
            ->toContain(
                '- Node: list, show, provision, remove, access add, access remove, role list, role add, and role remove.',
            )
            ->not->toContain('Docker Swarm, permissions, role add/remove')->toContain(
                'Do not restore the retired Agent, generic executor, direct SSH execution,',
            )->toContain('Docker Swarm, Compose, image-building, stream, database,')
            ->not->toContain(
                'Do not restore the retired Agent, generic executor, direct SSH execution, Docker Swarm, role add/remove, Compose',
            );

        expect($normalizedPublicContract)
            ->toContain(
                'Model binary node access add/remove and node-show access lists. Do not model granular permissions, presets, wildcards, permission editing, or legacy grant/revoke compatibility.',
            );
    });
});

function repository_guidance_contents(string $path): string
{
    $absolutePath = dirname(path: __DIR__, levels: 2)."/{$path}";
    $restoreCommand = 'git restore --source=HEAD -- AGENTS.md .ai/rules composer.json';
    $contents = is_file($absolutePath) && is_readable($absolutePath)
        ? file_get_contents($absolutePath)
        : false;

    if (! is_string($contents) || trim($contents) === '') {
        throw new RuntimeException(
            "Repository guidance bootstrap failed for [{$path}]. Restore it with `{$restoreCommand}`, then run `composer guidance:check`.",
        );
    }

    return $contents;
}

function repository_guidance_normalized_contents(string $path): string
{
    return preg_replace('/\s+/', replacement: ' ', subject: repository_guidance_contents($path)) ?? '';
}
