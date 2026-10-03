<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

/**
 * The uncut task context a reviewer reads from `.git/orbit/context.md`.
 *
 * The review packet caps the same parts. This file keeps each one whole, on every driver.
 */
final readonly class TaskReviewContext
{
    public const string Path = '$(git rev-parse --git-path orbit)/context.md';

    /**
     * @param  list<TaskDeliverable>  $deliverables
     * @param  list<array{title: string, body: string}>  $approvals  earlier approved subtasks, oldest first
     * @param  list<array{question: string, answer: string}>  $consults  answered consults, oldest first
     */
    public function __construct(
        private string $taskBrief,
        private string $subtaskBrief,
        private array $deliverables,
        private array $approvals,
        private string $resolution,
        private array $consults = [],
    ) {}

    public function render(): string
    {
        return implode("\n\n", [
            '# Task context',
            $this->section('Task brief', $this->taskBrief),
            $this->section('Subtask brief', $this->subtaskBrief),
            $this->section('Deliverables', $this->deliverablesText()),
            $this->section('Earlier approvals', $this->approvalsText()),
            $this->section('Held resolution', $this->resolution === '' ? 'None.' : $this->resolution),
            $this->section('Answered consults', $this->consultsText()),
        ])."\n";
    }

    private function section(string $heading, string $body): string
    {
        return '## '.$heading."\n\n".$body;
    }

    private function deliverablesText(): string
    {
        if ($this->deliverables === []) {
            return 'None.';
        }
        $blocks = [];
        foreach ($this->deliverables as $deliverable) {
            $lines = ['### '.$deliverable->id];
            foreach ($deliverable->toArray() as $field => $value) {
                $lines[] = $this->field((string) $field, $value);
            }
            $blocks[] = implode("\n", $lines);
        }

        return implode("\n\n", $blocks);
    }

    /** @param string|bool|list<string> $value */
    private function field(string $name, string|bool|array $value): string
    {
        if (is_bool($value)) {
            return '- '.$name.': '.($value ? 'true' : 'false');
        }
        if (is_array($value)) {
            if ($value === []) {
                return '- '.$name.':';
            }
            $lines = ['- '.$name.':'];
            foreach ($value as $item) {
                $lines[] = '  - '.$item;
            }

            return implode("\n", $lines);
        }
        if (! str_contains($value, "\n")) {
            return '- '.$name.': '.$value;
        }

        return "- {$name}:\n```\n{$value}\n```";
    }

    private function consultsText(): string
    {
        if ($this->consults === []) {
            return 'None.';
        }

        return implode("\n\n", array_map(
            static fn (array $consult): string => "### Question\n\n".$consult['question']."\n\n### Answer\n\n".$consult['answer'],
            $this->consults,
        ));
    }

    private function approvalsText(): string
    {
        if ($this->approvals === []) {
            return 'None.';
        }
        $blocks = [];
        foreach ($this->approvals as $approval) {
            $blocks[] = '### '.$approval['title']."\n\n".$approval['body'];
        }

        return implode("\n\n", $blocks);
    }
}
