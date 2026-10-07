<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\GatewayReleases\GatewayReleaseLayout;
use App\Domain\GatewayReleases\GatewayReleaseWebBuild;
use App\Domain\GitHub\RepositoryReadAccess;
use App\Infrastructure\GatewayReleases\GatewayReleaseBuilder;
use App\Infrastructure\GatewayReleases\NoGatewayReleaseWebBuild;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Processes\NativeProcessRunner;
use App\Infrastructure\Processes\ProcessInvocation;
use App\Infrastructure\Processes\ProcessRunner;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * A real release layout in a temporary directory: an origin repository with a Gateway-shaped tree,
 * the shared bare repository cloned from it, the shared env file, and a stand-in `composer` that
 * writes `vendor/autoload.php`. Git and the filesystem are real. `sudo` commands are recorded and
 * succeed without running.
 */
final class GatewayReleaseFixture implements ProcessRunner
{
    /** Keeps the developer's Git configuration, such as an fsmonitor daemon, out of the fixture. */
    private const array Isolated = ['GIT_CONFIG_GLOBAL' => '/dev/null', 'GIT_CONFIG_NOSYSTEM' => '1'];

    public readonly string $base;

    public readonly string $origin;

    public readonly string $composer;

    public readonly GatewayReleaseLayout $layout;

    /** @var list<list<string>> */
    public array $privileged = [];

    /** @var list<list<string>> */
    public array $commands = [];

    private readonly NativeProcessRunner $native;

    public function __construct()
    {
        $this->base = sys_get_temp_dir().'/orbit-release-'.Str::lower(Str::random(10));
        $this->origin = $this->base.'/origin';
        $this->composer = $this->base.'/bin/composer';
        $this->native = new NativeProcessRunner(maxOutputBytes: 1_048_576);
        $this->layout = new GatewayReleaseLayout($this->base.'/orbit/apps/gateway');

        mkdir($this->base.'/bin', 0700, true);
        file_put_contents($this->composer, <<<'BASH'
            #!/usr/bin/env bash
            set -eu
            directory=
            for argument in "$@"; do
                case "$argument" in --working-dir=*) directory=${argument#--working-dir=} ;; esac
            done
            [ ! -e "$directory/composer.fail" ] || { echo 'dependency resolution failed' >&2; exit 1; }
            case " $* " in
                *' install '*) mkdir -p "$directory/vendor"; echo '<?php' > "$directory/vendor/autoload.php" ;;
            esac
            BASH);
        chmod($this->composer, 0700);

        $this->git($this->base, 'init', '--quiet', '--initial-branch=main', $this->origin);
        $this->write('.gitignore', "/vendor/\n/REVISION\napps/*/vendor/\napps/gateway/.env\n");
        $this->write('apps/cli/composer.json', '{}');
        $this->write('apps/gateway/composer.json', '{}');
        $this->write('apps/gateway/public/index.php', "<?php\n");
        $this->write('apps/gateway/artisan', <<<'PHP'
            <?php
            // Stands in for `artisan config:cache`: caches the version that the release's REVISION names.
            $root = dirname(__DIR__, 2);
            if (is_file(__DIR__.'/config.fail')) {
                fwrite(STDERR, "configuration failed\n");
                exit(1);
            }
            $revision = is_file($root.'/REVISION') ? trim(file_get_contents($root.'/REVISION')) : 'dev';
            file_put_contents(__DIR__.'/bootstrap/cache/config.php', '<?php return '.var_export(['app' => ['version' => $revision]], true).';');
            PHP);
        $this->write('apps/gateway/storage/logs/.gitignore', "*\n!.gitignore\n");
        $this->write('apps/gateway/storage/framework/cache/.gitignore', "*\n!.gitignore\n");
        $this->write('apps/gateway/bootstrap/cache/.gitignore', "*\n!.gitignore\n");
        $this->commit('Initial Gateway');

        mkdir($this->layout->sharedPath(), 0700, true);
        $this->git($this->base, 'clone', '--quiet', '--bare', $this->origin, $this->layout->repositoryPath());
        file_put_contents($this->layout->environmentPath(), "APP_ENV=production\n");
    }

    public function builder(?GatewayReleaseWebBuild $web = null, float $freeBytes = 1e12): GatewayReleaseBuilder
    {
        return new GatewayReleaseBuilder(
            layout: $this->layout,
            processes: $this,
            readAccess: app(RepositoryReadAccess::class),
            web: $web ?? new NoGatewayReleaseWebBuild,
            composer: $this->composer,
            php: PHP_BINARY,
            freeSpace: static fn (): float => $freeBytes,
            checkoutAccess: function (string $application): void {
                $this->privileged[] = ['grant-access', $application];
            },
        );
    }

    public function write(string $path, string $contents): void
    {
        $file = $this->origin.'/'.$path;
        @mkdir(dirname($file), 0755, true);
        file_put_contents($file, $contents);
    }

    public function commit(string $message): string
    {
        $this->git($this->origin, 'add', '--all');
        $this->git($this->origin, '-c', 'user.name=Orbit', '-c', 'user.email=orbit@example.test', 'commit', '--quiet', '--allow-empty', '-m', $message);

        return trim($this->git($this->origin, 'rev-parse', 'HEAD'));
    }

    public function run(ProcessInvocation $invocation): CommandResult
    {
        $this->commands[] = $invocation->arguments;

        if ($invocation->arguments[0] === 'sudo') {
            $this->privileged[] = $invocation->arguments;

            return new CommandResult(0, '', '', 1, false);
        }

        return $this->native->run(new ProcessInvocation(
            arguments: $invocation->arguments,
            timeout: $invocation->timeout,
            input: $invocation->input,
            protectedInput: $invocation->protectedInput,
            environment: [...$invocation->environment, ...self::Isolated],
        ));
    }

    public function cleanup(): void
    {
        if (is_dir($this->base)) {
            exec('chmod -R u+w '.escapeshellarg($this->base).' && rm -rf '.escapeshellarg($this->base));
        }
    }

    private function git(string $directory, string ...$arguments): string
    {
        $result = $this->native->run(new ProcessInvocation(['git', '-C', $directory, ...$arguments], timeout: 60.0, environment: self::Isolated));

        if (! $result->succeeded()) {
            throw new RuntimeException('git '.implode(' ', $arguments).' failed: '.$result->stderr);
        }

        return $result->stdout;
    }
}
