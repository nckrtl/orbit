<?php

declare(strict_types=1);

namespace App\Data\Herdr;

final readonly class IssueObservationGrantData
{
    public function __construct(
        public string $pane,
        public string $terminal,
        public int $cols,
        public int $rows,
    ) {}
}
