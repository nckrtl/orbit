<?php

declare(strict_types=1);

use App\E2E\Git\GitRepository;
use App\E2E\PreparedStateFingerprint;
use App\E2E\Value\LaravelRelease;
use App\E2E\Value\PreparedFingerprint;
use Illuminate\Container\Container;
use Illuminate\Process\Factory as ProcessFactory;
use Illuminate\Support\Facades\Facade;

/** @property string $path */
describe('PreparedStateFingerprint', function (): void {
    it('rejects an invalid fingerprint at construction', function (): void {
        expect(fn () => new PreparedFingerprint('not-a-hash'))->toThrow(InvalidArgumentException::class);
    });

    beforeEach(function (): void {
        configureFingerprintProcessFacade();
        $this->path = temporaryPath('orbit-fingerprint-', 6);
        mkdir($this->path.'/resources', 0700, true);
        mkdir($this->path.'/contracts', 0700, true);
        mkdir($this->path.'/apps/e2e/app/E2E', 0700, true);
        mkdir($this->path.'/apps/e2e/resources/guest', 0700, true);
        mkdir($this->path.'/apps/cli/app/Commands/Gateway', 0700, true);
        fingerprintGit($this->path, ['init', '--quiet']);
        fingerprintGit($this->path, ['config', 'user.email', 'orbit@example.test']);
        fingerprintGit($this->path, ['config', 'user.name', 'Orbit']);
    });

    afterEach(function (): void {
        $tempDirectory = rtrim(sys_get_temp_dir(), '/');
        $expectedPrefix = $tempDirectory.'/orbit-fingerprint-';

        if (
            ! str_starts_with($this->path, $expectedPrefix)
            || preg_match('/\A'.preg_quote($expectedPrefix, '/').'[0-9a-f]{12}\z/', $this->path) !== 1
            || ! is_dir($this->path)
        ) {
            return;
        }

        removeFingerprintFixture($this->path);
    });

    it('canonicalizes prepared input while preserving the exact topology role order', function (): void {
        file_put_contents($this->path.'/contracts/a.php', "contract\n");
        writePreparedManifest($this->path, [
            'schema' => 1,
            'paths' => ['contracts/*.php'],
            'cold_epoch' => 'ubuntu-26.04-amd64-v1',
            'base_image_alias' => 'orbit-base-ubuntu-26.04-runtime',
            'declared_epochs' => ['php' => 1, 'base_image' => 2],
            'topology' => [
                'roles' => ['gateway', 'app-dev', 'app-prod'],
                'profile' => 'gateway_app-dev_app-prod',
                'checkout_roles' => ['gateway', 'app-dev'],
            ],
        ]);
        fingerprintGit($this->path, ['add', '.']);
        fingerprintGit($this->path, ['commit', '--quiet', '-m', 'first']);
        $first = fingerprintGit($this->path, ['rev-parse', 'HEAD']);

        writePreparedManifest($this->path, [
            'topology' => [
                'checkout_roles' => ['gateway', 'app-dev'],
                'profile' => 'gateway_app-dev_app-prod',
                'roles' => ['gateway', 'app-dev', 'app-prod'],
            ],
            'declared_epochs' => ['base_image' => 2, 'php' => 1],
            'base_image_alias' => 'orbit-base-ubuntu-26.04-runtime',
            'cold_epoch' => 'ubuntu-26.04-amd64-v1',
            'paths' => ['contracts/*.php'],
            'schema' => 1,
        ]);
        fingerprintGit($this->path, ['add', '.']);
        fingerprintGit($this->path, ['commit', '--quiet', '-m', 'second']);
        $second = fingerprintGit($this->path, ['rev-parse', 'HEAD']);

        $fingerprints = new PreparedStateFingerprint(new GitRepository($this->path), 'resources/prepared-state.json');

        expect($fingerprints->forCommit($first)->value)
            ->toBe($fingerprints->forCommit($second)->value)
            ->and($fingerprints->forCommit($second)->manifest['paths'])
            ->toBe([
                'contracts/a.php' => hash('sha256', "contract\n"),
            ])
            ->and($fingerprints->forCommit($second)->manifest)
            ->not
            ->toHaveKey('laravel_pin')
            ->and($fingerprints->forCommit($second)->manifest['cold_epoch'])
            ->toBe('ubuntu-26.04-amd64-v1')
            ->and($fingerprints->forCommit($second)->manifest['base_image_alias'])
            ->toBe('orbit-base-ubuntu-26.04-runtime')
            ->and($fingerprints->forCommit($second)->manifest['topology']['roles'])
            ->toBe(['gateway', 'app-dev', 'app-prod'])
            ->and($fingerprints->forCommit($second)->manifest['topology']['checkout_roles'])
            ->toBe(['gateway', 'app-dev']);
    });

    it('adds the exact resolved Laravel pin only to a final fingerprint', function (): void {
        file_put_contents($this->path.'/contracts/a.php', "contract\n");
        writePreparedManifest($this->path, preparedManifest());
        fingerprintGit($this->path, ['add', '.']);
        fingerprintGit($this->path, ['commit', '--quiet', '-m', 'release']);
        $fingerprints = new PreparedStateFingerprint(new GitRepository($this->path), 'resources/prepared-state.json');
        $release = new LaravelRelease('v13.2.1', str_repeat('b', 40));
        $structural = $fingerprints->forCommit();
        $pinned = $fingerprints->withLaravel($structural, $release);

        expect($structural->manifest)
            ->not
            ->toHaveKey('laravel_pin')
            ->and($fingerprints->forCommit('HEAD', $release)->manifest['laravel_pin'])
            ->toBe(['commit' => str_repeat('b', 40), 'tag' => 'v13.2.1'])
            ->and($pinned)
            ->toEqual($fingerprints->forCommit('HEAD', $release));
    });

    it('rejects malformed manifest schema and topology', function (array $change): void {
        $manifest = preparedManifest();
        $manifest = array_replace_recursive($manifest, $change);
        writePreparedManifest($this->path, $manifest);
        file_put_contents($this->path.'/contracts/a.php', "contract\n");
        fingerprintGit($this->path, ['add', '.']);
        fingerprintGit($this->path, ['commit', '--quiet', '-m', 'invalid']);

        expect(
            fn () => new PreparedStateFingerprint(
                new GitRepository($this->path),
                'resources/prepared-state.json',
            )->forCommit(),
        )
            ->toThrow(InvalidArgumentException::class);
    })->with([
        'extra root key' => [['unexpected' => true]],
        'invalid cold epoch' => [['cold_epoch' => 'ubuntu-26.04-amd64-v0']],
        'invalid base image alias' => [['base_image_alias' => 'ubuntu:26.04']],
        'invalid declared epoch' => [['declared_epochs' => ['php' => '1']]],
        'extra Laravel pin key' => [['laravel_pin' => ['tag' => 'v13.0.0', 'commit' => str_repeat('a', 40)]]],
        'invalid profile' => [['topology' => ['profile' => 'other']]],
        'invalid roles' => [['topology' => ['roles' => ['gateway', 'app-dev', 'other']]]],
        'reordered roles' => [['topology' => ['roles' => ['app-dev', 'gateway', 'app-prod']]]],
        'invalid checkout roles' => [['topology' => ['checkout_roles' => ['gateway', 'app-prod']]]],
        'reordered checkout roles' => [['topology' => ['checkout_roles' => ['app-dev', 'gateway']]]],
        'invalid path type' => [['paths' => [42]]],
    ]);

    it('tracks the prepared guest dependency closure without lifecycle-only orchestration', function (): void {
        $path = dirname(__DIR__, 3).'/resources/prepared-state.json';
        $manifest = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $paths = $manifest['paths'] ?? null;

        $sorted = $paths;
        sort($sorted, SORT_STRING);

        expect($paths)
            ->toBe($sorted)
            ->toContain(
                'apps/e2e/app/E2E/TopologyConverger.php',
                'apps/e2e/resources/guest/converge-app-dev.sh',
                'apps/e2e/resources/guest/converge-app-prod-internal-tls.sh',
                'apps/e2e/resources/guest/converge-gateway.sh',
                'apps/e2e/resources/guest/converge-sample-app.sh',
                'apps/e2e/resources/guest/prepare-node.sh',
                'apps/cli/app/Commands/Apps/CreateAppCommand.php',
                'apps/cli/app/Commands/Instances/CreateInstanceCommand.php',
                'apps/cli/app/Commands/Nodes/ShowNodeCommand.php',
                'apps/cli/app/Commands/Workspaces/CreateWorkspaceCommand.php',
                'apps/gateway/app/Console/Commands/ProvisionNodeCommand.php',
                'apps/gateway/app/Actions/Nodes/ProvisionNodeAction.php',
                'apps/gateway/app/Data/Nodes/ProvisionNodeData.php',
                'apps/gateway/app/Infrastructure/Nodes/NativeNodeConverger.php',
                'apps/gateway/app/Infrastructure/WireGuard/NativeWireGuardPeerConverger.php',
                'apps/gateway/app/Infrastructure/AppDev/AppDevPhpFpmConfigRenderer.php',
                'apps/gateway/app/Infrastructure/AppProd/AppProdPhpFpmConfigRenderer.php',
                'apps/gateway/app/Infrastructure/AppProd/AppProdCaddyConfigRenderer.php',
                'apps/gateway/app/Infrastructure/Nodes/PhpFpmRuntimeIniRenderer.php',
                'apps/gateway/app/Infrastructure/Nodes/RemotePhpPackageManager.php',
                'apps/gateway/app/Actions/Instances/UpdateInstancePhpAction.php',
                'apps/cli/app/Commands/Instances/UpdateInstancePhpCommand.php',
                'apps/cli/app/Commands/Nodes/AddNodeRoleCommand.php',
                'packages/php-sdk/src/Requests/Instances/UpdateInstancePhpRequest.php',
                'packages/php-sdk/src/Requests/Nodes/AddNodeRoleRequest.php',
                'packages/php-sdk/src/Requests/Apps/CreateAppRequest.php',
                'packages/php-sdk/src/Requests/Instances/CreateInstanceRequest.php',
                'packages/php-sdk/src/Requests/Nodes/ShowNodeRequest.php',
                'packages/php-sdk/src/Requests/Workspaces/CreateWorkspaceRequest.php',
                // The standby carries a converged Metrics role, so anything that decides the
                // bytes on those guests is prepared state: renderers, the values they render
                // from, and the stores those values live in.
                'apps/gateway/app/Infrastructure/Metrics/PrometheusConfigRenderer.php',
                'apps/gateway/app/Infrastructure/Metrics/GrafanaConfigRenderer.php',
                'apps/gateway/app/Infrastructure/Metrics/MetricsPublicationRenderer.php',
                'apps/gateway/app/Infrastructure/Metrics/MetricsRuntimeSpec.php',
                'apps/gateway/app/Infrastructure/Metrics/MetricsExporterRuntime.php',
                'apps/gateway/app/Infrastructure/Nodes/Roles/MetricsRoleBaseline.php',
                'apps/gateway/app/Domain/Metrics/ExporterSelector.php',
                'apps/gateway/app/Domain/Metrics/ExporterPreferenceRepository.php',
                'apps/gateway/app/Infrastructure/Metrics/NativeMetricsCredentialManager.php',
            )
            ->not->toContain(
                'apps/e2e/app/E2E/StandbyBuilder.php',
                'apps/e2e/app/E2E/StandbyRefresher.php',
                'apps/e2e/app/E2E/TopologyAcquirer.php',
                'apps/e2e/app/E2E/TopologyVerifier.php',
                'apps/e2e/resources/guest/verify-topology.sh',
                'apps/e2e/resources/guest/receive-source.sh',
            );

        foreach ($paths as $selector) {
            expect($selector)->not->toMatch('/[*?\[]/');
        }
    });

    it('changes for representative command action data and SDK request inputs', function (): void {
        $inputs = [
            'apps/gateway/app/Console/Commands/ProvisionNodeCommand.php',
            'apps/gateway/app/Actions/Nodes/ProvisionNodeAction.php',
            'apps/gateway/app/Data/Nodes/ProvisionNodeData.php',
            'packages/php-sdk/src/Requests/Instances/CreateInstanceRequest.php',
        ];
        foreach ($inputs as $input) {
            putFingerprintFixtureFile($this->path, $input, "v1\n");
        }
        writePreparedManifest($this->path, array_replace(preparedManifest(), ['paths' => $inputs]));
        fingerprintGit($this->path, ['add', '.']);
        fingerprintGit($this->path, ['commit', '--quiet', '-m', 'base']);
        $fingerprints = new PreparedStateFingerprint(
            new GitRepository($this->path),
            'resources/prepared-state.json',
        );
        $previous = $fingerprints->forCommit();

        foreach ($inputs as $index => $input) {
            putFingerprintFixtureFile($this->path, $input, 'v'.($index + 2)."\n");
            fingerprintGit($this->path, ['add', $input]);
            fingerprintGit($this->path, ['commit', '--quiet', '-m', 'change-'.$index]);
            $current = $fingerprints->forCommit();

            expect($current->value)->not->toBe($previous->value);
            $previous = $current;
        }
    });

    it('requires every current manifest selector to match a tracked file', function (): void {
        $root = dirname(__DIR__, 5);
        $manifest = json_decode(
            (string) file_get_contents($root.'/apps/e2e/resources/prepared-state.json'),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        foreach ($manifest['paths'] as $selector) {
            expect(fingerprintGit($root, ['ls-files', '--', $selector]))->not->toBe('');
        }
    });

    it('changes when an included prepared-state file changes, but ignores excluded lifecycle files', function (): void {
        file_put_contents($this->path.'/apps/e2e/resources/guest/converge-gateway.sh', "converge-v1\n");
        file_put_contents($this->path.'/apps/e2e/app/E2E/TopologyVerifier.php', "verifier-v1\n");
        file_put_contents($this->path.'/apps/e2e/resources/guest/verify-topology.sh', "verify-v1\n");
        file_put_contents($this->path.'/apps/cli/app/Commands/Gateway/GatewayStatusCommand.php', "status-v1\n");
        file_put_contents($this->path.'/apps/e2e/resources/guest/receive-source.sh', "transport-v1\n");
        writePreparedManifest($this->path, array_replace(preparedManifest(), [
            'paths' => ['apps/e2e/resources/guest/converge-gateway.sh'],
        ]));
        fingerprintGit($this->path, ['add', '.']);
        fingerprintGit($this->path, ['commit', '--quiet', '-m', 'first']);
        $first = new PreparedStateFingerprint(
            new GitRepository($this->path),
            'resources/prepared-state.json',
        )->forCommit();

        file_put_contents($this->path.'/apps/e2e/app/E2E/TopologyVerifier.php', "verifier-v2\n");
        fingerprintGit($this->path, ['add', '.']);
        fingerprintGit($this->path, ['commit', '--quiet', '-m', 'verifier']);
        $verifier = new PreparedStateFingerprint(
            new GitRepository($this->path),
            'resources/prepared-state.json',
        )->forCommit();

        file_put_contents($this->path.'/apps/e2e/resources/guest/verify-topology.sh', "verify-v2\n");
        fingerprintGit($this->path, ['add', '.']);
        fingerprintGit($this->path, ['commit', '--quiet', '-m', 'verify']);
        $verify = new PreparedStateFingerprint(
            new GitRepository($this->path),
            'resources/prepared-state.json',
        )->forCommit();

        file_put_contents($this->path.'/apps/cli/app/Commands/Gateway/GatewayStatusCommand.php', "status-v2\n");
        file_put_contents($this->path.'/apps/e2e/resources/guest/receive-source.sh', "transport-v2\n");
        fingerprintGit($this->path, ['add', '.']);
        fingerprintGit($this->path, ['commit', '--quiet', '-m', 'transport']);
        $transport = new PreparedStateFingerprint(
            new GitRepository($this->path),
            'resources/prepared-state.json',
        )->forCommit();

        file_put_contents($this->path.'/apps/e2e/resources/guest/converge-gateway.sh', "converge-v2\n");
        fingerprintGit($this->path, ['add', '.']);
        fingerprintGit($this->path, ['commit', '--quiet', '-m', 'convergence']);
        $convergence = new PreparedStateFingerprint(
            new GitRepository($this->path),
            'resources/prepared-state.json',
        )->forCommit();

        expect($verifier->value)
            ->toBe($first->value)
            ->and($verify->value)
            ->toBe($first->value)
            ->and($transport->value)
            ->toBe($first->value)
            ->and($convergence->value)
            ->not->toBe($first->value);
    });
});

/** @param list<string> $arguments */
function fingerprintGit(string $path, array $arguments): string
{
    $command = array_map(escapeshellarg(...), ['git', '-C', $path, ...$arguments]);
    $output = [];
    $exitCode = 0;
    exec(implode(' ', $command), $output, $exitCode);

    if ($exitCode !== 0) {
        throw new RuntimeException('Git fingerprint fixture command failed.');
    }

    return trim(implode("\n", $output));
}

function configureFingerprintProcessFacade(): void
{
    $container = new Container;
    $container->instance(ProcessFactory::class, new ProcessFactory);
    Facade::clearResolvedInstances();
    /** @mago-expect analysis:possibly-invalid-argument The process facade only needs the container contract. */
    Facade::setFacadeApplication($container);
}

/** @param array<string, mixed> $manifest */
function writePreparedManifest(string $path, array $manifest): void
{
    file_put_contents(
        $path.'/resources/prepared-state.json',
        json_encode($manifest, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)."\n",
    );
}

function putFingerprintFixtureFile(string $root, string $path, string $contents): void
{
    $directory = dirname($root.'/'.$path);
    if (! is_dir($directory)) {
        mkdir($directory, 0700, true);
    }

    file_put_contents($root.'/'.$path, $contents);
}

/** @return array<string, mixed> */
function preparedManifest(): array
{
    return [
        'schema' => 1,
        'paths' => ['contracts/*.php'],
        'cold_epoch' => 'ubuntu-26.04-amd64-v1',
        'base_image_alias' => 'orbit-base-ubuntu-26.04-runtime',
        'declared_epochs' => ['php' => 1],
        'topology' => [
            'profile' => 'gateway_app-dev_app-prod',
            'roles' => ['gateway', 'app-dev', 'app-prod'],
            'checkout_roles' => ['gateway', 'app-dev'],
        ],
    ];
}

function removeFingerprintFixture(string $path): void
{
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );

    foreach ($iterator as $item) {
        $item->isDir() && ! $item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
    }

    rmdir($path);
}
