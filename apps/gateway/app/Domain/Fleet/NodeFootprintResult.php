<?php

declare(strict_types=1);

namespace App\Domain\Fleet;

/** What one footprint re-apply did on a Node. */
final readonly class NodeFootprintResult
{
    public const string Applied = 'applied';

    public const string Unchanged = 'unchanged';

    public const string Skipped = 'skipped';

    /**
     * @param  array<string, string>  $artifacts  Artifact name to `applied`, `unchanged`, or `skipped`.
     * @param  array<string, array{reason: string, message: string, repair: bool}>  $skipped  Why each skipped artifact was skipped.
     */
    public function __construct(
        public array $artifacts,
        public string $digest,
        public array $skipped = [],
    ) {}

    public function changed(): bool
    {
        return in_array(self::Applied, $this->artifacts, true);
    }

    /** @return array{artifacts: array<string, string>, digest: string, changed: bool, skipped: array<string, array{reason: string, message: string, repair: bool}>} */
    public function toArray(): array
    {
        return ['artifacts' => $this->artifacts, 'digest' => $this->digest, 'changed' => $this->changed(), 'skipped' => $this->skipped];
    }
}
