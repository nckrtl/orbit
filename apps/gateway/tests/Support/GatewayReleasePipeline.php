<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Actions\GatewayReleases\DeployGatewayReleaseAction;
use App\Actions\GatewayReleases\RollbackGatewayReleaseAction;
use App\Actions\GatewayReleases\RunAutomaticGatewayReleaseAction;
use App\Domain\GatewayReleases\GatewayReleaseAutomation;
use App\Domain\GatewayReleases\GatewayReleaseDatabase;
use App\Domain\GatewayReleases\GatewayReleaseException;
use App\Domain\GatewayReleases\GatewayReleaseLayout;
use App\Domain\GatewayReleases\GatewayReleaseRuntime;
use App\Domain\GatewayReleases\GatewayReleaseSmoke;
use App\Domain\GatewayReleases\GatewayReleaseUnitStarter;
use App\Domain\GatewayReleases\GatewayReleaseVerifier;
use App\Domain\GatewayReleases\GatewayReleaseWebBuild;
use App\Domain\GitHub\BranchHeadReader;
use App\Domain\GitHub\GitHubApiException;
use App\Domain\GitHub\GitHubCheckRun;
use App\Domain\GitHub\GitHubRepository;
use App\Domain\GitHub\GreenCommit;
use App\Domain\GitHub\GreenCommitResolver;
use App\Domain\Releases\ReleaseAlert;
use App\Domain\Releases\ReleaseAlertNotifier;
use App\Domain\Releases\ReleaseAlertReceipt;
use App\Domain\Releases\ReleaseAlertStep;
use App\Domain\Settings\SettingRepository;
use App\Domain\Tasks\TaskExtensionState;
use App\Domain\Tasks\TaskTickClock;
use App\Infrastructure\GatewayReleases\GatewayReleaseAlerts;
use App\Infrastructure\GatewayReleases\GatewayReleaseBuilder;
use App\Infrastructure\GatewayReleases\GatewayReleaseGuard;
use App\Infrastructure\GatewayReleases\GatewayReleaseLock;
use App\Infrastructure\GatewayReleases\GatewayReleasePromoter;
use App\Infrastructure\GatewayReleases\GatewayReleaseRecorder;
use App\Infrastructure\GatewayReleases\GatewayReleaseRetry;
use App\Infrastructure\GatewayReleases\GatewayReleaseSource;
use App\Infrastructure\GatewayReleases\GatewayReleaseSupersession;
use App\Infrastructure\GatewayReleases\GatewayReleaseSwitcher;
use App\Infrastructure\GatewayReleases\GatewayReleaseTickConfirmation;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Processes\ProcessInvocation;
use App\Infrastructure\Processes\ProcessRunner;
use DateTimeImmutable;

/**
 * The whole Gateway release pipeline on a {@see GatewayReleaseFixture}: real layout, Git, lock, and
 * records; fake runtime handoff, verifier, smoke, GitHub resolver, and alert notifier. A test sets
 * `$failVerify`, `$pending`, or `$green` to steer one release.
 */
final class GatewayReleasePipeline implements BranchHeadReader, GatewayReleaseDatabase, GatewayReleaseRuntime, GatewayReleaseSmoke, GatewayReleaseUnitStarter, GatewayReleaseVerifier, GatewayReleaseWebBuild, GreenCommitResolver, ReleaseAlertNotifier
{
    public readonly GatewayReleaseFixture $fixture;

    public readonly string $home;

    /** @var list<string> */
    public array $steps = [];

    /** @var list<string> migration names the next release reports pending */
    public array $pending = [];

    public ?string $failVerify = null;

    /** The commit the resolver reports as green, or null for none. */
    public ?string $green = null;

    public ?GitHubApiException $githubFailure = null;

    /** The branch head GitHub reports, or null for the deployed commit. */
    public ?string $head = null;

    public int $headReads = 0;

    /** Whether the run unit of a queued record reports active. */
    public bool $unitActive = false;

    /** @var list<array{deployed: string, failed: list<string>, branch: string, check: string, repository: string}> */
    public array $resolutions = [];

    /** @var list<ReleaseAlert> */
    public array $alerts = [];

    /** @var array<string, mixed> what the schedule phase reports for the Gateway Node's own agent */
    public array $gatewayAgent = ['outcome' => 'unchanged', 'version' => '0.4.0'];

    public function __construct()
    {
        $this->fixture = new GatewayReleaseFixture;
        $this->home = $this->fixture->base.'/home';
        mkdir($this->home, 0700, true);
        config(['orbit.home' => $this->home]);
    }

    /** Builds the first release and makes it current, as `gateway:release:adopt` would. */
    public function adopt(): string
    {
        $sha = $this->fixture->commit('Adopted release');
        $this->fixture->builder()->prepare($sha);
        $current = $this->fixture->layout->currentPath();
        exec('rm -rf '.escapeshellarg($current));
        symlink($this->fixture->layout->linkTarget(substr($sha, 0, 12)), $current);

        return $sha;
    }

    public function lock(): GatewayReleaseLock
    {
        return new GatewayReleaseLock($this->home.'/gateway-release.lock');
    }

    public function source(): GatewayReleaseSource
    {
        return new GatewayReleaseSource(
            processes: new class implements ProcessRunner
            {
                public function run(ProcessInvocation $invocation): CommandResult
                {
                    return new CommandResult(0, "https://github.com/nckrtl/orbit.git\n", '', 1, false);
                }
            },
            branch: 'main',
            checkName: 'Required checks',
            layout: $this->fixture->layout,
        );
    }

    public function recorder(): GatewayReleaseRecorder
    {
        return new GatewayReleaseRecorder($this->home, new GatewayReleaseAlerts($this, $this->source()), $this->automation(), $this);
    }

    public function automation(): GatewayReleaseAutomation
    {
        return new GatewayReleaseAutomation(app(SettingRepository::class), $this->home.'/gateway-release.paused');
    }

    public function deployer(): DeployGatewayReleaseAction
    {
        $recorder = $this->recorder();
        $builder = $this->fixture->builder($this);

        return new DeployGatewayReleaseAction($this->lock(), $builder, $this, $this->promoter($recorder), $recorder, $this->guard(), new GatewayReleaseRetry, new GatewayReleaseSupersession($this->source(), $this));
    }

    public function rollback(): RollbackGatewayReleaseAction
    {
        $recorder = $this->recorder();

        return new RollbackGatewayReleaseAction($this->lock(), $this->fixture->layout, $this->promoter($recorder), $recorder, $this->guard());
    }

    public function guard(): GatewayReleaseGuard
    {
        return new GatewayReleaseGuard($this->fixture->layout, $this, $this->fixture);
    }

    public function automatic(): RunAutomaticGatewayReleaseAction
    {
        return new RunAutomaticGatewayReleaseAction(
            $this->automation(),
            $this->lock(),
            $this->fixture->layout,
            $this->source(),
            $this,
            $this->deployer(),
            new GatewayReleaseAlerts($this, $this->source()),
            $this,
            $this->recorder(),
            $this->ticks(),
        );
    }

    /** The post-release tick confirmation, with the real tick clock and tasks extension state. */
    public function ticks(): GatewayReleaseTickConfirmation
    {
        return new GatewayReleaseTickConfirmation(app(TaskTickClock::class), app(TaskExtensionState::class), new GatewayReleaseAlerts($this, $this->source()));
    }

    /** Binds the pipeline into the container, for commands and API requests. */
    public function bind(): void
    {
        app()->instance(GatewayReleaseLayout::class, $this->fixture->layout);
        app()->instance(GatewayReleaseLock::class, $this->lock());
        app()->instance(GatewayReleaseRecorder::class, $this->recorder());
        app()->instance(GatewayReleaseAutomation::class, $this->automation());
        app()->instance(DeployGatewayReleaseAction::class, $this->deployer());
        app()->instance(RollbackGatewayReleaseAction::class, $this->rollback());
        app()->instance(RunAutomaticGatewayReleaseAction::class, $this->automatic());
    }

    /**
     * Binds only the leaf services: the layout, the lock, the builder, the switcher, and the fakes for
     * the runtime, verifier, web build, smoke, database, GitHub, units, and alerts. Every action,
     * the recorder, the promoter, and the supersession check come from the service provider, so a
     * test proves their production wiring.
     */
    public function bindLeaves(): void
    {
        app()->instance(GatewayReleaseLayout::class, $this->fixture->layout);
        app()->instance(GatewayReleaseLock::class, $this->lock());
        app()->instance(GatewayReleaseBuilder::class, $this->fixture->builder($this));
        app()->instance(GatewayReleaseSwitcher::class, new GatewayReleaseSwitcher($this->fixture->layout, $this->fixture));
        app()->instance(GatewayReleaseSource::class, $this->source());
        app()->instance(GatewayReleaseGuard::class, $this->guard());

        foreach ([
            GatewayReleaseDatabase::class, GatewayReleaseRuntime::class, GatewayReleaseVerifier::class, GatewayReleaseWebBuild::class,
            GatewayReleaseSmoke::class, GreenCommitResolver::class, BranchHeadReader::class, ReleaseAlertNotifier::class,
            GatewayReleaseUnitStarter::class,
        ] as $contract) {
            app()->instance($contract, $this);
        }
    }

    /**
     * A supersession check that finds no newer green commit, for tests that build a deploy action by
     * hand and do not exercise automatic releases.
     */
    public static function newestGreen(): GatewayReleaseSupersession
    {
        $resolver = new class implements GreenCommitResolver
        {
            public function resolve(GitHubRepository $repository, string $branch, string $checkName, string $deployedSha, array $failedShas = []): ?GreenCommit
            {
                return null;
            }
        };
        $origin = new class implements ProcessRunner
        {
            public function run(ProcessInvocation $invocation): CommandResult
            {
                return new CommandResult(0, "https://github.com/nckrtl/orbit.git\n", '', 1, false);
            }
        };

        return new GatewayReleaseSupersession(new GatewayReleaseSource($origin, 'main', 'Required checks', new GatewayReleaseLayout('/home/orbit/orbit/apps/gateway')), $resolver);
    }

    public function cleanup(): void
    {
        $this->fixture->cleanup();
    }

    public function start(int $record, \Closure $claimed): void {}

    public function isActive(int $record): bool
    {
        return $this->unitActive;
    }

    public function head(GitHubRepository $repository, string $branch): string
    {
        $this->headReads++;

        if ($this->githubFailure instanceof GitHubApiException) {
            throw $this->githubFailure;
        }

        return $this->head ?? (string) $this->fixture->layout->preparedCommit((string) $this->fixture->layout->currentReleaseId());
    }

    public function resolve(GitHubRepository $repository, string $branch, string $checkName, string $deployedSha, array $failedShas = []): ?GreenCommit
    {
        $this->resolutions[] = [
            'deployed' => $deployedSha,
            'failed' => $failedShas,
            'branch' => $branch,
            'check' => $checkName,
            'repository' => $repository->owner.'/'.$repository->name,
        ];

        if ($this->githubFailure instanceof GitHubApiException) {
            throw $this->githubFailure;
        }

        return $this->green === null ? null : new GreenCommit($this->green, new GitHubCheckRun($checkName, 'success', null, status: 'completed', headSha: $this->green));
    }

    public function alert(ReleaseAlert $alert): ReleaseAlertReceipt
    {
        $this->alerts[] = $alert;

        return new ReleaseAlertReceipt('0198e15c-bf97-7c23-8f1f-61b8fe67a844', count($this->alerts), ReleaseAlertStep::done('release|fingerprint'), ReleaseAlertStep::skipped('not_configured'));
    }

    public function pending(string $releasePath): array
    {
        return $this->pending;
    }

    public function snapshot(string $id): string
    {
        return $this->home.'/backups/pre-'.$id.'.sqlite';
    }

    public function migrate(string $releasePath): void
    {
        $this->steps[] = 'migrate';
    }

    /** @var list<string> migration names the database reports applied */
    public array $applied = [];

    /** @var array<string, list<string>> migration files by release id */
    public array $files = [];

    /** Whether reading the applied migrations fails, as with an unreadable migrations table. */
    public bool $appliedFails = false;

    public function applied(): array
    {
        if ($this->appliedFails) {
            throw new GatewayReleaseException('snapshot', 'gateway.release_migrations_unreadable', 'The Gateway migrations table cannot be read.', 500);
        }

        return $this->applied;
    }

    public function snapshotBytes(): int
    {
        return 0;
    }

    public function migrations(string $releasePath): array
    {
        return $this->files[basename($releasePath)] ?? [];
    }

    public function handoff(string $id): array
    {
        $this->steps[] = 'handoff:'.$id;

        return [
            'caddy' => 'unchanged',
            'fpm' => 'unchanged',
            'scheduler' => 'restarted',
            'cleanup' => 'skipped',
            'agent_view' => 'restarted',
            'cleanup_paused' => false,
        ];
    }

    public function serving(): array
    {
        return ['status' => 'ok', 'version' => 'dev'];
    }

    public function schedule(string $id): array
    {
        $this->steps[] = 'schedule:'.$id;

        return ['scheduler' => 'restarted', 'cleanup' => 'skipped', 'cleanup_error_code' => null, 'cleanup_paused' => false, 'gateway_agent' => $this->gatewayAgent];
    }

    /** @var (\Closure(): void)|null runs when verify starts */
    public ?\Closure $onVerify = null;

    public function verify(string $sha): array
    {
        $this->steps[] = 'verify';

        if ($this->onVerify !== null) {
            ($this->onVerify)();
        }

        if ($this->failVerify === $sha) {
            throw new GatewayReleaseException('verify', 'gateway.release_verify_failed', "The Gateway version is not [{$sha}].");
        }

        return ['status' => 'ok', 'version' => $sha];
    }

    public function run(string $id, string $sha, ?DateTimeImmutable $since = null, array $skip = []): array
    {
        $this->steps[] = 'smoke';

        return ['outcome' => 'passed'];
    }

    public function install(string $id, string $sha): bool
    {
        return false;
    }

    public function publish(string $id): void
    {
        $this->steps[] = 'web:publish';
    }

    public function restore(string $id): void
    {
        $this->steps[] = 'web:restore';
    }

    public function remove(string $id): void {}

    public function prune(array $retained): void {}

    private function promoter(GatewayReleaseRecorder $recorder): GatewayReleasePromoter
    {
        $builder = $this->fixture->builder($this);

        return new GatewayReleasePromoter(
            $this->fixture->layout,
            new GatewayReleaseSwitcher($this->fixture->layout, $this->fixture),
            $this,
            $this,
            $this,
            $this,
            $recorder,
            $builder,
            guard: $this->guard(),
            ticks: $this->ticks(),
        );
    }
}
