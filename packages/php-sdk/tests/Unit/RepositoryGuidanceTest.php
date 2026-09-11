<?php

declare(strict_types=1);
use Orbit\Sdk\Requests\AppInstances\AppInstanceDeploymentLayoutRequest;
use Orbit\Sdk\Requests\AppInstances\CreateAppInstanceRequest;
use Orbit\Sdk\Requests\AppInstances\RegisterAppInstanceRequest;
use Orbit\Sdk\Requests\AppInstances\RemoveAppInstanceRequest;
use Orbit\Sdk\Requests\Clusters\ClearClusterRouterRequest;
use Orbit\Sdk\Requests\Clusters\ListClustersRequest;
use Orbit\Sdk\Requests\Doctor\RunDoctorRequest;
use Orbit\Sdk\Requests\Environment\ImportAppInstanceEnvironmentRequest;
use Orbit\Sdk\Requests\Environment\SynchronizeAppInstanceEnvironmentRequest;
use Orbit\Sdk\Requests\Environment\UpdateAppInstanceEnvironmentRequest;

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
                '`pint.json`',
                '`phpstan.neon`',
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

    it('runs the TIA guidance gate first without Laravel or Boost', function (): void {
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
            ->toBe('vendor/bin/pest --tia --compact');
        expect($composer['scripts']['check'][0] ?? null)->toBe('@guidance:check');
    });

    it('keeps every repository test command on TIA', function (): void {
        /** @var array{scripts: array<string, string|list<string>>} $composer */
        $composer = json_decode(
            repository_guidance_contents('composer.json'),
            associative: true,
            flags: JSON_THROW_ON_ERROR,
        );

        expect($composer['scripts']['check'])->not->toContain('@test');

        expect($composer['scripts']['test'] ?? null)
            ->toBe('vendor/bin/pest --parallel --tia --compact')
            ->and($composer['scripts'])
            ->not->toHaveKey('test:full');

        expect(repository_guidance_contents('README.md'))
            ->toContain('composer test       # Pest suite with TIA (parallel)')
            ->not->toContain('composer test:full')
            ->not->toContain('local TIA');

        expect(repository_guidance_contents('AGENTS.md'))
            ->toContain(
                'Use `composer test:affected` for Pest development checks. Reviewers run root `composer check` across all projects with TIA.',
            );

        foreach ([
            '.ai/rules/index.md',
            '.ai/rules/testing-quality.md',
            '.agents/skills/orbit-sdk-development/SKILL.md',
        ] as $guidanceFile) {
            expect(repository_guidance_contents($guidanceFile))
                ->toContain('parallel')
                ->not->toContain('Pest 5 TIA', 'composer test:full', 'after TIA');
        }
    });

    it('inventories every Tool transport operation and response DTO', function (): void {
        $expectedRequests = [
            'Orbit\\Sdk\\Requests\\Tools\\ListToolManagersRequest',
            'Orbit\\Sdk\\Requests\\Tools\\ListToolsRequest',
            'Orbit\\Sdk\\Requests\\Tools\\ShowToolRequest',
            'Orbit\\Sdk\\Requests\\Tools\\InstallToolRequest',
            'Orbit\\Sdk\\Requests\\Tools\\UpdateToolRequest',
            'Orbit\\Sdk\\Requests\\Tools\\RemoveToolRequest',
        ];
        $expectedResponses = [
            'Orbit\\Sdk\\Responses\\Tools\\ToolManagerResponse',
            'Orbit\\Sdk\\Responses\\Tools\\ToolManagersResponse',
            'Orbit\\Sdk\\Responses\\Tools\\ToolResponse',
            'Orbit\\Sdk\\Responses\\Tools\\ToolsResponse',
        ];
        $root = dirname(__DIR__, levels: 2);
        $requestFiles = glob("{$root}/src/Requests/Tools/*.php");
        $responseFiles = glob("{$root}/src/Responses/Tools/*.php");

        if (! is_array($requestFiles) || ! is_array($responseFiles)) {
            $this->fail('Could not read the Tool transport inventory.');
        }

        $toolRequestClasses = array_map(
            static fn (string $file): string => 'Orbit\\Sdk\\Requests\\Tools\\'.pathinfo($file, PATHINFO_FILENAME),
            $requestFiles,
        );
        $toolResponseClasses = array_map(
            static fn (string $file): string => 'Orbit\\Sdk\\Responses\\Tools\\'.pathinfo($file, PATHINFO_FILENAME),
            $responseFiles,
        );

        expect($toolRequestClasses)
            ->toHaveCount(6)
            ->toEqualCanonicalizing($expectedRequests)
            ->and($toolResponseClasses)
            ->toHaveCount(4)
            ->toEqualCanonicalizing($expectedResponses);

        foreach (array_merge($expectedRequests, $expectedResponses) as $class) {
            expect(class_exists($class))->toBeTrue();
        }

        $requestDirectory = "{$root}/src/Requests";
        $retiredInstanceRequestPaths = [
            'Instances/CreateInstanceRequest.php',
            'Instances/ListInstancesRequest.php',
            'Instances/UpdateInstancePhpRequest.php',
        ];
        $requestClasses = [];
        $requestFileCount = 0;
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($requestDirectory, FilesystemIterator::SKIP_DOTS),
        );

        foreach ($files as $file) {
            if (! $file instanceof SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }

            $relative = str_replace(
                search: $requestDirectory.'/',
                replace: '',
                subject: $file->getPathname(),
            );

            if (in_array($relative, $retiredInstanceRequestPaths, strict: true)) {
                expect((string) file_get_contents($file->getPathname()))
                    ->not
                    ->toMatch('/\b(?:class|interface|trait|enum)\s+[A-Za-z_]/');

                continue;
            }

            $requestFileCount++;
            $class = 'Orbit\\Sdk\\Requests\\'.str_replace(['/', '.php'], ['\\', ''], $relative);
            $reflection = new ReflectionClass($class);

            if (! $reflection->isAbstract()) {
                $requestClasses[] = $class;
            }
        }

        expect($requestFileCount)
            ->toBe(76)
            ->and($requestClasses)
            ->toHaveCount(73)
            ->toContain(AppInstanceDeploymentLayoutRequest::class)
            ->toContain(CreateAppInstanceRequest::class)
            ->toContain(RegisterAppInstanceRequest::class)
            ->toContain(RemoveAppInstanceRequest::class)
            ->toContain(ImportAppInstanceEnvironmentRequest::class)
            ->toContain(UpdateAppInstanceEnvironmentRequest::class)
            ->toContain(SynchronizeAppInstanceEnvironmentRequest::class)
            ->toContain(RunDoctorRequest::class)
            ->toContain(ListClustersRequest::class)
            ->toContain(ClearClusterRouterRequest::class);
    });

    it('documents the 73-operation SDK surface including AppInstance conversion and environment transport', function (): void {
        $publicContract = repository_guidance_contents('.ai/rules/public-contract.md');
        $normalizedPublicContract = repository_guidance_normalized_contents('.ai/rules/public-contract.md');

        expect($publicContract)
            ->toContain('The SDK models exactly 73 concrete public Gateway API operations:')
            ->toContain(
                '- Node: list, show, provision, settings update, remove, access add, access remove, role list, role add, and role remove.',
            )
            ->toContain(
                '- Cluster: list, show, create, update, remove, Node attach, Node detach, Router set, and Router clear.',
            )
            ->toContain('- Doctor: run the complete typed Gateway report.')
            ->toContain(
                '- AppInstance: list, show, create, register, remove, deployment-layout preparation, environment import, environment update, and environment synchronization through the concise Instance routes.',
            )
            ->toContain('- Route: list, show, create, update, target set, target clear, and remove.')
            ->not->toContain('Docker Swarm, permissions, role add/remove')->toContain(
                'Do not restore the retired Agent, generic executor, direct SSH execution,',
            )->toContain('Docker Swarm, Compose, image-building, stream, database,')
            ->not->toContain(
                'Do not restore the retired Agent, generic executor, direct SSH execution, Docker Swarm, role add/remove, Compose',
            );

        expect($normalizedPublicContract)
            ->toContain(
                'Model binary node access add/remove and node-show access lists. Do not model granular permissions, presets, wildcards, permission editing, or legacy grant/revoke compatibility.',
            )
            ->toContain(
                'Keep AppInstance deployment-layout transport limited to the numeric AppInstance ID and an optional explicit SQLite source path.',
            )
            ->toContain(
                'Keep AppInstance environment transport limited to an ID-or-hostname selector, optional import replacement, one key and string value for update, an empty synchronization body, and the bounded value-free operation result.',
            );

        expect(repository_guidance_normalized_contents('.ai/rules/redaction-security.md'))
            ->toContain(
                'Treat every submitted or remote environment value as sensitive, regardless of its key or whether its text resembles a credential.',
            );

        expect(repository_guidance_normalized_contents('README.md'))
            ->toContain(
                'The SDK exposes exactly 73 public Gateway operations.',
                'The SDK exposes typed deployment-layout preparation for one AppInstance and an optional explicit SQLite source path.',
                'The SDK exposes typed import, update, and synchronization requests for AppInstance environment configuration.',
                'Environment values remain outside normal SDK diagnostics and errors.',
            );

        preg_match(
            '/Do not restore the retired .*? surfaces\./',
            $normalizedPublicContract,
            $retiredSurfaceMatch,
        );
        expect($retiredSurfaceMatch[0] ?? '')->not->toContain('Tool', 'tool', 'VPN');
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
