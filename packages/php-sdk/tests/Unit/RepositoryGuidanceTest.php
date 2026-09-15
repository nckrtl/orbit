<?php

declare(strict_types=1);

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Requests\AppInstances\CloneAppInstanceRequest;
use Orbit\Sdk\Requests\AppInstances\CreateAppInstanceRequest;
use Orbit\Sdk\Requests\AppInstances\DestroyAppInstanceRequest;
use Orbit\Sdk\Requests\AppInstances\RegisterAppInstanceRequest;
use Orbit\Sdk\Requests\AppInstances\TransferAppInstanceRequest;
use Orbit\Sdk\Requests\AppInstances\UpdateAppInstanceRequest;
use Orbit\Sdk\Requests\Apps\UpdateAppRequest;
use Orbit\Sdk\Requests\Clusters\ListClustersRequest;
use Orbit\Sdk\Requests\Clusters\UnsetClusterRouterRequest;
use Orbit\Sdk\Requests\DatabaseConnections\AddInstanceDatabaseRequest;
use Orbit\Sdk\Requests\DatabaseConnections\CreateDatabaseConnectionRequest;
use Orbit\Sdk\Requests\DatabaseConnections\CreateDatabaseUserRequest;
use Orbit\Sdk\Requests\DatabaseConnections\DescribeDatabaseTableRequest;
use Orbit\Sdk\Requests\DatabaseConnections\DestroyDatabaseConnectionRequest;
use Orbit\Sdk\Requests\DatabaseConnections\ListDatabaseConnectionsRequest;
use Orbit\Sdk\Requests\DatabaseConnections\ListDatabaseTablesRequest;
use Orbit\Sdk\Requests\DatabaseConnections\ListDatabaseUsersRequest;
use Orbit\Sdk\Requests\DatabaseConnections\QueryDatabaseConnectionRequest;
use Orbit\Sdk\Requests\DatabaseConnections\RemoveInstanceDatabaseRequest;
use Orbit\Sdk\Requests\DatabaseConnections\ShowDatabaseConnectionRequest;
use Orbit\Sdk\Requests\DatabaseConnections\ShowDatabaseSchemaRequest;
use Orbit\Sdk\Requests\DatabaseConnections\UpdateDatabaseConnectionRequest;
use Orbit\Sdk\Requests\Deployments\CreateInstanceDeployStepRequest;
use Orbit\Sdk\Requests\Deployments\DeployAppInstanceRequest;
use Orbit\Sdk\Requests\Deployments\DestroyInstanceDeployStepRequest;
use Orbit\Sdk\Requests\Deployments\ListAppInstanceDeploymentsRequest;
use Orbit\Sdk\Requests\Deployments\ListAppInstanceReleasesRequest;
use Orbit\Sdk\Requests\Deployments\ListInstanceDeployStepsRequest;
use Orbit\Sdk\Requests\Deployments\RollbackAppInstanceRequest;
use Orbit\Sdk\Requests\Deployments\ShowAppInstanceDeploymentRequest;
use Orbit\Sdk\Requests\Deployments\UpdateInstanceDeployStepRequest;
use Orbit\Sdk\Requests\Doctor\RunDoctorRequest;
use Orbit\Sdk\Requests\Environment\ImportAppInstanceEnvironmentRequest;
use Orbit\Sdk\Requests\Environment\SynchronizeAppInstanceEnvironmentRequest;
use Orbit\Sdk\Requests\Environment\UpdateAppInstanceEnvironmentRequest;
use Orbit\Sdk\Requests\Herdr\AdoptHerdrSessionRequest;
use Orbit\Sdk\Requests\Herdr\CreateHerdrSessionRequest;
use Orbit\Sdk\Requests\Herdr\DestroyHerdrSessionRequest;
use Orbit\Sdk\Requests\Herdr\IssueObservationGrantRequest;
use Orbit\Sdk\Requests\Herdr\ListHerdrSessionsRequest;
use Orbit\Sdk\Requests\Herdr\RestartHerdrSessionRequest;
use Orbit\Sdk\Requests\Herdr\ShowHerdrSessionRequest;
use Orbit\Sdk\Requests\Nodes\ShowNodeMetricsRequest;
use Orbit\Sdk\Requests\Schedules\CompleteScheduleRequest;
use Orbit\Sdk\Requests\Schedules\CreateScheduleRequest;
use Orbit\Sdk\Requests\Schedules\DestroyScheduleRequest;
use Orbit\Sdk\Requests\Schedules\EnableScheduleRequest;
use Orbit\Sdk\Requests\Schedules\ListSchedulesRequest;
use Orbit\Sdk\Requests\Schedules\RunScheduleRequest;
use Orbit\Sdk\Requests\Schedules\ScheduleLogsRequest;
use Orbit\Sdk\Requests\Schedules\ShowScheduleRequest;

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
                '`phpunit.guidance.xml`',
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
            ->toBe('ORBIT_TIA_DIRECTORY=vendor/.orbit-guidance-tia vendor/bin/pest --configuration=phpunit.guidance.xml --tia --fresh --compact');
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

    });

    it('inventories every concrete transport operation and the Tool response DTOs', function (): void {
        $preScheduleOperationCount = 95;
        $scheduleRequests = [
            ListSchedulesRequest::class,
            CreateScheduleRequest::class,
            ShowScheduleRequest::class,
            RunScheduleRequest::class,
            ScheduleLogsRequest::class,
            CompleteScheduleRequest::class,
            DestroyScheduleRequest::class,
            EnableScheduleRequest::class,
        ];
        $herdrRequests = [
            ListHerdrSessionsRequest::class,
            CreateHerdrSessionRequest::class,
            AdoptHerdrSessionRequest::class,
            ShowHerdrSessionRequest::class,
            RestartHerdrSessionRequest::class,
            DestroyHerdrSessionRequest::class,
            IssueObservationGrantRequest::class,
        ];
        $databaseRequests = [
            ListDatabaseConnectionsRequest::class,
            ShowDatabaseConnectionRequest::class,
            CreateDatabaseConnectionRequest::class,
            CreateDatabaseUserRequest::class,
            UpdateDatabaseConnectionRequest::class,
            DestroyDatabaseConnectionRequest::class,
            AddInstanceDatabaseRequest::class,
            RemoveInstanceDatabaseRequest::class,
            QueryDatabaseConnectionRequest::class,
            ListDatabaseTablesRequest::class,
            ShowDatabaseSchemaRequest::class,
            DescribeDatabaseTableRequest::class,
            ListDatabaseUsersRequest::class,
        ];
        $expectedOperationCount = 3 + $preScheduleOperationCount + count($scheduleRequests) + count($herdrRequests) + count($databaseRequests);
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
            'Workspaces/CreateWorkspaceRequest.php',
            'Workspaces/ListWorkspacesRequest.php',
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

            $class = 'Orbit\\Sdk\\Requests\\'.str_replace(['/', '.php'], ['\\', ''], $relative);
            $reflection = new ReflectionClass($class);

            if (! $reflection->isSubclassOf(GatewayRequest::class)) {
                continue;
            }

            $requestFileCount++;

            if (! $reflection->isAbstract()) {
                $requestClasses[] = $class;
            }
        }

        expect($requestFileCount)
            ->toBe($expectedOperationCount + 4)
            ->and($requestClasses)
            ->toHaveCount($expectedOperationCount)
            ->toContain(CloneAppInstanceRequest::class)
            ->toContain(TransferAppInstanceRequest::class)
            ->toContain(CreateAppInstanceRequest::class)
            ->toContain(RegisterAppInstanceRequest::class)
            ->toContain(DestroyAppInstanceRequest::class)
            ->toContain(ImportAppInstanceEnvironmentRequest::class)
            ->toContain(UpdateAppInstanceEnvironmentRequest::class)
            ->toContain(SynchronizeAppInstanceEnvironmentRequest::class)
            ->toContain(CreateInstanceDeployStepRequest::class)
            ->toContain(ListInstanceDeployStepsRequest::class)
            ->toContain(UpdateInstanceDeployStepRequest::class)
            ->toContain(DestroyInstanceDeployStepRequest::class)
            ->toContain(UpdateAppRequest::class)
            ->toContain(UpdateAppInstanceRequest::class)
            ->toContain(DeployAppInstanceRequest::class)
            ->toContain(RollbackAppInstanceRequest::class)
            ->toContain(ListAppInstanceReleasesRequest::class)
            ->toContain(ListAppInstanceDeploymentsRequest::class)
            ->toContain(ShowAppInstanceDeploymentRequest::class)
            ->toContain(RunDoctorRequest::class)
            ->toContain(ListClustersRequest::class)
            ->toContain(UnsetClusterRouterRequest::class)
            ->toContain(ShowNodeMetricsRequest::class);

        expect(array_values(array_filter(
            $requestClasses,
            static fn (string $class): bool => str_starts_with($class, 'Orbit\\Sdk\\Requests\\Schedules\\'),
        )))
            ->toHaveCount(count($scheduleRequests))
            ->toEqualCanonicalizing($scheduleRequests);

        expect(array_values(array_filter(
            $requestClasses,
            static fn (string $class): bool => str_starts_with($class, 'Orbit\\Sdk\\Requests\\Herdr\\'),
        )))
            ->toHaveCount(count($herdrRequests))
            ->toEqualCanonicalizing($herdrRequests);

        expect(array_values(array_filter(
            $requestClasses,
            static fn (string $class): bool => str_starts_with($class, 'Orbit\\Sdk\\Requests\\DatabaseConnections\\'),
        )))
            ->toHaveCount(count($databaseRequests))
            ->toEqualCanonicalizing($databaseRequests);
    });

    it('documents the 122-operation SDK surface including Database connection transport', function (): void {
        $publicContract = repository_guidance_contents('.ai/rules/public-contract.md');
        $normalizedPublicContract = repository_guidance_normalized_contents('.ai/rules/public-contract.md');

        expect($publicContract)
            ->toContain('The SDK models exactly 122 concrete public Gateway API operations:')
            ->toContain(
                '- Node: list, show, add, settings update, remove, access add, access remove, role list, role add, role remove, and metrics.',
            )
            ->toContain(
                '- Cluster: list, show, create, update, remove, Node attach, Node detach, Router set, and Router clear.',
            )
            ->toContain('- Doctor: run the complete typed Gateway report.')
            ->toContain('- Schedule: list, add, show, run, logs, complete, remove, and activate.')
            ->toContain('- Herdr: session list, add, adopt, show, restart, remove, and observation-grant.')
            ->toContain('- Database connection: list, show, add, update, remove, attach, detach, query, tables, schema, describe, user create, and user list.')
            ->toContain(
                '- App runtime definition: process and Schedule list, create, show, update, and destroy.',
            )
            ->toContain(
                '- AppInstance: list, show, create, register, clone, transfer, remove, update, deploy-step create, list, update, and destroy, deploy, rollback, retained-release list, deployment-history list and show, environment import, environment update, environment synchronization, dependency inventory read, dependency scan, and full-domain instance resolution through the concise Instance routes.',
            )
            ->toContain('- Route: list, show, create, update, target set, target clear, and remove.')
            ->not->toContain('- Workspace: list, show, create, remove, and update PHP.')
            ->not->toContain('Docker Swarm, permissions, role add/remove')->toContain(
                'Do not restore the retired Agent, generic executor, direct SSH execution,',
            )->toContain('Docker Swarm, Compose, image-building, generic stream, unregistered database query,')
            ->not->toContain('or deploy surfaces')
            ->not->toContain(
                'Do not restore the retired Agent, generic executor, direct SSH execution, Docker Swarm, role add/remove, Compose',
            );

        expect($normalizedPublicContract)
            ->toContain(
                'Keep candidate clone transport limited to the numeric candidate AppInstance ID, destination Node ID, target name, preview name, optional branch, and optional SQLite source path.',
            )
            ->toContain(
                'Keep AppInstance transfer transport limited to the numeric AppInstance ID, destination Node ID, optional rename, and optional SQLite source path.',
            )
            ->toContain(
                'Model binary node access add/remove and node-show access lists. Do not model granular permissions, presets, wildcards, permission editing, or legacy grant/revoke compatibility.',
            )
            ->not->toContain(
                'Keep AppInstance deployment-layout transport limited to the numeric AppInstance ID and an optional explicit SQLite source path.',
            )
            ->toContain(
                'Keep AppInstance environment transport limited to an ID-or-domain selector, optional import replacement, one key and string value for update, an empty synchronization body, and the bounded value-free operation result.',
            )
            ->toContain(
                'Keep AppInstance deployment transport limited to named deploy-step create, list, update, and destroy, AppInstance branch update, explicit deploy and rollback streams, and retained-release inspection.',
            )
            ->toContain(
                "Keep App runtime definition transport limited to a numeric App ID, a definition name for item operations, and the caller's exact JSON document for create and full update.",
            )
            ->toContain(
                'Keep Schedule transport limited to typed Node and AppInstance targets and the eight shipped operations.',
            )
            ->toContain(
                'Keep Herdr transport limited to a numeric Node ID, a numeric session ID for item operations, explicit session name and Unix user on add or adopt, optional observer publication and restart handoff flags, optional removal termination acceptance, and pane, terminal, columns, rows, and an HTTPS browser origin for observation grants.',
            )
            ->toContain(
                'Keep Database connection transport limited to slug identity, driver, optional Node ID, host, port, database name, sqlite path, username, and password.',
            )
            ->toContain(
                'Attach and detach send an AppInstance ID-or-domain selector, the connection slug, and an optional prefix.',
            );

        expect(repository_guidance_normalized_contents('.ai/rules/redaction-security.md'))
            ->toContain(
                'Treat every submitted or remote environment value as sensitive, regardless of its key or whether its text resembles a credential.',
            );

        expect(repository_guidance_normalized_contents('README.md'))
            ->toContain(
                'The SDK exposes exactly 122 public Gateway operations.',
                'The SDK exposes typed list, show, add, update, remove, attach, detach, query, tables, schema, describe, and user create requests for Gateway-owned database connection records.',
                'The SDK exposes typed list, create, show, update, and destroy requests for App process and Schedule definitions.',
                'The SDK exposes typed list, add, show, run, logs, complete, remove, and activate requests for Node and AppInstance Schedules.',
                'Doctor accepts the current Gateway family set, including Schedule, Herdr, and Database connection.',
                'The SDK exposes typed list, add, adopt, show, restart, remove, and observation-grant requests for Herdr sessions.',
                'Observation grant URLs stay out of generic diagnostics.',
                "Create and update requests send the caller's exact JSON document to the Gateway.",
                'The SDK exposes typed deploy-step, deploy, rollback, and retained-release operations.',
                'Deployment streams are incremental, closeable, bounded, correlated, and never retried or replayed.',
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
