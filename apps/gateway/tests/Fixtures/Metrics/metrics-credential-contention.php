<?php

declare(strict_types=1);

use App\Domain\Metrics\MetricsCredentialOperationLock;
use App\Domain\Metrics\MetricsCredentialRuntime;
use App\Domain\Nodes\RoleName;
use App\Domain\Settings\SettingRepository;
use App\Domain\Settings\SettingScope;
use App\Domain\Settings\SettingScopeType;
use App\Domain\Settings\SettingValueProtection;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Metrics\MetricsRuntimeHost;
use App\Infrastructure\Metrics\MetricsService;
use App\Infrastructure\Metrics\NativeMetricsCredentialManager;
use App\Infrastructure\Processes\CommandDeadline;
use App\Models\Node;
use App\Models\NodeRole;
use App\Models\Setting;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

const ORB170_BARRIER_TIMEOUT_SECONDS = 10.0;

$base = dirname(__DIR__, 3);
require "{$base}/vendor/autoload.php";
$app = require "{$base}/bootstrap/app.php";
$app->make(Kernel::class)->bootstrap();

$mode = $argv[1] ?? 'run';
$barrierDirectory = $argv[2] ?? null;

if ($mode !== 'run' && $mode !== 'inspect') {
    exit(orb170RunWorker($mode, $barrierDirectory));
}

$settings = app(SettingRepository::class);
$runtime = app(MetricsCredentialRuntime::class);
$node = orb170AssignedMetricsNode();
$scope = orb170Scope($node);
$original = $settings->get($scope, NativeMetricsCredentialManager::ActivePasswordKey);
$directory = sys_get_temp_dir().'/orbit-metrics-credential-contention-'.bin2hex(random_bytes(8));
$summary = [
    'status' => 'failed',
    'baseline' => orb170Inspect($node, $settings, $runtime),
    'checks' => [],
    'cleanup' => 'not-run',
];
$exitCode = 1;

if ($mode === 'inspect') {
    echo json_encode($summary['baseline'], JSON_THROW_ON_ERROR).PHP_EOL;
    exit(0);
}

try {
    orb170Assert(is_string($original) && $original !== '', 'The active Metrics credential is missing.');
    orb170Assert($summary['baseline']['grafana_healthy'] === true, 'Grafana is not healthy.');
    orb170Assert($summary['baseline']['active_authenticates'] === true, 'The active credential does not authenticate.');
    orb170Assert($summary['baseline']['active_encrypted'] === true, 'The active credential is not encrypted.');
    orb170Assert($summary['baseline']['pending_present'] === false, 'A pending credential already exists.');
    (new Filesystem)->ensureDirectoryExists($directory, 0o700);

    $settings->delete($scope, NativeMetricsCredentialManager::ActivePasswordKey);
    $initialOwner = orb170StartWorker('initialize-paused', $directory);
    orb170WaitForFile("{$directory}/owned", $initialOwner);
    $initialContender = orb170RunProcess('initialize', $directory);
    orb170AssertWorkerBusy($initialContender, 'initial credential contender');
    orb170Assert(
        $settings->get($scope, NativeMetricsCredentialManager::ActivePasswordKey) === null,
        'The refused initial contender changed credential state.',
    );
    touch("{$directory}/release");
    orb170AssertWorkerSucceeded($initialOwner, 'initial credential owner');
    orb170ResetBarrier($directory);
    $initialWaiter = orb170RunProcess('initialize', $directory);
    orb170AssertWorkerSucceeded($initialWaiter, 'initial credential waiter');
    $initialCredential = $settings->get($scope, NativeMetricsCredentialManager::ActivePasswordKey);
    $initialWaiterResult = orb170WorkerResult($initialWaiter);
    orb170Assert(
        is_string($initialCredential)
            && $initialCredential !== ''
            && ($initialWaiterResult['reused_persisted'] ?? false) === true,
        'Initial credential waiters did not reuse authoritative state.',
    );
    $summary['checks'][] = 'first-creation: contender refused without mutation; waiter reused persisted state';
    orb170RestoreCredential($node, $settings, $runtime, $original);

    $resetOwner = orb170StartWorker('reset-paused', $directory);
    orb170WaitForFile("{$directory}/applied", $resetOwner);
    $pendingDuringReset = $settings->get($scope, NativeMetricsCredentialManager::PendingPasswordKey);
    $activeDuringReset = $settings->get($scope, NativeMetricsCredentialManager::ActivePasswordKey);
    orb170Assert(is_string($pendingDuringReset) && $pendingDuringReset !== '', 'Reset did not retain pending state.');
    orb170Assert($activeDuringReset === $original, 'Reset promoted before authentication.');
    $resetContender = orb170RunProcess('reset', $directory);
    orb170AssertWorkerBusy($resetContender, 'reset contender');
    orb170Assert(
        $settings->get($scope, NativeMetricsCredentialManager::PendingPasswordKey) === $pendingDuringReset
            && $settings->get($scope, NativeMetricsCredentialManager::ActivePasswordKey) === $original,
        'The refused reset contender changed credential state.',
    );
    touch("{$directory}/release");
    orb170AssertWorkerSucceeded($resetOwner, 'reset owner');
    orb170ResetBarrier($directory);
    $activeAfterReset = $settings->get($scope, NativeMetricsCredentialManager::ActivePasswordKey);
    orb170Assert(
        is_string($activeAfterReset)
            && $activeAfterReset === $pendingDuringReset
            && $settings->get($scope, NativeMetricsCredentialManager::PendingPasswordKey) === null
            && $runtime->verify($node, $activeAfterReset),
        'The reset owner did not promote authenticated pending state.',
    );
    $summary['checks'][] = 'reset/reset: contender refused; owner authenticated and promoted pending state';

    $resetBeforePurge = orb170StartWorker('reset-paused', $directory);
    orb170WaitForFile("{$directory}/applied", $resetBeforePurge);
    $purgeContender = orb170RunProcess('purge', $directory);
    orb170AssertWorkerBusy($purgeContender, 'purge contender');
    $pendingBeforePurgeRelease = $settings->get($scope, NativeMetricsCredentialManager::PendingPasswordKey);
    orb170Assert(
        is_string($pendingBeforePurgeRelease) && $pendingBeforePurgeRelease !== '',
        'The refused purge removed pending state.',
    );
    touch("{$directory}/release");
    orb170AssertWorkerSucceeded($resetBeforePurge, 'reset-before-purge owner');
    orb170ResetBarrier($directory);
    $credentialBeforeSuccessfulPurge = $settings->get($scope, NativeMetricsCredentialManager::ActivePasswordKey);
    $successfulPurge = orb170RunProcess('purge-normal', $directory);
    orb170AssertWorkerSucceeded($successfulPurge, 'purge waiter');
    orb170Assert(
        $settings->get($scope, NativeMetricsCredentialManager::ActivePasswordKey) === null
            && $settings->get($scope, NativeMetricsCredentialManager::PendingPasswordKey) === null,
        'Purge did not delete both credential settings.',
    );
    orb170Assert(
        is_string($credentialBeforeSuccessfulPurge) && $runtime->verify($node, $credentialBeforeSuccessfulPurge),
        'Purge changed the Grafana credential.',
    );
    $settings->put(
        $scope,
        NativeMetricsCredentialManager::ActivePasswordKey,
        $credentialBeforeSuccessfulPurge,
        SettingValueProtection::Secret,
    );
    $summary['checks'][] = 'reset/purge: purge refused while reset owned; later purge deleted only settings';

    $credentialBeforePurgeOwner = $settings->get($scope, NativeMetricsCredentialManager::ActivePasswordKey);
    $purgeOwner = orb170StartWorker('purge-paused', $directory);
    orb170WaitForFile("{$directory}/owned", $purgeOwner);
    $resetDuringPurge = orb170RunProcess('reset', $directory);
    orb170AssertWorkerBusy($resetDuringPurge, 'reset during purge');
    orb170Assert(
        $settings->get($scope, NativeMetricsCredentialManager::ActivePasswordKey) === $credentialBeforePurgeOwner,
        'The refused reset changed state before purge.',
    );
    touch("{$directory}/release");
    orb170AssertWorkerSucceeded($purgeOwner, 'purge owner');
    orb170ResetBarrier($directory);
    orb170Assert(
        $settings->get($scope, NativeMetricsCredentialManager::ActivePasswordKey) === null
            && $settings->get($scope, NativeMetricsCredentialManager::PendingPasswordKey) === null,
        'The purge owner did not remain authoritative.',
    );
    $resetAfterPurge = orb170RunProcess('reset-normal', $directory);
    $resetAfterPurgeResult = orb170WorkerResult($resetAfterPurge);
    orb170Assert(
        $resetAfterPurge->getExitCode() === 70
            && ($resetAfterPurgeResult['code'] ?? null) === 'metrics.credentials_missing'
            && $settings->get($scope, NativeMetricsCredentialManager::ActivePasswordKey) === null
            && $settings->get($scope, NativeMetricsCredentialManager::PendingPasswordKey) === null,
        'A reset that followed purge restored credential state.',
    );
    orb170Assert(
        is_string($credentialBeforePurgeOwner) && $runtime->verify($node, $credentialBeforePurgeOwner),
        'Purge unexpectedly changed Grafana authentication.',
    );
    $settings->put(
        $scope,
        NativeMetricsCredentialManager::ActivePasswordKey,
        $credentialBeforePurgeOwner,
        SettingValueProtection::Secret,
    );
    $summary['checks'][] = 'purge/reset: reset refused while purge owned and could not restore state afterward';

    $activeBeforeFailure = $settings->get($scope, NativeMetricsCredentialManager::ActivePasswordKey);
    $failedReset = orb170RunProcess('reset-fail-after-apply', $directory);
    $failedResetResult = orb170WorkerResult($failedReset);
    orb170Assert(
        $failedReset->getExitCode() === 70
            && ($failedResetResult['message'] ?? null) === 'Injected post-apply failure.',
        'The injected post-apply failure did not occur.',
    );
    $pendingAfterFailure = $settings->get($scope, NativeMetricsCredentialManager::PendingPasswordKey);
    orb170Assert(
        is_string($activeBeforeFailure)
            && is_string($pendingAfterFailure)
            && $pendingAfterFailure !== ''
            && $settings->get($scope, NativeMetricsCredentialManager::ActivePasswordKey) === $activeBeforeFailure
            && ! $runtime->verify($node, $activeBeforeFailure)
            && $runtime->verify($node, $pendingAfterFailure),
        'Post-apply failure did not retain recoverable pending state.',
    );
    $retry = orb170RunProcess('reset-retry-no-apply', $directory);
    orb170AssertWorkerSucceeded($retry, 'post-apply retry');
    orb170Assert(
        $settings->get($scope, NativeMetricsCredentialManager::ActivePasswordKey) === $pendingAfterFailure
            && $settings->get($scope, NativeMetricsCredentialManager::PendingPasswordKey) === null
            && $runtime->verify($node, $pendingAfterFailure),
        'Retry did not authenticate and promote the retained pending state.',
    );
    $summary['checks'][] = 'failure/retry: applied pending survived failure; retry authenticated and promoted without apply';

    $summary['status'] = 'passed';
    $exitCode = 0;
} catch (Throwable $exception) {
    $summary['error'] = [
        'type' => $exception::class,
        'message' => $exception->getMessage(),
    ];
} finally {
    try {
        if (is_string($original) && $original !== '') {
            orb170RestoreCredential($node, $settings, $runtime, $original);
            $summary['cleanup'] = 'original credential restored; pending setting absent; authentication verified';
        }
    } catch (Throwable $cleanupException) {
        $summary['cleanup'] = 'failed';
        $summary['cleanup_error'] = [
            'type' => $cleanupException::class,
            'message' => $cleanupException->getMessage(),
        ];
        $summary['status'] = 'failed';
        $exitCode = 1;
    }

    (new Filesystem)->deleteDirectory($directory);
}

$stream = $exitCode === 0 ? STDOUT : STDERR;
fwrite($stream, json_encode($summary, JSON_THROW_ON_ERROR).PHP_EOL);

exit($exitCode);

/** @return array<string, bool|int|string|list<string>> */
function orb170Inspect(Node $node, SettingRepository $settings, MetricsCredentialRuntime $runtime): array
{
    $scope = orb170Scope($node);
    $active = $settings->get($scope, NativeMetricsCredentialManager::ActivePasswordKey);
    $pending = $settings->get($scope, NativeMetricsCredentialManager::PendingPasswordKey);
    $activeRow = Setting::query()
        ->where('scope_type', SettingScopeType::Node->value)
        ->where('scope_id', $node->id)
        ->where('key', NativeMetricsCredentialManager::ActivePasswordKey)
        ->first();
    $pendingRow = Setting::query()
        ->where('scope_type', SettingScopeType::Node->value)
        ->where('scope_id', $node->id)
        ->where('key', NativeMetricsCredentialManager::PendingPasswordKey)
        ->first();

    return [
        'node_id' => $node->id,
        'node_name' => $node->name,
        'node_status' => $node->status->value,
        'roles' => $node->roles()->orderBy('role')->get()->map(
            static fn (NodeRole $assignment): string => $assignment->role->value,
        )->all(),
        'public_ssh_host' => $node->public_ssh_host,
        'public_ssh_port' => $node->public_ssh_port,
        'ssh_fingerprint' => $node->ssh_host_fingerprint ?? 'absent',
        'wireguard_ip' => $node->wireguard_ip ?? 'absent',
        'active_present' => is_string($active) && $active !== '',
        'active_encrypted' => $activeRow instanceof Setting
            && $activeRow->is_secret
            && is_string($activeRow->value)
            && $activeRow->value !== $active,
        'pending_present' => is_string($pending) && $pending !== '',
        'pending_encrypted' => $pendingRow instanceof Setting
            && $pendingRow->is_secret
            && is_string($pendingRow->value)
            && $pendingRow->value !== $pending,
        'grafana_healthy' => app(MetricsRuntimeHost::class)->health($node, MetricsService::Grafana),
        'active_authenticates' => is_string($active) && $active !== '' && $runtime->verify($node, $active),
    ];
}

function orb170RunWorker(string $mode, ?string $barrierDirectory): int
{
    try {
        $settings = app(SettingRepository::class);
        $runtime = app(MetricsCredentialRuntime::class);
        $lock = app(MetricsCredentialOperationLock::class);
        $node = orb170AssignedMetricsNode();
        $directory = is_string($barrierDirectory) ? $barrierDirectory : '';

        if (in_array($mode, ['reset', 'purge', 'initialize'], true)) {
            app(CommandDeadline::class)->start(2.0);
        }

        $result = match ($mode) {
            'initialize-paused' => (new NativeMetricsCredentialManager(
                $settings,
                $runtime,
                orb170PausingCredentialLock($lock, $directory),
            ))->passwordForConvergence($node),
            'initialize' => (new NativeMetricsCredentialManager($settings, $runtime, $lock))
                ->passwordForConvergence($node),
            'reset-paused' => (new NativeMetricsCredentialManager(
                $settings,
                orb170PausingCredentialRuntime($runtime, $directory),
                $lock,
            ))->reset()->password,
            'reset' => (new NativeMetricsCredentialManager($settings, $runtime, $lock))->reset()->password,
            'reset-normal' => (new NativeMetricsCredentialManager($settings, $runtime, $lock))->reset()->password,
            'purge' => orb170PurgeWith($node, $settings, $runtime, $lock),
            'purge-normal' => orb170PurgeWith($node, $settings, $runtime, $lock),
            'purge-paused' => orb170PurgeWith(
                $node,
                $settings,
                $runtime,
                orb170PausingCredentialLock($lock, $directory),
            ),
            'reset-fail-after-apply' => (new NativeMetricsCredentialManager(
                $settings,
                orb170FailAfterApplyCredentialRuntime($runtime),
                $lock,
            ))->reset()->password,
            'reset-retry-no-apply' => (new NativeMetricsCredentialManager(
                $settings,
                orb170RejectApplyCredentialRuntime($runtime),
                $lock,
            ))->reset()->password,
            default => throw new RuntimeException('Unknown fixture worker mode.'),
        };
        $scope = orb170Scope($node);
        $persisted = $settings->get($scope, NativeMetricsCredentialManager::ActivePasswordKey);

        echo json_encode([
            'status' => 'ok',
            'reused_persisted' => is_string($result) && is_string($persisted) && hash_equals($persisted, $result),
        ], JSON_THROW_ON_ERROR).PHP_EOL;

        return 0;
    } catch (ResourceOperationException $exception) {
        echo json_encode([
            'status' => 'refused',
            'code' => $exception->errorCode,
            'http_status' => $exception->status,
            'message' => $exception->getMessage(),
        ], JSON_THROW_ON_ERROR).PHP_EOL;

        return $exception->errorCode === 'metrics.credentials_busy' ? 75 : 70;
    } catch (Throwable $exception) {
        echo json_encode([
            'status' => 'failed',
            'type' => $exception::class,
            'message' => $exception->getMessage(),
        ], JSON_THROW_ON_ERROR).PHP_EOL;

        return 70;
    }
}

function orb170PurgeWith(
    Node $node,
    SettingRepository $settings,
    MetricsCredentialRuntime $runtime,
    MetricsCredentialOperationLock $lock,
): string {
    (new NativeMetricsCredentialManager($settings, $runtime, $lock))->purge($node);

    return 'purged';
}

function orb170AssignedMetricsNode(): Node
{
    $assignments = NodeRole::query()
        ->where('role', RoleName::Metrics->value)
        ->with('node')
        ->limit(2)
        ->get();

    if ($assignments->count() !== 1) {
        throw new RuntimeException('The fixture requires exactly one Metrics assignment.');
    }

    return $assignments->sole()->node;
}

function orb170Scope(Node $node): SettingScope
{
    return new SettingScope(SettingScopeType::Node, $node->id);
}

function orb170StartWorker(string $mode, string $directory): Process
{
    $process = new Process([PHP_BINARY, __FILE__, $mode, $directory], dirname(__DIR__, 3));
    $process->setTimeout(45.0);
    $process->start();

    return $process;
}

function orb170RunProcess(string $mode, string $directory): Process
{
    $process = orb170StartWorker($mode, $directory);
    $process->wait();

    return $process;
}

function orb170WaitForFile(string $path, Process $process): void
{
    $expiresAt = microtime(true) + ORB170_BARRIER_TIMEOUT_SECONDS;

    while (! is_file($path)) {
        if (! $process->isRunning()) {
            throw new RuntimeException(sprintf(
                'A fixture owner exited before reaching its pause point: %s',
                trim($process->getOutput().$process->getErrorOutput()),
            ));
        }

        if (microtime(true) >= $expiresAt) {
            $process->stop(0.1);

            throw new RuntimeException('A fixture owner did not reach its pause point in time.');
        }

        usleep(10_000);
    }
}

function orb170WaitForRelease(string $directory): void
{
    $expiresAt = microtime(true) + ORB170_BARRIER_TIMEOUT_SECONDS;

    while (! is_file("{$directory}/release")) {
        if (microtime(true) >= $expiresAt) {
            throw new RuntimeException('The fixture release barrier timed out.');
        }

        usleep(10_000);
    }
}

function orb170ResetBarrier(string $directory): void
{
    foreach (['owned', 'applied', 'release'] as $name) {
        @unlink("{$directory}/{$name}");
    }
}

function orb170AssertWorkerBusy(Process $process, string $label): void
{
    $result = orb170WorkerResult($process);

    orb170Assert(
        $process->getExitCode() === 75
            && ($result['status'] ?? null) === 'refused'
            && ($result['code'] ?? null) === 'metrics.credentials_busy'
            && ($result['http_status'] ?? null) === 409,
        "The {$label} did not receive the bounded Metrics credential refusal.",
    );
}

function orb170AssertWorkerSucceeded(Process $process, string $label): void
{
    if ($process->isRunning()) {
        $process->wait();
    }

    $result = orb170WorkerResult($process);

    orb170Assert(
        $process->getExitCode() === 0 && ($result['status'] ?? null) === 'ok',
        "The {$label} did not exit successfully.",
    );
}

/** @return array<string, mixed> */
function orb170WorkerResult(Process $process): array
{
    $decoded = json_decode(trim($process->getOutput()), true, flags: JSON_THROW_ON_ERROR);

    if (! is_array($decoded)) {
        throw new RuntimeException('A fixture worker returned an invalid result.');
    }

    return $decoded;
}

function orb170Assert(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

function orb170RestoreCredential(
    Node $node,
    SettingRepository $settings,
    MetricsCredentialRuntime $runtime,
    #[SensitiveParameter]
    string $original,
): void {
    $scope = orb170Scope($node);
    $active = $settings->get($scope, NativeMetricsCredentialManager::ActivePasswordKey);
    $pending = $settings->get($scope, NativeMetricsCredentialManager::PendingPasswordKey);

    if (! $runtime->verify($node, $original)) {
        $authenticated = null;

        foreach ([$pending, $active] as $candidate) {
            if (is_string($candidate) && $candidate !== '' && $runtime->verify($node, $candidate)) {
                $authenticated = $candidate;
                break;
            }
        }

        if (! is_string($authenticated)) {
            throw new RuntimeException('Cleanup could not authenticate a known credential.');
        }

        $runtime->apply($node, $authenticated, $original);
        orb170Assert($runtime->verify($node, $original), 'Cleanup did not restore Grafana authentication.');
    }

    $settings->put(
        $scope,
        NativeMetricsCredentialManager::ActivePasswordKey,
        $original,
        SettingValueProtection::Secret,
    );
    $settings->delete($scope, NativeMetricsCredentialManager::PendingPasswordKey);
}

function orb170PausingCredentialLock(
    MetricsCredentialOperationLock $inner,
    string $directory,
): MetricsCredentialOperationLock {
    return new readonly class($inner, $directory) implements MetricsCredentialOperationLock
    {
        public function __construct(
            private MetricsCredentialOperationLock $inner,
            private string $directory,
        ) {}

        public function run(int $nodeId, Closure $operation): mixed
        {
            return $this->inner->run($nodeId, function () use ($operation): mixed {
                touch("{$this->directory}/owned");
                orb170WaitForRelease($this->directory);

                return $operation();
            });
        }
    };
}

function orb170PausingCredentialRuntime(
    MetricsCredentialRuntime $inner,
    string $directory,
): MetricsCredentialRuntime {
    return new readonly class($inner, $directory) implements MetricsCredentialRuntime
    {
        public function __construct(
            private MetricsCredentialRuntime $inner,
            private string $directory,
        ) {}

        public function apply(
            Node $node,
            #[SensitiveParameter]
            string $activePassword,
            #[SensitiveParameter]
            string $pendingPassword,
        ): void {
            $this->inner->apply($node, $activePassword, $pendingPassword);
            touch("{$this->directory}/applied");
            orb170WaitForRelease($this->directory);
        }

        public function verify(Node $node, #[SensitiveParameter] string $password): bool
        {
            return $this->inner->verify($node, $password);
        }
    };
}

function orb170FailAfterApplyCredentialRuntime(MetricsCredentialRuntime $inner): MetricsCredentialRuntime
{
    return new readonly class($inner) implements MetricsCredentialRuntime
    {
        public function __construct(private MetricsCredentialRuntime $inner) {}

        public function apply(
            Node $node,
            #[SensitiveParameter]
            string $activePassword,
            #[SensitiveParameter]
            string $pendingPassword,
        ): void {
            $this->inner->apply($node, $activePassword, $pendingPassword);

            throw new RuntimeException('Injected post-apply failure.');
        }

        public function verify(Node $node, #[SensitiveParameter] string $password): bool
        {
            return $this->inner->verify($node, $password);
        }
    };
}

function orb170RejectApplyCredentialRuntime(MetricsCredentialRuntime $inner): MetricsCredentialRuntime
{
    return new readonly class($inner) implements MetricsCredentialRuntime
    {
        public function __construct(private MetricsCredentialRuntime $inner) {}

        public function apply(
            Node $node,
            #[SensitiveParameter]
            string $activePassword,
            #[SensitiveParameter]
            string $pendingPassword,
        ): void {
            throw new RuntimeException('Retry attempted an unexpected apply.');
        }

        public function verify(Node $node, #[SensitiveParameter] string $password): bool
        {
            return $this->inner->verify($node, $password);
        }
    };
}
