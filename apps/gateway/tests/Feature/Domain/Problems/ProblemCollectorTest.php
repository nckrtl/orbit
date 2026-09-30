<?php

declare(strict_types=1);

use App\Domain\Doctor\NodeInspectionData;
use App\Domain\Doctor\NodeStateInspector;
use App\Domain\Problems\ProblemSource;
use App\Domain\Shared\LifecycleStatus;
use App\Domain\Tasks\TaskExtensionState;
use App\Models\Activity;
use App\Models\Node;
use App\Models\ProblemCollectorState;
use App\Models\ProblemFingerprint;
use App\Models\Project;
use App\Models\Task;
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
    $requestIds = [
        (string) Str::uuid(),
        (string) Str::uuid(),
        (string) Str::uuid(),
    ];

    foreach ($requestIds as $requestId) {
        problem_activity('instance:clone', 'instance.clone_failed', 1, $requestId);
    }

    Artisan::call('problems:collect');

    $fingerprint = ProblemFingerprint::query()->sole();

    expect($fingerprint->fingerprint)->toBe('activity|instance:clone|instance.clone_failed')
        ->and($fingerprint->source)->toBe(ProblemSource::Activity)
        ->and($fingerprint->occurrences)->toBe(3)
        ->and($fingerprint->evidence['request_ids'])->toBe($requestIds)
        ->and($fingerprint->evidence['activity_ids'])->toHaveCount(3)
        ->and($fingerprint->evidence['error_message'])->toBe('token=[REDACTED]')
        ->and($fingerprint->evidence['paths'])->toBe(['/resources/instance:clone']);
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

it('collects fingerprints for one open assistance reason until that request clears', function (): void {
    problem_collector_sandbox();
    $project = Project::query()->create([
        'name' => 'Problem collector',
        'slug' => 'problem-collector',
        'repository_url' => 'https://example.test/problem-collector.git',
        'default_branch' => 'main',
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
): string {
    $loggedClass = str_replace('\\', '\\\\', $class);
    $loggedCall = str_replace('\\', '\\\\', $call);

    return '['.now()->utc()->format('Y-m-d H:i:s').'] testing.ERROR: '.$message.' {"exception":"[object] ('.$loggedClass.'(code: 0): '.$message.' at '.$framePath.':1)
[stacktrace]
#0 '.$framePath.'(1): '.$loggedCall.'
#1 /tmp/vendor/framework.php(1): ignore()
#2 {main}
","request_id":"'.$requestId.'"} 
';
}
