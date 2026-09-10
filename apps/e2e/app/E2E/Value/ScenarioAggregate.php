<?php

declare(strict_types=1);

namespace App\E2E\Value;

use InvalidArgumentException;

final readonly class ScenarioAggregate
{
    /** @param list<ScenarioResult> $results */
    public function __construct(
        public string $candidate,
        public ScenarioRunId $run,
        public array $results,
        public string $startedAt,
        public string $finishedAt,
    ) {
        if (preg_match('/\A[a-f0-9]{40}\z/D', $candidate) !== 1 || $results === []) {
            throw new InvalidArgumentException('The scenario aggregate is invalid.');
        }
        foreach ($results as $result) {
            if ($result->candidate !== $candidate || $result->run != $run) {
                throw new InvalidArgumentException('A scenario aggregate result has another identity.');
            }
        }
    }

    public function successful(): bool
    {
        return array_all($this->results, static fn (ScenarioResult $result): bool => $result->status === ScenarioStatus::Passed);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        $counts = array_fill_keys(array_column(ScenarioStatus::cases(), 'value'), 0);
        foreach ($this->results as $result) {
            $counts[$result->status->value]++;
        }

        return [
            'schema' => 1,
            'candidate_sha' => $this->candidate,
            'run_id' => $this->run->value,
            'started_at' => $this->startedAt,
            'finished_at' => $this->finishedAt,
            'status' => $this->successful() ? 'passed' : 'failed',
            'counts' => $counts,
            'results' => array_map(static fn (ScenarioResult $result): array => $result->toArray(), $this->results),
        ];
    }
}
