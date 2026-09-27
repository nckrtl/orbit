<?php

declare(strict_types=1);

use App\Domain\Tasks\AgentDriverRegistry;
use App\Domain\Tasks\NullTaskReviewDiff;
use App\Domain\Tasks\TaskAgentSpawner;
use App\Domain\Tasks\TaskCheckKind;
use App\Domain\Tasks\TaskCheckStatus;
use App\Domain\Tasks\TaskDeliverable;
use App\Domain\Tasks\TaskDeliverableType;
use App\Domain\Tasks\TaskPlannerMcp;
use App\Domain\Tasks\TaskReviewDiff;
use App\Domain\Tasks\TaskReviewPacketBuilder;
use App\Models\App as OrbitApp;
use App\Models\AppInstance;
use App\Models\Task;
use App\Models\TaskCheck;
use App\Models\TaskGroup;
use Symfony\Component\Process\Process;

function render_task_prompt(string $role, array $payload): array
{
    $process = new Process(
        [PHP_BINARY, base_path('artisan'), 'tasks:render-prompt', $role],
        base_path(),
    );
    $process->setInput(json_encode($payload, JSON_THROW_ON_ERROR));
    $process->mustRun();

    /** @var array{role: string, prompt: string, source_commit: string} $output */
    $output = json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);

    return $output;
}

function production_task_prompt_models(): array
{
    $app = OrbitApp::query()->create([
        'name' => 'Gateway prompts',
        'slug' => 'gateway-prompts',
        'repository_url' => 'git@example.test:gateway-prompts.git',
        'default_branch' => 'main',
        'task_check' => 'composer check',
    ]);
    $group = TaskGroup::query()->create([
        'app_id' => $app->id,
        'title' => 'Offline prompts',
        'brief' => 'Render <info>prompts</info> without <error>a workspace</error>.',
        'status' => 'running',
    ]);
    $task = Task::query()->create([
        'task_group_id' => $group->id,
        'position' => 1,
        'title' => 'Render JSON',
        'brief' => 'Read and validate the prompt input.',
        'status' => 'running',
        'deliverables' => [(new TaskDeliverable(
            id: 'command',
            type: TaskDeliverableType::Command,
            description: 'The command renders a prompt.',
            command: 'composer test',
            directory: 'apps/gateway',
        ))->toArray()],
        'subtask_start_commit' => 'abc1234',
    ]);
    $group->setRelation('app', $app);
    $group->setRelation('taskable', new AppInstance);
    $task->setRelation('taskGroup', $group);

    return [$app, $group, $task];
}

function production_spawner_prompt(string $method): array
{
    [$app, $group, $task] = production_task_prompt_models();
    $spawner = new TaskAgentSpawner(
        new AgentDriverRegistry([]),
        new TaskReviewPacketBuilder(new NullTaskReviewDiff),
        Mockery::mock(TaskPlannerMcp::class),
    );
    $reflection = new ReflectionMethod(TaskAgentSpawner::class, $method);
    $reflection->setAccessible(true);
    $prompt = $method === 'plannerPrompt'
        ? $reflection->invoke($spawner, $group)
        : $reflection->invoke($spawner, $group, $task, 31);

    return [
        'prompt' => $prompt,
        'group' => [
            'id' => $group->id,
            'title' => $group->title,
            'brief' => $group->brief,
            'project_slug' => $app->slug,
            'project_id' => $app->id,
            'default_branch' => $app->default_branch,
            'task_check' => $app->taskCheckCommand(),
        ],
        'subtask' => [
            'id' => $task->id,
            'title' => $task->title,
            'brief' => $task->brief,
            'position' => $task->position,
            'deliverables' => $task->deliverables,
        ],
    ];
}

function render_task_prompt_group(): array
{
    return [
        'id' => 17,
        'title' => 'Offline prompts',
        'brief' => 'Render <info>prompts</info> without <error>a workspace</error>.',
        'project_slug' => 'gateway',
        'project_id' => 23,
        'default_branch' => 'main',
        'task_check' => 'composer check',
    ];
}

function render_task_prompt_subtask(): array
{
    return [
        'id' => 29,
        'title' => 'Render JSON',
        'brief' => 'Read and validate the prompt input.',
        'position' => 1,
        'deliverables' => [
            (new TaskDeliverable(
                id: 'command',
                type: TaskDeliverableType::Command,
                description: 'The command renders a prompt.',
                command: 'composer test',
                directory: 'apps/gateway',
            ))->toArray(),
        ],
    ];
}

function render_task_prompt_review_packet(bool $continued = false): array
{
    $evidence = [
        'diff' => [['status' => 'modified', 'path' => 'app/Prompt.php']],
        'tests' => (object) [],
        'commands' => (object) ['command' => ['exit_code' => 0, 'output' => '']],
    ];

    return [
        'opens_pull_request' => false,
        'earlier_approved_subtasks' => [],
        'diff_files' => [
            ['path' => 'app/Prompt.php', 'insertions' => 2, 'deletions' => 1],
            ['path' => 'app/NewPrompt.php', 'insertions' => 1, 'deletions' => 0],
        ],
        'files_complete' => ! $continued,
        'diff_available' => ! $continued,
        'diff_summary' => ['files' => 2, 'insertions' => 3, 'deletions' => 1],
        'diff_body' => $continued ? '' : "diff --git a/app/Prompt.php b/app/Prompt.php\n+<info>prompt</info>\n",
        'untracked_content' => $continued ? [] : [['path' => 'app/NewPrompt.php', 'patch' => "diff --git /dev/null b/app/NewPrompt.php\n+<error>new</error>\n"]],
        'handoff_check' => ['status' => 'passed', 'exit_code' => 0, 'evidence' => $evidence],
        'start_commit' => 'abc1234',
        'held_resolution' => null,
    ];
}

function production_review_prompt(bool $continued): array
{
    [$app, $group, $task] = production_task_prompt_models();
    $group->update(['pr_url' => 'https://example.test/pull/1']);
    $group->setRelation('app', $app);
    $group->setRelation('taskable', new AppInstance);
    $task->setRelation('taskGroup', $group);
    TaskCheck::query()->create([
        'task_id' => $task->id,
        'kind' => TaskCheckKind::Handoff,
        'status' => TaskCheckStatus::Passed,
        'pid' => 1,
        'process_started' => 'Wed Sep 23 12:00:00 2026',
        'head_before' => str_repeat('a', 40),
        'tree_before' => str_repeat('b', 40),
        'exit_code' => 0,
        'deliverable_evidence' => [
            'diff' => [['status' => 'modified', 'path' => 'app/Prompt.php']],
            'tests' => [],
            'commands' => ['command' => ['exit_code' => 0, 'output' => '']],
        ],
        'started_at' => now(),
    ]);
    $diff = new class($continued) implements TaskReviewDiff
    {
        public function __construct(private bool $continued) {}

        public function read(AppInstance $instance, string $startCommit): array
        {
            return [
                'files' => $this->continued ? [] : [
                    ['path' => 'app/Prompt.php', 'insertions' => 2, 'deletions' => 1],
                    ['path' => 'app/NewPrompt.php', 'insertions' => 1, 'deletions' => 0],
                ],
                'diff' => $this->continued ? '' : "diff --git a/app/Prompt.php b/app/Prompt.php\n+<info>prompt</info>\n"."diff --git /dev/null b/app/NewPrompt.php\n+<error>new</error>\n"."\n",
                'files_complete' => ! $this->continued,
                'diff_available' => ! $this->continued,
                'summary' => ['files' => 2, 'insertions' => 3, 'deletions' => 1],
            ];
        }
    };
    $prompt = (new TaskReviewPacketBuilder($diff))->build($task, $continued, 32);
    $packet = render_task_prompt_review_packet($continued);
    $packet['opens_pull_request'] = false;

    return [
        'prompt' => $prompt,
        'payload' => [
            'group' => [
                'id' => $group->id,
                'title' => $group->title,
                'brief' => $group->brief,
                'project_slug' => $app->slug,
                'project_id' => $app->id,
                'default_branch' => $app->default_branch,
                'task_check' => $app->taskCheckCommand(),
            ],
            'subtask' => [
                'id' => $task->id,
                'title' => $task->title,
                'brief' => $task->brief,
                'position' => $task->position,
                'deliverables' => $task->deliverables,
            ],
            'thread_id' => 32,
            'review_packet' => $packet,
        ],
    ];
}

it('renders the same prompt for the planner', function (): void {
    $production = production_spawner_prompt('plannerPrompt');

    expect(render_task_prompt('planner', ['group' => $production['group']])['prompt'])->toBe($production['prompt']);
});

it('renders the same prompt for the implementer', function (): void {
    $production = production_spawner_prompt('implementerPrompt');

    expect(render_task_prompt('implementer', [
        'group' => $production['group'],
        'subtask' => $production['subtask'],
        'thread_id' => 31,
    ])['prompt'])->toBe($production['prompt']);
});

it('renders the same prompt for the opening reviewer', function (): void {
    $production = production_review_prompt(continued: false);
    $payload = $production['payload'];

    expect(render_task_prompt('reviewer', $payload)['prompt'])->toBe($production['prompt'])
        ->and($production['prompt'])->toContain('Treat guarantees against injected failures, such as a lost response or a crash between two writes, as follow-ups named in your summary, not as findings, unless the brief, an ADR, or a deliverable requires them.');
});

it('renders the same prompt for a continued reviewer', function (): void {
    $production = production_review_prompt(continued: true);
    $payload = $production['payload'];

    expect(render_task_prompt('reviewer-continue', $payload)['prompt'])->toBe($production['prompt']);
});

it('preserves literal formatter tags in prompt JSON', function (): void {
    $planner = render_task_prompt('planner', ['group' => render_task_prompt_group()]);
    $review = render_task_prompt_review_packet();
    $review['diff_body'] = "diff --git a/app/Prompt.php b/app/Prompt.php\n+<info>literal</info>\n";
    $reviewOutput = render_task_prompt('reviewer', [
        'group' => render_task_prompt_group(),
        'subtask' => render_task_prompt_subtask(),
        'thread_id' => 32,
        'review_packet' => $review,
    ]);

    expect($planner['prompt'])->toContain('<info>prompts</info>')
        ->and($reviewOutput['prompt'])->toContain('<info>literal</info>');
});

it('rejects role-inapplicable and missing nested fields', function (): void {
    $planner = new Process([PHP_BINARY, base_path('artisan'), 'tasks:render-prompt', 'planner'], base_path());
    $planner->setInput(json_encode(['group' => render_task_prompt_group(), 'subtask' => ['unexpected' => true]], JSON_THROW_ON_ERROR));
    $planner->run();
    $review = new Process([PHP_BINARY, base_path('artisan'), 'tasks:render-prompt', 'reviewer'], base_path());
    $review->setInput(json_encode(['group' => render_task_prompt_group(), 'subtask' => render_task_prompt_subtask(), 'review_packet' => array_diff_key(render_task_prompt_review_packet(), ['handoff_check' => true])], JSON_THROW_ON_ERROR));
    $review->run();
    $evidence = render_task_prompt_review_packet();
    unset($evidence['handoff_check']['evidence']);
    $missingEvidence = new Process([PHP_BINARY, base_path('artisan'), 'tasks:render-prompt', 'reviewer'], base_path());
    $missingEvidence->setInput(json_encode(['group' => render_task_prompt_group(), 'subtask' => render_task_prompt_subtask(), 'review_packet' => $evidence], JSON_THROW_ON_ERROR));
    $missingEvidence->run();
    $implementer = new Process([PHP_BINARY, base_path('artisan'), 'tasks:render-prompt', 'implementer'], base_path());
    $implementer->setInput(json_encode(['group' => render_task_prompt_group(), 'subtask' => render_task_prompt_subtask(), 'review_packet' => render_task_prompt_review_packet()], JSON_THROW_ON_ERROR));
    $implementer->run();

    expect($planner->getOutput())->toContain('input.subtask is not used')
        ->and($review->getOutput())->toContain('review_packet.handoff_check is required.')
        ->and($missingEvidence->getOutput())->toContain('review_packet.handoff_check.evidence is required.')
        ->and($implementer->getOutput())->toContain('input.review_packet is only valid for reviewer roles.');
});

it('refuses an unknown prompt field', function (): void {
    $process = new Process([PHP_BINARY, base_path('artisan'), 'tasks:render-prompt', 'planner'], base_path());
    $process->setInput(json_encode(['group' => render_task_prompt_group(), 'unexpected' => true], JSON_THROW_ON_ERROR));
    $process->run();

    expect($process->getExitCode())->toBe(1)
        ->and($process->getOutput())->toContain('input.unexpected is not a recognized field.');
});
