<?php

declare(strict_types=1);

use App\Infrastructure\Analytics\AnalyticsCaddyPublisher;
use App\Infrastructure\Analytics\AnalyticsCertificatePublisher;
use App\Infrastructure\AppDev\AppDevCaddyPublisher;
use App\Infrastructure\AppProd\AppProdCaddyPublisher;
use App\Infrastructure\Caddy\CaddyPublicationLock;
use App\Infrastructure\Herdr\RemoteHerdrObserverSitePublisher;
use App\Infrastructure\Metrics\MetricsCaddyPublisher;
use App\Infrastructure\Metrics\MetricsCertificatePublisher;
use App\Infrastructure\Metrics\MetricsPublicationReceipt;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Processes\ProcessInvocation;
use App\Infrastructure\Processes\ProcessRunner;
use App\Infrastructure\ProxyCli\ProxyCliCaddyPublisher;
use App\Infrastructure\ProxyCli\ProxyCliCertificatePublisher;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\WebSocket\WebSocketCaddyPublisher;
use App\Infrastructure\WebSocket\WebSocketCertificatePublisher;
use App\Models\HerdrSession;
use App\Models\Node;
use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Process\Process;
use Tests\Support\AppDevFakeSshExecutor;

describe('every Caddy publisher', function (): void {
    it('serializes on the shared hardened lock before it reads live Caddy state', function (Closure $programs): void {
        foreach ($programs() as ['arguments' => $arguments, 'program' => $program]) {
            $lock = mb_strpos(haystack: $program, needle: 'flock -w 30 9');
            $firstRead = min(array_filter([
                mb_strpos(haystack: $program, needle: 'readlink -f'),
                mb_strpos(haystack: $program, needle: 'mv -f'),
                mb_strpos(haystack: $program, needle: 'if [ ! -e "$versions" ]'),
            ], is_int(...)));

            expect($program)
                ->toContain(CaddyPublicationLock::script())
                ->not->toContain('orbit-caddy.lock')
                ->and(mb_substr_count(haystack: $program, needle: 'flock -w 30 9'))->toBe(1)
                ->and($lock)->toBeLessThan($firstRead)
                ->and(
                    in_array(CaddyPublicationLock::Path, $arguments, true)
                    || str_contains($program, 'lock='.escapeshellarg(CaddyPublicationLock::Path)),
                )->toBeTrue();
        }
    })->with([
        'app-dev' => [fn (): array => caddy_lock_remote_programs([
            new AppDevCaddyPublisher()->command('# app-dev', 'app-dev-version'),
            new AppDevCaddyPublisher()->removeCommand('app-dev-version'),
        ])],
        'app-prod' => [fn (): array => caddy_lock_remote_programs([
            new AppProdCaddyPublisher()->command('# app-prod', 'app-prod-version'),
            new AppProdCaddyPublisher()->removeCommand('app-prod-version'),
        ])],
        'herdr observer' => [fn (): array => caddy_lock_herdr_programs()],
        'metrics' => [fn (): array => caddy_lock_metrics_programs()],
        'websocket' => [fn (): array => caddy_lock_remote_programs([
            new WebSocketCaddyPublisher()->command('# websocket', '8080', '10.44.0.8'),
            new WebSocketCaddyPublisher()->removeCommand(),
            new WebSocketCertificatePublisher()->command('certificate', 'key'),
        ])],
        'proxycli' => [fn (): array => caddy_lock_remote_programs([
            new ProxyCliCaddyPublisher()->command('# proxycli', '8081', '10.44.0.8'),
            new ProxyCliCaddyPublisher()->removeCommand(),
            new ProxyCliCertificatePublisher()->command('certificate', 'key'),
        ])],
        'analytics' => [fn (): array => caddy_lock_remote_programs([
            new AnalyticsCaddyPublisher()->command('# analytics', '8082', '10.44.0.8'),
            new AnalyticsCaddyPublisher()->removeCommand(),
            new AnalyticsCertificatePublisher()->command('certificate', 'key'),
        ])],
    ]);

    it('is listed above whenever its program swaps the live Caddyfile', function (): void {
        $swappers = caddy_lock_source_files(fn (string $source): bool => str_contains($source, 'live_caddyfile'));

        expect($swappers)->toEqualCanonicalizing([
            'Analytics/AnalyticsCaddyPublisher.php',
            'AppDev/AppDevCaddyPublisher.php',
            'AppProd/AppProdCaddyPublisher.php',
            'Herdr/RemoteHerdrObserverSitePublisher.php',
            'Metrics/MetricsCaddyPublisher.php',
            'ProxyCli/ProxyCliCaddyPublisher.php',
            'WebSocket/WebSocketCaddyPublisher.php',
        ]);
    });

    it('names no Caddy lock other than the shared one', function (): void {
        $locks = caddy_lock_source_files(
            fn (string $source): bool => preg_match('#/run/lock/[^\s\'"]*caddy#i', $source) === 1,
        );

        expect($locks)->toBe(['Caddy/CaddyPublicationLock.php']);
    });
});

describe('the shared Caddy lock program', function (): void {
    it('creates a private lock directory and file and takes the lock', function (): void {
        $root = caddy_lock_directory();

        try {
            $result = caddy_lock_run($root);

            expect($result->getExitCode())->toBe(0)
                ->and($result->getOutput())->toBe("locked\n")
                ->and(fileperms($root.'/orbit') & 0o777)->toBe(0o700)
                ->and(fileperms($root.'/orbit/caddy.lock') & 0o777)->toBe(0o600);
        } finally {
            new Filesystem()->deleteDirectory($root);
        }
    });

    it('refuses a lock directory other users can enter', function (): void {
        $root = caddy_lock_directory();
        mkdir($root.'/orbit', 0o755);
        chmod($root.'/orbit', 0o755);

        try {
            $result = caddy_lock_run($root);

            expect($result->getExitCode())->not->toBe(0)
                ->and($result->getOutput())->toBe('');
        } finally {
            new Filesystem()->deleteDirectory($root);
        }
    });

    it('refuses a symlinked lock file', function (): void {
        $root = caddy_lock_directory();
        mkdir($root.'/orbit', 0o700);
        touch($root.'/elsewhere');
        symlink($root.'/elsewhere', $root.'/orbit/caddy.lock');

        try {
            $result = caddy_lock_run($root);

            expect($result->getExitCode())->not->toBe(0)
                ->and($result->getOutput())->toBe('');
        } finally {
            new Filesystem()->deleteDirectory($root);
        }
    });
})->skip(PHP_OS_FAMILY !== 'Linux', 'Uses GNU stat and the Linux flock utility.');

/**
 * @param  list<RemoteCommand>  $commands
 * @return list<array{arguments: list<string>, program: string}>
 */
function caddy_lock_remote_programs(array $commands): array
{
    return array_map(
        fn (RemoteCommand $command): array => [
            'arguments' => $command->arguments,
            'program' => $command->input ?? (string) stream_get_contents($command->protectedInput?->stream()),
        ],
        $commands,
    );
}

/** @return list<array{arguments: list<string>, program: string}> */
function caddy_lock_herdr_programs(): array
{
    $transport = new AppDevFakeSshExecutor;
    app()->instance(SshExecutor::class, $transport);
    $node = new Node(['name' => 'beast', 'wireguard_ip' => '10.44.0.8', 'user' => 'nckrtl']);
    $session = new HerdrSession(['session' => 'commander-tasks', 'user' => 'nckrtl', 'observer_port' => 7411]);
    $session->id = 12;
    $session->setRelation('node', $node);

    app(RemoteHerdrObserverSitePublisher::class)->retract($session, $node);

    return caddy_lock_remote_programs($transport->commands);
}

/** @return list<array{arguments: list<string>, program: string}> */
function caddy_lock_metrics_programs(): array
{
    $processes = new class implements ProcessRunner
    {
        /** @var list<ProcessInvocation> */
        public array $invocations = [];

        public function run(ProcessInvocation $invocation): CommandResult
        {
            $this->invocations[] = $invocation;

            return new CommandResult(0, 'orbit-metrics-publication:unchanged', '', 1, false);
        }
    };
    $caddy = new MetricsCaddyPublisher($processes);
    $certificate = new MetricsCertificatePublisher($processes);

    $caddy->publish("# Managed by Orbit: metrics\n");
    $caddy->withdrawForCutover();
    $certificate->restore(MetricsPublicationReceipt::created());
    $certificate->remove();

    return array_map(
        fn (ProcessInvocation $invocation): array => [
            'arguments' => $invocation->arguments,
            'program' => (string) $invocation->input,
        ],
        $processes->invocations,
    );
}

/**
 * @param  Closure(string): bool  $matches
 * @return list<string>
 */
function caddy_lock_source_files(Closure $matches): array
{
    $files = [];

    foreach (Finder::create()->files()->in(app_path('Infrastructure'))->name('*.php') as $file) {
        if ($matches($file->getContents())) {
            $files[] = $file->getRelativePathname();
        }
    }

    sort($files);

    return $files;
}

function caddy_lock_directory(): string
{
    $root = sys_get_temp_dir().'/orbit-caddy-lock-'.bin2hex(random_bytes(8));
    mkdir($root, 0o700);

    return $root;
}

/** Runs the lock program with the hardened checks aimed at a temporary lock owned by this user. */
function caddy_lock_run(string $root): Process
{
    $lock = $root.'/orbit/caddy.lock';
    $owner = posix_geteuid().':'.posix_getegid();
    $program = str_replace(
        [CaddyPublicationLock::Path, '0:0'],
        [$lock, $owner],
        CaddyPublicationLock::script($lock),
    );
    $process = new Process(['bash', '-seu']);
    $process->setInput($program."\necho locked\n");
    $process->run();

    return $process;
}
