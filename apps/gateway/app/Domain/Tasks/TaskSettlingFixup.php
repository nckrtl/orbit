<?php

declare(strict_types=1);

namespace App\Domain\Tasks;

/**
 * One fixup subtask for a conflict or a failed check on a settling pull request (ADR 0164).
 */
final readonly class TaskSettlingFixup
{
    /** A problem keeps at most this many fixups, in any status. */
    public const int Limit = 2;

    /**
     * Orbit check names whose reproduction command is the job's check steps, not its setup.
     *
     * @var array<string, array{string, string}>
     */
    private const array OrbitChecks = [
        'CLI' => ['composer check', 'apps/cli'],
        'Docs' => ['composer check', 'apps/docs'],
        'Gateway' => ['composer check', 'apps/gateway'],
        'E2E' => ['composer check', 'apps/e2e'],
        'PHP SDK' => ['composer check', 'packages/php-sdk'],
        'API reference' => ['bin/docs-openapi --check && bin/mcp-tools --check', '.'],
        'Web' => ['copy=$(mktemp) && cp src/api/schema.d.ts "$copy" && bun run types && git diff --exit-code --no-index "$copy" src/api/schema.d.ts && bun run check && bun run test && bun run build', 'apps/web'],
        'Pi server' => ['bun run check && bun run test && bun run build', 'apps/pi-server'],
        'Agent annotation' => ['bun run check && bun run build && bun run test', 'packages/agent-annotation'],
        'Rust agent' => ['cargo fmt --all -- --check && cargo clippy --locked --all-targets -- -D warnings && cargo test --locked', 'apps/agent'],
    ];

    /**
     * @param  list<array<string, string>>  $deliverables
     */
    public function __construct(
        public string $identity,
        public string $title,
        public string $brief,
        public array $deliverables,
    ) {}

    /** The base ref of a conflict fixup, or null when this fixup repairs a check. */
    public function conflictBase(): ?string
    {
        if (! str_starts_with($this->identity, 'conflict:')) {
            return null;
        }

        return substr($this->identity, strlen('conflict:'));
    }

    /**
     * Problems in the order one tick considers them: the conflict, then failed checks that have an
     * Orbit reproduction row, then the other failed checks. Both check groups keep GitHub's order.
     *
     * @param  list<TaskPullRequestCheck>  $failedChecks
     * @return list<self>
     */
    public static function plans(string $projectSlug, bool $conflicts, ?string $baseRef, array $failedChecks): array
    {
        $plans = [];
        if ($conflicts) {
            $base = $baseRef ?? 'the base branch';
            $plans[] = new self(
                identity: 'conflict:'.$base,
                title: 'Merge origin/'.$base,
                brief: 'Merge origin/'.$base.' into the task branch and resolve the conflicts. Do not rebase and do not force-push.',
                deliverables: self::deliverables(null, null),
            );
        }

        $reproducible = [];
        $others = [];
        foreach ($failedChecks as $check) {
            $plan = new self(
                identity: 'check:'.$check->name,
                title: mb_substr('Fix '.$check->name, 0, 160),
                brief: 'Check '.$check->name.' failed'.($check->url !== null ? ': '.$check->url : '').'. Do not rebase and do not force-push.',
                deliverables: self::deliverables($projectSlug, $check->name),
            );
            if (self::reproduces($projectSlug, $check->name)) {
                $reproducible[] = $plan;
            } else {
                $others[] = $plan;
            }
        }

        return [...$plans, ...$reproducible, ...$others];
    }

    public static function reproduces(string $projectSlug, string $checkName): bool
    {
        return $projectSlug === 'orbit' && isset(self::OrbitChecks[$checkName]);
    }

    /**
     * @return list<array<string, string>>
     */
    private static function deliverables(?string $projectSlug, ?string $checkName): array
    {
        $items = [
            new TaskDeliverable(
                id: 'composer-check',
                type: TaskDeliverableType::Command,
                description: 'Run composer check',
                command: 'composer check',
                directory: '.',
            ),
        ];
        if (is_string($projectSlug) && is_string($checkName) && self::reproduces($projectSlug, $checkName)) {
            [$command, $directory] = self::OrbitChecks[$checkName];
            if ($command !== 'composer check' || $directory !== '.') {
                $items[] = new TaskDeliverable(
                    id: 'reproduce-check',
                    type: TaskDeliverableType::Command,
                    description: mb_substr('Reproduce '.$checkName, 0, 500),
                    command: $command,
                    directory: $directory,
                );
            }
        }

        return array_map(static fn (TaskDeliverable $deliverable): array => $deliverable->toArray(), $items);
    }
}
