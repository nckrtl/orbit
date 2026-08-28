<?php

declare(strict_types=1);

use App\E2E\Git\GitRepository;
use App\E2E\PreparedStateFingerprint;
use Illuminate\Container\Container;
use Illuminate\Process\Factory as ProcessFactory;
use Illuminate\Support\Facades\Facade;

/** @property string $path */
describe('PreparedStateFingerprint', function (): void {
    beforeEach(function (): void {
        configureFingerprintProcessFacade();
        $this->path = sys_get_temp_dir().'/orbit-fingerprint-'.bin2hex(random_bytes(6));
        mkdir($this->path.'/resources', 0700, true);
        mkdir($this->path.'/contracts', 0700, true);
        fingerprintGit($this->path, ['init', '--quiet']);
        fingerprintGit($this->path, ['config', 'user.email', 'orbit@example.test']);
        fingerprintGit($this->path, ['config', 'user.name', 'Orbit']);
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
