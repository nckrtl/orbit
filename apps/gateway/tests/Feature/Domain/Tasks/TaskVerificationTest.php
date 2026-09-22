<?php

declare(strict_types=1);

use App\Actions\Tasks\VerifyTaskAction;
use App\Data\Tasks\VerifyTaskData;
use App\Domain\Tasks\AgentSpawner;
use App\Domain\Tasks\TaskCheckResult;
use App\Domain\Tasks\TaskCheckRunner;
use App\Domain\Tasks\TaskExtensionState;
use App\Domain\Tasks\TaskGroupStatus;
use App\Domain\Tasks\TaskScheduler;
use App\Domain\Tasks\TaskStatus;
use App\Domain\Tasks\TaskVerificationGate;
use App\Domain\Tasks\TaskVerificationPolicy;
use App\Infrastructure\Tasks\T3\T3ThreadReader;
use App\Models\App as OrbitApp;
use App\Models\AppInstance;
use App\Models\Node;
use App\Models\Task;
use App\Models\TaskGroup;
use Illuminate\Support\Str;
use Laravel\Ai\Classification;
use Laravel\Ai\Classification\Boolean;
use Laravel\Ai\Prompts\ClassificationPrompt;
use Laravel\Ai\Responses\ClassificationResponse;
use Laravel\Ai\Responses\Data\BooleanAnswer;
use Laravel\Ai\Responses\Data\ChoiceAnswer;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\TextUsage;

use function Pest\Laravel\mock;

/** @return list<array{id: string, requirement: string, question: string, true: string, false: string, environment: string}> */
function verification_criteria(): array
{
    return [['id' => 'proof', 'requirement' => 'A failed check prevents review.',
        'question' => 'Does the named test demonstrate that a failed check prevents review?',
        'true' => 'It observes no review handoff after a failed check.', 'false' => 'It does not observe that outcome.', 'environment' => 'local']];
}

function verification_task(): Task
{
    $node = Node::query()->create(['name' => 'verification-gateway', 'status' => 'active', 'platform' => 'linux',
        'public_ssh_host' => '192.0.2.44', 'wireguard_ip' => '10.44.0.44']);
    test()->markAsGateway($node);
    test()->withServerVariables(['REMOTE_ADDR' => $node->wireguard_ip]);
    $app = OrbitApp::query()->create(['name' => 'verify', 'slug' => 'verify', 'repository_url' => 'git@example.test:orbit.git', 'default_branch' => 'main']);
    $instance = AppInstance::query()->create(['app_id' => $app->id, 'node_id' => $node->id, 'name' => 'task-check',
        'checkout_path' => '/srv/orbit/task-check', 'status' => 'source_resolved']);
    $group = TaskGroup::query()->create(['app_id' => $app->id, 'title' => 'Verify handoff', 'brief' => 'Check evidence.', 'status' => TaskGroupStatus::Running]);
    $group->taskable()->associate($instance);
    $group->save();
    $task = Task::query()->create(['task_group_id' => $group->id, 'position' => 1, 'title' => 'Checks', 'brief' => 'Check evidence.',
        'status' => TaskStatus::Running, 'verification_required' => true, 'verification_criteria' => verification_criteria()]);
    config()->set('orbit.tasks.verification_app_ids', [$app->id]);
    config()->set('orbit.tasks.verification_noul_threshold', 0.9);
    app(TaskExtensionState::class)->enable();

    return $task->refresh();
}

function verification_runner(): TaskCheckRunner
{
    $runner = new class implements TaskCheckRunner
    {
        public int $calls = 0;

        public bool $passed = true;

        public bool $hasEvidence = true;

        public string $digest;

        public ?Closure $onFingerprint = null;

        public function __construct()
        {
            $this->digest = str_repeat('a', 64);
        }

        public function run(AppInstance $instance, array $references): TaskCheckResult
        {
            $this->calls++;

            return new TaskCheckResult($this->digest, $this->passed,
                [['project' => 'apps/gateway', 'command' => ['composer', 'check'], 'exit_code' => $this->passed ? 0 : 1]],
                $this->hasEvidence ? ['proof' => ['test' => 'it refuses failed checks', 'source' => 'assertNoReviewAfterFailedCheck();', 'result' => 'passed']] : [], 1.0);
        }

        public function fingerprint(AppInstance $instance): string
        {
            $callback = $this->onFingerprint;
            $this->onFingerprint = null;
            if ($callback !== null) {
                $callback();
            }

            return $this->digest;
        }
    };
    app()->instance(TaskCheckRunner::class, $runner);

    return $runner;
}

function verification_input(): VerifyTaskData
{
    return new VerifyTaskData((string) Str::uuid(), [['criterion_id' => 'proof', 'project' => 'apps/gateway',
        'path' => 'tests/Feature/ChecksTest.php', 'test' => 'it refuses failed checks']]);
}

function verification_url(Task $task, string $operation = 'verify'): string
{
    return '/api/v1/task-groups/'.$task->task_group_id.'/tasks/'.$task->id.'/'.$operation;
}

describe('consumed task verification', function (): void {
    it('records the runner result and reuses the same request without checks or inference', function (): void {
        $task = verification_task();
        $runner = verification_runner();
        $input = verification_input();
        Classification::fake([['proof' => new BooleanAnswer(0.98)]])->preventStrayClassifications();

        $first = $this->postJson(verification_url($task), ['run_key' => $input->runKey, 'evidence' => $input->references])->assertOk();
        $first->assertJsonPath('data.status', 'complete')->assertJsonPath('data.answers.proof', 0.98);
        $this->postJson(verification_url($task), ['run_key' => $input->runKey, 'evidence' => $input->references])
            ->assertOk()->assertJsonPath('data.id', $first->json('data.id'));
        $this->getJson(verification_url($task, 'verification'))->assertOk()->assertJsonPath('data.id', $first->json('data.id'));

        expect($runner->calls)->toBe(1);
        expect(app(TaskVerificationGate::class)->accepted($task)?->id)->toBe($first->json('data.id'));
        Classification::assertClassified(fn (ClassificationPrompt $prompt): bool => $prompt->model === 'jev-1.13.0'
            && $prompt->timeout === 3 && $prompt->questions['proof'] instanceof Boolean);
    });

    it('consumes the run before review even when the transcript contains no checks', function (): void {
        $task = verification_task();
        verification_runner();
        Classification::fake([['proof' => new BooleanAnswer(0.98)], ['blocked' => new ChoiceAnswer('no', [], 0.98)]])->preventStrayClassifications();
        $run = app(VerifyTaskAction::class)->execute($task, verification_input());
        test_link_agent_threads($task->taskGroup->load('tasks'));
        mock(T3ThreadReader::class)->shouldReceive('snapshot')->andReturn(['thread' => ['session' => ['status' => 'idle']]]);
        mock(AgentSpawner::class)->shouldReceive('requestReview')->once()->withArgs(fn (Task $reviewed): bool => $reviewed->review_verification_id === $run->id);

        app(TaskScheduler::class)->tick();
        app(TaskScheduler::class)->tick();

        $this->assertDatabaseHas('tasks', ['id' => $task->id, 'status' => 'reviewing', 'review_verification_id' => $run->id]);
    });

    it('refuses verification reads and writes while the extension is disabled', function (): void {
        $task = verification_task();
        $runner = verification_runner();
        $input = verification_input();
        app(TaskExtensionState::class)->disable();

        $this->getJson(verification_url($task, 'verification'))->assertConflict()->assertJsonPath('error.code', 'tasks.disabled');
        $this->postJson(verification_url($task), ['run_key' => $input->runKey, 'evidence' => $input->references])->assertConflict();

        expect($runner->calls)->toBe(0);
    });

    it('does not accept a result that arrives after its reservation expires', function (): void {
        $task = verification_task();
        verification_runner();
        Classification::fake([function (): array {
            test()->travel(901)->seconds();

            return ['proof' => new BooleanAnswer(0.98)];
        }])->preventStrayClassifications();

        $run = app(VerifyTaskAction::class)->execute($task, verification_input());

        expect($run->status)->toBe('interrupted');
        expect(app(TaskVerificationGate::class)->accepted($task))->toBeNull();
    });

    it('does not pass an uncertain or negative required Noul', function (float $probability): void {
        $task = verification_task();
        verification_runner();
        Classification::fake([['proof' => new BooleanAnswer($probability)]])->preventStrayClassifications();
        app(VerifyTaskAction::class)->execute($task, verification_input());

        expect(app(TaskVerificationGate::class)->accepted($task))->toBeNull();
        app(TaskScheduler::class)->settleImplementer($task);
        $this->assertDatabaseHas('tasks', ['id' => $task->id, 'status' => 'running', 'review_verification_id' => null]);
    })->with([0.1, 0.7, 0.899]);

    it('rejects changed files without discarding the earlier result', function (): void {
        $task = verification_task();
        $runner = verification_runner();
        Classification::fake([['proof' => new BooleanAnswer(0.98)]])->preventStrayClassifications();
        $run = app(VerifyTaskAction::class)->execute($task, verification_input());
        expect(app(TaskVerificationGate::class)->accepted($task)?->id)->toBe($run->id);
        $runner->digest = str_repeat('b', 64);

        expect(app(TaskVerificationGate::class)->accepted($task))->toBeNull();
        expect($run->fresh()->answers)->toBe(['proof' => 0.98]);
    });

    it('never falls back to an older pass after a new failure', function (): void {
        $task = verification_task();
        $runner = verification_runner();
        Classification::fake([['proof' => new BooleanAnswer(0.98)]])->preventStrayClassifications();
        $old = app(VerifyTaskAction::class)->execute($task, verification_input());
        $runner->passed = false;
        $failed = app(VerifyTaskAction::class)->execute($task, verification_input());

        expect(app(TaskVerificationGate::class)->accepted($task))->toBeNull();
        expect(app(TaskVerificationGate::class)->latest($task)?->id)->toBe($failed->id)->not->toBe($old->id);
    });

    it('keeps missing test evidence unverified without asking Jev to guess', function (): void {
        $task = verification_task();
        $runner = verification_runner();
        $runner->hasEvidence = false;
        Classification::fake()->preventStrayClassifications();

        $run = app(VerifyTaskAction::class)->execute($task, verification_input());

        expect($run->status)->toBe('complete');
        expect($run->result['passed'])->toBeTrue();
        expect($run->error)->toContain('Executed test evidence is missing for: proof');
        expect($run->answers)->toBeNull();
        expect(app(TaskVerificationGate::class)->accepted($task))->toBeNull();
        Classification::assertNothingClassified();
    });

    it('invalidates evidence after node reassignment or a threshold change', function (string $change): void {
        $task = verification_task();
        verification_runner();
        Classification::fake([['proof' => new BooleanAnswer(0.98)]])->preventStrayClassifications();
        app(VerifyTaskAction::class)->execute($task, verification_input());
        if ($change === 'node') {
            $other = Node::query()->create(['name' => 'other-verifier', 'status' => 'active', 'platform' => 'linux',
                'public_ssh_host' => '192.0.2.45', 'wireguard_ip' => '10.44.0.45']);
            $task->taskGroup->taskable->update(['node_id' => $other->id]);
        } else {
            config()->set('orbit.tasks.verification_noul_threshold', 0.95);
        }

        expect(app(TaskVerificationGate::class)->accepted($task->fresh()))->toBeNull();
    })->with(['node', 'threshold']);

    it('invalidates a result for a different attempt or requirement', function (string $change): void {
        $task = verification_task();
        verification_runner();
        Classification::fake([['proof' => new BooleanAnswer(0.98)]])->preventStrayClassifications();
        app(VerifyTaskAction::class)->execute($task, verification_input());
        if ($change === 'attempt') {
            $task->increment('completion_attempt');
        } else {
            $criteria = verification_criteria();
            $criteria[0]['requirement'] = 'A different observable behavior.';
            $task->update(['verification_criteria' => $criteria]);
        }

        expect(app(TaskVerificationGate::class)->accepted($task->fresh()))->toBeNull();
    })->with(['attempt', 'requirement']);

    it('refuses a handoff if a new run starts during the last file check', function (): void {
        $task = verification_task();
        $runner = verification_runner();
        Classification::fake([['proof' => new BooleanAnswer(0.98)]])->preventStrayClassifications();
        $old = app(VerifyTaskAction::class)->execute($task, verification_input());
        $runner->onFingerprint = function () use ($old): void {
            $new = $old->replicate();
            $new->fill(['run_key' => (string) Str::uuid(), 'status' => 'running', 'result' => null, 'answers' => null]);
            $new->save();
        };

        app(TaskScheduler::class)->settleImplementer($task);

        $this->assertDatabaseHas('tasks', ['id' => $task->id, 'status' => 'running', 'review_verification_id' => null]);
    });

    it('keeps an interrupted newest run from exposing an earlier pass', function (): void {
        $task = verification_task();
        verification_runner();
        Classification::fake([['proof' => new BooleanAnswer(0.98)]])->preventStrayClassifications();
        $old = app(VerifyTaskAction::class)->execute($task, verification_input());
        $run = $old->replicate();
        $run->fill(['run_key' => (string) Str::uuid(), 'status' => 'running', 'result' => null, 'answers' => null, 'expires_at' => now()->addSeconds(10)]);
        $run->save();
        expect(app(TaskVerificationGate::class)->busy($task))->toBeTrue();
        $this->travel(11)->seconds();

        expect(app(TaskVerificationGate::class)->busy($task))->toBeFalse();
        expect($run->fresh()->status)->toBe('interrupted');
        expect(app(TaskVerificationGate::class)->accepted($task))->toBeNull();
    });

    it('retries a provider failure without rerunning passing checks', function (): void {
        $task = verification_task();
        $runner = verification_runner();
        $input = verification_input();
        Classification::fake([fn () => throw new RuntimeException('private provider detail')])->preventStrayClassifications();
        $failed = app(VerifyTaskAction::class)->execute($task, $input);
        expect($failed->status)->toBe('error');
        expect($failed->error)->not->toContain('private provider detail');
        Classification::fake([['proof' => new BooleanAnswer(0.98)]])->preventStrayClassifications();
        $recovered = app(VerifyTaskAction::class)->execute($task, $input);

        expect($runner->calls)->toBe(1);
        expect($recovered->id)->toBe($failed->id);
        expect(app(TaskVerificationGate::class)->accepted($task)?->id)->toBe($recovered->id);
    });

    it('does not retry semantic negatives to seek a favorable answer', function (): void {
        $task = verification_task();
        verification_runner();
        Classification::fake([['proof' => new BooleanAnswer(0.2)]])->preventStrayClassifications();
        app(VerifyTaskAction::class)->execute($task, verification_input());
        $repeat = app(VerifyTaskAction::class)->execute($task, verification_input());

        expect($repeat->answers)->toBe(['proof' => 0.2]);
        expect($repeat->semantic_attempts)->toBe(0);
        expect(app(TaskVerificationGate::class)->accepted($task))->toBeNull();
    });

    it('fails closed on missing or invalid Noul answers', function (array $answers): void {
        $task = verification_task();
        verification_runner();
        Classification::fake([new ClassificationResponse($answers, new TextUsage, new Meta('typesafe', TaskVerificationPolicy::Model))]);
        $run = app(VerifyTaskAction::class)->execute($task, verification_input());

        expect($run->status)->toBe('error');
        expect($run->answers)->toBeNull();
        $this->assertDatabaseHas('tasks', ['id' => $task->id, 'status' => 'running']);
    })->with(['missing' => [[]], 'non-finite' => [['proof' => new BooleanAnswer(NAN)]], 'out of range' => [['proof' => new BooleanAnswer(1.2)]]]);

    it('rejects caller-supplied results and incomplete evidence references before executing', function (): void {
        $task = verification_task();
        $runner = verification_runner();
        $input = verification_input();
        $this->postJson(verification_url($task), ['run_key' => $input->runKey, 'evidence' => $input->references, 'passed' => true])->assertUnprocessable();
        $this->postJson(verification_url($task), ['run_key' => $input->runKey, 'evidence' => []])->assertUnprocessable();

        expect($runner->calls)->toBe(0);
        $this->assertDatabaseCount('task_verifications', 0);
    });

    it('refuses unknown peers and a task outside the requested group', function (): void {
        $task = verification_task();
        $runner = verification_runner();
        $input = verification_input();
        $other = TaskGroup::query()->create(['app_id' => $task->taskGroup->app_id, 'title' => 'Other', 'brief' => 'Other group.', 'status' => 'queued']);
        $this->postJson('/api/v1/task-groups/'.$other->id.'/tasks/'.$task->id.'/verify', ['run_key' => $input->runKey, 'evidence' => $input->references])->assertNotFound();
        $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.99'])->postJson(verification_url($task), ['run_key' => $input->runKey, 'evidence' => $input->references])->assertForbidden();

        expect($runner->calls)->toBe(0);
    });

    it('requires criteria before implementation and rejects Linux proof in the local pilot', function (): void {
        $task = verification_task();
        $payload = ['title' => 'Next', 'brief' => 'The next task.'];
        $url = '/api/v1/task-groups/'.$task->task_group_id.'/tasks';
        $this->postJson($url, $payload)->assertUnprocessable();
        $this->postJson($url, [...$payload, 'verification' => verification_criteria()])->assertCreated()
            ->assertJsonPath('data.verification_required', true)->assertJsonPath('data.verification.0.id', 'proof');
        $criteria = verification_criteria();
        $criteria[0]['environment'] = 'incus';
        $this->postJson($url, [...$payload, 'verification' => $criteria])->assertUnprocessable();
    });
});
