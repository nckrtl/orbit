<?php

declare(strict_types=1);

namespace App\Data\Herdr;

use App\Domain\Herdr\HerdrObserveContract;
use App\Domain\Herdr\ObservationGrant;
use Spatie\LaravelData\Attributes\MapOutputName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

#[MapOutputName(SnakeCaseMapper::class)]
final class ObservationGrantData extends Data
{
    public function __construct(
        public string $observerUrl,
        public string $scope,
        public string $pane,
        public string $terminal,
        public int $cols,
        public int $rows,
        public string $expiresAt,
        public string $nonce,
    ) {}

    public static function fromGrant(ObservationGrant $grant): self
    {
        return new self(
            observerUrl: $grant->observerUrl,
            scope: HerdrObserveContract::GrantScope,
            pane: $grant->claims->pane,
            terminal: $grant->claims->terminal,
            cols: $grant->claims->cols,
            rows: $grant->claims->rows,
            expiresAt: gmdate('c', $grant->claims->expiresAt),
            nonce: $grant->claims->nonce,
        );
    }
}
