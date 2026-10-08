<?php

declare(strict_types=1);

use App\Actions\GatewayReleases\DeployGatewayReleaseAction;
use App\Domain\GatewayReleases\GatewayReleaseAutomation;
use App\Domain\GatewayReleases\GatewayReleaseDatabase;
use App\Domain\GatewayReleases\GatewayReleaseLayout;
use App\Domain\GatewayReleases\GatewayReleaseUnitStarter;
use App\Domain\Shared\LifecycleStatus;
use App\Infrastructure\GatewayReleases\GatewayReleaseLock;
use App\Infrastructure\GatewayReleases\GatewayReleaseRecorder;
use App\Infrastructure\GatewayReleases\ScriptGatewayReleaseSmoke;
use App\Infrastructure\GatewayReleases\SystemdGatewayReleaseUnitStarter;
use App\Infrastructure\Processes\CommandDeadline;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Processes\ProcessInvocation;
use App\Infrastructure\Processes\ProcessRunner;
use App\Models\GatewayRelease;
use App\Models\Node;
use Carbon\CarbonImmutable;

const RELEASE_CURRENT = '0123456789ab0123456789ab0123456789ab0123';

const RELEASE_TARGET = 'fedcba9876543210fedcba9876543210fedcba98';

final class RecordingReleaseUnitProcesses implements ProcessRunner
{
    /** @var list<list<string>> */
    public array $ran = [];

    public bool $fail = false;

    public function run(ProcessInvocation $invocation): CommandResult
    {
        if (($invocation->arguments[1] ?? null) === 'is-active') {
            return new CommandResult($this->fail ? 3 : 0, $this->fail ? "inactive\n" : "active\n", '', 1, false);
        }

        $this->ran[] = $invocation->arguments;

        return $this->fail ? new CommandResult(1, '', 'Unit not found.', 1, false) : new CommandResult(0, '', '', 1, false);
    }
}

final class GatewayReleaseMigrationFiles implements GatewayReleaseDatabase
{
    /** @var array<string, list<string>> */
    public array $files = [];

    /** @var list<string> */
    public array $applied = [];

    public function pending(string $releasePath): array
    {
        return [];
    }

    public function snapshot(string $id): string
    {
        return '/home/orbit/.orbit/backups/pre-'.$id.'.sqlite';
    }

    public function migrate(string $releasePath): void {}

    public function applied(): array
    {
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
}

/** Answers every smoke run with one recorded `bin/gateway-smoke` result and keeps the command it ran. */
final class RecordedSmokeProcesses implements ProcessRunner
{
    /** @var list<string> */
    public array $arguments = [];

    public ?float $timeout = null;

    public function __construct(private readonly CommandResult $result) {}

    public function run(ProcessInvocation $invocation): CommandResult
    {
        $this->arguments = $invocation->arguments;
        $this->timeout = $invocation->timeout;

        return $this->result;
    }
}

/**
 * The report `bin/gateway-smoke` prints for the current release, with every check passed or with
 * the web and scheduler checks failed.
 *
 * @return array<string, mixed>
 */
function gateway_smoke_report(bool $passed): array
{
    $check = static fn (string $status, string $message, ?string $error = null, int $ms = 400): array => ['status' => $status, 'error' => $error, 'message' => $message, 'duration_ms' => $ms, 'detail' => []];
    $checks = [
        'deploy_verify' => $check('passed', 'The Gateway serves '.RELEASE_CURRENT.'.', ms: 2_100),
        'node_list' => $check('passed', 'node:list returned 3 Nodes.', ms: 900),
        'tasks_list' => $check('passed', 'tasks:list answered.', ms: 800),
        'web' => $passed
            ? $check('passed', 'The web app serves build '.substr(RELEASE_CURRENT, 0, 12).'.', ms: 300)
            : $check('failed', 'web/current serves build aaaaaaaaaaaa, not '.substr(RELEASE_CURRENT, 0, 12).'.', 'web_release_mismatch', 300),
        'scheduler' => $passed
            ? $check('passed', 'orbit-process-108-schedule-work.service is active.', ms: 100)
            : $check('failed', 'orbit-process-108-schedule-work.service is not active.', 'scheduler_inactive', 100),
        'tasks_tick' => $check('passed', 'A tasks tick started at 2026-10-07T12:00:00Z.', ms: 41_000),
        'agent_view' => $check('passed', 'orbit-agent-view.service is active.', ms: 100),
        'documents' => $check('skipped', 'No --smoke-project; the write check is off.', ms: 0),
    ];
    $report = [
        'schema' => 1,
        'passed' => $passed,
        'source' => 'live',
        'expected_sha' => RELEASE_CURRENT,
        'since' => null,
        'started_at' => '2026-10-07T12:00:00Z',
        'finished_at' => '2026-10-07T12:00:42Z',
        'duration_ms' => 42_000,
        'timeout_seconds' => 90,
        'summary' => $passed ? ['passed' => 7, 'failed' => 0, 'timeout' => 0, 'skipped' => 1] : ['passed' => 5, 'failed' => 2, 'timeout' => 0, 'skipped' => 1],
        'checks' => $checks,
    ];

    return $passed ? $report : [
        ...$report,
        'error' => 'checks_failed',
        'failed_checks' => ['web', 'scheduler'],
        'message' => '2 of 7 checks did not pass: web, scheduler.',
        'next' => 'Fix the failed checks, then run smoke again. Do not deploy.',
    ];
}

/** Runs the API smoke against `bin/gateway-smoke` of the current release, answered by `$processes`. */
function gateway_smoke_api(GatewayReleaseLayout $layout, RecordedSmokeProcesses $processes, int $timeout = 90): void
{
    mkdir($layout->currentPath().'/bin', 0755, true);
    touch($layout->currentPath().'/bin/gateway-smoke');
    // The action itself comes from the container, so it gets the request's CommandDeadline.
    app()->instance(ScriptGatewayReleaseSmoke::class, new ScriptGatewayReleaseSmoke(
        layout: $layout,
        processes: $processes,
        origin: 'https://gateway.orbit',
        webRoot: '/home/orbit/web',
        timeoutSeconds: $timeout,
    ));
}

/** A release layout with a current release and a retained target, without Git. */
function gateway_release_api_layout(string $base): GatewayReleaseLayout
{
    $layout = new GatewayReleaseLayout($base.'/orbit/apps/gateway');

    foreach ([RELEASE_CURRENT, RELEASE_TARGET] as $sha) {
        mkdir($layout->releasePath(substr($sha, 0, 12)), 0755, true);
        file_put_contents($layout->releasePath(substr($sha, 0, 12)).'/REVISION', $sha."\n");
    }

    symlink($layout->linkTarget(substr(RELEASE_CURRENT, 0, 12)), $layout->currentPath());

    return $layout;
}

/** @param array<string, mixed> $attributes */
function gateway_release_record(array $attributes): GatewayRelease
{
    return GatewayRelease::query()->create([
        'release_id' => substr(RELEASE_TARGET, 0, 12),
        'sha' => RELEASE_TARGET,
        'requested' => RELEASE_TARGET,
        'trigger' => 'deploy',
        'outcome' => 'verified',
        'phases' => [],
        'duration_ms' => 0,
        ...$attributes,
    ]);
}

/** @return array<string, mixed> every step of a release that went live */
function gateway_release_phases(string $id, string $from): array
{
    return [
        'prepare' => ['outcome' => 'prepared', 'duration_ms' => 41_000],
        'guard' => ['outcome' => 'passed', 'force' => false],
        'configuration' => ['outcome' => 'cached'],
        'snapshot' => ['outcome' => 'skipped'],
        'migrate' => ['outcome' => 'skipped'],
        'switch' => ['outcome' => 'switched', 'from' => $from, 'to' => $id],
        'handoff' => ['caddy' => 'unchanged', 'fpm' => 'unchanged', 'opcache' => 'reset', 'agent_view' => 'restarted'],
        'verify' => ['outcome' => 'passed', 'status' => 'ok', 'version' => RELEASE_TARGET],
        'scheduler' => ['scheduler' => 'restarted', 'scheduler_unit' => 'orbit-process-108-schedule-work.service', 'cleanup' => 'resumed', 'cleanup_error_code' => null, 'cleanup_paused' => false],
        'web' => ['outcome' => 'published'],
        'smoke' => ['outcome' => 'passed'],
    ];
}

/**
 * Record 1 deploys RELEASE_TARGET over RELEASE_CURRENT at each point the CLI can see.
 *
 * @return array<string, mixed>
 */
function gateway_release_scenario(string $scenario): array
{
    $target = substr(RELEASE_TARGET, 0, 12);
    $current = substr(RELEASE_CURRENT, 0, 12);
    $live = gateway_release_phases($target, $current);
    $failedVerify = ['outcome' => 'failed', 'error_code' => 'gateway.release_verify_failed'];
    $verifyMessage = 'The Gateway reported version ['.RELEASE_CURRENT.'], not ['.RELEASE_TARGET.'].';
    $alert = static fn (string $kind): array => ['kind' => $kind, 'request_id' => fixture_request_id(), 'activity_id' => 9, 'problem' => ['outcome' => 'done', 'fingerprint' => 'release|'.$kind.'|gateway|'.RELEASE_TARGET], 'webhook' => ['outcome' => 'skipped', 'reason' => 'not_configured']];

    return match ($scenario) {
        'running' => [
            'outcome' => 'running',
            'previous_release_id' => null,
            'phases' => array_intersect_key($live, array_flip(['prepare', 'guard', 'configuration', 'snapshot', 'migrate', 'switch'])),
        ],
        'verified' => [
            'previous_release_id' => $current,
            'phases' => $live,
            'duration_ms' => 58_000,
        ],
        'switched-back' => [
            'outcome' => 'switched_back',
            'previous_release_id' => $current,
            'phases' => [
                ...array_intersect_key($live, array_flip(['prepare', 'guard', 'configuration', 'snapshot', 'migrate', 'switch', 'handoff'])),
                'verify' => $failedVerify,
                'switch_back' => ['outcome' => 'switched_back', 'to' => $current, 'handoff' => $live['handoff']],
            ],
            'error_code' => 'gateway.release_verify_failed',
            'message' => $verifyMessage,
            'duration_ms' => 52_000,
            'alert' => $alert('release_failed'),
        ],
        'paused' => [
            'outcome' => 'paused',
            'migrations_ran' => true,
            'snapshot_path' => '/home/orbit/.orbit/backups/pre-'.$target.'.sqlite',
            'previous_release_id' => $current,
            'phases' => [
                'prepare' => $live['prepare'],
                'guard' => $live['guard'],
                'configuration' => $live['configuration'],
                'snapshot' => ['outcome' => 'snapshotted', 'path' => '/home/orbit/.orbit/backups/pre-'.$target.'.sqlite', 'pending' => ['2026_10_14_000000_track_gateway_release_requests']],
                'migrate' => ['outcome' => 'migrated', 'pending' => ['2026_10_14_000000_track_gateway_release_requests']],
                'switch' => $live['switch'],
                'handoff' => $live['handoff'],
                'verify' => $failedVerify,
                'pause' => ['outcome' => 'paused', 'snapshot' => '/home/orbit/.orbit/backups/pre-'.$target.'.sqlite'],
            ],
            'error_code' => 'gateway.release_verify_failed',
            'message' => $verifyMessage,
            'duration_ms' => 61_000,
            'alert' => $alert('release_paused'),
        ],
        'rolled-back' => [
            'trigger' => 'rollback',
            'requested' => $target,
            'force' => true,
            'previous_release_id' => $current,
            'snapshot_path' => '/home/orbit/.orbit/backups/pre-'.$current.'.sqlite',
            'phases' => [
                'rollback' => ['outcome' => 'started', 'force' => true, 'snapshot' => '/home/orbit/.orbit/backups/pre-'.$current.'.sqlite'],
                ...array_intersect_key($live, array_flip(['configuration', 'switch', 'handoff', 'verify', 'scheduler', 'web', 'smoke'])),
            ],
            'duration_ms' => 19_000,
        ],
    };
}

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-07T12:00:00Z'));
    $caller = $this->markAsGateway(Node::query()->create([
        'name' => 'release-gateway',
        'status' => LifecycleStatus::Active,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.30',
        'wireguard_ip' => '10.44.0.30',
    ]));
    $this->withServerVariables(['REMOTE_ADDR' => $caller->wireguard_ip]);
    $this->withHeader('X-Orbit-Request-Id', fixture_request_id());

    $this->base = sys_get_temp_dir().'/orbit-release-api-'.bin2hex(random_bytes(4));
    mkdir($this->base.'/home', 0700, true);
    config(['orbit.home' => $this->base.'/home', 'orbit.gateway_checkout' => $this->base.'/orbit/apps/gateway']);
    $this->layout = gateway_release_api_layout($this->base);
    $this->processes = new RecordingReleaseUnitProcesses;
    $this->database = new GatewayReleaseMigrationFiles;
    $this->lock = new GatewayReleaseLock($this->base.'/home/gateway-release.lock');
    app()->instance(GatewayReleaseLayout::class, $this->layout);
    app()->instance(GatewayReleaseLock::class, $this->lock);
    app()->instance(GatewayReleaseDatabase::class, $this->database);
    app()->instance(GatewayReleaseRecorder::class, new GatewayReleaseRecorder($this->base.'/home'));
    app()->instance(GatewayReleaseUnitStarter::class, new SystemdGatewayReleaseUnitStarter($this->processes));
    app()->bind(DeployGatewayReleaseAction::class, static fn () => throw new LogicException('The API never runs a release in-process.'));
});

afterEach(function (): void {
    exec('rm -rf '.escapeshellarg($this->base));
});

describe('gateway release API', function (): void {
    it('queues a deploy, starts its unit, and answers 202 without running the release', function (): void {
        $response = $this->postJson('/api/v1/gateway/releases', ['commit' => RELEASE_TARGET])->assertStatus(202);
        $record = GatewayRelease::query()->sole();

        expect($response->json('data.id'))->toBe($record->id)
            ->and($response->json('data.outcome'))->toBe('queued')
            ->and($response->json('data.finished'))->toBeFalse()
            ->and($response->json('data.release'))->toBe(substr(RELEASE_TARGET, 0, 12))
            ->and($this->processes->ran)->toBe([['sudo', '-n', 'systemctl', 'start', '--no-block', 'orbit-gateway-release-run@'.$record->id.'.service']])
            ->and($this->layout->currentReleaseId())->toBe(substr(RELEASE_CURRENT, 0, 12));
        record_fixture($response, 'gateway-releases/gateway-release-deploy/queued', 'Orbit\\Sdk\\Requests\\GatewayReleases\\DeployGatewayReleaseRequest', 'POST /api/v1/gateway/releases');
    });

    it('queues a short commit with an unknown release until prepare resolves it', function (): void {
        $this->postJson('/api/v1/gateway/releases', ['commit' => 'fedcba9'])
            ->assertStatus(202)
            ->assertJsonPath('data.requested', 'fedcba9')
            ->assertJsonPath('data.sha', null)
            ->assertJsonPath('data.release', null);
    });

    it('refuses a deploy while a release step holds the lock or another release is queued', function (): void {
        $response = $this->lock->run(fn () => $this->postJson('/api/v1/gateway/releases', ['commit' => RELEASE_TARGET]));
        $response->assertStatus(409)->assertJsonPath('error.code', 'gateway.release_in_progress');
        expect(GatewayRelease::query()->count())->toBe(0);
        record_fixture($response, 'gateway-releases/gateway-release-deploy/in-progress', 'Orbit\\Sdk\\Requests\\GatewayReleases\\DeployGatewayReleaseRequest', 'POST /api/v1/gateway/releases');

        gateway_release_record(['outcome' => 'queued']);
        $this->postJson('/api/v1/gateway/releases', ['commit' => RELEASE_TARGET])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'gateway.release_in_progress');
        expect($this->processes->ran)->toBe([]);
    });

    it('refuses a branch name and an unknown body field', function (): void {
        $this->postJson('/api/v1/gateway/releases', ['commit' => 'main'])->assertStatus(422)->assertJsonPath('error.code', 'validation.failed');
        $this->postJson('/api/v1/gateway/releases', ['commit' => RELEASE_TARGET, 'force' => true])->assertStatus(422);
        expect(GatewayRelease::query()->count())->toBe(0);
    });

    it('ends the record when systemd does not start the unit', function (): void {
        $this->processes->fail = true;

        $this->postJson('/api/v1/gateway/releases', ['commit' => RELEASE_TARGET])
            ->assertStatus(503)
            ->assertJsonPath('error.code', 'gateway.release_unit_failed');

        expect(GatewayRelease::query()->sole())
            ->outcome->toBe('failed')
            ->retryable->toBeTrue()
            ->error_code->toBe('gateway.release_unit_failed');
    });

    it('refuses a deploy before the Gateway runs from a release', function (): void {
        unlink($this->layout->currentPath());

        $this->postJson('/api/v1/gateway/releases', ['commit' => RELEASE_TARGET])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'gateway.release_not_adopted');
    });

    it('refuses a rollback across a migration without force and queues it with force', function (): void {
        $this->database->files = [
            substr(RELEASE_CURRENT, 0, 12) => ['2026_10_01_000000_one.php', '2026_10_02_000000_two.php'],
            substr(RELEASE_TARGET, 0, 12) => ['2026_10_01_000000_one.php'],
        ];
        $this->database->applied = ['2026_10_01_000000_one', '2026_10_02_000000_two'];
        $id = substr(RELEASE_TARGET, 0, 12);

        $refused = $this->postJson("/api/v1/gateway/releases/{$id}/rollback", ['force' => false])->assertStatus(409)
            ->assertJsonPath('error.code', 'gateway.release_migration_crossed');
        expect(GatewayRelease::query()->count())->toBe(0);
        record_fixture($refused, 'gateway-releases/gateway-release-rollback/migration-crossed', 'Orbit\\Sdk\\Requests\\GatewayReleases\\RollbackGatewayReleaseRequest', 'POST /api/v1/gateway/releases/{release}/rollback');

        $queued = $this->postJson("/api/v1/gateway/releases/{$id}/rollback", ['force' => true])->assertStatus(202)
            ->assertJsonPath('data.trigger', 'rollback')
            ->assertJsonPath('data.force', true)
            ->assertJsonPath('data.sha', RELEASE_TARGET);
        record_fixture($queued, 'gateway-releases/gateway-release-rollback/queued', 'Orbit\\Sdk\\Requests\\GatewayReleases\\RollbackGatewayReleaseRequest', 'POST /api/v1/gateway/releases/{release}/rollback');
    });

    it('refuses a rollback to a release that is not retained', function (): void {
        $this->postJson('/api/v1/gateway/releases/aaaaaaaaaaaa/rollback', ['force' => false])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'gateway.release_not_prepared');
    });

    it('lists release records newest first and finds them by id or commit', function (): void {
        gateway_release_record([
            'release_id' => substr(RELEASE_CURRENT, 0, 12),
            'sha' => RELEASE_CURRENT,
            'requested' => RELEASE_CURRENT,
            'trigger' => 'auto',
            'previous_release_id' => 'aaaaaaaaaaaa',
            'phases' => gateway_release_phases(substr(RELEASE_CURRENT, 0, 12), 'aaaaaaaaaaaa'),
            'duration_ms' => 63_000,
        ]);
        gateway_release_record(gateway_release_scenario('switched-back'));
        gateway_release_record(['release_id' => null, 'sha' => null, 'requested' => 'fedcba9', 'outcome' => 'failed', 'retryable' => true, 'error_code' => 'gateway.release_commit_unknown', 'message' => 'Commit [fedcba9] is not in the repository, or the prefix names more than one commit.', 'phases' => ['prepare' => ['outcome' => 'failed', 'error_code' => 'gateway.release_commit_unknown']], 'duration_ms' => 2_100]);
        gateway_release_record(gateway_release_scenario('running'));

        $list = $this->getJson('/api/v1/gateway/releases')->assertOk();
        expect(array_column($list->json('data'), 'id'))->toBe([4, 3, 2, 1]);
        record_fixture($list, 'gateway-releases/gateway-release-list/default', 'Orbit\\Sdk\\Requests\\GatewayReleases\\ListGatewayReleasesRequest', 'GET /api/v1/gateway/releases');

        $this->getJson('/api/v1/gateway/releases/'.substr(RELEASE_CURRENT, 0, 9))->assertOk()->assertJsonPath('data.id', 1);
        $this->getJson('/api/v1/gateway/releases/fedcba9')->assertOk()->assertJsonPath('data.id', 4);
        $this->getJson('/api/v1/gateway/releases/2')->assertOk()->assertJsonPath('data.outcome', 'switched_back');
        record_fixture($this->getJson('/api/v1/gateway/releases/99')->assertNotFound()->assertJsonPath('error.code', 'gateway.release_not_found'), 'gateway-releases/gateway-release-show/not-found', 'Orbit\\Sdk\\Requests\\GatewayReleases\\ShowGatewayReleaseRequest', 'GET /api/v1/gateway/releases/{release}');
    });

    it('records one release record as the CLI follows it', function (string $scenario): void {
        gateway_release_record(gateway_release_scenario($scenario));

        record_fixture($this->getJson('/api/v1/gateway/releases/1')->assertOk(), 'gateway-releases/gateway-release-show/'.$scenario, 'Orbit\\Sdk\\Requests\\GatewayReleases\\ShowGatewayReleaseRequest', 'GET /api/v1/gateway/releases/{release}');
    })->with(['running', 'verified', 'switched-back', 'paused', 'rolled-back']);

    it('enables, disables, and resumes automatic releases', function (): void {
        $status = $this->getJson('/api/v1/gateway/release-automation')->assertOk()
            ->assertJsonPath('data.enabled', false)
            ->assertJsonPath('data.current_release', substr(RELEASE_CURRENT, 0, 12))
            ->assertJsonPath('data.current_sha', RELEASE_CURRENT);
        record_fixture($status, 'gateway-releases/gateway-release-auto-status/disabled', 'Orbit\\Sdk\\Requests\\GatewayReleases\\ShowGatewayReleaseAutomationRequest', 'GET /api/v1/gateway/release-automation');

        $enabled = $this->postJson('/api/v1/gateway/release-automation/enable')->assertOk()->assertJsonPath('data.enabled', true);
        record_fixture($enabled, 'gateway-releases/gateway-release-auto-enable/enabled', 'Orbit\\Sdk\\Requests\\GatewayReleases\\EnableGatewayReleaseAutomationRequest', 'POST /api/v1/gateway/release-automation/enable');

        $notPaused = $this->postJson('/api/v1/gateway/release-automation/resume')->assertStatus(409)->assertJsonPath('error.code', 'gateway.release_not_paused');
        record_fixture($notPaused, 'gateway-releases/gateway-release-auto-resume/not-paused', 'Orbit\\Sdk\\Requests\\GatewayReleases\\ResumeGatewayReleaseAutomationRequest', 'POST /api/v1/gateway/release-automation/resume');

        $automation = app(GatewayReleaseAutomation::class);
        $automation->recordTick('paused');
        gateway_release_record([
            'trigger' => 'auto',
            'outcome' => 'paused',
            'migrations_ran' => true,
            'snapshot_path' => '/home/orbit/.orbit/backups/pre-'.substr(RELEASE_TARGET, 0, 12).'.sqlite',
            'error_code' => 'gateway.release_smoke_failed',
        ]);
        file_put_contents($this->base.'/home/gateway-release.paused', '{}');
        $automation->pauseFor('migration_failure', GatewayRelease::query()->findOrFail(1));
        $paused = $this->getJson('/api/v1/gateway/release-automation')->assertOk()
            ->assertJsonPath('data.paused', true)
            ->assertJsonPath('data.pause.reason', 'migration_failure')
            ->assertJsonPath('data.pause.record', 1)
            ->assertJsonPath('data.last_tick.result', 'paused');
        record_fixture($paused, 'gateway-releases/gateway-release-auto-status/paused', 'Orbit\\Sdk\\Requests\\GatewayReleases\\ShowGatewayReleaseAutomationRequest', 'GET /api/v1/gateway/release-automation');

        $resumed = $this->postJson('/api/v1/gateway/release-automation/resume')->assertOk()->assertJsonPath('data.paused', false);
        expect(is_file($this->base.'/home/gateway-release.paused'))->toBeFalse();
        record_fixture($resumed, 'gateway-releases/gateway-release-auto-resume/resumed', 'Orbit\\Sdk\\Requests\\GatewayReleases\\ResumeGatewayReleaseAutomationRequest', 'POST /api/v1/gateway/release-automation/resume');

        $disabled = $this->postJson('/api/v1/gateway/release-automation/disable')->assertOk()->assertJsonPath('data.enabled', false);
        record_fixture($disabled, 'gateway-releases/gateway-release-auto-disable/disabled', 'Orbit\\Sdk\\Requests\\GatewayReleases\\DisableGatewayReleaseAutomationRequest', 'POST /api/v1/gateway/release-automation/disable');
    });

    it('runs smoke against the current release inside the request and writes no record', function (): void {
        $processes = new RecordedSmokeProcesses(new CommandResult(0, json_encode(gateway_smoke_report(true), JSON_THROW_ON_ERROR), '', 42_000, false));
        gateway_smoke_api($this->layout, $processes);

        $response = $this->postJson('/api/v1/gateway/release-smoke', ['commit' => null, 'since' => null])->assertOk()
            ->assertJsonPath('data.release', substr(RELEASE_CURRENT, 0, 12))
            ->assertJsonPath('data.sha', RELEASE_CURRENT)
            ->assertJsonPath('data.outcome', 'passed')
            ->assertJsonPath('data.report.summary.passed', 7);
        record_fixture($response, 'gateway-releases/gateway-release-smoke/passed', 'Orbit\\Sdk\\Requests\\GatewayReleases\\SmokeGatewayReleaseRequest', 'POST /api/v1/gateway/release-smoke');

        expect(array_slice($processes->arguments, 4, 5))->toBe([$this->layout->currentPath().'/bin/gateway-smoke', '--sha', RELEASE_CURRENT, '--timeout', '90'])
            ->and(GatewayRelease::query()->count())->toBe(0)
            ->and($this->processes->ran)->toBe([]);

        $this->postJson('/api/v1/gateway/release-smoke', ['commit' => 'fedcba9', 'since' => '2026-10-07T06:00:00Z'])->assertOk()
            ->assertJsonPath('data.sha', 'fedcba9');
        expect($processes->arguments)->toContain('--since', '2026-10-07T06:00:00Z');
    });

    it('answers failed checks with the failed outcome and their report', function (): void {
        gateway_smoke_api($this->layout, new RecordedSmokeProcesses(new CommandResult(1, json_encode(gateway_smoke_report(false), JSON_THROW_ON_ERROR), '', 42_000, false)));

        $response = $this->postJson('/api/v1/gateway/release-smoke', ['commit' => null, 'since' => null])->assertOk()
            ->assertJsonPath('data.outcome', 'failed')
            ->assertJsonPath('data.report.failed_checks', ['web', 'scheduler']);
        record_fixture($response, 'gateway-releases/gateway-release-smoke/failed', 'Orbit\\Sdk\\Requests\\GatewayReleases\\SmokeGatewayReleaseRequest', 'POST /api/v1/gateway/release-smoke');
    });

    it('keeps a smoke that printed no report an error', function (): void {
        gateway_smoke_api($this->layout, new RecordedSmokeProcesses(new CommandResult(1, '', 'Traceback (most recent call last)', 300, false)));

        $this->postJson('/api/v1/gateway/release-smoke', ['commit' => null, 'since' => null])->assertStatus(500)
            ->assertJsonPath('error.code', 'gateway.release_smoke_failed');
    });

    it('lowers a long smoke limit so the run ends within the command deadline of the request', function (): void {
        $processes = new RecordedSmokeProcesses(new CommandResult(0, json_encode(gateway_smoke_report(true), JSON_THROW_ON_ERROR), '', 42_000, false));
        gateway_smoke_api($this->layout, $processes, timeout: 600);

        $this->postJson('/api/v1/gateway/release-smoke', ['commit' => null, 'since' => null])->assertOk();

        // The request's deadline is 570 seconds with a 20-second cleanup reserve, inside PHP-FPM's 600.
        expect($processes->arguments[7])->toBe('--timeout')
            ->and((int) $processes->arguments[8])->toBeBetween(510, 520)
            ->and((int) $processes->arguments[3])->toBe((int) $processes->arguments[8] + 15)
            ->and($processes->timeout)->toBeLessThanOrEqual(550.0);

        // A deadline that already runs, with less time left, bounds the run too.
        app(CommandDeadline::class)->start(200.0, CommandDeadline::CleanupReserveSeconds);
        $this->postJson('/api/v1/gateway/release-smoke', ['commit' => null, 'since' => null])->assertOk();
        app(CommandDeadline::class)->clear();

        expect((int) $processes->arguments[8])->toBeBetween(140, 150)
            ->and($processes->timeout)->toBeLessThanOrEqual(180.0);
    });

    it('keeps a smoke that was terminated an error, not a failed result', function (): void {
        gateway_smoke_api($this->layout, new RecordedSmokeProcesses(new CommandResult(143, '{"schema":1,"passed":false,"error":"terminated"}', '', 300, false)));

        $this->postJson('/api/v1/gateway/release-smoke', ['commit' => null, 'since' => null])->assertStatus(500)
            ->assertJsonPath('error.code', 'gateway.release_smoke_failed');
    });

    it('refuses a smoke while another one runs', function (): void {
        $processes = new RecordedSmokeProcesses(new CommandResult(0, json_encode(gateway_smoke_report(true), JSON_THROW_ON_ERROR), '', 42_000, false));
        gateway_smoke_api($this->layout, $processes);
        $held = fopen($this->base.'/home/gateway-release-smoke.lock', 'c');
        flock($held, LOCK_EX);

        try {
            $this->postJson('/api/v1/gateway/release-smoke', ['commit' => null, 'since' => null])->assertStatus(409)
                ->assertJsonPath('error.code', 'gateway.release_smoke_in_progress');
        } finally {
            flock($held, LOCK_UN);
            fclose($held);
        }

        expect($processes->arguments)->toBe([]);
        $this->postJson('/api/v1/gateway/release-smoke', ['commit' => null, 'since' => null])->assertOk();
    });

    it('refuses an invalid commit, a time without a zone, and an unknown body field', function (): void {
        gateway_smoke_api($this->layout, new RecordedSmokeProcesses(new CommandResult(0, '', '', 1, false)));

        $this->postJson('/api/v1/gateway/release-smoke', ['commit' => 'main'])->assertStatus(422)->assertJsonPath('error.code', 'validation.failed');
        $this->postJson('/api/v1/gateway/release-smoke', ['force' => true])->assertStatus(422);
        $this->postJson('/api/v1/gateway/release-smoke', ['since' => '2026-10-07 06:00'])->assertStatus(422)->assertJsonPath('error.code', 'gateway.release_since_invalid');
    });

    it('reports the current release and the automatic release state in Gateway status', function (): void {
        app(GatewayReleaseAutomation::class)->enable();

        $this->getJson('/api/v1/gateway/status')->assertOk()
            ->assertJsonPath('data.release', substr(RELEASE_CURRENT, 0, 12))
            ->assertJsonPath('data.release_sha', RELEASE_CURRENT)
            ->assertJsonPath('data.auto_release', ['enabled' => true, 'paused' => false, 'last_checked_at' => null, 'last_result' => null]);

        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.99'])
            ->getJson('/api/v1/gateway/status')->assertOk()
            ->assertJsonPath('data.release', substr(RELEASE_CURRENT, 0, 12))
            ->assertJsonPath('data.auto_release', null);
    });

    it('requires an active WireGuard peer', function (): void {
        $this->withServerVariables(['REMOTE_ADDR' => '10.44.0.99'])
            ->getJson('/api/v1/gateway/releases')
            ->assertForbidden();
    });
});
