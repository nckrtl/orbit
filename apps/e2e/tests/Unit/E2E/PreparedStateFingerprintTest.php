<?php

declare(strict_types=1);

use App\E2E\Git\GitRepository;
use App\E2E\PreparedStateFingerprint;
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
        $this->path = sys_get_temp_dir().'/orbit-fingerprint-'.bin2hex(random_bytes(6));
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

    it('canonicalizes prepared input and excludes source identity', function (): void {
        file_put_contents($this->path.'/contracts/a.php', "contract\n");
        writePreparedManifest($this->path, [
            'schema' => 1,
            'paths' => ['contracts/*.php'],
            'cold_epoch' => 'ubuntu-26.04-amd64-v1',
            'base_image_alias' => 'orbit-base-ubuntu-26.04-runtime',
            'declared_epochs' => ['php' => 1, 'base_image' => 2],
            'laravel_pin' => ['tag' => 'v13.2.1', 'commit' => str_repeat('a', 40)],
            'topology' => [
                'roles' => ['app-prod', 'gateway', 'app-dev'],
                'profile' => 'gateway_app-dev_app-prod',
                'checkout_roles' => ['app-dev', 'gateway'],
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
            'laravel_pin' => ['commit' => str_repeat('a', 40), 'tag' => 'v13.2.1'],
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
            ->and($fingerprints->forCommit($second)->manifest['laravel_pin'])
            ->toBe([
                'commit' => str_repeat('a', 40),
                'tag' => 'v13.2.1',
            ])
            ->and($fingerprints->forCommit($second)->manifest['cold_epoch'])
            ->toBe('ubuntu-26.04-amd64-v1')
            ->and($fingerprints->forCommit($second)->manifest['base_image_alias'])
            ->toBe('orbit-base-ubuntu-26.04-runtime');
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
        'invalid cold epoch' => [['cold_epoch' => 'ubuntu-24.04-amd64-v1']],
        'invalid base image alias' => [['base_image_alias' => 'ubuntu:26.04']],
        'invalid declared epoch' => [['declared_epochs' => ['php' => '1']]],
        'invalid Laravel tag' => [['laravel_pin' => ['tag' => '13.0.0']]],
        'invalid Laravel commit' => [['laravel_pin' => ['commit' => 'main']]],
        'invalid profile' => [['topology' => ['profile' => 'other']]],
        'invalid roles' => [['topology' => ['roles' => ['gateway', 'app-dev', 'other']]]],
        'invalid checkout roles' => [['topology' => ['checkout_roles' => ['gateway', 'app-prod']]]],
        'invalid path type' => [['paths' => [42]]],
    ]);

    it('tracks the prepared guest dependency closure without lifecycle-only orchestration', function (): void {
        $path = dirname(__DIR__, 3).'/resources/prepared-state.json';
        $manifest = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $paths = $manifest['paths'] ?? null;

        expect($paths)
            ->toBeArray()
            ->toContain(
                'apps/e2e/app/E2E/TopologyConverger.php',
                'apps/e2e/resources/guest/converge-app-dev.sh',
                'apps/e2e/resources/guest/converge-app-prod-internal-tls.sh',
                'apps/e2e/resources/guest/converge-gateway.sh',
                'apps/e2e/resources/guest/converge-sample-app.sh',
                'apps/gateway/app/Actions/Gateway/BootstrapGatewayAction.php',
                'apps/gateway/app/Actions/Gateway/GatewayBootstrapIdentityValidator.php',
                'apps/gateway/app/Infrastructure/Nodes/Roles/*.php',
                'apps/gateway/app/Infrastructure/WireGuard/*.php',
                'apps/gateway/app/Http/Middleware/RequireActiveWireGuardPeer.php',
                'apps/gateway/app/Http/Middleware/RequireNodeAccess.php',
                'packages/php-sdk/src/Requests/Instances/CreateInstanceRequest.php',
            )
            ->not->toContain(
                'apps/e2e/app/E2E/StandbyBuilder.php',
                'apps/e2e/app/E2E/StandbyRefresher.php',
                'apps/e2e/app/E2E/TopologyAcquirer.php',
                'apps/e2e/app/E2E/TopologyVerifier.php',
                'apps/e2e/app/E2E/State/*.php',
                'apps/e2e/resources/guest/prepare-node.sh',
                'apps/e2e/resources/guest/verify-topology.sh',
                'apps/e2e/resources/guest/hydrate-orbit.sh',
                'apps/e2e/resources/guest/receive-source.sh',
                'apps/cli/app/Commands/Gateway/GatewayStatusCommand.php',
                'apps/gateway/app/Console/Commands/*.php',
                'apps/gateway/app/Http/Controllers/Api/GatewayStatusesController.php',
                'packages/php-sdk/src/Requests/Gateway/ShowGatewayStatusRequest.php',
                'packages/php-sdk/src/Responses/Gateway/GatewayStatusResponse.php',
            );
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

/** @return array<string, mixed> */
function preparedManifest(): array
{
    return [
        'schema' => 1,
        'paths' => ['contracts/*.php'],
        'cold_epoch' => 'ubuntu-26.04-amd64-v1',
        'base_image_alias' => 'orbit-base-ubuntu-26.04-runtime',
        'declared_epochs' => ['php' => 1],
        'laravel_pin' => ['tag' => 'v13.0.0', 'commit' => str_repeat('a', 40)],
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
