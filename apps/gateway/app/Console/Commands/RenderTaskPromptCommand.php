<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Tasks\TaskDeliverable;
use App\Domain\Tasks\TaskDeliverableEvidence;
use App\Domain\Tasks\TaskPromptGroup;
use App\Domain\Tasks\TaskPromptRenderer;
use App\Domain\Tasks\TaskPromptSubtask;
use App\Domain\Tasks\TaskReviewPacket;
use Illuminate\Console\Command;
use InvalidArgumentException;
use JsonException;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Renders task prompts without loading the task database or contacting a workspace.
 */
final class RenderTaskPromptCommand extends Command
{
    #[\Override]
    protected $signature = 'tasks:render-prompt {role : The prompt role (implementer, reviewer, or reviewer-continue)}';

    #[\Override]
    protected $description = 'Render a task agent prompt from JSON on standard input';

    public function handle(): int
    {
        try {
            $input = $this->decode((string) file_get_contents('php://stdin'));
            $role = $this->argument('role');
            if (! in_array($role, ['implementer', 'reviewer', 'reviewer-continue'], true)) {
                throw new InvalidArgumentException('role must be implementer, reviewer, or reviewer-continue.');
            }

            $this->knownFields($input, ['group', 'subtask', 'thread_id', 'review_packet'], 'input');
            $this->validateRoleFields($input, $role);
            $prompt = match ($role) {
                'implementer' => TaskPromptRenderer::implementer($this->group($input), $this->subtask($input), $this->threadId($input)),
                'reviewer', 'reviewer-continue' => $this->reviewPacket($input, $role === 'reviewer-continue'),
            };

            $this->rawLine(json_encode([
                'role' => $role,
                'prompt' => $prompt,
                'source_commit' => is_string(config('app.version')) ? config('app.version') : 'dev',
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

            return self::SUCCESS;
        } catch (JsonException|InvalidArgumentException $exception) {
            $this->error('Invalid input: '.$exception->getMessage());

            return self::FAILURE;
        }
    }

    /** @return array<string, mixed> */
    private function decode(string $input): array
    {
        if (trim($input) === '') {
            throw new InvalidArgumentException('standard input must contain one JSON object.');
        }

        try {
            $decoded = json_decode($input, false, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('standard input is not valid JSON: '.$exception->getMessage(), previous: $exception);
        }
        if (! $decoded instanceof \stdClass) {
            throw new InvalidArgumentException('standard input must contain one JSON object.');
        }

        return get_object_vars($decoded);
    }

    private function rawLine(string $line): void
    {
        $this->output->getOutput()->write($line, true, OutputInterface::OUTPUT_RAW);
    }

    /** @param array<string, mixed> $data */
    private function validateRoleFields(array $data, string $role): void
    {
        if ($role === 'implementer' && array_key_exists('review_packet', $data)) {
            throw new InvalidArgumentException('input.review_packet is only valid for reviewer roles.');
        }
        if (in_array($role, ['reviewer', 'reviewer-continue'], true) && ! array_key_exists('review_packet', $data)) {
            throw new InvalidArgumentException('input.review_packet is required for reviewer roles.');
        }
    }

    /** @param array<string, mixed> $data */
    private function group(array $data): TaskPromptGroup
    {
        $group = $this->object($data, 'group');
        $this->knownFields($group, ['id', 'title', 'brief', 'project_slug', 'project_id', 'default_branch', 'task_check', 'start_commit'], 'group');

        return new TaskPromptGroup(
            id: $this->integer($group, 'id', 'group'),
            title: $this->string($group, 'title', 'group'),
            brief: $this->string($group, 'brief', 'group'),
            projectSlug: $this->string($group, 'project_slug', 'group'),
            projectId: $this->integer($group, 'project_id', 'group'),
            defaultBranch: $this->nullableString($group, 'default_branch', 'group'),
            taskCheck: $this->nullableString($group, 'task_check', 'group'),
            startCommit: $this->nullableString($group, 'start_commit', 'group'),
        );
    }

    /** @param array<string, mixed> $data */
    private function subtask(array $data): TaskPromptSubtask
    {
        $subtask = $this->object($data, 'subtask');
        $this->knownFields($subtask, ['id', 'title', 'brief', 'position', 'deliverables'], 'subtask');
        $deliverables = $this->list($subtask, 'deliverables', 'subtask');

        return new TaskPromptSubtask(
            id: $this->integer($subtask, 'id', 'subtask'),
            title: $this->string($subtask, 'title', 'subtask'),
            brief: $this->string($subtask, 'brief', 'subtask'),
            position: $this->integer($subtask, 'position', 'subtask'),
            deliverables: array_map(fn (mixed $deliverable, int $index): TaskDeliverable => $this->deliverable($deliverable, 'subtask.deliverables['.$index.']'), $deliverables, array_keys($deliverables)),
        );
    }

    /** @param array<string, mixed> $data */
    private function threadId(array $data): ?int
    {
        if (! array_key_exists('thread_id', $data)) {
            return null;
        }
        if ($data['thread_id'] !== null && ! is_int($data['thread_id'])) {
            throw new InvalidArgumentException('thread_id must be an integer or null.');
        }

        return $data['thread_id'];
    }

    /** @param array<string, mixed> $data */
    private function reviewPacket(array $data, bool $continued): string
    {
        $group = $this->group($data);
        $subtask = $this->subtask($data);
        $packet = $this->object($data, 'review_packet');
        $this->knownFields($packet, [
            'opens_pull_request', 'earlier_approved_subtasks', 'diff_files', 'files_complete', 'diff_available',
            'diff_summary', 'diff_body', 'untracked_content', 'handoff_check', 'start_commit', 'held_resolution',
        ], 'review_packet');

        $approvals = [];
        foreach ($this->list($packet, 'earlier_approved_subtasks', 'review_packet') as $index => $value) {
            $approval = $this->objectValue($value, 'review_packet.earlier_approved_subtasks['.$index.']');
            $this->knownFields($approval, ['title', 'summary'], 'review_packet.earlier_approved_subtasks['.$index.']');
            $approvals[] = [
                'title' => $this->string($approval, 'title', 'review_packet.earlier_approved_subtasks['.$index.']'),
                'summary' => $this->string($approval, 'summary', 'review_packet.earlier_approved_subtasks['.$index.']'),
            ];
        }

        $diffFiles = [];
        foreach ($this->list($packet, 'diff_files', 'review_packet') as $index => $value) {
            $file = $this->objectValue($value, 'review_packet.diff_files['.$index.']');
            $this->knownFields($file, ['path', 'insertions', 'deletions'], 'review_packet.diff_files['.$index.']');
            $diffFiles[] = [
                'path' => $this->string($file, 'path', 'review_packet.diff_files['.$index.']'),
                'insertions' => $this->integer($file, 'insertions', 'review_packet.diff_files['.$index.']'),
                'deletions' => $this->integer($file, 'deletions', 'review_packet.diff_files['.$index.']'),
            ];
        }

        $summary = $this->object($packet, 'diff_summary', 'review_packet');
        $this->knownFields($summary, ['files', 'insertions', 'deletions'], 'review_packet.diff_summary');
        $diffSummary = [
            'files' => $this->integer($summary, 'files', 'review_packet.diff_summary'),
            'insertions' => $this->integer($summary, 'insertions', 'review_packet.diff_summary'),
            'deletions' => $this->integer($summary, 'deletions', 'review_packet.diff_summary'),
        ];

        $untracked = [];
        foreach ($this->list($packet, 'untracked_content', 'review_packet') as $index => $value) {
            $entry = $this->objectValue($value, 'review_packet.untracked_content['.$index.']');
            $this->knownFields($entry, ['path', 'patch'], 'review_packet.untracked_content['.$index.']');
            $this->string($entry, 'path', 'review_packet.untracked_content['.$index.']');
            $untracked[] = $this->string($entry, 'patch', 'review_packet.untracked_content['.$index.']');
        }

        $handoff = $this->object($packet, 'handoff_check', 'review_packet');
        $this->knownFields($handoff, ['status', 'exit_code', 'evidence'], 'review_packet.handoff_check');
        if (! array_key_exists('evidence', $handoff)) {
            throw new InvalidArgumentException('review_packet.handoff_check.evidence is required.');
        }
        $evidence = $handoff['evidence'];
        if ($evidence !== null) {
            $evidenceObject = $this->objectValue($evidence, 'review_packet.handoff_check.evidence');
            $this->validateEvidence($evidenceObject);
            $evidence = $this->arrays($evidenceObject);
        }

        if (! array_key_exists('held_resolution', $packet)) {
            throw new InvalidArgumentException('review_packet.held_resolution is required.');
        }
        $heldResolution = $packet['held_resolution'];
        if ($heldResolution !== null && ! is_string($heldResolution)) {
            throw new InvalidArgumentException('review_packet.held_resolution must be a string or null.');
        }

        $diffBody = $this->string($packet, 'diff_body', 'review_packet');
        $diff = $this->diff($diffBody, $untracked, $this->boolean($packet, 'diff_available', 'review_packet'));

        return new TaskReviewPacket(
            groupBrief: $group->brief,
            subtaskId: $subtask->id,
            subtaskTitle: $subtask->title,
            subtaskBrief: $subtask->brief,
            deliverables: $subtask->deliverables,
            approvals: $continued ? [] : $approvals,
            diffFiles: $this->boolean($packet, 'files_complete', 'review_packet') ? $diffFiles : [],
            diff: $diff,
            taskCheck: $group->taskCheck,
            handoffStatus: $this->string($handoff, 'status', 'review_packet.handoff_check'),
            handoffExitCode: $this->nullableInteger($handoff, 'exit_code', 'review_packet.handoff_check'),
            evidence: TaskDeliverableEvidence::fromArray($evidence),
            startCommit: $this->string($packet, 'start_commit', 'review_packet'),
            continued: $continued,
            opensPullRequest: $this->boolean($packet, 'opens_pull_request', 'review_packet'),
            diffFilesComplete: $this->boolean($packet, 'files_complete', 'review_packet'),
            diffAvailable: $this->boolean($packet, 'diff_available', 'review_packet'),
            diffCounts: $diffSummary,
            resolution: $continued ? '' : ($heldResolution ?? ''),
            threadId: $this->threadId($data),
            groupStartCommit: $group->startCommit ?? '',
        )->render();
    }

    /** @param list<string> $patches */
    private function diff(string $body, array $patches, bool $available): string
    {
        if (! $available) {
            return '';
        }

        $capture = substr($body.implode('', $patches), 0, 20_000);

        return $capture."\n";
    }

    private function arrays(mixed $value): mixed
    {
        if ($value instanceof \stdClass) {
            $value = get_object_vars($value);
        }
        if (is_array($value)) {
            return array_map($this->arrays(...), $value);
        }

        return $value;
    }

    /** @param array<string, mixed> $evidence */
    private function validateEvidence(array $evidence): void
    {
        $this->knownFields($evidence, ['diff', 'tests', 'commands'], 'review_packet.handoff_check.evidence');
        if (! array_key_exists('diff', $evidence) || ! array_key_exists('tests', $evidence) || ! array_key_exists('commands', $evidence)) {
            throw new InvalidArgumentException('review_packet.handoff_check.evidence must contain diff, tests, and commands.');
        }
        if ($evidence['diff'] !== null) {
            foreach ($this->listValue($evidence['diff'], 'review_packet.handoff_check.evidence.diff') as $index => $value) {
                $diff = $this->objectValue($value, 'review_packet.handoff_check.evidence.diff['.$index.']');
                $this->knownFields($diff, ['status', 'path'], 'review_packet.handoff_check.evidence.diff['.$index.']');
                $this->string($diff, 'status', 'evidence.diff');
                $this->string($diff, 'path', 'evidence.diff');
            }
        }
        $tests = $this->object($evidence, 'tests', 'evidence');
        foreach ($tests as $id => $run) {
            $test = $this->objectValue($run, 'evidence.tests.'.$id);
            $this->knownFields($test, ['exit_code', 'cases', 'base_placed', 'base_exit_code', 'base_timed_out', 'base_timeout_seconds', 'base_cases'], 'evidence.tests.'.$id);
            $this->integer($test, 'exit_code', 'evidence.tests.'.$id);
            $this->cases($test, 'cases', 'evidence.tests.'.$id);
            if (array_key_exists('base_placed', $test) && ! is_bool($test['base_placed'])) {
                throw new InvalidArgumentException('evidence.tests.'.$id.'.base_placed must be a boolean.');
            }
            if (array_key_exists('base_exit_code', $test) && ! is_int($test['base_exit_code'])) {
                throw new InvalidArgumentException('evidence.tests.'.$id.'.base_exit_code must be an integer.');
            }
            if (array_key_exists('base_timed_out', $test) && ! is_bool($test['base_timed_out'])) {
                throw new InvalidArgumentException('evidence.tests.'.$id.'.base_timed_out must be a boolean.');
            }
            if (array_key_exists('base_timeout_seconds', $test) && ! is_int($test['base_timeout_seconds'])) {
                throw new InvalidArgumentException('evidence.tests.'.$id.'.base_timeout_seconds must be an integer.');
            }
            if (array_key_exists('base_cases', $test)) {
                $this->cases($test, 'base_cases', 'evidence.tests.'.$id);
            }
        }
        $commands = $this->object($evidence, 'commands', 'evidence');
        foreach ($commands as $id => $run) {
            $command = $this->objectValue($run, 'evidence.commands.'.$id);
            $this->knownFields($command, ['exit_code', 'output'], 'evidence.commands.'.$id);
            $this->integer($command, 'exit_code', 'evidence.commands.'.$id);
            $this->string($command, 'output', 'evidence.commands.'.$id);
        }
    }

    /** @param array<string, mixed> $data */
    private function cases(array $data, string $field, string $path): void
    {
        foreach ($this->list($data, $field, $path) as $index => $value) {
            $case = $this->objectValue($value, $path.'.'.$field.'['.$index.']');
            $this->knownFields($case, ['name', 'status', 'kind', 'message'], $path.'.'.$field.'['.$index.']');
            $this->string($case, 'name', $path.'.'.$field.'['.$index.']');
            $this->string($case, 'status', $path.'.'.$field.'['.$index.']');
            if (array_key_exists('kind', $case)) {
                $this->string($case, 'kind', $path.'.'.$field.'['.$index.']');
            }
            if (array_key_exists('message', $case)) {
                $this->string($case, 'message', $path.'.'.$field.'['.$index.']');
            }
        }
    }

    private function deliverable(mixed $value, string $path): TaskDeliverable
    {
        $deliverable = $this->objectValue($value, $path);
        $this->knownFields($deliverable, ['id', 'type', 'description', 'path', 'change', 'project', 'file', 'name', 'fails_on_base', 'command', 'directory'], $path);
        foreach (['id', 'type', 'description'] as $field) {
            $this->string($deliverable, $field, $path);
        }
        $type = $deliverable['type'];
        $fields = match ($type) {
            'file' => ['path', 'change'],
            'test' => ['project', 'file', 'name', 'fails_on_base'],
            'command' => ['command', 'directory'],
            'review' => [],
            default => throw new InvalidArgumentException($path.'.type must be file, test, command, or review.'),
        };
        $this->knownFields($deliverable, array_values(array_unique(array_merge(['id', 'type', 'description'], $fields))), $path);
        foreach ($fields as $field) {
            if (! array_key_exists($field, $deliverable)) {
                throw new InvalidArgumentException($path.'.'.$field.' is required.');
            }
            if ($field === 'fails_on_base') {
                if (! is_bool($deliverable[$field])) {
                    throw new InvalidArgumentException($path.'.fails_on_base must be a boolean.');
                }
            } else {
                $this->string($deliverable, $field, $path);
            }
        }

        return TaskDeliverable::fromArray($deliverable);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function object(array $data, string $field, string $parent = 'input'): array
    {
        if (! array_key_exists($field, $data)) {
            throw new InvalidArgumentException($parent.'.'.$field.' is required.');
        }

        return $this->objectValue($data[$field], $parent.'.'.$field);
    }

    /** @return array<string, mixed> */
    private function objectValue(mixed $value, string $path): array
    {
        if (! $value instanceof \stdClass) {
            throw new InvalidArgumentException($path.' must be an object.');
        }

        return get_object_vars($value);
    }

    /**
     * @param  array<string, mixed>  $data
     * @return list<mixed>
     */
    private function list(array $data, string $field, string $parent): array
    {
        if (! array_key_exists($field, $data)) {
            throw new InvalidArgumentException($parent.'.'.$field.' is required.');
        }

        return $this->listValue($data[$field], $parent.'.'.$field);
    }

    /** @return list<mixed> */
    private function listValue(mixed $value, string $path): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            throw new InvalidArgumentException($path.' must be an array.');
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<string>  $allowed
     */
    private function knownFields(array $data, array $allowed, string $path): void
    {
        foreach (array_keys($data) as $field) {
            if (! in_array($field, $allowed, true)) {
                throw new InvalidArgumentException($path.'.'.$field.' is not a recognized field.');
            }
        }
    }

    /** @param array<string, mixed> $data */
    private function string(array $data, string $field, string $path): string
    {
        if (! array_key_exists($field, $data)) {
            throw new InvalidArgumentException($path.'.'.$field.' is required.');
        }
        if (! is_string($data[$field])) {
            throw new InvalidArgumentException($path.'.'.$field.' must be a string.');
        }

        return $data[$field];
    }

    /** @param array<string, mixed> $data */
    private function nullableString(array $data, string $field, string $path): ?string
    {
        if (! array_key_exists($field, $data)) {
            throw new InvalidArgumentException($path.'.'.$field.' is required.');
        }
        if ($data[$field] !== null && ! is_string($data[$field])) {
            throw new InvalidArgumentException($path.'.'.$field.' must be a string or null.');
        }

        return $data[$field];
    }

    /** @param array<string, mixed> $data */
    private function integer(array $data, string $field, string $path): int
    {
        if (! array_key_exists($field, $data)) {
            throw new InvalidArgumentException($path.'.'.$field.' is required.');
        }
        if (! is_int($data[$field])) {
            throw new InvalidArgumentException($path.'.'.$field.' must be an integer.');
        }

        return $data[$field];
    }

    /** @param array<string, mixed> $data */
    private function nullableInteger(array $data, string $field, string $path): ?int
    {
        if (! array_key_exists($field, $data)) {
            throw new InvalidArgumentException($path.'.'.$field.' is required.');
        }
        if ($data[$field] !== null && ! is_int($data[$field])) {
            throw new InvalidArgumentException($path.'.'.$field.' must be an integer or null.');
        }

        return $data[$field];
    }

    /** @param array<string, mixed> $data */
    private function boolean(array $data, string $field, string $path): bool
    {
        if (! array_key_exists($field, $data)) {
            throw new InvalidArgumentException($path.'.'.$field.' is required.');
        }
        if (! is_bool($data[$field])) {
            throw new InvalidArgumentException($path.'.'.$field.' must be a boolean.');
        }

        return $data[$field];
    }
}
