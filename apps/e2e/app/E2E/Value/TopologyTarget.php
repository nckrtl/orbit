<?php

declare(strict_types=1);

namespace App\E2E\Value;

use InvalidArgumentException;

/** @mago-expect lint:cyclomatic-complexity,too-many-methods One identity boundary derives every attempt-scoped resource name. */
final readonly class TopologyTarget
{
    private const string ISSUE_PATTERN = '/\A[A-Z][A-Z0-9]{1,9}-[1-9][0-9]{0,8}\z/D';

    private function __construct(
        public string $issue,
        public ?AttemptId $attempt,
        public TopologyRecipe $recipe,
        private ?TopologySnapshotIdentity $topologySnapshot = null,
    ) {}

    public static function feature(string $issue, AttemptId $attempt, ?TopologyRecipe $recipe = null): self
    {
        self::assertIssue($issue);

        return new self($issue, $attempt, $recipe ?? TopologyRecipe::registered());
    }

    public static function disposableCold(string $issue, AttemptId $attempt, TopologyRecipe $recipe): self
    {
        self::assertIssue($issue);

        return new self($issue, $attempt, $recipe);
    }

    /** The current or retired physical topology snapshot. */
    public static function topologySnapshot(
        ?TopologySnapshotIdentity $identity = null,
        ?TopologyRecipe $recipe = null,
    ): self {
        $recipe ??= TopologyRecipe::registered();
        if ($recipe->nodeKeys() !== TopologyProfile::ROLES) {
            throw new InvalidArgumentException('The topology snapshot requires the registered physical Node keys.');
        }

        return new self(
            'topology-snapshot',
            null,
            $recipe,
            $identity ?? TopologySnapshotIdentity::primary(),
        );
    }

    public static function assertIssue(string $issue): void
    {
        if (preg_match(self::ISSUE_PATTERN, $issue) !== 1) {
            throw new InvalidArgumentException('The Linear issue ID is invalid.');
        }
    }

    public static function ipv4For(int $slot, int|string $node, ?TopologyRecipe $recipe = null): string
    {
        if ($slot < 1 || $slot > 200) {
            throw new InvalidArgumentException('The topology slot is invalid.');
        }

        $address = is_int($node) ? $node : ($recipe ?? TopologyRecipe::registered())->resolveNode($node)->address;
        if ($address < 10 || $address > 254) {
            throw new InvalidArgumentException('The topology Node address position is invalid.');
        }

        return '10.232.'.$slot.'.'.$address;
    }

    public function isTopologySnapshot(): bool
    {
        return $this->topologySnapshot !== null;
    }

    /** The topology snapshot identity of a topology snapshot target; a feature target has none. */
    public function requireTopologySnapshotIdentity(): TopologySnapshotIdentity
    {
        return (
            $this->topologySnapshot ?? throw new InvalidArgumentException(
                'The feature target has no topology snapshot identity.',
            )
        );
    }

    public function matchesBranch(string $branch): bool
    {
        if ($this->topologySnapshot !== null) {
            return false;
        }

        return self::issueMatchesBranch($this->issue, $branch);
    }

    public static function issueMatchesBranch(string $issue, string $branch): bool
    {
        self::assertIssue($issue);

        return (
            preg_match(
                '/(?:\A|[^a-z0-9])'.preg_quote($issue, '/').'(?=\z|[^a-z0-9])/i',
                $branch,
            ) === 1
        );
    }

    public function network(): string
    {
        if ($this->topologySnapshot !== null) {
            return $this->topologySnapshot->network();
        }

        return 'oe-'.substr(hash('sha256', $this->issue.':'.$this->requireAttempt()->value), 0, 12);
    }

    public function instance(string $nodeOrRole): string
    {
        $node = $this->recipe->resolveNode($nodeOrRole);

        if ($this->topologySnapshot !== null) {
            return $this->topologySnapshot->instance($node->key);
        }

        return 'orbit-e2e-'.strtolower($this->issue).'-'.$this->requireAttempt()->short().'-'.$node->key;
    }

    public function mac(string $nodeOrRole): string
    {
        $node = $this->recipe->resolveNode($nodeOrRole);

        return self::macFor($this->network(), $node->key);
    }

    public static function macFor(string $topology, string $node): string
    {
        if (preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9_.-]{0,62}\z/D', $topology) !== 1) {
            throw new InvalidArgumentException('The topology network identity is invalid.');
        }
        TopologyNode::assertKey($node);

        $hash = substr(sha1($topology.':'.$node), 0, 6);

        return '00:16:3e:'.implode(':', str_split($hash, 2));
    }

    /** The attempt identity of a feature target; topology snapshot has none. */
    public function requireAttempt(): AttemptId
    {
        return $this->attempt ?? throw new InvalidArgumentException('The feature target has no attempt identity.');
    }
}
