<?php

declare(strict_types=1);

use App\Actions\Doctor\RunDoctorAction;
use App\Domain\Doctor\DoctorIssueKind;
use App\Domain\Doctor\InstalledPackageInventory;
use App\Domain\Doctor\NodeInspectionData;
use App\Domain\Doctor\NodeStateInspector;
use App\Domain\Problems\ProblemEvidence;
use App\Domain\Problems\ProblemSource;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Tasks\TaskExtensionState;
use App\Domain\Tools\ToolInventoryPackage;
use App\Domain\Tools\ToolInventoryPackageKind;
use App\Domain\Tools\ToolInventoryScan;
use App\Domain\Tools\ToolInventoryScanState;
use App\Domain\Tools\ToolManagerName;
use App\Models\Activity;
use App\Models\Node;
use App\Models\ProblemCollectorState;
use App\Models\ProblemFingerprint;
use App\Models\Project;
use App\Models\Task;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\Support\TestOrbitHome;

it('collects fingerprints for the same doctor issue across two runs', function (): void {
    problem_collector_sandbox();
    $node = problem_collector_node('drift-a');
    problem_collector_node('drift-b');
    app()->instance(NodeStateInspector::class, new class implements NodeStateInspector
    {
        public function inspect(Node $node): NodeInspectionData
        {
            return new NodeInspectionData(false, null, null, null);
        }
    });

    Artisan::call('problems:collect');
    $this->travel(5)->minutes();
    Artisan::call('problems:collect');

    $fingerprint = ProblemFingerprint::query()
        ->where('fingerprint', 'doctor|node.lifecycle_not_active|node|'.$node->id)
        ->sole();

    expect(ProblemFingerprint::query()->where('source', ProblemSource::Doctor)->count())->toBe(2)
        ->and($fingerprint->occurrences)->toBe(2)
        ->and($fingerprint->source)->toBe(ProblemSource::Doctor)
        ->and($fingerprint->evidence['expected'])->toBe('active')
        ->and($fingerprint->evidence['observed'])->toBe('provisioning')
        ->and($fingerprint->evidence['observation_times'])->toHaveCount(2)
        ->and(ProblemFingerprint::query()->where('fingerprint', 'like', 'doctor|node.lifecycle_not_active|node|%')->pluck('occurrences')->all())
        ->toBe([2, 2]);
});

it('collects no fingerprint for an informational doctor issue', function (): void {
    problem_collector_sandbox();
    $node = problem_collector_node('informational');
    $node->update(['status' => LifecycleStatus::Active]);
    app()->instance(NodeStateInspector::class, new class implements NodeStateInspector
    {
        public function inspect(Node $node): NodeInspectionData
        {
            return new NodeInspectionData(true, 'linux', 'amd64', true);
        }
    });
    app()->instance(InstalledPackageInventory::class, new class implements InstalledPackageInventory
    {
        public function inspect(Node $node): array
        {
            return [
                new ToolInventoryScan(ToolManagerName::Brew, ToolInventoryScanState::Complete, [
                    new ToolInventoryPackage(
                        manager: ToolManagerName::Brew,
                        package: 'zebra',
                        packageKind: ToolInventoryPackageKind::Formula,
                        installedVersion: '2.0.0',
                        dependency: false,
                        registered: false,
                        toolId: null,
                        adoption: ToolInventoryPackage::SUPPORTED,
                        adoptionBlock: null,
                    ),
                ]),
                new ToolInventoryScan(ToolManagerName::BrewCask, ToolInventoryScanState::Unsupported, []),
                new ToolInventoryScan(ToolManagerName::Vp, ToolInventoryScanState::Unsupported, []),
            ];
        }
    });
    $kinds = collect(app(RunDoctorAction::class)->executeForFleet()->nodes)
        ->flatMap(static fn ($report): array => $report->families)
        ->flatMap(static fn ($family): array => $family->issues)
        ->filter(static fn ($issue): bool => $issue->code === 'tool.package_unregistered')
        ->map(static fn ($issue): DoctorIssueKind => $issue->kind)
        ->unique()
        ->values()
        ->all();

    Artisan::call('problems:collect');

    expect($kinds)->toBe([DoctorIssueKind::Informational])
        ->and(ProblemFingerprint::query()->where('fingerprint', 'like', 'doctor|tool.package_unregistered|%')->exists())->toBeFalse();
});

it('collects fingerprints for neither a sub-500 refusal nor a log entry without an app frame', function (): void {
    problem_collector_sandbox();
    problem_log('');
    Artisan::call('problems:collect');

    problem_activity('instance:show', 'http.422', null);
    problem_activity('instance:update', 'validation.failed', 0);
    problem_log(problem_log_record(
        NotFoundHttpException::class,
        base_path().'/app/Http/Controllers/Api/InstancesController.php',
        'App\\Http\\Controllers\\Api\\InstancesController->show()',
        (string) Str::uuid(),
        'Not Found',
    ));
    problem_log(problem_log_record(
        ValidationException::class,
        base_path().'/app/Http/Requests/Instances/StoreInstanceRequest.php',
        'App\\Http\\Requests\\Instances\\StoreInstanceRequest->rules()',
        (string) Str::uuid(),
        'The request data is invalid.',
    ));
    problem_log(problem_log_record(
        'RuntimeException',
        '/tmp/vendor/framework.php',
        'ignore()',
        (string) Str::uuid(),
        'No app frame',
    ));

    Artisan::call('problems:collect');

    expect(ProblemFingerprint::query()->count())->toBe(0);
});

it('collects fingerprints for a burst of identical activity failures and keeps their request ids', function (): void {
    problem_collector_sandbox();
    Artisan::call('problems:collect');
    $this->travelTo(Carbon::parse('2026-10-02 15:00:00', 'UTC'));
    $requestIds = [
        (string) Str::uuid(),
        (string) Str::uuid(),
        (string) Str::uuid(),
    ];
    $occurredAt = Carbon::parse('2026-10-01 00:01:06', 'UTC');

    foreach ($requestIds as $requestId) {
        problem_activity_at('instance:clone', 'instance.clone_failed', 1, $occurredAt, $requestId);
    }

    Artisan::call('problems:collect');

    $fingerprint = ProblemFingerprint::query()->sole();

    expect($fingerprint->fingerprint)->toBe('activity|instance:clone|instance.clone_failed')
        ->and($fingerprint->source)->toBe(ProblemSource::Activity)
        ->and($fingerprint->occurrences)->toBe(1)
        ->and($fingerprint->evidence['observation_times'])->toBe(['2026-10-01T00:01:06.000000Z'])
        ->and($fingerprint->evidence['observation_counts'])->toBe([3])
        ->and($fingerprint->first_seen?->utc()->format('Y-m-d H:i:s'))->toBe('2026-10-01 00:01:06')
        ->and($fingerprint->last_seen?->utc()->format('Y-m-d H:i:s'))->toBe('2026-10-01 00:01:06')
        ->and($fingerprint->evidence['request_ids'])->toBe($requestIds)
        ->and($fingerprint->evidence['activity_ids'])->toHaveCount(3)
        ->and($fingerprint->evidence['error_message'])->toBe('token=[REDACTED]')
        ->and($fingerprint->evidence['paths'])->toBe(['/resources/instance:clone']);
});

it('collects activity occurrences from created_at across separate windows', function (): void {
    problem_collector_sandbox();
    Artisan::call('problems:collect');
    $this->travelTo(Carbon::parse('2026-10-02 15:00:00', 'UTC'));

    problem_activity_at('instance:clone', 'instance.clone_failed', 1, Carbon::parse('2026-10-01 00:01:06', 'UTC'));
    problem_activity_at('instance:clone', 'instance.clone_failed', 1, Carbon::parse('2026-10-01 00:04:06', 'UTC'));
    problem_activity_at('instance:clone', 'instance.clone_failed', 1, Carbon::parse('2026-10-01 00:06:06', 'UTC'));

    Artisan::call('problems:collect');

    $fingerprint = ProblemFingerprint::query()->sole();

    expect($fingerprint->occurrences)->toBe(2)
        ->and($fingerprint->evidence['observation_times'])->toBe([
            '2026-10-01T00:01:06.000000Z',
            '2026-10-01T00:06:06.000000Z',
        ])
        ->and($fingerprint->evidence['observation_counts'])->toBe([2, 1])
        ->and($fingerprint->first_seen?->utc()->format('Y-m-d H:i:s'))->toBe('2026-10-01 00:01:06')
        ->and($fingerprint->last_seen?->utc()->format('Y-m-d H:i:s'))->toBe('2026-10-01 00:06:06');
});

it('collects one occurrence at the log time for a burst of log records in one window', function (): void {
    problem_collector_sandbox();
    problem_log('');
    Artisan::call('problems:collect');
    $this->travelTo(Carbon::parse('2026-10-02 15:00:00', 'UTC'));

    $frame = base_path().'/app/Domain/Tasks/TaskScheduler.php';
    $call = 'App\\Domain\\Tasks\\TaskScheduler->tick()';
    $record = problem_log_record(
        'RuntimeException',
        $frame,
        $call,
        (string) Str::uuid(),
        'T3 subscription ended.',
        '2026-10-01 00:01:06',
    );
    $records = 129;
    problem_log(str_repeat($record, $records));

    Artisan::call('problems:collect');

    $fingerprint = ProblemFingerprint::query()->sole();

    expect($fingerprint->fingerprint)->toBe(
        'log|RuntimeException|app/Domain/Tasks/TaskScheduler.php:App\\Domain\\Tasks\\TaskScheduler->tick',
    )
        ->and($fingerprint->source)->toBe(ProblemSource::Log)
        ->and($fingerprint->occurrences)->toBe(1)
        ->and($fingerprint->evidence['observation_times'])->toBe(['2026-10-01T00:01:06.000000Z'])
        ->and($fingerprint->evidence['observation_counts'])->toBe([$records])
        ->and($fingerprint->first_seen?->utc()->format('Y-m-d H:i:s'))->toBe('2026-10-01 00:01:06')
        ->and($fingerprint->last_seen?->utc()->format('Y-m-d H:i:s'))->toBe('2026-10-01 00:01:06');
});

it('does not count a signal again when its window has left the occurrence sample', function (): void {
    problem_collector_sandbox();
    Artisan::call('problems:collect');
    $start = Carbon::parse('2026-10-01 00:00:00', 'UTC');

    for ($index = 0; $index < 21; $index++) {
        problem_activity_at(
            'instance:clone',
            'instance.clone_failed',
            1,
            $start->copy()->addMinutes($index * 5),
        );
    }

    Artisan::call('problems:collect');

    $fingerprint = ProblemFingerprint::query()->sole();

    expect($fingerprint->occurrences)->toBe(21)
        ->and($fingerprint->evidence['observation_times'])->toHaveCount(20)
        ->and($fingerprint->evidence['observation_times'][0])->toBe('2026-10-01T00:05:00.000000Z')
        ->and($fingerprint->evidence['counted_blocks'])->toHaveCount(21);

    problem_activity_at('instance:clone', 'instance.clone_failed', 1, $start->copy());
    Artisan::call('problems:collect');

    expect($fingerprint->refresh()->occurrences)->toBe(21)
        ->and($fingerprint->evidence['observation_times'])->toHaveCount(20)
        ->and($fingerprint->evidence['observation_times'][0])->toBe('2026-10-01T00:05:00.000000Z')
        ->and($fingerprint->evidence['counted_blocks'])->toHaveCount(21);
});

it('records the next activity occurrence at created_at after dropping legacy history', function (): void {
    problem_collector_sandbox();
    Artisan::call('problems:collect');
    ProblemFingerprint::query()->create([
        'fingerprint' => 'activity|instance:clone|instance.clone_failed',
        'source' => ProblemSource::Activity,
        'occurrences' => 129,
        'first_seen' => Carbon::parse('2026-10-02 15:00:00', 'UTC'),
        'last_seen' => Carbon::parse('2026-10-02 15:20:00', 'UTC'),
        'evidence' => [
            'observation_times' => [
                '2026-10-02T15:00:00.000000Z',
                '2026-10-02T15:10:00.000000Z',
                '2026-10-02T15:20:00.000000Z',
            ],
            'paths' => ['/resources/instance:clone'],
        ],
    ]);
    problem_activity_at(
        'instance:clone',
        'instance.clone_failed',
        1,
        Carbon::parse('2026-10-01 00:01:06', 'UTC'),
    );

    Artisan::call('problems:collect');

    $fingerprint = ProblemFingerprint::query()->sole();

    expect($fingerprint->occurrences)->toBe(1)
        ->and($fingerprint->evidence['observation_times'])->toBe(['2026-10-01T00:01:06.000000Z'])
        ->and($fingerprint->evidence['observation_counts'])->toBe([1])
        ->and($fingerprint->evidence['paths'])->toBe(['/resources/instance:clone'])
        ->and($fingerprint->first_seen?->utc()->format('Y-m-d H:i:s'))->toBe('2026-10-01 00:01:06')
        ->and($fingerprint->last_seen?->utc()->format('Y-m-d H:i:s'))->toBe('2026-10-01 00:01:06');
});

it('collects fingerprints once so the cursor and log offset prevent double counting on the next run', function (): void {
    problem_collector_sandbox();
    $frame = base_path().'/app/Domain/Tasks/TaskScheduler.php';
    $call = 'App\\Domain\\Tasks\\TaskScheduler->tick()';
    problem_activity('instance:clone', 'instance.clone_failed', 1);
    problem_log(problem_log_record('RuntimeException', $frame, $call, (string) Str::uuid(), 'Already on disk'));

    Artisan::call('problems:collect');

    expect(ProblemFingerprint::query()->count())->toBe(0);

    $requestId = (string) Str::uuid();
    $activity = problem_activity('node:provision', 'gateway.unhandled', 1, $requestId);
    problem_log(problem_log_record('RuntimeException', $frame, $call, $requestId, 'Provision failed'));

    Artisan::call('problems:collect');

    $activityFingerprint = ProblemFingerprint::query()->where('source', ProblemSource::Activity)->sole();
    $logFingerprint = ProblemFingerprint::query()->where('source', ProblemSource::Log)->sole();
    $state = ProblemCollectorState::query()->sole();

    expect($activityFingerprint->fingerprint)->toBe('activity|node:provision|gateway.unhandled')
        ->and($activityFingerprint->occurrences)->toBe(1)
        ->and($activityFingerprint->evidence['request_ids'])->toBe([$requestId])
        ->and($logFingerprint->fingerprint)->toBe(
            'log|RuntimeException|app/Domain/Tasks/TaskScheduler.php:App\\Domain\\Tasks\\TaskScheduler->tick',
        )
        ->and($logFingerprint->occurrences)->toBe(1)
        ->and($logFingerprint->evidence['request_ids'])->toBe([$requestId])
        ->and($state->activity_cursor)->toBe($activity->id)
        ->and($state->log_offset)->toBe(filesize(storage_path('logs/laravel.log')));

    Artisan::call('problems:collect');

    expect($activityFingerprint->refresh()->occurrences)->toBe(1)
        ->and($logFingerprint->refresh()->occurrences)->toBe(1)
        ->and(ProblemCollectorState::query()->sole()->activity_cursor)->toBe($activity->id)
        ->and(ProblemCollectorState::query()->sole()->log_offset)->toBe(filesize(storage_path('logs/laravel.log')));
});

it('collects fingerprints for a closure frame without line numbers', function (): void {
    problem_collector_sandbox();
    problem_log('');
    Artisan::call('problems:collect');

    $call = 'Illuminate\\Pipeline\\Pipeline->{closure:{closure:Illuminate\\Pipeline\\Pipeline::carry():194}:195}()';
    problem_log(problem_log_record(
        'RuntimeException',
        base_path().'/app/Http/Middleware/RequireNodeAccess.php',
        $call,
        (string) Str::uuid(),
        'Pipeline closure',
    ));

    Artisan::call('problems:collect');

    $fingerprint = ProblemFingerprint::query()->sole()->fingerprint;

    expect($fingerprint)->toBe('log|RuntimeException|app/Http/Middleware/RequireNodeAccess.php:Illuminate\\Pipeline\\Pipeline->{closure:{closure:Illuminate\\Pipeline\\Pipeline::carry()}}')
        ->and($fingerprint)->not->toMatch('/\\d/');
});

it('cuts a doctor summary and an assistance reason to 1000 characters', function (): void {
    $long = str_repeat('é', 1001);
    $stored = app(ProblemEvidence::class)->apply([], [
        'summary' => $long,
        'assistance_reason' => $long,
        'error_message' => $long,
    ]);

    expect(mb_strlen($stored['summary']))->toBe(1000)
        ->and($stored['summary'])->toBe(str_repeat('é', 1000))
        ->and(mb_strlen($stored['assistance_reason']))->toBe(1000)
        ->and($stored['assistance_reason'])->toBe(str_repeat('é', 1000))
        ->and($stored['error_message'])->toBe($long);
});

it('collects fingerprints for one open assistance reason until that request clears', function (): void {
    problem_collector_sandbox();
    $project = Project::query()->create([
        'name' => 'Problem collector',
        'slug' => 'problem-collector',
        'repository_url' => 'https://example.test/problem-collector.git',
        'default_branch' => 'main',
        'apps' => fixture_apps(null),
    ]);
    $task = Task::topLevel()->create([
        'project_id' => $project->id,
        'title' => 'Needs help',
        'brief' => 'The collector should count this once while it stays open.',
        'status' => 'running',
        'assistance_requested' => true,
        'assistance_reason' => 'Restart node 123e4567-e89b-12d3-a456-426614174000 after 3 failures',
    ]);

    Artisan::call('problems:collect');
    Artisan::call('problems:collect');

    $fingerprint = ProblemFingerprint::query()->sole();

    expect($fingerprint->fingerprint)->toBe('assist|restart node # after # failures')
        ->and($fingerprint->source)->toBe(ProblemSource::Assist)
        ->and($fingerprint->occurrences)->toBe(1)
        ->and($fingerprint->evidence['assistance_task_ids'])->toBe([$task->id]);

    $task->update(['assistance_requested' => false, 'assistance_reason' => null]);
    Artisan::call('problems:collect');
    $this->travel(5)->minutes();
    $task->update([
        'assistance_requested' => true,
        'assistance_reason' => 'Restart node 123e4567-e89b-12d3-a456-426614174000 after 9 failures',
    ]);
    Artisan::call('problems:collect');

    expect($fingerprint->refresh()->occurrences)->toBe(2)
        ->and($fingerprint->evidence['assistance_task_ids'])->toBe([$task->id]);
});

function problem_collector_sandbox(): void
{
    $path = TestOrbitHome::scratch('problem-collector');
    mkdir($path.'/logs', 0700, true);
    app()->useStoragePath($path);
    app(TaskExtensionState::class)->enable();
}

function problem_collector_node(string $name): Node
{
    return Node::query()->create([
        'name' => $name,
        'status' => LifecycleStatus::Provisioning,
        'platform' => 'linux',
        'public_ssh_host' => '192.0.2.'.random_int(10, 200),
        'user' => 'orbit',
    ]);
}

function problem_activity(string $command, ?string $errorCode, ?int $exitCode, ?string $requestId = null): Activity
{
    return Activity::query()->create([
        'log_name' => 'commands',
        'description' => $command,
        'request_id' => $requestId ?? (string) Str::uuid(),
        'command' => $command,
        'status' => 'failed',
        'exit_code' => $exitCode,
        'error_code' => $errorCode,
        'properties' => [
            'path' => '/resources/'.$command,
            'error_message' => 'token=supersecretvalue',
        ],
    ]);
}

function problem_activity_at(
    string $command,
    ?string $errorCode,
    ?int $exitCode,
    Carbon $createdAt,
    ?string $requestId = null,
): Activity {
    $activity = problem_activity($command, $errorCode, $exitCode, $requestId);
    $activity->forceFill(['created_at' => $createdAt])->save();

    return $activity;
}

function problem_log(string $contents): void
{
    $path = storage_path('logs/laravel.log');
    $directory = dirname($path);

    if (! is_dir($directory)) {
        mkdir($directory, 0700, true);
    }

    file_put_contents($path, $contents, FILE_APPEND);
}

function problem_log_record(
    string $class,
    string $framePath,
    string $call,
    string $requestId,
    string $message,
    ?string $recordedAt = null,
): string {
    $loggedClass = str_replace('\\', '\\\\', $class);
    $loggedCall = str_replace('\\', '\\\\', $call);
    $timezone = config('app.timezone');
    $stamp = $recordedAt ?? now()->timezone(is_string($timezone) && $timezone !== '' ? $timezone : 'UTC')->format('Y-m-d H:i:s');

    return '['.$stamp.'] testing.ERROR: '.$message.' {"exception":"[object] ('.$loggedClass.'(code: 0): '.$message.' at '.$framePath.':1)
[stacktrace]
#0 '.$framePath.'(1): '.$loggedCall.'
#1 /tmp/vendor/framework.php(1): ignore()
#2 {main}
","request_id":"'.$requestId.'"} 
';
}
