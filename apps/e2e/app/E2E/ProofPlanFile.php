<?php

declare(strict_types=1);

namespace App\E2E;

use App\E2E\Git\GitRepository;
use App\E2E\Value\ProofPlan;
use App\E2E\Value\TopologyRequest;
use App\E2E\Value\TopologyTarget;
use InvalidArgumentException;

/** Resolve and load the one proof plan owned by an issue workspace. */
final readonly class ProofPlanFile
{
    public const string DIRECTORY = '.loop/proof';

    private function __construct(
        public string $path,
        public ProofPlan $plan,
    ) {}

    public static function current(TopologyRequest $request, mixed $option): self
    {
        $path = self::path($request->issue, $option);

        return new self($path, self::fromFile($request->worktree, $path));
    }

    /**
     * A removal-only candidate no longer carries `.loop/`, so proof consumers
     * recover the same plan from the immutable proved commit pinned by the harness.
     */
    public static function currentOrRetained(TopologyRequest $request, mixed $option): self
    {
        $path = self::path($request->issue, $option);
        if (is_file($request->worktree.'/'.$path)) {
            return new self($path, self::fromFile($request->worktree, $path));
        }

        $repository = new GitRepository($request->worktree);
        if (array_any(
            array_keys($repository->entries($repository->commit())),
            static fn (string $entry): bool => str_starts_with($entry, '.loop/'),
        )) {
            throw new InvalidArgumentException(
                'Proof plan '.self::display($path).' cannot be read from the current issue workspace.',
            );
        }

        $proof = IssueState::forWorktree($request->issue, $request->worktree)->proof() ?? [];
        $provedSha = $proof['candidate_sha'] ?? null;
        if (! is_string($provedSha) || preg_match('/\A[0-9a-f]{40}\z/D', $provedSha) !== 1) {
            throw new InvalidArgumentException(
                'Proof plan '.self::display($path).' has no retained proved candidate.',
            );
        }
        try {
            $content = $repository->blobs($provedSha, [$path])[$path] ?? null;
            if (! is_string($content)) {
                throw new InvalidArgumentException('The retained proof plan blob is missing.');
            }

            return new self($path, ProofPlan::fromJson($content));
        } catch (\Throwable $exception) {
            throw new InvalidArgumentException(
                'Proof plan '.self::display($path).' cannot be read from the retained proved candidate: '
                    .$exception->getMessage(),
                previous: $exception,
            );
        }
    }

    public static function pathForIssue(string $issue): string
    {
        TopologyTarget::assertIssue($issue);

        return self::DIRECTORY.'/'.$issue.'.json';
    }

    private static function path(string $issue, mixed $option): string
    {
        $expected = self::pathForIssue($issue);
        if ($option === null || $option === '') {
            return $expected;
        }
        if (! is_string($option) || $option !== $expected) {
            throw new InvalidArgumentException(
                'Proof plan '.self::display($option).' must be '.self::display($expected).' for the active issue.',
            );
        }

        return $option;
    }

    private static function fromFile(string $worktree, string $path): ProofPlan
    {
        try {
            return ProofPlan::fromFile($worktree.'/'.$path);
        } catch (\Throwable $exception) {
            throw new InvalidArgumentException(
                'Proof plan '.self::display($path).' is invalid: '.$exception->getMessage(),
                previous: $exception,
            );
        }
    }

    private static function display(mixed $path): string
    {
        if (! is_string($path)) {
            return '[non-string]';
        }

        return (string) json_encode($path, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    }
}
