<?php

declare(strict_types=1);

use App\Domain\Nodes\RoleName;
use App\Infrastructure\Analytics\AnalyticsCertificatePublisher;
use App\Infrastructure\Caddy\Build\NodeCaddyfile;
use App\Infrastructure\Caddy\Build\NodeCaddyfileRenderer;
use App\Infrastructure\Caddy\Build\NodeCaddyPushScript;
use App\Infrastructure\Caddy\CaddyPublicationLock;
use App\Infrastructure\Metrics\MetricsCertificatePublisher;
use App\Infrastructure\Metrics\MetricsPublicationReceipt;
use App\Infrastructure\Metrics\NativeMetricsAccessRevoker;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Processes\ProcessInvocation;
use App\Infrastructure\Processes\ProcessRunner;
use App\Infrastructure\ProxyCli\ProxyCliCertificatePublisher;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\WebSocket\WebSocketCertificatePublisher;
use App\Models\Node;
use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Process\Process;

describe('every Caddy writer', function (): void {
    it('serializes on the shared hardened lock before it changes or reloads Caddy', function (Closure $programs): void {
        foreach ($programs() as ['arguments' => $arguments, 'program' => $program]) {
            $lock = mb_strpos(haystack: $program, needle: 'flock -w 30 9');
            $firstChange = min(array_filter([
                mb_strpos(haystack: $program, needle: 'readlink -f'),
                mb_strpos(haystack: $program, needle: 'mv -f'),
                mb_strpos(haystack: $program, needle: 'if [ ! -e "$versions" ]'),
                mb_strpos(haystack: $program, needle: 'systemctl'),
            ], is_int(...)));

            expect($program)
                ->toContain(CaddyPublicationLock::script())
                ->not->toContain('orbit-caddy.lock')
                ->and(mb_substr_count(haystack: $program, needle: 'flock -w 30 9'))->toBe(1)
                ->and($lock)->toBeLessThan($firstChange)
                ->and(
                    in_array(CaddyPublicationLock::Path, $arguments, true)
                    || str_contains($program, 'lock='.escapeshellarg(CaddyPublicationLock::Path)),
                )->toBeTrue();
        }
    })->with([
        'node caddy build' => [fn (): array => caddy_lock_remote_programs([
            new NodeCaddyPushScript()->command(caddy_lock_caddyfile()),
        ])],
        'metrics certificate' => [fn (): array => caddy_lock_metrics_programs()],
        'metrics access reload' => [fn (): array => caddy_lock_access_programs()],
        'websocket certificate' => [fn (): array => caddy_lock_remote_programs([
            new WebSocketCertificatePublisher()->command('certificate', 'key'),
        ])],
        'proxycli certificate' => [fn (): array => caddy_lock_remote_programs([
            new ProxyCliCertificatePublisher()->command('certificate', 'key'),
        ])],
        'analytics certificate' => [fn (): array => caddy_lock_remote_programs([
            new AnalyticsCertificatePublisher()->command('certificate', 'key'),
        ])],
    ]);

    it('is listed above whenever its program reloads Caddy', function (): void {
        $reloaders = caddy_lock_source_files(
            fn (string $source): bool => preg_match('/systemctl (reload|reload-or-restart)[^\n]*caddy/', $source) === 1,
        );

        expect($reloaders)->toEqualCanonicalizing([
            'Analytics/AnalyticsCertificatePublisher.php',
            'AppDev/RemoteAppDevCertificateManager.php',
            'Caddy/Build/NodeCaddyPushScript.php',
            'Gateway/NativeGatewayCertificatePublisher.php',
            'Metrics/MetricsCertificatePublisher.php',
            'Metrics/NativeMetricsAccessRevoker.php',
            'ProxyCli/ProxyCliCertificatePublisher.php',
            'WebSocket/WebSocketCertificatePublisher.php',
        ]);

        foreach ($reloaders as $reloader) {
            expect(str_contains((string) file_get_contents(app_path('Infrastructure/'.$reloader)), 'CaddyPublicationLock'))->toBeTrue($reloader);
        }
    });

    it('is the only program that swaps the live Caddyfile', function (): void {
        $swappers = caddy_lock_source_files(
            fn (string $source): bool => str_contains($source, 'Caddyfile')
                && preg_match('/mv -fT -- \S+ "\\\\?\$(live|live_caddyfile)"/', $source) === 1,
        );

        expect($swappers)->toBe(['Caddy/Build/NodeCaddyPushScript.php']);
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

function caddy_lock_caddyfile(): NodeCaddyfile
{
    $content = NodeCaddyfileRenderer::Marker."\n";

    return new NodeCaddyfile('app-dev', $content, NodeCaddyfileRenderer::version($content), [], []);
}

/** @return list<array{arguments: list<string>, program: string}> */
function caddy_lock_metrics_programs(): array
{
    $processes = caddy_lock_process_runner();
    $certificate = new MetricsCertificatePublisher($processes);

    $certificate->restore(MetricsPublicationReceipt::created());
    $certificate->remove();

    return caddy_lock_invocation_programs($processes->invocations);
}

/** @return list<array{arguments: list<string>, program: string}> */
function caddy_lock_access_programs(): array
{
    $processes = caddy_lock_process_runner();
    Node::query()->create([
        'name' => 'metrics',
        'status' => 'active',
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.3',
        'user' => 'orbit',
        'wireguard_ip' => '10.44.0.3',
    ])->roles()->create(['role' => RoleName::Metrics, 'status' => 'active']);

    new NativeMetricsAccessRevoker($processes)->revoke();

    return caddy_lock_invocation_programs($processes->invocations);
}

function caddy_lock_process_runner(): object
{
    return new class implements ProcessRunner
    {
        /** @var list<ProcessInvocation> */
        public array $invocations = [];

        public function run(ProcessInvocation $invocation): CommandResult
        {
            $this->invocations[] = $invocation;

            return new CommandResult(0, 'orbit-metrics-publication:unchanged', '', 1, false);
        }
    };
}

/**
 * @param  list<ProcessInvocation>  $invocations
 * @return list<array{arguments: list<string>, program: string}>
 */
function caddy_lock_invocation_programs(array $invocations): array
{
    return array_map(
        fn (ProcessInvocation $invocation): array => [
            'arguments' => $invocation->arguments,
            'program' => (string) $invocation->input,
        ],
        $invocations,
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
